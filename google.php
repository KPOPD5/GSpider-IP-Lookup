<?php
/**
 * Google 蜘蛛 IP 段查询页面（只读缓存，更新由定时任务处理）
 */

require_once __DIR__ . '/config.php';

// 自动更新调度（无缓存时自动拉取一次）
@include_once __DIR__ . '/auto_update.php';

/**
 * 判断 IP 是否落在 CIDR 范围内（支持 IPv4 和 IPv6）
 */
function ipInCIDR(string $ip, string $cidr): bool
{
    // 判断 IP 类型
    $isV6 = strpos($ip, ':') !== false;
    $cidrIsV6 = strpos($cidr, ':') !== false;
    if ($isV6 !== $cidrIsV6) return false;

    if ($isV6) {
        // IPv6 CIDR 匹配
        $parts = explode('/', $cidr);
        if (count($parts) !== 2) return false;
        $subnet = inet_pton($parts[0]);
        $target = inet_pton($ip);
        if ($subnet === false || $target === false) return false;
        $bits = (int)$parts[1];
        if ($bits < 0 || $bits > 128) return false;

        // 比较前缀
        $byteLen = (int)($bits / 8);
        $bitRem = $bits % 8;
        for ($i = 0; $i < $byteLen; $i++) {
            if ($subnet[$i] !== $target[$i]) return false;
        }
        if ($bitRem > 0) {
            $mask = 0xff << (8 - $bitRem);
            if ((ord($subnet[$byteLen]) & $mask) !== (ord($target[$byteLen]) & $mask)) return false;
        }
        return true;
    } else {
        // IPv4 CIDR 匹配
        $parts = explode('/', $cidr);
        if (count($parts) !== 2) return false;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($parts[0]);
        if ($ipLong === false || $subnetLong === false) return false;
        $bits = (int)$parts[1];
        if ($bits < 0 || $bits > 32) return false;
        $mask = ($bits === 0) ? 0 : (-1 << (32 - $bits));
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}

$cacheFile = __DIR__ . '/db/google_spider_cache.json';
$cacheTime = 0;
$allData = [];

// 只读取缓存
if (file_exists($cacheFile)) {
    $cacheTime = filemtime($cacheFile);
    $cached = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cached)) $allData = $cached;
}

$cacheAge = $cacheTime > 0 ? time() - $cacheTime : PHP_INT_MAX;
$isStale = $cacheAge > 604800; // 超过7天提示

// 首次安装时自动获取一次
if (empty($allData)) {
    @include_once __DIR__ . '/update_google_spiders.php';
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) $allData = $cached;
        $cacheTime = filemtime($cacheFile);
        $isStale = false;
    }
}

// 统计
$totalAll = count($allData);
$totalV4 = $totalV6 = 0;
$sources = [];
foreach ($allData as $d) {
    if (strpos($d['prefix'], ':') !== false) $totalV6++; else $totalV4++;
    $s = $d['source'] ?? '';
    if ($s) $sources[$s] = ($sources[$s] ?? 0) + 1;
}

$filter = $_GET['filter'] ?? 'all';
$source = $_GET['source'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$displayData = $allData;
if ($filter === 'ipv4') $displayData = array_filter($displayData, fn($d) => strpos($d['prefix'], ':') === false);
elseif ($filter === 'ipv6') $displayData = array_filter($displayData, fn($d) => strpos($d['prefix'], ':') !== false);
if ($source !== 'all') $displayData = array_filter($displayData, fn($d) => ($d['source'] ?? '') === $source);
if ($search) {
    $q = strtolower($search);
    // 判断是否为完整 IP 地址：若是则做精确 CIDR 匹配，否则做子串模糊搜索
    $isFullIP = filter_var($search, FILTER_VALIDATE_IP) !== false;
    if ($isFullIP) {
        $displayData = array_filter($displayData, fn($d) => ipInCIDR($search, $d['prefix']));
    } else {
        $displayData = array_filter($displayData, fn($d) => strpos(strtolower($d['prefix']), $q) !== false);
    }
}
$displayData = array_values($displayData);

$sourceLabels = [
    'common-crawlers' => 'Common Crawlers',
    'special-crawlers' => 'Special Crawlers',
    'user-triggered-fetchers' => 'User-Triggered Fetchers',
];

// 从数据库加载自定义数据源的显示名称（覆盖/追加内置标签）
if (file_exists(SQLITE_DB_PATH)) {
    try {
        $srcDb = new SQLite3(SQLITE_DB_PATH);
        $srcDb->enableExceptions(false);
        // 确保表存在（兼容已有数据库升级）
        $srcDb->exec("CREATE TABLE IF NOT EXISTS google_data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_key TEXT NOT NULL UNIQUE,
            source_name TEXT NOT NULL,
            endpoint_url TEXT NOT NULL,
            is_active INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $srcResult = $srcDb->query("SELECT source_key, source_name, is_active FROM google_data_sources ORDER BY sort_order ASC");
        if ($srcResult) {
            while ($srcRow = $srcResult->fetchArray(SQLITE3_ASSOC)) {
                if ($srcRow['is_active']) {
                    $sourceLabels[$srcRow['source_key']] = $srcRow['source_name'];
                } else {
                    unset($sourceLabels[$srcRow['source_key']]);
                }
            }
        }
        $srcDb->close();
    } catch (Exception $e) {}
}

// 读取最近一次更新记录
$updateLog = null;
if (file_exists(SQLITE_DB_PATH)) {
    try {
        $db = new SQLite3(SQLITE_DB_PATH);
        $row = $db->querySingle("SELECT message, created_at, records_count FROM update_records WHERE update_type = 'google_spider_ips' AND status = 'success' ORDER BY created_at DESC LIMIT 1", true);
        if ($row) {
            $updateLog = [
                'time' => $row['created_at'],
                'total' => $row['records_count'],
            ];
            $detail = json_decode($row['message'], true);
            if ($detail) {
                $updateLog['imported'] = $detail['imported'] ?? 0;
                $updateLog['reactivated'] = $detail['reactivated'] ?? 0;
                $updateLog['sources'] = $detail['sources'] ?? [];
            }
        }
        $db->close();
    } catch (Exception $e) {}
}

// 统一的数据更新时间：优先使用数据库记录时间，回退到缓存文件时间
$displayUpdateTime = $updateLog['time'] ?? ($cacheTime ? date('Y-m-d H:i:s', $cacheTime) : null);

// SEO：构建当前页面完整 URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = getSafeHost();
$canonicalUrl = $scheme . '://' . $host . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>Google 蜘蛛 IP 段查询 - Googlebot Crawler IP Ranges</title>
<meta name="description" content="查询 Google 官方蜘蛛爬虫 IP 段列表，包含 Googlebot、特殊爬虫、用户触发抓取工具等全部 IPv4/IPv6 地址段，数据源自 Google 官方每7天自动更新。">
<meta name="keywords" content="Googlebot,谷歌蜘蛛,谷歌爬虫,Google蜘蛛IP,Google Crawler,IP段查询,Google IP Ranges,common-crawlers,special-crawlers">
<meta name="robots" content="index, follow">
<meta name="author" content="your-name">
<link rel="canonical" href="<?php echo $canonicalUrl; ?>">

<meta property="og:type" content="website">
<meta property="og:title" content="Google 蜘蛛 IP 段查询 - Googlebot Crawler IP Ranges">
<meta property="og:description" content="查询 Google 官方蜘蛛爬虫 IP 段，IPv4/IPv6 全量数据，每7天自动同步。">
<meta property="og:url" content="<?php echo $canonicalUrl; ?>">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="Google 蜘蛛 IP 段查询">
<meta name="applicable-device" content="pc,mobile">

<link rel="stylesheet" href="assets/css/common.css?v=1.0.0">
<link rel="stylesheet" href="assets/css/google.css?v=1.0.0">

<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "WebApplication",
    "name": "Google 蜘蛛 IP 段查询",
    "url": "<?php echo $canonicalUrl; ?>",
    "description": "查询 Google 官方蜘蛛爬虫 IP 段列表，数据定时同步更新",
    "applicationCategory": "DeveloperApplication",
    "author": { "@type": "Organization", "name": "your-name", "url": "https://your-domain.com/" },
    "inLanguage": "zh-CN"
}
</script>
</head>
<body>
<div class="container">
<a href="/" class="back-link">← 返回蜘蛛识别查询</a>
<div class="header">
    <div style="display:flex;align-items:center;justify-content:center;gap:10px">
        <svg width="32" height="32" viewBox="0 0 48 48" style="flex-shrink:0">
            <path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"/>
            <path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"/>
            <path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z"/>
            <path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z"/>
        </svg>
        <h1 style="margin:0">Google 蜘蛛 IP 段查询</h1>
    </div>
    <p>Google Crawler IP Ranges · 数据源自 Google 官方<?php echo $isStale ? ' · ⚠️ 缓存超过7天，请运行定时更新' : ''; ?></p>
</div>

<?php if ($updateLog): ?>
<div class="update-log-card">
    <div class="log-item">
        <span class="log-dot"></span>
        <span>获取 <strong class="log-val"><?php echo number_format($updateLog['total']); ?></strong> 条</span>
    </div>
    <div class="log-item">
        <span class="log-dot"></span>
        <span>新增 <strong class="log-val"><?php echo number_format($updateLog['imported']); ?></strong> 条</span>
    </div>
    <?php if (!empty($updateLog['sources'])): ?>
    <div class="log-item">
        <?php foreach ($updateLog['sources'] as $src => $cnt): ?>
            <span><?php echo htmlspecialchars($src); ?>: <strong class="log-val"><?php echo number_format($cnt); ?></strong></span>
            <?php if (!next($updateLog['sources'])) break; ?><span class="log-sep">|</span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="log-item" style="margin-left:auto">
        <span>最后更新: <?php echo htmlspecialchars($displayUpdateTime); ?></span>
    </div>
</div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-number"><?php echo number_format($totalAll); ?></div><div class="stat-label">总计 IP 段</div></div>
    <div class="stat-card"><div class="stat-number"><?php echo number_format($totalV4); ?></div><div class="stat-label">IPv4</div></div>
    <div class="stat-card"><div class="stat-number"><?php echo number_format($totalV6); ?></div><div class="stat-label">IPv6</div></div>
    <div class="stat-card"><div class="stat-number"><?php echo count($sources); ?></div><div class="stat-label">数据源</div></div>
</div>

<form method="GET" id="filterForm">
<div class="toolbar">
    <a href="?filter=all&source=<?php echo urlencode($source); ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $filter==='all'?'active':''; ?>">全部</a>
    <a href="?filter=ipv4&source=<?php echo urlencode($source); ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $filter==='ipv4'?'active':''; ?>">IPv4</a>
    <a href="?filter=ipv6&source=<?php echo urlencode($source); ?>&search=<?php echo urlencode($search); ?>" class="<?php echo $filter==='ipv6'?'active':''; ?>">IPv6</a>
    <button type="button" class="btn" onclick="copyAll()">📋 复制列表</button>
</div>
<div class="search-wrap">
    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="搜索 IP，如 66.249..." autocomplete="off">
    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
    <input type="hidden" name="source" value="<?php echo htmlspecialchars($source); ?>">
    <?php if ($search): ?><button type="button" onclick="clearSearch()">✕ 清除</button><?php endif; ?>
</div>
</form>

<div class="source-tabs">
    <a href="?source=all&filter=<?php echo urlencode($filter); ?>&search=<?php echo urlencode($search); ?>" class="source-tab <?php echo $source==='all'?'active':''; ?>">全部来源</a>
    <?php foreach ($sourceLabels as $key => $label): ?>
        <a href="?source=<?php echo $key; ?>&filter=<?php echo urlencode($filter); ?>&search=<?php echo urlencode($search); ?>" class="source-tab <?php echo $source===$key?'active':''; ?>"><?php echo htmlspecialchars($label); ?></a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">
        <h2>IP 段列表</h2>
        <span class="badge">显示 <?php echo count($displayData); ?> / <?php echo $totalAll; ?> 条</span>
    </div>
    <?php if (empty($allData)): ?>
        <div class="empty-state"><div class="icon">📡</div><p>暂无数据</p><p style="font-size:.85rem;margin-top:4px">请在服务器执行 update_google_spiders.php 获取数据</p></div>
    <?php elseif (empty($displayData)): ?>
        <div class="empty-state"><div class="icon">🔍</div><p>未找到匹配的 IP 段</p></div>
    <?php else: ?>
        <div style="max-height:520px;overflow-y:auto">
        <table class="ip-table">
        <thead><tr><th>IP 段</th><th>类型</th><th>数据源</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($displayData as $d): ?>
        <tr>
            <td class="ip-addr"><?php echo htmlspecialchars($d['prefix']); ?></td>
            <td><span class="ip-type <?php echo ($d['type']??'')==='IPv6'?'badge-v6':'badge-v4'; ?>"><?php echo htmlspecialchars($d['type']??''); ?></span></td>
            <td style="font-size:.8rem;color:var(--text-muted)"><?php echo htmlspecialchars($sourceLabels[$d['source']]??$d['source']??'-'); ?></td>
            <td><button class="copy-btn" onclick="copySingleIP('<?php echo htmlspecialchars($d['prefix'],ENT_QUOTES); ?>')">复制</button></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<footer class="footer">
<span>数据来源：developers.google.cn/crawling/ipranges · 服务端缓存 · 每7天自动更新</span>
<p class="footer-copy">© 2026 <a href="https://your-domain.com/" target="_blank" rel="noopener">your-name</a></p>
</footer>
</div>

<div class="toast" id="toast"></div>
<script src="assets/js/google.js?v=1.0.0"></script>
</body>
</html>

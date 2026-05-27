<?php
/**
 * 蜘蛛IP自动识别查询系统 - 主页面
 * 提供可视化查询界面（支持百度/Google/Bing 等）
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/SpiderChecker.php';
require_once __DIR__ . '/GeoIPLookup.php';

// =============================================
// 去除 URL 中的 /index.php（纯 PHP 实现，无需 Nginx 配置）
// 直接访问 /index.php 时 301 永久重定向到 /
// =============================================
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($requestPath === '/index.php' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    $target = '/' . ($queryString !== '' ? '?' . $queryString : '');
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $target);
    exit;
}

// =============================================
// 自定义后台路径路由（PHP 层面兜底）
// 当 nginx/Apache 将未知路径 fallback 到 index.php 时，
// 自动拦截自定义 admin_path 并路由到物理 admin/ 目录
// =============================================
if (defined('ADMIN_PATH') && ADMIN_PATH !== 'admin') {
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $adminPattern = '#^/' . preg_quote(ADMIN_PATH, '#') . '($|/)#';
    
    if (preg_match($adminPattern, $requestUri)) {
        $subPath = substr($requestUri, strlen('/' . ADMIN_PATH));
        if ($subPath === '' || $subPath === false) $subPath = '/index.php';
        
        // 安全检查
        if (strpos($subPath, '..') !== false || strpos($subPath, "\\") !== false) {
            http_response_code(403);
            exit('Forbidden');
        }
        
        $targetFile = __DIR__ . '/admin' . $subPath;
        
        // 如果是 PHP 文件，直接 include
        if (file_exists($targetFile) && pathinfo($targetFile, PATHINFO_EXTENSION) === 'php') {
            // 修正服务器变量，让被包含文件能正常工作
            $_SERVER['SCRIPT_FILENAME'] = $targetFile;
            $_SERVER['SCRIPT_NAME'] = '/admin' . $subPath;
            $_SERVER['PHP_SELF'] = '/admin' . $subPath;
            require $targetFile;
            exit;
        }
        
        // 静态资源
        if (file_exists($targetFile)) {
            $ext = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
            $mimeTypes = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png',
                'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'ico' => 'image/x-icon'];
            if (isset($mimeTypes[$ext])) header('Content-Type: ' . $mimeTypes[$ext]);
            readfile($targetFile);
            exit;
        }
    }
}
// =============================================

// 确保数据库已初始化
if (!file_exists(SQLITE_DB_PATH)) {
    require_once __DIR__ . '/init_db.php';
}

// 自动更新调度（无需宝塔计划任务）
@include_once __DIR__ . '/auto_update.php';

// 启动会话（用于 PRG 模式的闪存消息）
session_start();

// 获取客户端信息
$clientIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// ============================================
// 处理 POST 请求 → 执行操作 → 重定向到 GET（PRG 模式）
// ============================================
// 构建干净的当前页面路径（配合 Nginx try_files，去除 /index.php 后缀）
$selfPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
if ($selfPath === '/index.php') $selfPath = '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 保护
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        $_SESSION['flash_msg'] = '安全验证失败，请刷新页面后重试';
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . $selfPath);
        exit;
    }
    
    // 处理清除日志
    if (isset($_POST['clear_logs'])) {
        try {
            $clearChecker = new SpiderChecker();
            $deleted = $clearChecker->clearLogs();
            $_SESSION['flash_msg'] = "已清除 {$deleted} 条查询记录";
            $_SESSION['flash_type'] = 'success';
        } catch (Exception $e) {
            error_log('[index] clear_logs error: ' . $e->getMessage());
            $_SESSION['flash_msg'] = '清除失败，请稍后重试';
            $_SESSION['flash_type'] = 'error';
        }
        header('Location: ' . $selfPath);
        exit;
    }
    
    // 处理 IP 查询
    if (isset($_POST['query_ip'])) {
        // 速率限制检查
        $maxPerMinute = defined('ADMIN_SETTING_MAX_QUERY_PER_MINUTE') ? (int)ADMIN_SETTING_MAX_QUERY_PER_MINUTE : 30;
        $rateLimitError = checkRateLimit($maxPerMinute);
        if ($rateLimitError) {
            $_SESSION['flash_msg'] = $rateLimitError;
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . $selfPath);
            exit;
        }
        
        $queryIP = trim($_POST['query_ip'] ?? '');
        $queryUA = trim($_POST['query_ua'] ?? '');
        
        if (empty($queryIP)) {
            $_SESSION['flash_msg'] = '请输入要查询的 IP 地址';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . $selfPath);
            exit;
        }
        
        if (!filter_var($queryIP, FILTER_VALIDATE_IP)) {
            $_SESSION['flash_msg'] = '请输入有效的 IP 地址';
            $_SESSION['flash_type'] = 'error';
            header('Location: ' . $selfPath);
            exit;
        }
        
        try {
            $checker = new SpiderChecker();
            $geo = new GeoIPLookup();
            
            $spiderResult = $checker->check($queryIP, $queryUA);
            $geoResult = $geo->lookup($queryIP);
            
            $checker->updateLastLogGeo($queryIP, $geoResult);
            
            $_SESSION['query_result'] = [
                'spider'   => $spiderResult,
                'location' => $geoResult,
            ];
            $_SESSION['query_ip'] = $queryIP;
            $_SESSION['query_ua'] = $queryUA;
        } catch (Exception $e) {
            error_log('[index] query error: ' . $e->getMessage());
            $_SESSION['flash_msg'] = '查询失败，请稍后重试';
            $_SESSION['flash_type'] = 'error';
        }
        header('Location: ' . $selfPath);
        exit;
    }
}

// ============================================
// GET 请求：读取闪存消息和查询结果
// ============================================
$clearMsg = '';
$flashType = 'success';
if (isset($_SESSION['flash_msg'])) {
    $clearMsg = $_SESSION['flash_msg'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

$queryResult = null;
$queryIP = '';
$queryUA = '';
$error = '';
if (isset($_SESSION['query_result'])) {
    $queryResult = $_SESSION['query_result'];
    $queryIP = $_SESSION['query_ip'] ?? '';
    $queryUA = $_SESSION['query_ua'] ?? '';
    unset($_SESSION['query_result'], $_SESSION['query_ip'], $_SESSION['query_ua']);
}
// 如果是错误消息且没有查询结果，显示为表单错误
if ($flashType === 'error' && $clearMsg) {
    $error = $clearMsg;
    $clearMsg = '';
}

// 获取统计信息
try {
    $checker = new SpiderChecker();
    $stats = $checker->getStats();
} catch (Exception $e) {
    $stats = [
        'total_ranges'   => 0,
        'total_queries'  => 0,
        'spider_queries' => 0,
        'last_update'    => '未初始化',
        'unique_ips'     => 0,
    ];
}

// 获取最近查询
$recentQueries = [];
try {
    $recentQueries = $checker->getRecentQueries(20);
} catch (Exception $e) {
    // 静默处理
}

// 检测系统状态
$geoipAvailable = file_exists(GEOIP_DB_PATH);
$dbExists = file_exists(SQLITE_DB_PATH);
$composerAvailable = file_exists(__DIR__ . '/vendor/autoload.php');

// SEO：构建当前页面完整 URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = getSafeHost();
$canonicalUrl = $scheme . '://' . $host . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>蜘蛛IP识别查询 - 在线检测 Baidu Spider / Googlebot / Bingbot 爬虫</title>
    <meta name="description" content="免费在线蜘蛛爬虫识别查询工具，支持百度蜘蛛、Googlebot、Bingbot 等爬虫IP检测，基于 GeoIP2 + DB-IP 双库精准定位，提供 IP段 + User-Agent + 反向DNS 三重验证。">
    <meta name="keywords" content="蜘蛛IP识别,百度蜘蛛,Baiduspider,Googlebot,Bingbot,爬虫检测,蜘蛛查询,User-Agent验证,反向DNS,IP地理位置">
    <meta name="robots" content="index, follow">
    <meta name="author" content="your-name">
    <link rel="canonical" href="<?php echo $canonicalUrl; ?>">

    <!-- Open Graph / 社交媒体 -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="蜘蛛IP识别查询 - 在线检测 Baidu Spider / Googlebot / Bingbot 爬虫">
    <meta property="og:description" content="免费在线蜘蛛爬虫识别查询工具，支持 IP段 + User-Agent + 反向DNS 三重验证，精准识别百度蜘蛛、谷歌爬虫、必应爬虫等。">
    <meta property="og:url" content="<?php echo $canonicalUrl; ?>">
    <meta property="og:site_name" content="蜘蛛IP识别查询系统">
    <meta property="og:locale" content="zh_CN">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="蜘蛛IP识别查询 - Spider / Crawler Checker">
    <meta name="twitter:description" content="免费在线蜘蛛爬虫识别工具，支持百度/Google/Bing 等，三重验证精准检测。">

    <!-- 百度适配 -->
    <meta name="baidu-site-verification" content="code-1b87012e16de8a666f46b4a110691a3b">
    <meta name="mobile-agent" content="format=html5;url=<?php echo $canonicalUrl; ?>">
    <meta name="applicable-device" content="pc,mobile">

    <!-- 资源预加载 -->
    <link rel="preload" href="assets/css/common.css?v=1.0.0" as="style">
    <link rel="preload" href="assets/css/style.css?v=1.0.0" as="style">

    <link rel="stylesheet" href="assets/css/common.css?v=1.0.0">
    <link rel="stylesheet" href="assets/css/style.css?v=1.0.0">

    <!-- 结构化数据 JSON-LD (Schema.org) -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebApplication",
        "name": "蜘蛛IP识别查询系统",
        "url": "<?php echo $canonicalUrl; ?>",
        "description": "在线检测 IP 是否为蜘蛛爬虫（百度/Google/Bing 等），提供 IP段 + User-Agent + 反向DNS 三重验证",
        "applicationCategory": "DeveloperApplication",
        "operatingSystem": "All",
        "author": {
            "@type": "Organization",
            "name": "your-name",
            "url": "https://your-domain.com/"
        },
        "inLanguage": "zh-CN",
        "offers": {
            "@type": "Offer",
            "price": "0",
            "priceCurrency": "CNY"
        }
    }
    </script>
</head>
<body>
    <div class="container">
        <!-- 头部 -->
        <header class="header">
            <div class="header-inner">
                <div class="logo">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 9.5a1 1.5 0 1 0 2 0a1 1.5 0 1 0 -2 0"/>
                        <path d="M14.463 11.596c1.282 1.774 3.476 3.416 3.476 3.416s1.921 1.574 .593 3.636c-1.328 2.063 -4.892 1.152 -4.892 1.152s-1.416 -.44 -3.06 -.088c-1.644 .356 -3.06 .22 -3.06 .22s-2.055 -.22 -2.47 -2.304c-.416 -2.084 1.918 -3.638 2.102 -3.858c.182 -.222 1.409 -.966 2.284 -2.394c.875 -1.428 3.337 -2.287 5.027 .221"/>
                        <path d="M8 4.5a1 1.5 0 1 0 2 0a1 1.5 0 1 0 -2 0"/>
                        <path d="M14 4.5a1 1.5 0 1 0 2 0a1 1.5 0 1 0 -2 0"/>
                        <path d="M18 9.5a1 1.5 0 1 0 2 0a1 1.5 0 1 0 -2 0"/>
                    </svg>
                    <h1>蜘蛛IP识别查询</h1>
                </div>
                <p class="subtitle">Baidu Spider · Googlebot · Bingbot 自动检测与 IP 地理位置查询</p>
            </div>
        </header>

        <!-- 系统状态栏 -->
        <div class="status-bar">
            <div class="status-item">
                <span class="status-dot <?php echo $dbExists ? 'online' : 'offline'; ?>"></span>
                数据库: <?php echo $dbExists ? '正常' : '未初始化'; ?>
            </div>
            <div class="status-item">
                <span class="status-dot <?php echo $geoipAvailable ? 'online' : 'offline'; ?>"></span>
                GeoIP2: <?php echo $geoipAvailable ? '已安装' : '未安装'; ?>
            </div>
            <div class="status-item">
                <span class="status-dot <?php echo $composerAvailable ? 'online' : 'offline'; ?>"></span>
                Composer: <?php echo $composerAvailable ? '已配置' : '未安装'; ?>
            </div>
            <div class="status-item">
                最后更新: <?php echo htmlspecialchars($stats['last_update'] ?? 'N/A'); ?>
            </div>
        </div>

        <!-- 查询表单 -->
        <div class="card query-card">
            <h2>🔍 IP 查询</h2>
            <form method="POST" action="" class="query-form">
                <?php echo csrfField(); ?>
                <div class="form-row">
                    <div class="form-group flex-2">
                        <label for="query_ip">IP 地址 *</label>
                        <input 
                            type="text" 
                            id="query_ip" 
                            name="query_ip" 
                            value="<?php echo htmlspecialchars($queryIP); ?>"
                            placeholder="例如: 116.179.32.1"
                            required
                        >
                    </div>
                    <div class="form-group flex-3">
                        <label for="query_ua">User-Agent（可选）</label>
                        <input 
                            type="text" 
                            id="query_ua" 
                            name="query_ua" 
                            value="<?php echo htmlspecialchars($queryUA); ?>"
                            placeholder="例如: Mozilla/5.0 (compatible; Baiduspider/2.0)"
                        >
                    </div>
                    <div class="form-group flex-1 form-submit">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">🔍 查询</button>
                    </div>
                </div>
            </form>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($queryResult): ?>
            <div class="query-results">
                <!-- 蜘蛛检测结果 -->
                <div class="result-section">
                    <h3>
                        <?php 
                        $spiderType = $queryResult['spider']['spider_type'] ?? '';
                        $isGoogle = (stripos($spiderType, 'Google') !== false);
                        $isBaidu = (stripos($spiderType, 'Baidu') !== false);
                        if ($queryResult['spider']['is_spider']): 
                            if ($isGoogle): ?>
                                🕷️ Google 蜘蛛检测 — <span class="text-success">确认为 Google 蜘蛛</span>
                            <?php elseif ($isBaidu): ?>
                                🕷️ 百度蜘蛛检测 — <span class="text-success">确认为百度蜘蛛</span>
                            <?php elseif ($spiderType): ?>
                                🕷️ <?php echo htmlspecialchars($spiderType); ?> 检测 — <span class="text-success">确认为 <?php echo htmlspecialchars($spiderType); ?></span>
                            <?php else: ?>
                                🕷️ 蜘蛛检测 — <span class="text-success">确认为蜘蛛</span>
                            <?php endif;
                        else: ?>
                            👤 蜘蛛检测 — <span class="text-muted">非蜘蛛</span>
                        <?php endif; ?>
                    </h3>
                    <table class="result-table">
                        <tr>
                            <td class="label">IP 地址</td>
                            <td><code><?php echo htmlspecialchars($queryResult['spider']['ip']); ?></code></td>
                        </tr>
                        <tr>
                            <td class="label">是否蜘蛛</td>
                            <td>
                                <?php if ($queryResult['spider']['is_spider']): ?>
                                    <span class="badge badge-spider">是</span>
                                <?php else: ?>
                                    <span class="badge badge-no">否</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($queryResult['spider']['spider_type']): ?>
                        <tr>
                            <td class="label">蜘蛛类型</td>
                            <td><strong><?php echo htmlspecialchars($queryResult['spider']['spider_type']); ?></strong></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($queryResult['spider']['match_method']): ?>
                        <tr>
                            <td class="label">匹配方式</td>
                            <td><?php echo htmlspecialchars($queryResult['spider']['match_method']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($queryResult['spider']['matched_range']): ?>
                        <tr>
                            <td class="label">匹配IP段</td>
                            <td><code><?php echo htmlspecialchars($queryResult['spider']['matched_range']); ?></code></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($queryResult['spider']['rdns_hostname'])): ?>
                        <tr>
                            <td class="label">反向DNS</td>
                            <td>
                                <?php if ($queryResult['spider']['rdns_hostname']): ?>
                                    <code><?php echo htmlspecialchars($queryResult['spider']['rdns_hostname']); ?></code>
                                <?php else: ?>
                                    <span class="text-muted">无PTR记录</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($queryResult['spider']['rdns_verified'])): ?>
                        <tr>
                            <td class="label">DNS验证</td>
                            <td>
                                <?php if ($queryResult['spider']['rdns_verified']): ?>
                                    <?php if (!empty($queryResult['spider']['forward_confirmed'])): ?>
                                        <span class="badge badge-success">✅✅ 双向确认（PTR + A 记录一致）</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">✅ 通过（rDNS 匹配）</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-no">❌ 未通过</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($queryResult['spider']['confidence'] ?? ''): ?>
                        <tr>
                            <td class="label">可信度</td>
                            <td>
                                <?php $conf = $queryResult['spider']['confidence']; ?>
                                <span class="badge badge-conf badge-conf-<?php echo htmlspecialchars($conf); ?>">
                                    <?php
                                        $labels = ['verified' => '已验证', 'high' => '高', 'medium' => '中', 'low' => '低'];
                                        echo $labels[$conf] ?? $conf;
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($queryResult['spider']['warning'] ?? ''): ?>
                        <tr>
                            <td class="label">⚠️ 警告</td>
                            <td class="text-warning"><?php echo htmlspecialchars($queryResult['spider']['warning']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    <p class="disclaimer-note">ℹ️ 信息仅供参考，如需用于生产环境防护，建议结合多种验证手段综合判断</p>
                </div>

                <!-- 地理位置结果 -->
                <div class="result-section">
                    <h3>📍 IP 地理位置</h3>
                    <table class="result-table">
                        <tr>
                            <td class="label">国家</td>
                            <td>
                                <strong><?php echo htmlspecialchars($queryResult['location']['country']); ?></strong>
                                <span class="text-muted">(<?php echo htmlspecialchars($queryResult['location']['country_code']); ?>)</span>
                            </td>
                        </tr>
                        <tr>
                            <td class="label">城市</td>
                            <td><?php echo htmlspecialchars($queryResult['location']['city']); ?></td>
                        </tr>
                        <?php if ($queryResult['location']['subdivision'] ?? ''): ?>
                        <tr>
                            <td class="label">省份/州</td>
                            <td><?php echo htmlspecialchars($queryResult['location']['subdivision']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($queryResult['location']['latitude']): ?>
                        <tr>
                            <td class="label">经纬度</td>
                            <td>
                                <?php echo htmlspecialchars($queryResult['location']['latitude']); ?>, 
                                <?php echo htmlspecialchars($queryResult['location']['longitude']); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($queryResult['location']['timezone'] ?? ''): ?>
                        <tr>
                            <td class="label">时区</td>
                            <td><?php echo htmlspecialchars($queryResult['location']['timezone']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 统计面板 -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_ranges']); ?></div>
                <div class="stat-label">蜘蛛 IP 数量</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_queries']); ?></div>
                <div class="stat-label">总查询次数</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['spider_queries']); ?></div>
                <div class="stat-label">蜘蛛命中</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['unique_ips']); ?></div>
                <div class="stat-label">独立 IP</div>
            </div>
        </div>

        <div class="nav-links">
            <a href="confirmed.php" class="btn btn-primary">已确认蜘蛛 IP 列表</a>
            <a href="google.php" class="btn btn-google">Google 蜘蛛 IP 段</a>
            <a href="https://www.bing.com/toolbox/verify-bingbot" class="btn btn-bing" target="_blank" rel="noopener noreferrer">Bingbot 验证工具</a>
        </div>

        <!-- 最近查询记录 -->
        <?php if (!empty($recentQueries)): ?>
        <div class="card">
            <div class="card-header-row">
                <h2>📋 最近查询记录</h2>
                <form method="POST" action="" class="clear-form" onsubmit="return confirm('确定要清除所有查询记录吗？此操作不可撤销。');">
                    <?php echo csrfField(); ?>
                    <button type="submit" name="clear_logs" value="1" class="btn btn-danger btn-sm">🗑️ 清除记录</button>
                </form>
            </div>
            <?php if ($clearMsg): ?>
                <div class="alert <?php echo $flashType === 'error' ? 'alert-error' : 'alert-success'; ?>"><?php echo htmlspecialchars($clearMsg); ?></div>
            <?php endif; ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>IP 地址</th>
                            <th>蜘蛛</th>
                            <th>类型</th>
                            <th>国家</th>
                            <th>城市</th>
                            <th>时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentQueries as $row): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($row['ip_address']); ?></code></td>
                            <td>
                                <?php if ($row['is_spider']): ?>
                                    <span class="badge badge-spider">是</span>
                                <?php else: ?>
                                    <span class="badge badge-no">否</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['spider_type'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['country'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['city'] ?? '-'); ?></td>
                            <td class="text-muted"><?php echo htmlspecialchars($row['query_time']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- 页脚 -->
        <footer class="footer">
            <p>蜘蛛IP自动识别查询系统 | MaxMind GeoLite2 + DB-IP Lite | SQLite</p>
            <p class="text-muted">IP段 + User-Agent + 反向DNS 三重验证</p>
            <p class="footer-copy">© 2026 <a href="https://your-domain.com/" target="_blank" rel="noopener">your-name</a>. Email: your-name#your-domain.com(#替换为@)</p>
        </footer>
    </div>

    <script src="assets/js/app.js?v=1.0.0"></script>
</body>
</html>

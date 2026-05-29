<?php
/**
 * 已确认的蜘蛛 IP 列表
 * 集中展示所有被识别为蜘蛛的 IP（百度/Google/Bing 等）
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/SpiderChecker.php';

// 确保数据库已初始化
if (!file_exists(SQLITE_DB_PATH)) {
    require_once __DIR__ . '/init_db.php';
}

// 自动更新调度
if (file_exists(__DIR__ . '/auto_update.php')) {
    include_once __DIR__ . '/auto_update.php';
}

$checker = new SpiderChecker();
try {
    $spiders = $checker->getConfirmedSpiders();
} catch (Exception $e) {
    error_log('[confirmed] getConfirmedSpiders error: ' . $e->getMessage());
    $spiders = [];
}
$total = count($spiders);

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
    <title>已确认的蜘蛛 IP 列表 - Spider IP 验证记录</title>
    <meta name="description" content="实时展示所有被系统三重验证（IP段+User-Agent+反向DNS）确认为蜘蛛的 IP 地址列表，支持百度、Google、Bing 等，包含验证方式、可信度、地理位置等详细信息。">
    <meta name="keywords" content="蜘蛛IP,百度蜘蛛,Googlebot,Bingbot,爬虫列表,蜘蛛确认记录,爬虫验证">
    <meta name="robots" content="index, follow">
    <meta name="author" content="your-name">
    <link rel="canonical" href="<?php echo $canonicalUrl; ?>">

    <meta property="og:type" content="website">
    <meta property="og:title" content="已确认的蜘蛛 IP 列表 - Spider IP">
    <meta property="og:description" content="三重验证确认为蜘蛛的 IP 地址完整列表。">
    <meta property="og:url" content="<?php echo $canonicalUrl; ?>">
    <meta name="twitter:card" content="summary">
    <meta name="applicable-device" content="pc,mobile">

    <link rel="stylesheet" href="assets/css/common.css?v=1.0.0">
    <link rel="stylesheet" href="assets/css/confirmed.css?v=1.0.0">

    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebApplication",
        "name": "已确认的蜘蛛 IP 列表",
        "url": "<?php echo $canonicalUrl; ?>",
        "description": "实时展示经三重验证确认为蜘蛛的 IP 地址记录",
        "applicationCategory": "DeveloperApplication",
        "author": { "@type": "Organization", "name": "your-name", "url": "https://your-domain.com/" },
        "inLanguage": "zh-CN"
    }
    </script>
</head>
<body>
    <div class="container">
        <a href="/" class="back-link">← 返回查询页面</a>
        
        <div class="header">
            <h1>🕷️ 已确认的蜘蛛 IP 列表</h1>
            <p>所有被系统识别为蜘蛛的 IP 地址展示（百度 / Google / Bing 等）</p>
        </div>
        
        <div class="count-bar">
            共确认 <strong><?php echo $total; ?></strong> 个蜘蛛 IP
        </div>
        
        <?php if (empty($spiders)): ?>
            <div class="ip-list">
                <div class="empty-state">
                    <div class="icon">🕸️</div>
                    <p>暂无已确认的蜘蛛 IP</p>
                    <p style="font-size:0.85rem;margin-top:4px">在查询页面提交蜘蛛 IP 后将自动记录到这里</p>
                </div>
            </div>
        <?php else: ?>
            <div class="ip-list">
                <div class="ip-header">
                    <span class="col-ip">IP 地址</span>
                    <span class="col-type">蜘蛛类型</span>
                    <span class="col-conf">可信度</span>
                    <span class="col-method">验证方式</span>
                    <span class="col-rdns">反向DNS</span>
                    <span class="col-meta">最近检测</span>
                </div>
                <?php foreach ($spiders as $spider): ?>
                <div class="ip-row">
                    <span class="col-ip ip-addr"><?php echo htmlspecialchars($spider['ip_address']); ?></span>
                    
                    <span class="col-type">
                        <?php if ($spider['spider_type']): ?>
                            <span class="ip-badge badge-spider"><?php echo htmlspecialchars($spider['spider_type']); ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </span>
                    
                    <span class="col-conf">
                        <?php if ($spider['confidence']): ?>
                            <span class="ip-badge badge-conf badge-conf-<?php echo htmlspecialchars($spider['confidence']); ?>">
                                <?php
                                    $confLabels = ['verified' => '已验证', 'high' => '高', 'medium' => '中', 'low' => '低'];
                                    echo $confLabels[$spider['confidence']] ?? $spider['confidence'];
                                ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </span>
                    
                    <span class="col-method">
                        <?php if ($spider['match_method']): ?>
                            <span class="ip-badge badge-method"><?php echo htmlspecialchars($spider['match_method']); ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </span>
                    
                    <span class="col-rdns">
                        <?php if ($spider['rdns_hostname']): ?>
                            <span class="rdns" title="<?php echo htmlspecialchars($spider['rdns_hostname']); ?>">
                                <?php echo htmlspecialchars($spider['rdns_hostname']); ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </span>
                    
                    <span class="col-meta ip-meta">
                        <?php echo htmlspecialchars($spider['last_seen'] ?? $spider['first_seen']); ?>
                        <?php if ($spider['seen_count'] > 1): ?>
                            <br><small class="text-muted">首次 <?php echo htmlspecialchars($spider['first_seen']); ?> · <?php echo (int)$spider['seen_count']; ?>次</small>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <footer class="footer">
            <p class="footer-copy">© 2026 <a href="https://your-domain.com/" target="_blank" rel="noopener">your-name</a></p>
        </footer>
    </div>
</body>
</html>

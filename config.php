<?php
/**
 * 百度蜘蛛识别查询系统 - 配置文件
 */

// ============================================
// MaxMind GeoIP2 配置
// License Key 请在后台「系统设置」中配置（支持在线更换，无需修改源码）
// 申请地址：https://www.maxmind.com/en/geolite2/signup（免费注册获取）
// Account ID 请通过 .env 文件或环境变量 MAXMIND_ACCOUNT_ID 配置
// ============================================
define('GEOIP_DB_PATH', __DIR__ . '/geoip/GeoLite2-City.mmdb');
// DB-IP Lite 免费城市数据库（城市覆盖率更高，作为 GeoLite2 的补充）
define('DBIP_DB_PATH', __DIR__ . '/geoip/dbip-city-lite.mmdb');
// Account ID 从环境变量读取（兼容 getenv 被禁用的主机环境）
$maxmindAccountId = '';
if (function_exists('getenv')) {
    $maxmindAccountId = getenv('MAXMIND_ACCOUNT_ID') ?: '';
}
define('MAXMIND_ACCOUNT_ID', $maxmindAccountId);

// ============================================
// SQLite 数据库配置
// ============================================
define('SQLITE_DB_PATH', __DIR__ . '/db/spiders.sqlite');

// ============================================
// 百度蜘蛛 User-Agent 关键词
// ============================================
define('BAIDU_SPIDER_UA_KEYWORDS', [
    'Baiduspider',
    'Baiduspider-mobile',
    'Baiduspider-image',
    'Baiduspider-video',
    'Baiduspider-news',
    'Baiduspider-favo',
    'Baiduspider-cpro',
    'Baiduspider-ads',
    'Baiduspider-render',
]);

// ============================================
// 反向DNS验证 — 百度蜘蛛合法域名后缀
// 真正百度蜘蛛的IP做反向DNS会解析到这些域名
// ============================================
define('BAIDU_SPIDER_RDNS_DOMAINS', [
    '.baidu.com',
    '.baidu.jp',
    '.baidu.com.cn',
]);

// ============================================
// Google 蜘蛛 User-Agent 关键词
// ============================================
define('GOOGLE_SPIDER_UA_KEYWORDS', [
    'Googlebot',
    'Googlebot-Image',
    'Googlebot-Video',
    'Googlebot-News',
    'Googlebot-Mobile',
    'Mediapartners-Google',
    'AdsBot-Google',
    'APIs-Google',
]);

// ============================================
// 反向DNS验证 — Google 蜘蛛合法域名后缀
// ============================================
define('GOOGLE_SPIDER_RDNS_DOMAINS', [
    '.googlebot.com',
    '.google.com',
]);

// ============================================
// 百度蜘蛛已知 IP 段（优先从后台设置读取，兜底用默认值）
// ============================================
/**
 * 获取百度蜘蛛默认 IP 段列表
 * 优先级：后台「🕸 百度蜘蛛默认 IP 段」设置 > config.php 默认数组
 */
function getBaiduDefaultRanges(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    
    // 1. 优先从 admin_settings 读取
    if (file_exists(SQLITE_DB_PATH)) {
        $db = null;
        try {
            $db = new SQLite3(SQLITE_DB_PATH);
            $db->enableExceptions(false);
            $tableCheck = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='admin_settings'");
            if ($tableCheck > 0) {
                $row = $db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'baidu_default_ranges'", true);
                if ($row && !empty($row['setting_value'])) {
                    $lines = preg_split('/[\r\n]+/', trim($row['setting_value']));
                    $lines = array_map('trim', $lines);
                    $lines = array_filter($lines, fn($l) => $l !== '');
                    if (!empty($lines)) {
                        $cached = array_values($lines);
                        return $cached;
                    }
                }
            }
        } catch (\Exception $e) {
            // 数据库读取失败，使用默认值
        } finally {
            if ($db) {
                $db->close();
            }
        }
    }
    
    // 2. 兜底：config.php 默认数组
    $cached = [
        '116.179.32.0/24',
        '116.179.33.0/24',
        '116.179.34.0/24',
        '116.179.35.0/24',
        '116.179.36.0/24',
        '116.179.37.0/24',
        '116.179.38.0/24',
        '116.179.39.0/24',
        '116.179.40.0/24',
        '116.179.41.0/24',
        '116.179.42.0/24',
        '116.179.43.0/24',
        '116.179.44.0/24',
        '116.179.45.0/24',
        '116.179.46.0/24',
        '116.179.47.0/24',
        '180.76.0.0/16',
        '185.10.104.0/24',
        '185.10.105.0/24',
        '220.181.0.0/18',
        '123.125.0.0/16',
        '111.206.0.0/16',
        '106.38.0.0/16',
        '103.235.46.0/24',
        '103.235.47.0/24',
        '61.135.0.0/16',
        '61.49.0.0/18',
        '202.108.0.0/16',
        '211.94.0.0/16',
        '61.48.0.0/16',
        '112.34.0.0/16',
    ];
    return $cached;
}

// 兼容旧代码的常量（保持 define 不变，改为调用函数）
define('BAIDU_SPIDER_KNOWN_RANGES', getBaiduDefaultRanges());

// ============================================
// 会话安全配置（必须在 session_start() 之前）
// ============================================
ini_set('session.cookie_httponly', 1);
// 安全检测 HTTPS：支持直接访问 + 反向代理（Cloudflare/Nginx）
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false);
ini_set('session.cookie_secure', $isHttps ? 1 : 0);
ini_set('session.cookie_samesite', 'Lax');
// 注意：use_strict_mode 在部分共享主机/代理环境下可能导致 session 丢失，
// 如需启用请在 php.ini 中配置并充分测试
// ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);

// ============================================
// 时区设置（全站统一使用中国北京时间 UTC+8）
// ============================================
// PHP 层：date() / time() / strtotime() 均基于此时区
// SQLite：所有 INSERT 使用 datetime('now','localtime')，不使用 CURRENT_TIMESTAMP（UTC）
// JS 前端：toLocaleString 指定 timeZone: 'Asia/Shanghai'
date_default_timezone_set('Asia/Shanghai');

// ============================================
// 加载 Composer 自动加载（可选，纯 PHP 解析器已内置兜底）
// ============================================
$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// ============================================
// 加载共享工具函数
// ============================================
require_once __DIR__ . '/helpers.php';

// ============================================
// 后台管理系统配置
// ============================================
define('ADMIN_DEFAULT_USERNAME', 'admin');
// 密码哈希由 init_db.php / AdminAuth.php 动态生成
// 首次登录后必须在后台修改密码，该常量保留仅供兼容性参考
define('ADMIN_DEFAULT_PASSWORD_HASH', '');
define('ADMIN_SESSION_TIMEOUT', 7200); // 2小时
define('ADMIN_MAX_LOGIN_ATTEMPTS', 5);
define('ADMIN_LOCKOUT_DURATION', 900); // 15分钟
define('ADMIN_DEFAULT_PATH', 'admin'); // 物理目录名，不可修改

// ============================================
// 动态设置加载（从数据库读取，覆盖默认值）
// 注意：此功能依赖数据库已初始化，首次运行前数据库可能不存在
// ============================================
if (file_exists(SQLITE_DB_PATH)) {
    $db = null;
    try {
        $db = new SQLite3(SQLITE_DB_PATH);
        $db->enableExceptions(true);
        
        // 检查 admin_settings 表是否存在
        $tableCheck = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='admin_settings'");
        if ($tableCheck > 0) {
            $result = $db->query("SELECT setting_key, setting_value, setting_type FROM admin_settings");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $key = 'ADMIN_SETTING_' . strtoupper($row['setting_key']);
                if (!defined($key)) {
                    switch ($row['setting_type']) {
                        case 'bool':  $value = (bool)(int)$row['setting_value']; break;
                        case 'int':   $value = (int)$row['setting_value']; break;
                        case 'float': $value = (float)$row['setting_value']; break;
                        default:      $value = $row['setting_value']; break;
                    }
                    define($key, $value);
                }
            }
            
            // 单独处理 admin_path — 用于路由判断
            if (!defined('ADMIN_PATH')) {
                $adminPath = $db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'admin_path'");
                define('ADMIN_PATH', $adminPath ?: 'admin');
            }
        }
    } catch (Exception $e) {
        // 静默处理 — 数据库可能尚未初始化
    } finally {
        if ($db) {
            $db->close();
        }
    }
}

// admin_path 兜底（数据库未初始化时使用默认值）
if (!defined('ADMIN_PATH')) {
    define('ADMIN_PATH', 'admin');
}

// ============================================
// 后台路径保护：自定义路径下禁止直接访问 /admin/
// Apache: REQUEST_URI 保留原始 URL，可准确判断
// Nginx: 由 nginx location 规则拦截（见 nginx-admin-route.conf）
// ============================================
if (ADMIN_PATH !== 'admin') {
    $isApache = (stripos($_SERVER['SERVER_SOFTWARE'] ?? '', 'apache') !== false)
             || (stripos($_SERVER['SERVER_SOFTWARE'] ?? '', 'litespeed') !== false);
    
    if ($isApache) {
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        // 直接访问 /admin/ → 404；通过自定义路径 rewrite 的请求不受影响
        // （rewrite 时 Apache 设置 E=ADMIN_ROUTED:1，在 PHP 中为 REDIRECT_ADMIN_ROUTED）
        $isRouted = !empty($_SERVER['REDIRECT_ADMIN_ROUTED']);
        if (!$isRouted && preg_match('#^/admin($|/)#', $requestUri)) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>404 Not Found</title></head>'
               . '<body style="text-align:center;padding:80px 20px;font-family:system-ui,sans-serif">'
               . '<h1 style="font-size:3rem;margin:0;color:#666">404</h1>'
               . '<p style="color:#999">页面未找到</p></body></html>';
            exit;
        }
    }
}

// ============================================
// 安全响应头（防止 XSS / MIME 嗅探 / 点击劫持）
// ============================================
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ============================================
// 错误报告（生产环境请关闭）
// ============================================
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 0);
// 生产环境建议在 php.ini 中额外配置：
//   open_basedir = /path/to/project:/tmp
//   expose_php = Off

// ============================================
// MaxMind License Key 动态获取（优先级：后台设置 > 环境变量 > .env 文件）
// ============================================
function getMaxmindLicenseKey(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;
    
    // 1. 优先从数据库 admin_settings 读取（后台可在线更换）
    if (file_exists(SQLITE_DB_PATH)) {
        try {
            // 避免循环依赖：直接用 SQLite3 读取，不依赖 AdminSettings 类
            $db = new SQLite3(SQLITE_DB_PATH);
            $db->enableExceptions(false);
            $tableCheck = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='admin_settings'");
            if ($tableCheck > 0) {
                $stmt = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = :key LIMIT 1");
                $stmt->bindValue(':key', 'maxmind_license_key', SQLITE3_TEXT);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                if ($row && !empty($row['setting_value'])) {
                    $cached = $row['setting_value'];
                    $db->close();
                    return $cached;
                }
            }
            $db->close();
        } catch (\Exception $e) {
            // DB 不可用时回退
        }
    }
    
    // 2. 从环境变量读取
    $envKey = getenv('MAXMIND_LICENSE_KEY') ?: '';
    if (!empty($envKey)) { $cached = $envKey; return $cached; }
    
    // 3. 从 .env 文件读取
    $envFile = __DIR__ . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (preg_match('/^MAXMIND_LICENSE_KEY\s*=\s*(.+)$/', $line, $m)) {
                $cached = trim($m[1]);
                return $cached;
            }
        }
    }
    
    $cached = '';
    return $cached;
}

/**
 * 获取 GeoIP2 数据库下载 URL
 */
function getGeoipDbUrl(): string
{
    $key = getMaxmindLicenseKey();
    return 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=' . urlencode($key) . '&suffix=tar.gz';
}

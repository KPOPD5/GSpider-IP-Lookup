<?php
/**
 * JSON API 接口
 * 
 * 端点：
 *   GET  /api.php?action=check&ip=xxx[&ua=xxx]   - 检查IP是否为百度蜘蛛
 *   GET  /api.php?action=geo&ip=xxx               - 查询IP地理位置
 *   GET  /api.php?action=full&ip=xxx[&ua=xxx]     - 完整查询（蜘蛛+地理）
 *   GET  /api.php?action=stats                    - 获取统计信息
 *   GET  /api.php?action=ranges                   - 获取所有IP段
 *   GET  /api.php?action=recent&limit=20          - 获取最近查询记录
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

// 处理 OPTIONS 预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config.php';

// CORS：仅允许本站同源访问（使用安全过滤后的 Host，防止 Origin 反射攻击）
$corsOrigin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$corsOrigin .= getSafeHost();
header('Access-Control-Allow-Origin: ' . $corsOrigin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
require_once __DIR__ . '/SpiderChecker.php';
require_once __DIR__ . '/GeoIPLookup.php';

// 启动会话用于速率限制
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 对需要查询的 action 进行速率限制
$rateLimitedActions = ['check', 'geo', 'full'];
if (in_array($action, $rateLimitedActions)) {
    $maxPerMinute = defined('ADMIN_SETTING_MAX_QUERY_PER_MINUTE') ? (int)ADMIN_SETTING_MAX_QUERY_PER_MINUTE : 30;
    $rateLimitError = checkRateLimit($maxPerMinute);
    if ($rateLimitError) {
        jsonResponse(429, ['error' => true, 'message' => $rateLimitError]);
    }
}

try {
    switch ($action) {
        case 'check':
            handleCheck();
            break;
        case 'geo':
            handleGeo();
            break;
        case 'full':
            handleFull();
            break;
        case 'stats':
            handleStats();
            break;
        case 'ranges':
            handleRanges();
            break;
        case 'recent':
            handleRecent();
            break;
        default:
            jsonResponse(400, [
                'error'   => true,
                'message' => '未知操作。支持: check, geo, full, stats, ranges, recent',
                'usage'   => [
                    'check'  => '/api.php?action=check&ip=1.2.3.4&ua=Baiduspider',
                    'geo'    => '/api.php?action=geo&ip=1.2.3.4',
                    'full'   => '/api.php?action=full&ip=1.2.3.4&ua=Baiduspider',
                    'stats'  => '/api.php?action=stats',
                    'ranges' => '/api.php?action=ranges',
                    'recent' => '/api.php?action=recent&limit=20',
                ],
            ]);
    }
} catch (Exception $e) {
    error_log('[api] Internal error: ' . $e->getMessage());
    jsonResponse(500, ['error' => true, 'message' => '服务器内部错误，请稍后重试']);
}

// ============================================
// 处理函数
// ============================================

/**
 * 检查 IP 是否为百度蜘蛛
 */
function handleCheck(): void
{
    $ip = getIPParam();
    $ua = $_GET['ua'] ?? $_POST['ua'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $checker = new SpiderChecker();
    $result = $checker->check($ip, $ua);
    
    jsonResponse(200, [
        'success' => true,
        'data'    => $result,
    ]);
}

/**
 * 查询 IP 地理位置
 */
function handleGeo(): void
{
    $ip = getIPParam();
    
    $geo = new GeoIPLookup();
    $result = $geo->lookup($ip);
    
    jsonResponse(200, [
        'success' => true,
        'data'    => $result,
    ]);
}

/**
 * 完整查询：蜘蛛检测 + 地理位置
 */
function handleFull(): void
{
    $ip = getIPParam();
    $ua = $_GET['ua'] ?? $_POST['ua'] ?? $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $checker = new SpiderChecker();
    $geo = new GeoIPLookup();
    
    $spiderResult = $checker->check($ip, $ua);
    $geoResult = $geo->lookup($ip);
    
    jsonResponse(200, [
        'success' => true,
        'data'    => [
            'spider'   => $spiderResult,
            'location' => $geoResult,
        ],
    ]);
}

/**
 * 获取统计信息
 */
function handleStats(): void
{
    $checker = new SpiderChecker();
    $stats = $checker->getStats();
    
    jsonResponse(200, [
        'success' => true,
        'data'    => $stats,
    ]);
}

/**
 * 获取所有蜘蛛 IP 段
 */
function handleRanges(): void
{
    $checker = new SpiderChecker();
    $ranges = $checker->getAllRanges();
    
    jsonResponse(200, [
        'success' => true,
        'data'    => $ranges,
        'total'   => count($ranges),
    ]);
}

/**
 * 获取最近查询记录
 */
function handleRecent(): void
{
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    
    $checker = new SpiderChecker();
    $records = $checker->getRecentQueries($limit);
    
    jsonResponse(200, [
        'success' => true,
        'data'    => $records,
    ]);
}

// ============================================
// 辅助函数
// ============================================

/**
 * 获取请求中的 IP 参数
 */
function getIPParam(): string
{
    $ip = $_GET['ip'] ?? $_POST['ip'] ?? '';
    
    if (empty($ip)) {
        $ip = getClientIP();
    }
    
    return trim($ip);
}

/**
 * 输出 JSON 响应
 */
function jsonResponse(int $code, array $data): void
{
    http_response_code($code);
    // 仅本地/调试环境输出格式化 JSON，生产环境使用紧凑格式减少带宽
    $flags = JSON_UNESCAPED_UNICODE;
    if (($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1' || ($_GET['pretty'] ?? '') === '1') {
        $flags |= JSON_PRETTY_PRINT;
    }
    echo json_encode($data, $flags);
    exit;
}

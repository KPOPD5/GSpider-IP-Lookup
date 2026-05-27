<?php
/**
 * 共享工具函数
 * 提供跨文件复用的通用辅助方法
 */

/**
 * 检查 IP 是否为私有/保留地址
 */
function isPrivateIP(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * 获取客户端真实 IP（支持代理/CDN）
 */
function getClientIP(): string
{
    $headers = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'HTTP_CF_CONNECTING_IP',
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * 安全输出 HTML 字符串（简写别名）
 */
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 生成 CSRF Token（前端页面使用）
 */
function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 验证 CSRF Token
 */
function verifyCsrfToken(string $token): bool
{
    if (empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 输出 CSRF 隐藏表单域
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(generateCsrfToken()) . '">';
}

/**
 * IP 查询速率限制检查
 * 基于 Session 记录最近查询时间戳，超过限制则拒绝
 * @param int $maxPerMinute 每分钟最大查询次数（从后台设置读取）
 * @return string|null 返回错误消息表示被限制，null 表示通过
 */
function checkRateLimit(int $maxPerMinute = 30): ?string
{
    if ($maxPerMinute <= 0) return null; // 0 表示不限
    
    $now = time();
    $window = 60; // 1 分钟窗口
    
    if (!isset($_SESSION['rate_limit_timestamps'])) {
        $_SESSION['rate_limit_timestamps'] = [];
    }
    
    // 清理过期记录（超过 1 分钟的）
    $_SESSION['rate_limit_timestamps'] = array_filter(
        $_SESSION['rate_limit_timestamps'],
        fn($t) => ($now - $t) < $window
    );
    
    // 重新索引
    $_SESSION['rate_limit_timestamps'] = array_values($_SESSION['rate_limit_timestamps']);
    
    // 检查是否超限
    if (count($_SESSION['rate_limit_timestamps']) >= $maxPerMinute) {
        $oldest = $_SESSION['rate_limit_timestamps'][0];
        $waitSeconds = $window - ($now - $oldest);
        return "查询过于频繁，请 {$waitSeconds} 秒后再试（限制：{$maxPerMinute} 次/分钟）";
    }
    
    // 记录本次查询时间
    $_SESSION['rate_limit_timestamps'][] = $now;
    
    return null; // 通过
}

/**
 * 安全获取当前请求的 Host（防止 Host Header 注入）
 */
function getSafeHost(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // 仅允许合法域名/IP字符（含 IPv6 方括号）
    $host = preg_replace('/[^a-zA-Z0-9\.\-:\[\]]/', '', $host);
    return $host ?: 'localhost';
}

/**
 * 构建安全的 canonical URL
 */
function buildCanonicalUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = getSafeHost();
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    return $scheme . '://' . $host . $path;
}

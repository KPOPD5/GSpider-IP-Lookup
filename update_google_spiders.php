<?php
/**
 * Google 蜘蛛 IP 段自动更新脚本
 * 用于宝塔面板计划任务（Cron），建议每7天执行一次
 * 
 * 宝塔面板 Cron 设置：
 *   任务类型：Shell脚本
 *   任务名称：更新Google蜘蛛IP段
 *   执行周期：每7天 03:00
 *   脚本内容：php /www/wwwroot/your-domain/baidu/update_google_spiders.php
 */

require_once __DIR__ . '/config.php';

set_time_limit(300);
ini_set('memory_limit', '256M');

echo "========================================\n";
echo "Google蜘蛛IP段更新脚本 - " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

// Google 官方 JSON 数据源（内置默认）
$endpoints = [
    'common-crawlers'     => 'https://developers.google.cn/crawling/ipranges/common-crawlers.json',
    'special-crawlers'    => 'https://developers.google.cn/crawling/ipranges/special-crawlers.json',
    'user-triggered-fetchers' => 'https://developers.google.cn/crawling/ipranges/user-triggered-fetchers.json',
];

// 从数据库加载用户自定义数据源（覆盖同名内置源）
if (file_exists(SQLITE_DB_PATH)) {
    try {
        $db = new SQLite3(SQLITE_DB_PATH);
        $db->enableExceptions(false);
        // 确保表存在（兼容已有数据库升级）
        $db->exec("CREATE TABLE IF NOT EXISTS google_data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_key TEXT NOT NULL UNIQUE,
            source_name TEXT NOT NULL,
            endpoint_url TEXT NOT NULL,
            is_active INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $result = $db->query("SELECT source_key, endpoint_url, is_active FROM google_data_sources ORDER BY sort_order ASC");
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['is_active']) {
                    $endpoints[$row['source_key']] = $row['endpoint_url'];
                } else {
                    // 禁用的源从列表中移除
                    unset($endpoints[$row['source_key']]);
                }
            }
        }
        $db->close();
    } catch (Exception $e) {
        echo "⚠️ 数据库数据源读取失败，使用内置默认源\n";
    }
}

$allIPs = [];
$totalFromSources = [];

// 1. 从 Google 官方获取数据
foreach ($endpoints as $key => $url) {
    echo "获取: {$key} ... ";
    $json = curlGet($url);
    if (!$json) {
        echo "❌ 请求失败\n";
        continue;
    }
    
    $parsed = json_decode($json, true);
    if (!$parsed || empty($parsed['prefixes'])) {
        echo "❌ 数据为空\n";
        continue;
    }
    
    $count = 0;
    foreach ($parsed['prefixes'] as $p) {
        $prefix = '';
        if (is_string($p)) $prefix = $p;
        elseif (!empty($p['ipv6Prefix'])) $prefix = $p['ipv6Prefix'];
        elseif (!empty($p['ipv4Prefix'])) $prefix = $p['ipv4Prefix'];
        if ($prefix) {
            $allIPs[] = [
                'prefix' => $prefix,
                'type'   => strpos($prefix, ':') !== false ? 'IPv6' : 'IPv4',
                'source' => $key,
            ];
            $count++;
        }
    }
    $totalFromSources[$key] = $count;
    echo "✅ {$count} 条\n";
}

$totalIPs = count($allIPs);
echo "\n共获取 {$totalIPs} 个 IP 段\n\n";

if (empty($allIPs)) {
    echo "❌ 未获取到任何数据，更新中止\n";
    exit(1);
}

// 2. 写入缓存文件
$cacheDir = __DIR__ . '/db';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
$cacheFile = $cacheDir . '/google_spider_cache.json';
file_put_contents($cacheFile, json_encode($allIPs, JSON_UNESCAPED_UNICODE), LOCK_EX);
echo "✅ 缓存已更新\n";

// 3. 导入数据库
if (!file_exists(SQLITE_DB_PATH)) {
    echo "⚠️ 数据库不存在，跳过导入\n";
    echo "\n========================================\n更新完成\n========================================\n";
    exit(0);
}

try {
    $db = new SQLite3(SQLITE_DB_PATH);
    $db->enableExceptions(true);
    
    // 开启事务：大幅提升批量写入性能（SQLite 默认为每条语句自动提交）
    $db->exec('BEGIN');
    
    // 先将旧的 Googlebot IP 段标记为过期
    $db->exec("UPDATE spider_ranges SET is_active = 0 WHERE source = 'google_official'");
    $deactivated = $db->changes();
    echo "已标记 {$deactivated} 条旧 Google IP 为过期\n";
    
    // 确保表结构完整
    $db->exec("CREATE TABLE IF NOT EXISTS spider_ranges (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_range TEXT NOT NULL UNIQUE,
        cidr_notation TEXT NOT NULL,
        ip_start TEXT NOT NULL,
        ip_end TEXT NOT NULL,
        spider_type TEXT DEFAULT 'Googlebot',
        source TEXT DEFAULT 'google_official',
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $imported = 0;
    $reactivated = 0;
    $stmtInsert = $db->prepare("
        INSERT OR IGNORE INTO spider_ranges 
            (ip_range, cidr_notation, ip_start, ip_end, ip_start_num, ip_end_num, spider_type, source, is_active, created_at, updated_at)
        VALUES (:range, :cidr, :start, :end, :startNum, :endNum, 'Googlebot', 'google_official', 1, datetime('now','localtime'), datetime('now','localtime'))
    ");
    $stmtUpdate = $db->prepare("
        UPDATE spider_ranges SET is_active = 1, updated_at = datetime('now','localtime')
        WHERE ip_range = :range2
    ");
    
    foreach ($allIPs as $item) {
        $prefix = $item['prefix'] ?? '';
        if (empty($prefix)) continue;
        
        // IPv6 暂不导入（CIDR 匹配逻辑不同）
        if (strpos($prefix, ':') !== false) continue;
        
        if (strpos($prefix, '/') === false) $prefix .= '/32';
        list($subnet, $bits) = explode('/', $prefix);
        $bits = (int)$bits;
        $ipLong = ip2long($subnet);
        if ($ipLong === false) continue;
        
        $mask = -1 << (32 - $bits);
        $network = $ipLong & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);
        
        $stmtInsert->bindValue(':range', $prefix, SQLITE3_TEXT);
        $stmtInsert->bindValue(':cidr', $prefix, SQLITE3_TEXT);
        $stmtInsert->bindValue(':start', long2ip($network), SQLITE3_TEXT);
        $stmtInsert->bindValue(':end', long2ip($broadcast), SQLITE3_TEXT);
        $stmtInsert->bindValue(':startNum', $network, SQLITE3_INTEGER);
        $stmtInsert->bindValue(':endNum', $broadcast, SQLITE3_INTEGER);
        $stmtInsert->execute();
        
        if ($db->changes() > 0) {
            $imported++;
        } else {
            // IP 已存在，重新激活
            $stmtUpdate->bindValue(':range2', $prefix, SQLITE3_TEXT);
            $stmtUpdate->execute();
            if ($db->changes() > 0) $reactivated++;
            $stmtUpdate->reset();
        }
        $stmtInsert->reset();
    }
    
    // 提交事务
    $db->exec('COMMIT');
    
    // 记录更新日志
    $db->exec("CREATE TABLE IF NOT EXISTS update_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        update_type TEXT NOT NULL,
        status TEXT DEFAULT 'success',
        message TEXT,
        records_count INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $msg = json_encode([
        'total' => $totalIPs,
        'imported' => $imported,
        'reactivated' => $reactivated,
        'sources' => $totalFromSources,
    ], JSON_UNESCAPED_UNICODE);
    $logStmt = $db->prepare("INSERT INTO update_records (update_type, status, message, records_count, created_at) VALUES ('google_spider_ips', 'success', :msg, :count, datetime('now','localtime'))");
    $logStmt->bindValue(':msg', $msg, SQLITE3_TEXT);
    $logStmt->bindValue(':count', $totalIPs, SQLITE3_INTEGER);
    $logStmt->execute();
    
    $db->close();
    
    echo "✅ 新增/更新: {$imported} 条\n";
    echo "✅ 更新记录已写入\n";
} catch (Exception $e) {
    echo "❌ 数据库操作失败: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n========================================\n";
echo "更新完成 - " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

// ============================================
// 辅助函数
// ============================================

function curlGet(string $url): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SpiderChecker/1.0)',
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && !empty($res)) ? $res : null;
}

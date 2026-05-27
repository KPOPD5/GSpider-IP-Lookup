<?php
/**
 * GeoIP2 数据库下载/更新脚本
 * 用于宝塔面板计划任务，建议每月执行一次
 * 
 * 宝塔面板 Cron 设置：
 *   任务类型：Shell脚本
 *   任务名称：更新GeoIP2数据库
 *   执行周期：每月1号 04:00
 *   脚本内容：php /www/wwwroot/your-domain/baidu/update_geoip.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/GeoIPLookup.php';

set_time_limit(600);
ini_set('memory_limit', '512M');

echo "========================================\n";
echo "GeoIP2 数据库更新脚本 - " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

$geoip = new GeoIPLookup();

echo "正在下载 GeoLite2-City 数据库...\n";
echo "目标路径: " . GEOIP_DB_PATH . "\n\n";

$result = $geoip->downloadDatabase();

// 记录更新日志到数据库
$logStatus = 'success';
$logMessage = '';
$logCount = 0;

if ($result['success']) {
    echo "✅ 更新成功！\n";
    echo "   文件大小: " . number_format($result['size'] / 1024 / 1024, 2) . " MB\n";
    echo "   保存位置: " . GEOIP_DB_PATH . "\n";
    $logMessage = json_encode([
        'size_mb' => round($result['size'] / 1024 / 1024, 2),
        'file' => GEOIP_DB_PATH,
    ], JSON_UNESCAPED_UNICODE);
    $logCount = 1;
} else {
    echo "❌ 更新失败: " . $result['message'] . "\n";
    $logStatus = 'failed';
    $logMessage = $result['message'];
    
    // 如果备份存在，恢复备份
    $backupPath = GEOIP_DB_PATH . '.bak';
    if (file_exists($backupPath)) {
        echo "⚠️ 正在恢复旧数据库...\n";
        copy($backupPath, GEOIP_DB_PATH);
        echo "✅ 已恢复旧数据库\n";
    }
}

// 写入更新记录到数据库
if (file_exists(SQLITE_DB_PATH)) {
    try {
        $db = new SQLite3(SQLITE_DB_PATH);
        $db->enableExceptions(true);
        
        $db->exec("CREATE TABLE IF NOT EXISTS update_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            update_type TEXT NOT NULL,
            status TEXT DEFAULT 'success',
            message TEXT,
            records_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $stmt = $db->prepare("INSERT INTO update_records (update_type, status, message, records_count, created_at) VALUES ('geoip_db', :status, :msg, :count, datetime('now','localtime'))");
        $stmt->bindValue(':status', $logStatus, SQLITE3_TEXT);
        $stmt->bindValue(':msg', $logMessage, SQLITE3_TEXT);
        $stmt->bindValue(':count', $logCount, SQLITE3_INTEGER);
        $stmt->execute();
        $db->close();
    } catch (Exception $e) {
        echo "⚠️ 更新记录写入失败: " . $e->getMessage() . "\n";
    }
}

echo "\n========================================\n";
echo "更新完成 - " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

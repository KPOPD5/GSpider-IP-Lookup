<?php
/**
 * DB-IP Lite 数据库下载/更新脚本
 * 数据来源：https://db-ip.com/db/download/ip-to-city-lite
 * 免费版每月更新一次
 */

require_once __DIR__ . '/config.php';

set_time_limit(600);
ini_set('memory_limit', '512M');

echo "========================================\n";
echo "DB-IP Lite 数据库更新脚本 - " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

$dbPath = DBIP_DB_PATH;
$logStatus = 'success';
$logMessage = '';

// DB-IP Lite 免费下载 URL（格式：YYYY-MM）
$yearMonth = date('Y-m');
$downloadUrl = "https://download.db-ip.com/free/dbip-city-lite-{$yearMonth}.mmdb.gz";

echo "正在下载 DB-IP Lite 数据库...\n";
echo "下载地址: {$downloadUrl}\n";
echo "目标路径: {$dbPath}\n\n";

try {
    $tmpGz = sys_get_temp_dir() . '/dbip_update_' . uniqid() . '.mmdb.gz';
    $tmpMmdb = sys_get_temp_dir() . '/dbip_update_' . uniqid() . '.mmdb';
    
    // 下载 gzip 文件
    $fp = fopen($tmpGz, 'wb');
    if (!$fp) {
        throw new Exception('无法创建临时文件');
    }
    
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_USERAGENT => 'DB-IP Update Script/1.0',
        CURLOPT_FAILONERROR => true,
    ]);
    
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    
    if ($httpCode !== 200 || !empty($curlError)) {
        throw new Exception("下载失败 HTTP {$httpCode}" . ($curlError ? ": {$curlError}" : ''));
    }
    
    if (filesize($tmpGz) === 0) {
        throw new Exception('下载的文件为空');
    }
    
    // 解压 gzip
    $gz = gzopen($tmpGz, 'rb');
    $out = fopen($tmpMmdb, 'wb');
    if (!$gz || !$out) {
        throw new Exception('无法打开压缩文件');
    }
    while (!gzeof($gz)) {
        fwrite($out, gzread($gz, 4096));
    }
    gzclose($gz);
    fclose($out);
    
    // 备份旧数据库
    if (file_exists($dbPath)) {
        copy($dbPath, $dbPath . '.bak');
    }
    
    // 移动新数据库到目标位置
    if (!rename($tmpMmdb, $dbPath)) {
        throw new Exception('无法移动数据库文件到目标位置');
    }
    
    // 设置权限
    chmod($dbPath, 0644);
    if (function_exists('chown')) @chown($dbPath, 'www');
    if (function_exists('chgrp')) @chgrp($dbPath, 'www');
    
    $newSize = filesize($dbPath);
    echo "✅ 更新成功！\n";
    echo "   文件大小: " . number_format($newSize / 1024 / 1024, 2) . " MB\n";
    echo "   保存位置: {$dbPath}\n";
    
    $logMessage = json_encode([
        'size_mb' => round($newSize / 1024 / 1024, 2),
        'source' => 'db-ip.com',
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo "❌ 更新失败: " . $e->getMessage() . "\n";
    $logStatus = 'failed';
    $logMessage = $e->getMessage();
    
    if (file_exists($dbPath . '.bak')) {
        echo "⚠️ 正在恢复旧数据库...\n";
        copy($dbPath . '.bak', $dbPath);
        echo "✅ 已恢复旧数据库\n";
    }
} finally {
    // 清理临时文件
    @unlink($tmpGz ?? '');
    @unlink($tmpMmdb ?? '');
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
        
        $stmt = $db->prepare("INSERT INTO update_records (update_type, status, message, records_count, created_at) VALUES ('dbip_lite', :status, :msg, 1, datetime('now','localtime'))");
        $stmt->bindValue(':status', $logStatus, SQLITE3_TEXT);
        $stmt->bindValue(':msg', $logMessage, SQLITE3_TEXT);
        $stmt->execute();
        $db->close();
    } catch (Exception $e) {
        echo "⚠️ 更新记录写入失败: " . $e->getMessage() . "\n";
    }
}

echo "\n========================================\n";
echo "更新完成 - " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

<?php
/**
 * IP2Location LITE 数据库下载/更新脚本
 * 数据来源：https://lite.ip2location.com/（免费注册获取 Token）
 * 数据库：DB11LITE (IP-COUNTRY-REGION-CITY)
 * 
 * 使用前请在后台「系统设置」中配置 IP2Location Token
 */

require_once __DIR__ . '/config.php';

set_time_limit(600);
ini_set('memory_limit', '512M');

echo "========================================\n";
echo "IP2Location LITE 数据库更新脚本 - " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

$dbPath = __DIR__ . '/geoip/IP2Location-LITE-DB11.BIN';
$logStatus = 'success';
$logMessage = '';

// 从设置中获取 Token
$token = '';
if (file_exists(SQLITE_DB_PATH)) {
    try {
        $setDb = new SQLite3(SQLITE_DB_PATH);
        $tokenRow = $setDb->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'ip2location_token'", true);
        if ($tokenRow) $token = $tokenRow['setting_value'] ?? '';
        $setDb->close();
    } catch (Exception $e) {}
}

if (empty($token)) {
    echo "❌ 未配置 IP2Location Token\n";
    echo "   请在后台「系统设置」中填写 Token\n";
    echo "   免费注册地址：https://lite.ip2location.com/\n";
    $logStatus = 'failed';
    $logMessage = '未配置 IP2Location Token';
} else {
    $downloadUrl = "https://www.ip2location.com/download/?token={$token}&file=DB11LITEBIN";
    
    echo "正在下载 IP2Location LITE DB11...\n";
    echo "目标路径: {$dbPath}\n\n";
    
    try {
        $tmpZip = sys_get_temp_dir() . '/ip2l_update_' . uniqid() . '.zip';
        $tmpDir = sys_get_temp_dir() . '/ip2l_extract_' . uniqid();
        
        if (!mkdir($tmpDir, 0755, true)) {
            throw new Exception('无法创建临时目录');
        }
        
        // 下载 ZIP 文件
        $fp = fopen($tmpZip, 'wb');
        if (!$fp) throw new Exception('无法创建临时文件');
        
        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_USERAGENT => 'IP2Location Update Script/1.0',
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
        
        if (filesize($tmpZip) < 1000) {
            // 可能是错误页面，检查内容
            $content = file_get_contents($tmpZip);
            if (strpos($content, 'Invalid token') !== false || strpos($content, 'error') !== false) {
                throw new Exception('Token 无效或已过期，请检查 IP2Location Token');
            }
            throw new Exception('下载的文件异常（可能 Token 无效）');
        }
        
        // 解压 ZIP
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            throw new Exception('无法打开 ZIP 文件');
        }
        
        // 查找 .BIN 文件
        $binFile = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (preg_match('/\.BIN$/i', $filename)) {
                $binFile = $filename;
                break;
            }
        }
        
        if (!$binFile) {
            throw new Exception('ZIP 中未找到 .BIN 数据库文件');
        }
        
        $zip->extractTo($tmpDir);
        $zip->close();
        
        $extractedBin = $tmpDir . '/' . $binFile;
        if (!file_exists($extractedBin)) {
            throw new Exception('解压后未找到 BIN 文件');
        }
        
        // 备份旧数据库
        if (file_exists($dbPath)) {
            copy($dbPath, $dbPath . '.bak');
        }
        
        // 确保目标目录存在
        $geoipDir = dirname($dbPath);
        if (!is_dir($geoipDir)) {
            mkdir($geoipDir, 0755, true);
        }
        
        // 移动新数据库到目标位置（优先 rename，失败则 copy+unlink）
        if (!@rename($extractedBin, $dbPath)) {
            if (!@copy($extractedBin, $dbPath)) {
                throw new Exception('无法移动数据库文件到目标位置（权限不足或磁盘空间不足）');
            }
            @unlink($extractedBin);
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
            'source' => 'ip2location.com',
            'database' => 'DB11LITE',
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
        @unlink($tmpZip ?? '');
        if (isset($tmpDir)) rmdirRecursive($tmpDir);
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
        
        $stmt = $db->prepare("INSERT INTO update_records (update_type, status, message, records_count, created_at) VALUES ('ip2location_lite', :status, :msg, 1, datetime('now','localtime'))");
        $stmt->bindValue(':status', $logStatus, SQLITE3_TEXT);
        $stmt->bindValue(':msg', $logMessage, SQLITE3_TEXT);
        $stmt->execute();
        $db->close();
    } catch (Exception $e) {
        echo "⚠️ 更新记录写入失败: " . $e->getMessage() . "\n";
    }
}

// 递归删除目录
function rmdirRecursive($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) ? rmdirRecursive($path) : unlink($path);
    }
    rmdir($dir);
}

echo "\n========================================\n";
echo "更新完成 - " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n";

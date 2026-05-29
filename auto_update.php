<?php
/**
 * 自动更新调度器
 * 无需宝塔计划任务，访问网站时自动按周期触发后台更新
 * 
 * 在 index.php 顶部 include 即可实现全自动维护
 * 
 * 2026 更新：支持从后台动态配置更新间隔和开关
 */

// 从数据库加载动态配置（如果可用）
$autoUpdateEnabled = true;
$googleInterval = 604800;   // 默认7天
$geoipInterval = 2592000;   // 默认30天
$dbipInterval = 2592000;    // 默认30天
$ip2lInterval = 2592000;    // 默认30天
$logRetentionDays = 90;

if (file_exists(SQLITE_DB_PATH)) {
    try {
        $db = new SQLite3(SQLITE_DB_PATH);
        $db->enableExceptions(true);
        
        $tableCheck = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='admin_settings'");
        if ($tableCheck > 0) {
            $enabled = $db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'auto_update_enabled'");
            if ($enabled !== null) {
                $autoUpdateEnabled = (bool)(int)$enabled;
            }
            $gi = $db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'google_spider_update_interval'");
            if ($gi !== null) $googleInterval = (int)$gi;
            $gii = $db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'geoip_update_interval'");
            if ($gii !== null) $geoipInterval = (int)$gii;
            $dbi = $db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'dbip_update_interval'");
            if ($dbi !== null) $dbipInterval = (int)$dbi;
            $ip2i = $db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'ip2location_update_interval'");
            if ($ip2i !== null) $ip2lInterval = (int)$ip2i;
            $lrd = $db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'query_log_retention_days'");
            if ($lrd !== null) $logRetentionDays = (int)$lrd;
        }
        $db->close();
    } catch (Exception $e) {
        // 静默处理
    }
}

// 如果自动更新被禁用，直接返回
if (!$autoUpdateEnabled) {
    return;
}

// 调度配置：任务名 => [更新脚本, 间隔秒数]
$tasks = [
    'google_spiders'    => [__DIR__ . '/update_google_spiders.php', $googleInterval],
    'geoip_db'          => [__DIR__ . '/update_geoip.php', $geoipInterval],
    'dbip_lite'         => [__DIR__ . '/update_dbip.php', $dbipInterval],
    'ip2location_lite'  => [__DIR__ . '/update_ip2location.php', $ip2lInterval],
    'log_cleanup'       => [null, 86400],
];

// 调度状态存储文件
$scheduleFile = __DIR__ . '/db/auto_update_schedule.json';

// 读取上次运行时间
$schedule = [];
if (file_exists($scheduleFile)) {
    $schedule = json_decode(file_get_contents($scheduleFile), true) ?: [];
}

$now = time();
$triggered = false;

foreach ($tasks as $name => $task) {
    list($script, $interval) = $task;
    
    // 检查是否需要更新
    $lastRun = $schedule[$name] ?? 0;
    if (($now - $lastRun) >= $interval) {
        // 标记为已触发（避免并发请求重复触发）
        $schedule[$name] = $now;
        $triggered = true;
        
        if ($name === 'log_cleanup') {
            // 日志清理：在当前进程内直接执行（轻量操作）
            cleanOldQueryLogs($logRetentionDays);
        } else {
            // 后台异步执行更新脚本（不阻塞当前请求）
            runInBackground($script);
        }
    }
}

// 保存更新后的调度状态
if ($triggered) {
    $dir = dirname($scheduleFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($scheduleFile, json_encode($schedule), LOCK_EX);
}

/**
 * 清理超过保留天数的查询日志（轻量操作，同步执行）
 */
function cleanOldQueryLogs(int $retentionDays): void
{
    if ($retentionDays <= 0 || !file_exists(SQLITE_DB_PATH)) return;
    
    try {
        $db = new SQLite3(SQLITE_DB_PATH);
        $db->enableExceptions(false);
        
        $stmt = $db->prepare("DELETE FROM query_stats WHERE query_time < datetime('now', 'localtime', :days)");
        $stmt->bindValue(':days', "-{$retentionDays} days", SQLITE3_TEXT);
        $stmt->execute();
        $deleted = $db->changes();
        
        $db->close();
        
        if ($deleted > 0) {
            error_log("[auto_update] Log cleanup: deleted {$deleted} old query records (retention: {$retentionDays} days)");
        }
    } catch (\Exception $e) {
        // 静默处理
    }
}
function runInBackground(string $script): void
{
    if (!file_exists($script)) return;
    
    $phpBin = PHP_BINARY ?: 'php';
    
    // 优先级1: 使用 popen（兼容性好）
    if (function_exists('popen')) {
        if (stripos(PHP_OS, 'WIN') === 0) {
            // Windows: 使用 start /B 后台运行，路径用引号包裹
            $cmd = 'start /B "" ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' > NUL 2>&1';
        } else {
            // Linux: 重定向输出
            $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &';
        }
        $handle = @popen($cmd, 'r');
        if ($handle) {
            pclose($handle);
            return;
        }
    }
    
    // 优先级2: 使用 proc_open（更可靠的后台进程启动方式）
    if (function_exists('proc_open')) {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(
            escapeshellarg($phpBin) . ' ' . escapeshellarg($script),
            $descriptorspec,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (is_resource($process)) {
            // 关闭所有管道以释放资源，进程继续后台运行
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) fclose($pipe);
            }
            proc_close($process);
            return;
        }
    }
    
    // 回退：无法执行后台任务，记录日志
    error_log("[auto_update] Cannot run background task: neither popen() nor proc_open() available, script={$script}");
}

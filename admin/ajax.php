<?php
/**
 * 后台管理 - AJAX 处理端点
 * 所有后台异步请求的统一入口
 * 
 * 2026 现代方案：RESTful JSON API + CSRF 保护
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../AdminAuth.php';
require_once __DIR__ . '/../AdminSettings.php';
require_once __DIR__ . '/../SpiderChecker.php';

// 确保数据库已初始化
if (!file_exists(SQLITE_DB_PATH)) {
    require_once __DIR__ . '/../init_db.php';
}

$auth = new AdminAuth();
$settings = new AdminSettings();

// 验证登录状态
if (!$auth->isLoggedIn()) {
    jsonOut(401, ['error' => '未登录或会话已过期']);
}

// ============================================
// 统一解析请求体（php://input 只能读取一次，必须复用）
// ============================================
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$rawBody = ($_SERVER['REQUEST_METHOD'] !== 'GET') ? file_get_contents('php://input') : '';
$jsonInput = [];

// 尝试解析 JSON body（无论 Content-Type 是否声明，只要内容是合法 JSON 就解析）
if ($rawBody !== '' && $rawBody !== false) {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $jsonInput = $decoded;
    }
}

// 获取 action（优先 GET 参数，其次 JSON body）
$action = $_GET['action'] ?? $jsonInput['action'] ?? '';

// 验证 CSRF（除 GET 外的请求）
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $csrf = $jsonInput['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    
    if (!$auth->verifyCsrfToken($csrf)) {
        jsonOut(403, ['error' => 'CSRF 验证失败，请刷新页面重试']);
    }
}

// 敏感操作（数据修改类）必须通过 POST 请求，防止 CSRF 通过 GET 触发
$mutationActions = [
    'save_settings', 'trigger_update', 'clear_update_records',
    'import_confirmed_spider', 'import_confirmed_spiders', 'batch_delete_confirmed_spiders',
    'add_spider_range', 'toggle_spider_range', 'delete_spider_range', 'batch_delete_spider_ranges',
    'clear_logs', 'clean_old_data', 'change_password',
    'add_google_source', 'update_google_source', 'delete_google_source', 'toggle_google_source', 'validate_baidu_ranges',
    'add_rdns_rule', 'update_rdns_rule', 'delete_rdns_rule', 'toggle_rdns_rule',
    'add_ua_rule', 'update_ua_rule', 'delete_ua_rule', 'toggle_ua_rule',
    'batch_add_rdns_rules', 'batch_delete_rdns_rules', 'batch_add_ua_rules', 'batch_delete_ua_rules',
    'reset_rdns_rules', 'reset_ua_rules',
];
if (in_array($action, $mutationActions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(405, ['error' => '此操作仅支持 POST 请求']);
}

/**
 * 获取请求参数（优先 JSON body，其次 POST，最后 GET）
 */
function input(string $key, mixed $default = null): mixed
{
    global $jsonInput;
    return $jsonInput[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
}

try {
    switch ($action) {
        // ========== 仪表盘数据 ==========
        case 'dashboard':
            jsonOut(200, $settings->getDashboardData());
            break;
        
        // ========== 设置操作 ==========
        case 'get_settings':
            jsonOut(200, ['settings' => $settings->getAll()]);
            break;
        
        case 'save_settings':
            $rawSettings = input('settings', []);
            $parsed = [];
            $newAdminPath = null;
            foreach ($rawSettings as $item) {
                if (isset($item['key']) && isset($item['value'])) {
                    $parsed[$item['key']] = [
                        'value' => $item['value'],
                        'type' => $item['type'] ?? 'string',
                    ];
                    if ($item['key'] === 'admin_path') {
                        $newAdminPath = $item['value'];
                    }
                }
            }
            
            // 如果 admin_path 有变化，先记录旧值并清理旧目录
            $oldAdminPath = null;
            $adminPathChanged = false;
            if ($newAdminPath !== null) {
                $oldAdminPath = $settings->get('admin_path', 'admin');
                $adminPathChanged = ($newAdminPath !== $oldAdminPath);
            }
            
            $results = $settings->setMultiple($parsed);
            
            // 仅当 admin_path 实际发生变化时才更新服务器配置
            $serverConfig = null;
            if ($adminPathChanged) {
                // 清理旧的自动生成目录
                if ($oldAdminPath !== 'admin' && $oldAdminPath !== null) {
                    $oldDir = __DIR__ . '/../' . $oldAdminPath;
                    if (is_dir($oldDir)) {
                        AdminSettings::removeDirStatic($oldDir);
                    }
                }
                $serverConfig = $settings->generateServerConfig($newAdminPath);
            }
            
            jsonOut(200, [
                'success' => true, 
                'results' => $results,
                'server_config' => $serverConfig,
                'admin_path_changed' => $adminPathChanged,
            ]);
            break;
        
        // ========== 更新操作 ==========
        case 'trigger_update':
            $updateType = input('update_type', '');
            $allowedTypes = ['google_spiders', 'geoip_db', 'dbip_lite', 'ip2location_lite'];
            
            if (!in_array($updateType, $allowedTypes)) {
                jsonOut(400, ['error' => '未知的更新类型: ' . $updateType]);
            }
            
            $scriptMap = [
                'google_spiders'    => __DIR__ . '/../update_google_spiders.php',
                'geoip_db'          => __DIR__ . '/../update_geoip.php',
                'dbip_lite'         => __DIR__ . '/../update_dbip.php',
                'ip2location_lite'  => __DIR__ . '/../update_ip2location.php',
            ];
            
            $script = $scriptMap[$updateType];
            if (!file_exists($script)) {
                jsonOut(500, ['error' => '更新脚本不存在: ' . $updateType]);
            }
            
            // 在当前进程中同步执行更新脚本（避免 exec 命令注入风险）
            $startTime = microtime(true);
            ob_start();
            $exitCode = 0;
            
            try {
                // 直接 include 脚本文件，比 exec() 更安全
                // 脚本通过 return 控制退出码
                $__scriptResult = (static function() use ($script) {
                    ob_start();
                    $result = include $script;
                    $output = ob_get_clean();
                    return ['output' => $output, 'result' => $result];
                })();
                $outputStr = $__scriptResult['output'];
                $exitCode = ($__scriptResult['result'] === false) ? 1 : 0;
            } catch (\Throwable $e) {
                error_log('[ajax] trigger_update script error: ' . $e->getMessage());
                $outputStr = '脚本执行异常，请查看服务器日志';
                $exitCode = 1;
            }
            ob_end_clean();
            
            $execTime = round((microtime(true) - $startTime) * 1000, 2);
            
            jsonOut(200, [
                'success' => $exitCode === 0,
                'message' => $outputStr ?: ($exitCode === 0 ? '更新完成' : '更新失败'),
                'exit_code' => $exitCode,
                'execution_time_ms' => $execTime,
            ]);
            break;
        
        case 'get_update_records':
            $limit = (int)($_GET['limit'] ?? 20);
            jsonOut(200, ['records' => $settings->getUpdateRecords($limit)]);
            break;
        
        case 'clear_update_records':
            try {
                $db = new SQLite3(SQLITE_DB_PATH);
                $db->enableExceptions(true);
                $db->exec("DELETE FROM update_records");
                $deleted = $db->changes();
                $db->close();
                jsonOut(200, ['success' => true, 'message' => "已清除 {$deleted} 条更新记录"]);
            } catch (Exception $e) {
                error_log('[ajax] clear_update_records error: ' . $e->getMessage());
                jsonOut(500, ['error' => '清除失败，请稍后重试']);
            }
            break;
        
        // ========== 蜘蛛IP段操作 ==========
        case 'get_confirmed_spiders':
            $limit = max(10, min(500, (int)($_GET['limit'] ?? 200)));
            $search = trim($_GET['search'] ?? '');
            $filterType = trim($_GET['filter_type'] ?? '');
            $filterConf = trim($_GET['filter_conf'] ?? '');
            jsonOut(200, ['spiders' => $settings->getConfirmedSpiders($limit, $search, $filterType, $filterConf)]);
            break;
        
        case 'import_confirmed_spider':
            $ip = trim(input('ip_address', ''));
            if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
                jsonOut(400, ['error' => '无效的 IP 地址']);
            }
            
            try {
                $db = new SQLite3(SQLITE_DB_PATH);
                $db->enableExceptions(true);
                
                // 从已确认表查找
                $stmt = $db->prepare("SELECT ip_address, spider_type, confidence FROM confirmed_spiders WHERE ip_address = :ip LIMIT 1");
                $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                
                if (!$row) {
                    jsonOut(404, ['error' => '该 IP 不在已确认蜘蛛列表中']);
                }
                
                $ipLong = ip2long($ip);
                $confidence = $row['confidence'] ?? '';
                
                $insertStmt = $db->prepare("
                    INSERT OR IGNORE INTO spider_ranges 
                        (ip_range, cidr_notation, ip_start, ip_end, ip_start_num, ip_end_num, spider_type, source, confidence, is_active, created_at, updated_at)
                    VALUES (:range, :cidr, :start, :end, :startNum, :endNum, :type, 'confirmed_import', :conf, 1, datetime('now','localtime'), datetime('now','localtime'))
                ");
                $insertStmt->bindValue(':range', $ip, SQLITE3_TEXT);
                $insertStmt->bindValue(':cidr', $ip, SQLITE3_TEXT);
                $insertStmt->bindValue(':start', $ip, SQLITE3_TEXT);
                $insertStmt->bindValue(':end', $ip, SQLITE3_TEXT);
                $insertStmt->bindValue(':startNum', $ipLong ?: 0, SQLITE3_INTEGER);
                $insertStmt->bindValue(':endNum', $ipLong ?: 0, SQLITE3_INTEGER);
                $insertStmt->bindValue(':type', $row['spider_type'] ?: 'Baiduspider', SQLITE3_TEXT);
                $insertStmt->bindValue(':conf', $confidence, SQLITE3_TEXT);
                $insertStmt->execute();
                
                $changes = $db->changes();
                $db->close();
                
                if ($changes > 0) {
                    jsonOut(200, ['success' => true, 'message' => "IP {$ip} 已导入到蜘蛛IP管理"]);
                } else {
                    jsonOut(200, ['success' => true, 'message' => "IP {$ip} 已存在，无需重复导入"]);
                }
            } catch (\Exception $e) {
                if (isset($db)) $db->close();
                error_log('[ajax] import_confirmed_spider error: ' . $e->getMessage());
                jsonOut(500, ['error' => '导入失败，请稍后重试']);
            }
            break;
        
        case 'batch_delete_confirmed_spiders':
            $ipList = input('ip_list', []);
            if (!is_array($ipList) || empty($ipList)) {
                jsonOut(400, ['error' => '请选择要删除的 IP']);
            }
            
            $deleted = 0;
            try {
                $db = new SQLite3(SQLITE_DB_PATH);
                $db->enableExceptions(true);
                
                $placeholders = implode(',', array_fill(0, count($ipList), '?'));
                $stmt = $db->prepare("DELETE FROM confirmed_spiders WHERE ip_address IN ({$placeholders})");
                foreach ($ipList as $i => $ip) {
                    $stmt->bindValue($i + 1, $ip, SQLITE3_TEXT);
                }
                $stmt->execute();
                $deleted = $db->changes();
                $db->close();
                
                jsonOut(200, ['success' => true, 'message' => "已删除 {$deleted} 条记录"]);
            } catch (\Exception $e) {
                if (isset($db)) $db->close();
                error_log('[ajax] batch_delete_confirmed_spiders error: ' . $e->getMessage());
                jsonOut(500, ['error' => '删除失败，请稍后重试']);
            }
            break;
        
        case 'import_confirmed_spiders':
            $imported = 0;
            $skipped = 0;
            $ipList = input('ip_list', null); // 可选：指定 IP 列表
            
            try {
                $db = new SQLite3(SQLITE_DB_PATH);
                $db->enableExceptions(true);
                
                // 开启事务：大幅提升批量写入性能
                $db->exec('BEGIN');
                
                // 确保 spider_ranges 表存在
                $db->exec("CREATE TABLE IF NOT EXISTS spider_ranges (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip_range TEXT NOT NULL UNIQUE,
                    cidr_notation TEXT NOT NULL,
                    ip_start TEXT NOT NULL,
                    ip_end TEXT NOT NULL,
                    ip_start_num INTEGER DEFAULT 0,
                    ip_end_num INTEGER DEFAULT 0,
                    spider_type TEXT DEFAULT 'Baiduspider',
                    source TEXT DEFAULT 'manual',
                    confidence TEXT DEFAULT '',
                    is_active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )");
                
                // 从已确认蜘蛛表读取 IP
                $confirmed = [];
                if (is_array($ipList) && !empty($ipList)) {
                    // 批量导入指定 IP
                    $placeholders = implode(',', array_fill(0, count($ipList), '?'));
                    $stmt = $db->prepare("SELECT ip_address, spider_type, confidence FROM confirmed_spiders WHERE ip_address IN ({$placeholders}) ORDER BY last_seen DESC");
                    foreach ($ipList as $i => $ip) {
                        $stmt->bindValue($i + 1, $ip, SQLITE3_TEXT);
                    }
                    $result = $stmt->execute();
                } else {
                    // 导入全部
                    $result = $db->query("SELECT ip_address, spider_type, confidence FROM confirmed_spiders ORDER BY last_seen DESC");
                }
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $confirmed[] = $row;
                }
                
                if (empty($confirmed)) {
                    jsonOut(200, ['success' => true, 'message' => '没有可导入的蜘蛛 IP', 'imported' => 0, 'skipped' => 0]);
                }
                
                $insertStmt = $db->prepare("
                    INSERT OR IGNORE INTO spider_ranges 
                        (ip_range, cidr_notation, ip_start, ip_end, ip_start_num, ip_end_num, spider_type, source, confidence, is_active, created_at, updated_at)
                    VALUES (:range, :cidr, :start, :end, :startNum, :endNum, :type, 'confirmed_import', :conf, 1, datetime('now','localtime'), datetime('now','localtime'))
                ");
                
                foreach ($confirmed as $row) {
                    $ip = $row['ip_address'];
                    if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
                    
                    $ipLong = ip2long($ip);
                    if ($ipLong === false && strpos($ip, ':') === false) continue;
                    
                    $insertStmt->bindValue(':range', $ip, SQLITE3_TEXT);
                    $insertStmt->bindValue(':cidr', $ip, SQLITE3_TEXT);
                    $insertStmt->bindValue(':start', $ip, SQLITE3_TEXT);
                    $insertStmt->bindValue(':end', $ip, SQLITE3_TEXT);
                    $insertStmt->bindValue(':startNum', $ipLong ?: 0, SQLITE3_INTEGER);
                    $insertStmt->bindValue(':endNum', $ipLong ?: 0, SQLITE3_INTEGER);
                    $insertStmt->bindValue(':type', $row['spider_type'] ?: 'Baiduspider', SQLITE3_TEXT);
                    $insertStmt->bindValue(':conf', $row['confidence'] ?? '', SQLITE3_TEXT);
                    $insertStmt->execute();
                    
                    if ($db->changes() > 0) {
                        $imported++;
                    } else {
                        $skipped++;
                    }
                    $insertStmt->reset();
                }
                
                // 提交事务
                $db->exec('COMMIT');
                
                $db->close();
                
                jsonOut(200, [
                    'success' => true,
                    'message' => "导入完成：新增 {$imported} 条，跳过 {$skipped} 条（已存在）",
                    'imported' => $imported,
                    'skipped' => $skipped,
                ]);
            } catch (\Exception $e) {
                if (isset($db)) {
                    // 出错时回滚事务
                    try { @$db->exec('ROLLBACK'); } catch (\Exception $ignore) {}
                    $db->close();
                }
                error_log('[ajax] import_confirmed_spiders error: ' . $e->getMessage());
                jsonOut(500, ['error' => '批量导入失败，请稍后重试']);
            }
            break;
        
        case 'get_spider_ranges':
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = max(10, min(200, (int)($_GET['per_page'] ?? 50)));
            $search = trim($_GET['search'] ?? '');
            $filterType = trim($_GET['filter_type'] ?? '');
            $filterSource = trim($_GET['filter_source'] ?? '');
            $checker = new SpiderChecker();
            jsonOut(200, $checker->getRangesPaginated($page, $perPage, $search, $filterType, $filterSource));
            break;
        
        case 'validate_baidu_ranges':
            $rangesText = trim(input('ranges', ''));
            $lines = preg_split('/[\r\n]+/', $rangesText);
            $lines = array_map('trim', $lines);
            $lines = array_filter($lines, fn($l) => $l !== '');
            
            if (empty($lines)) {
                jsonOut(400, ['error' => '请输入 IP 段']);
            }
            
            $results = [];
            $passed = 0;
            $failed = 0;
            $checker = new SpiderChecker();
            
            foreach ($lines as $cidr) {
                $rangeInfo = parseCidrForAdmin($cidr);
                if (!$rangeInfo) {
                    $results[] = ['cidr' => $cidr, 'status' => 'invalid', 'msg' => '无效格式'];
                    $failed++;
                    continue;
                }
                
                // 从 IP 段中间 50% 位置随机取 3 个 IP 作为样本验证
                $startNum = ip2long($rangeInfo['start']);
                $endNum = ip2long($rangeInfo['end']);
                if ($startNum === false || $endNum === false) {
                    $results[] = ['cidr' => $cidr, 'status' => 'invalid', 'msg' => '无法解析 IP'];
                    $failed++;
                    continue;
                }
                
                $rangeSize = $endNum - $startNum;
                if ($rangeSize <= 2) {
                    // 范围太小，取全部
                    $sampleNums = range($startNum, $endNum);
                } else {
                    // 中间 50% 范围：从 25% 到 75% 位置
                    $midStart = $startNum + (int)($rangeSize * 0.25);
                    $midEnd = $startNum + (int)($rangeSize * 0.75);
                    $sampleNums = [];
                    $attempts = 0;
                    while (count($sampleNums) < 3 && $attempts < 10) {
                        $num = random_int($midStart, $midEnd);
                        if (!in_array($num, $sampleNums)) {
                            $sampleNums[] = $num;
                        }
                        $attempts++;
                    }
                }
                
                // 逐一验证，全部通过才算通过
                $allPassed = true;
                $passCount = 0;
                $rdnsInfo = '';
                foreach ($sampleNums as $sampleNum) {
                    $sampleIP = long2ip($sampleNum);
                    $checkResult = $checker->check($sampleIP, '');
                    if ($checkResult['is_spider'] && stripos($checkResult['spider_type'] ?? '', 'Baidu') !== false) {
                        $passCount++;
                        if (empty($rdnsInfo)) $rdnsInfo = $checkResult['rdns_hostname'] ?? '';
                    } else {
                        $allPassed = false;
                    }
                }
                
                if ($allPassed) {
                    $results[] = ['cidr' => $cidr, 'status' => 'pass', 'msg' => "✅ 确认百度蜘蛛 ({$passCount}/" . count($sampleNums) . ')', 'rdns' => $rdnsInfo];
                    $passed++;
                    
                    // 将验证通过的 CIDR 段添加到 spider_ranges 表
                    try {
                        $impDb = new SQLite3(SQLITE_DB_PATH);
                        $impDb->enableExceptions(true);
                        $impStmt = $impDb->prepare("
                            INSERT OR IGNORE INTO spider_ranges 
                                (ip_range, cidr_notation, ip_start, ip_end, ip_start_num, ip_end_num, spider_type, source, confidence, is_active, created_at, updated_at)
                            VALUES (:range, :cidr, :start, :end, :startNum, :endNum, 'Baiduspider', 'builtin', 'verified', 1, datetime('now','localtime'), datetime('now','localtime'))
                        ");
                        $impStmt->bindValue(':range', $cidr, SQLITE3_TEXT);
                        $impStmt->bindValue(':cidr', $cidr, SQLITE3_TEXT);
                        $impStmt->bindValue(':start', $rangeInfo['start'], SQLITE3_TEXT);
                        $impStmt->bindValue(':end', $rangeInfo['end'], SQLITE3_TEXT);
                        $impStmt->bindValue(':startNum', $startNum, SQLITE3_INTEGER);
                        $impStmt->bindValue(':endNum', $endNum, SQLITE3_INTEGER);
                        $impStmt->execute();
                        $impDb->close();
                    } catch (\Exception $e) {
                        error_log('[ajax] validate_baidu_ranges import error: ' . $e->getMessage());
                    }
                } elseif ($passCount > 0) {
                    $results[] = ['cidr' => $cidr, 'status' => 'warn', 'msg' => "⚠️ 部分通过 ({$passCount}/" . count($sampleNums) . ')', 'rdns' => $rdnsInfo];
                    $failed++;
                } else {
                    $results[] = ['cidr' => $cidr, 'status' => 'fail', 'msg' => '❌ 未通过验证', 'rdns' => $rdnsInfo];
                    $failed++;
                }
            }
            
            jsonOut(200, [
                'total' => count($lines),
                'passed' => $passed,
                'failed' => $failed,
                'results' => $results,
            ]);
            break;
        
        case 'add_spider_range':
            $cidrRaw = trim(input('cidr', ''));
            $spiderType = trim(input('spider_type', 'Baiduspider'));
            $source = trim(input('source', 'manual'));
            
            if (empty($cidrRaw)) {
                jsonOut(400, ['error' => 'CIDR 不能为空']);
            }
            
            // 按行分割，支持批量添加
            $lines = preg_split('/[\r\n]+/', $cidrRaw);
            $lines = array_map('trim', $lines);
            $lines = array_filter($lines, fn($l) => $l !== '');
            
            if (empty($lines)) {
                jsonOut(400, ['error' => '请输入有效的 CIDR 或 IP']);
            }
            
            $db = new SQLite3(SQLITE_DB_PATH);
            $db->enableExceptions(true);
            
            $added = 0;
            $skipped = 0;
            $errors = [];
            
            try {
                $stmt = $db->prepare("
                    INSERT OR IGNORE INTO spider_ranges (ip_range, cidr_notation, ip_start, ip_end, ip_start_num, ip_end_num, spider_type, source, confidence, is_active, created_at, updated_at)
                    VALUES (:range, :cidr, :start, :end, :startNum, :endNum, :type, :source, '', 1, datetime('now','localtime'), datetime('now','localtime'))
                ");
                
                foreach ($lines as $cidr) {
                    $rangeInfo = parseCidrForAdmin($cidr);
                    if (!$rangeInfo) {
                        $errors[] = "无效格式: {$cidr}";
                        continue;
                    }
                    
                    $stmt->bindValue(':range', $cidr, SQLITE3_TEXT);
                    $stmt->bindValue(':cidr', $cidr, SQLITE3_TEXT);
                    $stmt->bindValue(':start', $rangeInfo['start'], SQLITE3_TEXT);
                    $stmt->bindValue(':end', $rangeInfo['end'], SQLITE3_TEXT);
                    $stmt->bindValue(':startNum', ip2long($rangeInfo['start']) ?: 0, SQLITE3_INTEGER);
                    $stmt->bindValue(':endNum', ip2long($rangeInfo['end']) ?: 0, SQLITE3_INTEGER);
                    $stmt->bindValue(':type', $spiderType, SQLITE3_TEXT);
                    $stmt->bindValue(':source', $source, SQLITE3_TEXT);
                    $stmt->execute();
                    
                    if ($db->changes() > 0) {
                        $added++;
                    } else {
                        $skipped++;
                    }
                    $stmt->reset();
                }
                
                $msg = "添加完成：新增 {$added} 条";
                if ($skipped > 0) $msg .= "，跳过 {$skipped} 条（已存在）";
                if (!empty($errors)) $msg .= "，" . count($errors) . " 条格式无效";
                
                jsonOut(200, ['success' => true, 'message' => $msg, 'added' => $added, 'skipped' => $skipped, 'errors' => $errors]);
            } catch (Exception $e) {
                error_log('[ajax] add_spider_range error: ' . $e->getMessage());
                jsonOut(500, ['error' => '添加失败，请稍后重试']);
            } finally {
                $db->close();
            }
            break;
        
        case 'toggle_spider_range':
            $rangeId = (int)(input('id', 0));
            $active = (int)(input('active', 1));
            
            if ($rangeId <= 0) {
                jsonOut(400, ['error' => '无效的ID']);
            }
            
            $db = new SQLite3(SQLITE_DB_PATH);
            $db->enableExceptions(true);
            $stmt = $db->prepare("UPDATE spider_ranges SET is_active = :active WHERE id = :id");
            $stmt->bindValue(':active', $active, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $rangeId, SQLITE3_INTEGER);
            $stmt->execute();
            $db->close();
            
            jsonOut(200, ['success' => true, 'message' => $active ? '已启用' : '已禁用']);
            break;
        
        case 'delete_spider_range':
            $rangeId = (int)(input('id', 0));
            
            if ($rangeId <= 0) {
                jsonOut(400, ['error' => '无效的ID']);
            }
            
            $db = new SQLite3(SQLITE_DB_PATH);
            $db->enableExceptions(true);
            $stmt = $db->prepare("DELETE FROM spider_ranges WHERE id = :id");
            $stmt->bindValue(':id', $rangeId, SQLITE3_INTEGER);
            $stmt->execute();
            $deleted = $db->changes();
            $db->close();
            
            if ($deleted > 0) {
                jsonOut(200, ['success' => true, 'message' => 'IP段已删除']);
            } else {
                jsonOut(404, ['error' => 'IP段不存在']);
            }
            break;
        
        case 'batch_delete_spider_ranges':
            $ids = input('ids', []);
            if (!is_array($ids) || empty($ids)) {
                jsonOut(400, ['error' => '请选择要删除的 IP 段']);
            }
            
            // 过滤并转换为整数
            $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
            if (empty($ids)) {
                jsonOut(400, ['error' => '无效的 ID 列表']);
            }
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            $db = new SQLite3(SQLITE_DB_PATH);
            $db->enableExceptions(true);
            $stmt = $db->prepare("DELETE FROM spider_ranges WHERE id IN ({$placeholders})");
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
            }
            $stmt->execute();
            $deleted = $db->changes();
            $db->close();
            
            jsonOut(200, ['success' => true, 'message' => "已删除 {$deleted} 条 IP 段"]);
            break;
        
        // ========== 查询日志操作 ==========
        case 'get_logs':
            $page = (int)($_GET['page'] ?? 1);
            $perPage = (int)($_GET['per_page'] ?? 50);
            $filter = $_GET['filter'] ?? '';
            jsonOut(200, $settings->getQueryLogs($page, $perPage, $filter));
            break;
        
        case 'clear_logs':
            $deleted = 0;
            try {
                $db = new SQLite3(SQLITE_DB_PATH);
                $db->enableExceptions(true);
                $db->exec("DELETE FROM query_stats");
                $deleted = $db->changes();
                $db->close();
            } catch (Exception $e) {
                error_log('[ajax] clear_logs error: ' . $e->getMessage());
                jsonOut(500, ['error' => '清空失败，请稍后重试']);
            }
            jsonOut(200, ['success' => true, 'message' => "已清空 {$deleted} 条记录"]);
            break;
        
        // ========== 数据清理 ==========
        case 'clean_old_data':
            $result = $settings->cleanOldData();
            jsonOut(200, $result);
            break;
        
        // ========== API统计 ==========
        case 'get_api_stats':
            $days = (int)($_GET['days'] ?? 30);
            jsonOut(200, ['stats' => $settings->getApiStats($days)]);
            break;
        
        // ========== 修改密码 ==========
        case 'change_password':
            $oldPassword = input('old_password', '');
            $newPassword = input('new_password', '');
            
            if (empty($oldPassword) || empty($newPassword)) {
                jsonOut(400, ['error' => '请输入原密码和新密码']);
            }
            if (strlen($newPassword) < 6) {
                jsonOut(400, ['error' => '新密码长度不能少于6位']);
            }
            
            $username = $_SESSION['admin_username'] ?? '';
            $result = $auth->changePassword($username, $oldPassword, $newPassword);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        // ========== 获取统计摘要 ==========
        case 'get_stats_summary':
            $checker = new SpiderChecker();
            $stats = $checker->getStats();
            jsonOut(200, $stats);
            break;
        
        // ========== Google 数据源管理 ==========
        case 'get_google_sources':
            jsonOut(200, ['sources' => $settings->getGoogleDataSources()]);
            break;
        
        case 'add_google_source':
            $key = trim(input('source_key', ''));
            $name = trim(input('source_name', ''));
            $url = trim(input('endpoint_url', ''));
            $result = $settings->addGoogleDataSource($key, $name, $url);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'update_google_source':
            $id = (int)input('id', 0);
            $name = trim(input('source_name', ''));
            $url = trim(input('endpoint_url', ''));
            $active = (int)input('is_active', 1);
            $result = $settings->updateGoogleDataSource($id, $name, $url, $active);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'delete_google_source':
            $id = (int)input('id', 0);
            $result = $settings->deleteGoogleDataSource($id);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'toggle_google_source':
            $id = (int)input('id', 0);
            $active = (int)input('active', 1);
            $result = $settings->toggleGoogleDataSource($id, $active);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        // ========== 自定义反向DNS规则管理 ==========
        case 'get_rdns_rules':
            jsonOut(200, ['rules' => $settings->getCustomRdnsRules()]);
            break;
        
        case 'add_rdns_rule':
            $pattern = trim(input('hostname_pattern', ''));
            $spiderType = trim(input('spider_type', ''));
            $matchType = trim(input('match_type', 'contains'));
            $result = $settings->addCustomRdnsRule($pattern, $spiderType, $matchType);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'update_rdns_rule':
            $id = (int)input('id', 0);
            $pattern = trim(input('hostname_pattern', ''));
            $spiderType = trim(input('spider_type', ''));
            $matchType = trim(input('match_type', 'contains'));
            $isActive = (int)input('is_active', 1);
            $result = $settings->updateCustomRdnsRule($id, $pattern, $spiderType, $matchType, $isActive);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'delete_rdns_rule':
            $id = (int)input('id', 0);
            $result = $settings->deleteCustomRdnsRule($id);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'toggle_rdns_rule':
            $id = (int)input('id', 0);
            $active = (int)input('active', 1);
            $result = $settings->toggleCustomRdnsRule($id, $active);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        // ========== 自定义 User-Agent 规则管理 ==========
        case 'get_ua_rules':
            jsonOut(200, ['rules' => $settings->getCustomUaRules()]);
            break;
        
        case 'add_ua_rule':
            $keyword = trim(input('ua_keyword', ''));
            $spiderType = trim(input('spider_type', ''));
            $matchType = trim(input('match_type', 'contains'));
            $result = $settings->addCustomUaRule($keyword, $spiderType, $matchType);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'update_ua_rule':
            $id = (int)input('id', 0);
            $keyword = trim(input('ua_keyword', ''));
            $spiderType = trim(input('spider_type', ''));
            $matchType = trim(input('match_type', 'contains'));
            $isActive = (int)input('is_active', 1);
            $result = $settings->updateCustomUaRule($id, $keyword, $spiderType, $matchType, $isActive);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'delete_ua_rule':
            $id = (int)input('id', 0);
            $result = $settings->deleteCustomUaRule($id);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'toggle_ua_rule':
            $id = (int)input('id', 0);
            $active = (int)input('active', 1);
            $result = $settings->toggleCustomUaRule($id, $active);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        // ========== 批量操作：反向DNS规则 ==========
        case 'batch_add_rdns_rules':
            $rules = input('rules', []);
            if (!is_array($rules) || empty($rules)) {
                jsonOut(400, ['error' => '规则列表为空']);
            }
            $result = $settings->batchAddCustomRdnsRules($rules);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'batch_delete_rdns_rules':
            $ids = input('ids', []);
            $result = $settings->batchDeleteCustomRdnsRules($ids);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'batch_add_ua_rules':
            $rules = input('rules', []);
            if (!is_array($rules) || empty($rules)) {
                jsonOut(400, ['error' => '规则列表为空']);
            }
            $result = $settings->batchAddCustomUaRules($rules);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'batch_delete_ua_rules':
            $ids = input('ids', []);
            $result = $settings->batchDeleteCustomUaRules($ids);
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'reset_rdns_rules':
            $result = $settings->resetDefaultRdnsRules();
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        case 'reset_ua_rules':
            $result = $settings->resetDefaultUaRules();
            jsonOut($result['success'] ? 200 : 400, $result);
            break;
        
        default:
            jsonOut(400, [
                'error' => '未知操作',
                'available_actions' => [
                    'dashboard', 'get_settings', 'save_settings',
                    'trigger_update', 'get_update_records', 'clear_update_records',
                    'get_confirmed_spiders', 'import_confirmed_spider',
                    'import_confirmed_spiders', 'get_spider_ranges', 'add_spider_range', 'toggle_spider_range', 'delete_spider_range',
                    'get_logs', 'clear_logs', 'clean_old_data',
                    'get_api_stats', 'change_password', 'get_stats_summary',
                    'get_google_sources', 'add_google_source', 'update_google_source', 'delete_google_source', 'toggle_google_source',
                    'get_rdns_rules', 'add_rdns_rule', 'update_rdns_rule', 'delete_rdns_rule', 'toggle_rdns_rule',
                    'get_ua_rules', 'add_ua_rule', 'update_ua_rule', 'delete_ua_rule', 'toggle_ua_rule',
                    'batch_add_rdns_rules', 'batch_delete_rdns_rules', 'batch_add_ua_rules', 'batch_delete_ua_rules',
                    'reset_rdns_rules', 'reset_ua_rules',
                ],
            ]);
    }
} catch (Exception $e) {
    error_log('[ajax] Unhandled error: ' . $e->getMessage());
    jsonOut(500, ['error' => '服务器内部错误，请稍后重试']);
}

// ============================================
// 辅助函数
// ============================================

function jsonOut(int $code, array $data): void
{
    http_response_code($code);
    $flags = JSON_UNESCAPED_UNICODE;
    // 仅本地请求输出格式化 JSON
    if (($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1') {
        $flags |= JSON_PRETTY_PRINT;
    }
    echo json_encode($data, $flags);
    exit;
}

function parseCidrForAdmin(string $cidr): ?array
{
    if (strpos($cidr, '/') === false) {
        return ['start' => $cidr, 'end' => $cidr];
    }
    
    list($subnet, $bits) = explode('/', $cidr);
    $bits = (int)$bits;
    $ipLong = ip2long($subnet);
    
    if ($ipLong === false || $bits < 0 || $bits > 32) {
        return null;
    }
    
    $mask = -1 << (32 - $bits);
    $network = $ipLong & $mask;
    $broadcast = $network | (~$mask & 0xFFFFFFFF);
    
    return [
        'start' => long2ip($network),
        'end'   => long2ip($broadcast),
    ];
}

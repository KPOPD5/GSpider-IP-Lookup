<?php
/**
 * 后台设置管理类
 * 提供动态配置的读写、系统状态获取等功能
 * 
 * 2026 现代方案：数据库驱动配置，支持实时生效
 */

require_once __DIR__ . '/config.php';

class AdminSettings
{
    private SQLite3 $db;
    private array $cache = [];
    private bool $cacheLoaded = false;
    
    public function __construct()
    {
        if (!file_exists(SQLITE_DB_PATH)) {
            require_once __DIR__ . '/init_db.php';
        }
        $this->db = new SQLite3(SQLITE_DB_PATH);
        $this->db->enableExceptions(true);
        $this->ensureSettingsTable();
    }
    
    /**
     * 确保 admin_settings 表存在（兼容旧数据库升级）
     */
    private function ensureSettingsTable(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS admin_settings (
                    setting_key TEXT PRIMARY KEY,
                    setting_value TEXT,
                    setting_type TEXT DEFAULT 'string',
                    description TEXT,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } catch (\Exception $e) {
            // 表可能已存在
        }
    }
    
    /**
     * 加载所有设置到缓存
     */
    private function loadCache(): void
    {
        if ($this->cacheLoaded) return;
        
        try {
            $result = $this->db->query("SELECT setting_key, setting_value, setting_type FROM admin_settings");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $this->cache[$row['setting_key']] = $this->castValue($row['setting_value'], $row['setting_type']);
            }
        } catch (\Exception $e) {
            // 表可能还不存在
        }
        $this->cacheLoaded = true;
    }
    
    /**
     * 类型转换
     */
    private function castValue(string $value, string $type): mixed
    {
        switch ($type) {
            case 'bool':  return (bool)(int)$value;
            case 'int':   return (int)$value;
            case 'float': return (float)$value;
            case 'json':  return json_decode($value, true) ?? $value;
            default:      return $value;
        }
    }
    
    /**
     * 获取单个设置
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->loadCache();
        
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        
        // 回退：直接查数据库
        try {
            $stmt = $this->db->prepare("SELECT setting_value, setting_type FROM admin_settings WHERE setting_key = :key LIMIT 1");
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($row) {
                $value = $this->castValue($row['setting_value'], $row['setting_type']);
                $this->cache[$key] = $value;
                return $value;
            }
        } catch (\Exception $e) {}
        
        return $default;
    }
    
    /**
     * 设置单个配置
     */
    public function set(string $key, mixed $value, string $type = 'string'): bool
    {
        switch ($type) {
            case 'bool': $strValue = $value ? '1' : '0'; break;
            case 'json': $strValue = json_encode($value, JSON_UNESCAPED_UNICODE); break;
            default:     $strValue = (string)$value; break;
        }
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO admin_settings (setting_key, setting_value, setting_type, updated_at)
                VALUES (:key, :val, :type, datetime('now', 'localtime'))
                ON CONFLICT(setting_key) DO UPDATE SET 
                    setting_value = :val2, 
                    setting_type = :type2,
                    updated_at = datetime('now', 'localtime')
            ");
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $stmt->bindValue(':val', $strValue, SQLITE3_TEXT);
            $stmt->bindValue(':type', $type, SQLITE3_TEXT);
            $stmt->bindValue(':val2', $strValue, SQLITE3_TEXT);
            $stmt->bindValue(':type2', $type, SQLITE3_TEXT);
            $stmt->execute();
            
            // 更新缓存
            $this->cache[$key] = $this->castValue($strValue, $type);
            return true;
        } catch (\Exception $e) {
            error_log("AdminSettings::set() error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 批量更新设置
     * @param array $settings ['key' => ['value' => ..., 'type' => ...], ...]
     */
    public function setMultiple(array $settings): array
    {
        $results = [];
        foreach ($settings as $key => $config) {
            if (is_array($config) && isset($config['value'])) {
                $results[$key] = $this->set($key, $config['value'], $config['type'] ?? 'string');
            } else {
                $results[$key] = $this->set($key, $config);
            }
        }
        return $results;
    }
    
    /**
     * 获取所有设置（用于后台管理页面展示）
     */
    public function getAll(): array
    {
        $this->loadCache();
        $allSettings = [];
        
        try {
            $result = $this->db->query("SELECT setting_key, setting_value, setting_type, description FROM admin_settings ORDER BY setting_key");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $allSettings[] = [
                    'key' => $row['setting_key'],
                    'value' => $this->castValue($row['setting_value'], $row['setting_type']),
                    'type' => $row['setting_type'],
                    'description' => $row['description'],
                ];
            }
        } catch (\Exception $e) {}
        
        return $allSettings;
    }
    
    /**
     * 获取系统仪表盘数据
     */
    public function getDashboardData(): array
    {
        try {
            // 基础统计
            $totalRanges = (int)$this->db->querySingle("SELECT COUNT(*) FROM spider_ranges WHERE is_active = 1");
            $totalQueries = (int)$this->db->querySingle("SELECT COUNT(*) FROM query_stats");
            $spiderQueries = (int)$this->db->querySingle("SELECT COUNT(*) FROM query_stats WHERE is_spider = 1");
            $confirmedSpiders = 0;
            try {
                $confirmedSpiders = (int)$this->db->querySingle("SELECT COUNT(*) FROM confirmed_spiders");
            } catch (\Exception $e) {}
            
            // 今日统计（单次查询合并计数，避免多次查询）
            $todayStmt = $this->db->prepare(
                "SELECT COUNT(*) AS total, COALESCE(SUM(is_spider), 0) AS spider_count FROM query_stats WHERE query_time >= :today"
            );
            $todayStmt->bindValue(':today', date('Y-m-d 00:00:00'), SQLITE3_TEXT);
            $todayRow = $todayStmt->execute()->fetchArray(SQLITE3_ASSOC);
            $todayQueries = (int)$todayRow['total'];
            $todaySpiders = (int)$todayRow['spider_count'];
            
            // 最近更新
            $lastUpdate = $this->db->querySingle("SELECT MAX(created_at) FROM update_records WHERE status = 'success'");
            
            // GeoIP 状态
            $geoipExists = file_exists(GEOIP_DB_PATH);
            $geoipSize = $geoipExists ? round(filesize(GEOIP_DB_PATH) / 1024 / 1024, 2) : 0;
            $geoipDate = $geoipExists ? date('Y-m-d', filemtime(GEOIP_DB_PATH)) : 'N/A';
            
            // API 密钥数量
            $apiKeysCount = 0;
            try {
                $apiKeysCount = (int)$this->db->querySingle("SELECT COUNT(*) FROM api_keys WHERE is_active = 1");
            } catch (\Exception $e) {}
            
            // 30天 API 调用量
            $apiCalls30d = 0;
            try {
                $apiCalls30d = (int)$this->db->querySingle("SELECT COUNT(*) FROM api_call_logs WHERE created_at >= datetime('now', 'localtime', '-30 days')");
            } catch (\Exception $e) {}
            
            // 系统信息
            $phpVersion = PHP_VERSION;
            $dbSize = file_exists(SQLITE_DB_PATH) ? round(filesize(SQLITE_DB_PATH) / 1024, 2) : 0;
            
            // DB-IP Lite 状态
            $dbipExists = defined('DBIP_DB_PATH') && file_exists(DBIP_DB_PATH);
            $dbipSize = $dbipExists ? round(filesize(DBIP_DB_PATH) / 1024 / 1024, 2) : 0;
            $dbipDate = $dbipExists ? date('Y-m-d', filemtime(DBIP_DB_PATH)) : 'N/A';
            
            // IP2Location LITE 状态
            $ip2lPath = __DIR__ . '/geoip/IP2Location-LITE-DB11.BIN';
            $ip2lExists = file_exists($ip2lPath);
            $ip2lSize = $ip2lExists ? round(filesize($ip2lPath) / 1024 / 1024, 2) : 0;
            $ip2lDate = $ip2lExists ? date('Y-m-d', filemtime($ip2lPath)) : 'N/A';
            
            // Google 蜘蛛缓存状态
            $googleCacheFile = __DIR__ . '/db/google_spider_cache.json';
            $googleCacheExists = file_exists($googleCacheFile);
            $googleCacheCount = 0;
            if ($googleCacheExists) {
                $cacheData = json_decode(file_get_contents($googleCacheFile), true);
                $googleCacheCount = is_array($cacheData) ? count($cacheData) : 0;
            }
            
            // rDNS 规则数
            $rdnsRuleCount = 0;
            try {
                $rdnsRuleCount = (int)$this->db->querySingle("SELECT COUNT(*) FROM custom_rdns_rules WHERE is_active = 1");
            } catch (\Exception $e) {}
            
            // UA 规则数
            $uaRuleCount = 0;
            try {
                $uaRuleCount = (int)$this->db->querySingle("SELECT COUNT(*) FROM custom_ua_rules WHERE is_active = 1");
            } catch (\Exception $e) {}
            
            return [
                'total_ranges' => $totalRanges,
                'total_queries' => $totalQueries,
                'spider_queries' => $spiderQueries,
                'confirmed_spiders' => $confirmedSpiders,
                'today_queries' => $todayQueries,
                'today_spiders' => $todaySpiders,
                'last_update' => $lastUpdate ?: '从未更新',
                'geoip_exists' => $geoipExists,
                'geoip_size_mb' => $geoipSize,
                'geoip_date' => $geoipDate,
                'dbip_exists' => $dbipExists,
                'dbip_size_mb' => $dbipSize,
                'dbip_date' => $dbipDate,
                'ip2l_exists' => $ip2lExists,
                'ip2l_size_mb' => $ip2lSize,
                'ip2l_date' => $ip2lDate,
                'google_cache_exists' => $googleCacheExists,
                'google_cache_count' => $googleCacheCount,
                'rdns_rule_count' => $rdnsRuleCount,
                'ua_rule_count' => $uaRuleCount,
                'api_keys_count' => $apiKeysCount,
                'api_calls_30d' => $apiCalls30d,
                'php_version' => $phpVersion,
                'db_size_kb' => $dbSize,
                'maintenance_mode' => $this->get('maintenance_mode', false),
                'auto_update_enabled' => $this->get('auto_update_enabled', true),
                'enable_spider_check' => $this->get('enable_spider_check', true),
                'enable_rdns_check' => $this->get('enable_rdns_check', true),
                'enable_geolocation' => $this->get('enable_geolocation', true),
                'enable_api' => $this->get('enable_api', true),
            ];
        } catch (\Exception $e) {
            error_log('[AdminSettings] getDashboardData error: ' . $e->getMessage());
            return ['error' => '数据获取失败，请稍后重试'];
        }
    }
    
    /**
     * 获取最近 N 天的日统计（用于图表）
     * @return array ['labels' => [...], 'total' => [...], 'spider' => [...]]
     */
    public function getDailyStats(int $days = 7): array
    {
        $labels = [];
        $totalData = [];
        $spiderData = [];
        
        try {
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $labels[] = date('m/d', strtotime("-{$i} days"));
                
                $stmt = $this->db->prepare(
                    "SELECT COUNT(*) AS total, COALESCE(SUM(is_spider), 0) AS spider
                     FROM query_stats
                     WHERE query_time >= :start AND query_time < :end"
                );
                $stmt->bindValue(':start', $date . ' 00:00:00', SQLITE3_TEXT);
                $stmt->bindValue(':end', date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00', SQLITE3_TEXT);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                $totalData[] = (int)($row['total'] ?? 0);
                $spiderData[] = (int)($row['spider'] ?? 0);
            }
        } catch (\Exception $e) {}
        
        return [
            'labels' => $labels,
            'total' => $totalData,
            'spider' => $spiderData,
        ];
    }
    
    /**
     * 获取查询日志（按 IP 聚合，分页）
     */
    public function getQueryLogs(int $page = 1, int $perPage = 50, ?string $filter = null): array
    {
        $offset = ($page - 1) * $perPage;
        
        try {
            $where = '';
            $having = '';
            $params = [];
            
            if ($filter === 'spider') {
                $having = "HAVING MAX(is_spider) = 1";
            } elseif ($filter === 'visitor') {
                $having = "HAVING MAX(is_spider) = 0";
            } elseif ($filter) {
                // IP 搜索
                $where = "WHERE ip_address LIKE :filter";
                $params[':filter'] = '%' . addcslashes($this->db->escapeString($filter), '%_') . '%';
            }
            
            // 按 IP 聚合查询
            $countSql = "
                SELECT COUNT(*) FROM (
                    SELECT ip_address FROM query_stats {$where}
                    GROUP BY ip_address {$having}
                )
            ";
            $totalStmt = $this->db->prepare($countSql);
            foreach ($params as $key => $val) {
                $totalStmt->bindValue($key, $val, SQLITE3_TEXT);
            }
            $total = (int)$totalStmt->execute()->fetchArray(SQLITE3_NUM)[0];
            
            $logs = [];
            $logSql = "
                SELECT 
                    qs.ip_address,
                    COUNT(*) AS query_count,
                    MIN(qs.query_time) AS first_query_time,
                    MAX(qs.query_time) AS last_query_time,
                    MAX(qs.is_spider) AS is_spider,
                    cs.spider_type,
                    cs.match_method
                FROM query_stats qs
                LEFT JOIN confirmed_spiders cs ON qs.ip_address = cs.ip_address
                {$where}
                GROUP BY qs.ip_address {$having}
                ORDER BY last_query_time DESC
                LIMIT :limit OFFSET :offset
            ";
            $logStmt = $this->db->prepare($logSql);
            foreach ($params as $key => $val) {
                $logStmt->bindValue($key, $val, SQLITE3_TEXT);
            }
            $logStmt->bindValue(':limit', $perPage, SQLITE3_INTEGER);
            $logStmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
            $result = $logStmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $logs[] = $row;
            }
            
            return [
                'logs' => $logs,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage),
            ];
        } catch (\Exception $e) {
            error_log('[AdminSettings] getQueryLogs error: ' . $e->getMessage());
            return ['logs' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0, 'error' => '日志查询失败，请稍后重试'];
        }
    }
    
    /**
     * 获取更新记录
     */
    public function getUpdateRecords(int $limit = 20): array
    {
        $records = [];
        try {
            $stmt = $this->db->prepare("SELECT * FROM update_records ORDER BY created_at DESC LIMIT :limit");
            $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $records[] = $row;
            }
        } catch (\Exception $e) {}
        return $records;
    }
    
    /**
     * 获取确认的蜘蛛列表
     */
    public function getConfirmedSpiders(int $limit = 100, string $search = '', string $filterType = '', string $filterConf = ''): array
    {
        $spiders = [];
        try {
            $where = '';
            $params = [];
            
            if ($search !== '') {
                $where .= " AND ip_address LIKE :search";
                $params[':search'] = '%' . addcslashes($this->db->escapeString($search), '%_') . '%';
            }
            if ($filterType !== '') {
                $where .= " AND spider_type = :type";
                $params[':type'] = $filterType;
            }
            if ($filterConf !== '') {
                $where .= " AND confidence = :conf";
                $params[':conf'] = $filterConf;
            }
            
            $sql = "SELECT * FROM confirmed_spiders WHERE 1=1 {$where} ORDER BY last_seen DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, SQLITE3_TEXT);
            }
            $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $spiders[] = $row;
            }
        } catch (\Exception $e) {
            error_log('[AdminSettings] getConfirmedSpiders error: ' . $e->getMessage());
        }
        return $spiders;
    }
    
    /**
     * 清理过期数据
     */
    public function cleanOldData(): array
    {
        $retentionDays = (int)$this->get('query_log_retention_days', 90);
        try {
            $stmt = $this->db->prepare("DELETE FROM query_stats WHERE query_time < datetime('now', 'localtime', :days)");
            $stmt->bindValue(':days', "-{$retentionDays} days", SQLITE3_TEXT);
            $stmt->execute();
            $deleted = $this->db->changes();
            
            $stmt2 = $this->db->prepare("DELETE FROM api_call_logs WHERE created_at < datetime('now', 'localtime', :days)");
            $stmt2->bindValue(':days', "-{$retentionDays} days", SQLITE3_TEXT);
            $stmt2->execute();
            
            return ['success' => true, 'changes' => $deleted + $this->db->changes()];
        } catch (\Exception $e) {
            error_log('[AdminSettings] cleanOldData error: ' . $e->getMessage());
            return ['success' => false, 'error' => '数据清理失败，请稍后重试'];
        }
    }
    
    /**
     * 获取API调用统计（单次 GROUP BY 查询，避免 N+1）
     */
    public function getApiStats(int $days = 30): array
    {
        try {
            $stats = [];
            $stmt = $this->db->prepare("
                SELECT date(created_at) as d, COUNT(*) as cnt 
                FROM api_call_logs 
                WHERE created_at >= datetime('now', 'localtime', :days)
                GROUP BY d 
                ORDER BY d ASC
            ");
            $stmt->bindValue(':days', "-{$days} days", SQLITE3_TEXT);
            $result = $stmt->execute();
            
            // 初始化所有日期为 0，再填充有数据的日期
            for ($i = $days - 1; $i >= 0; $i--) {
                $stats[date('Y-m-d', strtotime("-{$i} days"))] = 0;
            }
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $stats[$row['d']] = (int)$row['cnt'];
            }
            return $stats;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 记录 API 调用
     */
    public function logApiCall(string $apiName, string $endpoint, string $callerIP, string $userAgent, int $responseCode, float $executionTime): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO api_call_logs (api_name, endpoint, caller_ip, user_agent, response_code, execution_time_ms, created_at)
                VALUES (:name, :endpoint, :ip, :ua, :code, :time, datetime('now', 'localtime'))
            ");
            $stmt->bindValue(':name', $apiName, SQLITE3_TEXT);
            $stmt->bindValue(':endpoint', $endpoint, SQLITE3_TEXT);
            $stmt->bindValue(':ip', $callerIP, SQLITE3_TEXT);
            $stmt->bindValue(':ua', $userAgent, SQLITE3_TEXT);
            $stmt->bindValue(':code', $responseCode, SQLITE3_INTEGER);
            $stmt->bindValue(':time', $executionTime, SQLITE3_FLOAT);
            $stmt->execute();
        } catch (\Exception $e) {}
    }
    
    /**
     * 检测 Web 服务器类型
     */
    private function detectWebServer(): string
    {
        $server = $_SERVER['SERVER_SOFTWARE'] ?? '';
        if (stripos($server, 'nginx') !== false) return 'nginx';
        if (stripos($server, 'apache') !== false) return 'apache';
        if (stripos($server, 'litespeed') !== false) return 'litespeed';
        // litespeed 兼容 .htaccess，当作 apache 处理
        return 'apache'; // 默认假设 Apache（最常见）
    }
    
    /**
     * 验证 admin_path 合法性
     */
    private function validateAdminPath(string $adminPath): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $adminPath)) {
            return '后台路径包含非法字符，仅允许字母、数字、下划线和连字符';
        }
        
        $reservedPaths = ['api', 'assets', 'db', 'geoip', 'vendor', 'index', 'confirmed', 'google', 'admin_router'];
        if (in_array(strtolower($adminPath), $reservedPaths)) {
            return '该路径为系统保留路径，请使用其他名称';
        }
        
        return null; // 验证通过
    }
    
    /**
     * 生成服务器 URL 重写配置
     * Apache: 生成 .htaccess
     * Nginx:  生成配置片段文件 nginx-admin-route.conf
     * 
     * @param string $adminPath 自定义后台路径
     * @return array ['success' => bool, 'message' => string, 'server_type' => string, 'config_content' => string|null]
     */
    public function generateServerConfig(string $adminPath): array
    {
        $error = $this->validateAdminPath($adminPath);
        if ($error !== null) {
            return ['success' => false, 'message' => $error, 'server_type' => '', 'config_content' => null];
        }
        
        $serverType = $this->detectWebServer();
        $projectRoot = __DIR__;
        $adminDir = 'admin'; // 物理后台目录名（固定值）
        
        // 恢复默认路径 — 清理所有生成的文件
        if ($adminPath === 'admin') {
            $cleaned = [];
            // 删除 .htaccess
            $htaccessPath = $projectRoot . '/.htaccess';
            if (file_exists($htaccessPath) && @unlink($htaccessPath)) {
                $cleaned[] = '.htaccess';
            }
            // 删除 nginx 配置片段
            $nginxConfPath = $projectRoot . '/nginx-admin-route.conf';
            if (file_exists($nginxConfPath) && @unlink($nginxConfPath)) {
                $cleaned[] = 'nginx-admin-route.conf';
            }
            // 删除恢复文件
            $recoveryPath = $projectRoot . '/.admin_path';
            if (file_exists($recoveryPath) && @unlink($recoveryPath)) {
                $cleaned[] = '.admin_path';
            }
            // 删除旧的旧版自定义路径目录（如果存在）
            $oldCustomDir = $projectRoot . '/' . $this->get('admin_path', 'admin');
            if ($oldCustomDir !== 'admin' && is_dir($oldCustomDir)) {
                $this->removeDir($oldCustomDir);
                $cleaned[] = $oldCustomDir . '/';
            }
            
            $msg = '已恢复默认路径，后台通过 /admin/ 访问';
            if (!empty($cleaned)) {
                $msg .= '（已清理: ' . implode(', ', $cleaned) . '）';
            }
            return ['success' => true, 'message' => $msg, 'server_type' => $serverType, 'config_content' => null];
        }
        
        // 创建自定义路径目录（物理目录，无需服务器配置即可工作）
        $this->createCustomPathDir($adminPath, $adminDir);
        
        // 保存恢复文件（忘记路径时可查看此文件）
        $this->saveRecoveryFile($adminPath);
        
        // 根据服务器类型生成对应配置
        if ($serverType === 'nginx') {
            return $this->generateNginxConfig($adminPath, $adminDir);
        } else {
            return $this->generateApacheConfig($adminPath, $adminDir);
        }
    }
    
    /**
     * 生成 Apache .htaccess 配置
     */
    private function generateApacheConfig(string $adminPath, string $adminDir): array
    {
        $htaccessPath = __DIR__ . '/.htaccess';
        
        $htaccessContent = "# ============================================\n";
        $htaccessContent .= "# 蜘蛛识别查询系统 - URL 重写规则 (Apache)\n";
        $htaccessContent .= "# 自动生成于: " . date('Y-m-d H:i:s') . "\n";
        $htaccessContent .= "# 请勿手动修改此文件\n";
        $htaccessContent .= "# ============================================\n\n";
        $htaccessContent .= "<IfModule mod_rewrite.c>\n";
        $htaccessContent .= "    RewriteEngine On\n\n";
        $htaccessContent .= "    # 自定义后台路径 → 物理 {$adminDir}/ 目录（内部重写）\n";
        $htaccessContent .= "    RewriteRule ^{$adminPath}($|/) {$adminDir}/\$1 [L,QSA,E=ADMIN_ROUTED:1]\n\n";
        $htaccessContent .= "    # 禁止直接通过 /{$adminDir}/ 访问（已设置自定义路径后生效）\n";
        $htaccessContent .= "    RewriteRule ^{$adminDir}($|/) - [R=404,L]\n";
        $htaccessContent .= "</IfModule>\n\n";
        $htaccessContent .= "# 防止目录列表\n";
        $htaccessContent .= "Options -Indexes\n";
        
        $result = @file_put_contents($htaccessPath, $htaccessContent);
        if ($result === false) {
            return ['success' => false, 'message' => '无法写入 .htaccess 文件，请检查根目录写入权限', 'server_type' => 'apache', 'config_content' => $htaccessContent];
        }
        
        return ['success' => true, 'message' => "后台访问路径已更新为 /{$adminPath}/（原 /{$adminDir}/ 已返回 404）", 'server_type' => 'apache', 'config_content' => null];
    }
    
    /**
     * 生成 Nginx 配置片段
     */
    private function generateNginxConfig(string $adminPath, string $adminDir): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', __DIR__), '/');
        $confPath = __DIR__ . '/nginx-admin-route.conf';
        
        $nginxConfig = "# ============================================\n";
        $nginxConfig .= "# 蜘蛛识别查询系统 - Nginx URL 重写配置\n";
        $nginxConfig .= "# 自动生成于: " . date('Y-m-d H:i:s') . "\n";
        $nginxConfig .= "# \n";
        $nginxConfig .= "# 使用方法：将以下 location 块添加到 nginx 配置的 server { } 块中\n";
        $nginxConfig .= "# 然后执行: nginx -t && nginx -s reload\n";
        $nginxConfig .= "# ============================================\n\n";
        $nginxConfig .= "# 自定义后台路径 \"{$adminPath}\"（物理目录已创建，nginx 可直接访问）\n";
        $nginxConfig .= "# 如需封锁 /{$adminDir}/ 访问，请添加以下配置：\n";
        $nginxConfig .= "location ~ ^/{$adminDir}(\$|/) {\n";
        $nginxConfig .= "    return 404;\n";
        $nginxConfig .= "}\n";
        
        $written = @file_put_contents($confPath, $nginxConfig);
        
        $message = "后台访问路径已更新为 /{$adminPath}/（物理目录已创建，无需服务器配置即可访问）\n\n";
        
        if ($written !== false) {
            $message .= "✅ 配置文件已生成: nginx-admin-route.conf\n";
        }
        
        $message .= "\n可选：封锁原 /{$adminDir}/ 访问（添加以下 nginx 配置后重载）：\n";
        $message .= "────────────────────────\n";
        $message .= "location ~ ^/{$adminDir}(\$|/) { return 404; }\n";
        $message .= "────────────────────────\n";
        $message .= "重载命令: nginx -t && nginx -s reload";
        
        return [
            'success' => true, 
            'message' => $message, 
            'server_type' => 'nginx', 
            'config_content' => $nginxConfig,
            'conf_file' => 'nginx-admin-route.conf',
        ];
    }
    
    /**
     * 创建自定义路径物理目录（无需服务器 rewrite 即可工作）
     * 在根目录创建 {customPath}/ 目录，内含 index.php 路由器和关键文件的包装器
     */
    private function createCustomPathDir(string $customPath, string $adminDir): void
    {
        $dir = __DIR__ . '/' . $customPath;
        
        // 创建目录
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        // 创建 index.php — 主路由器，处理所有子路径请求
        $indexContent = "<?php\n"
            . "/**\n"
            . " * 后台管理自定义路径入口 — 自动生成\n"
            . " * 路径: /{$customPath}/\n"
            . " * 生成时间: " . date('Y-m-d H:i:s') . "\n"
            . " */\n\n"
            . "// 解析子路径\n"
            . "\$customBase = '/{$customPath}';\n"
            . "\$requestUri = parse_url(\$_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);\n"
            . "\$subPath = substr(\$requestUri, strlen(\$customBase));\n"
            . "if (\$subPath === '' || \$subPath === false || \$subPath === '/') {\n"
            . "    \$subPath = '/index.php';\n"
            . "}\n\n"
            . "// 安全检查\n"
            . "if (strpos(\$subPath, '..') !== false || strpos(\$subPath, \"\\\\\") !== false) {\n"
            . "    http_response_code(403);\n"
            . "    exit('Forbidden');\n"
            . "}\n\n"
            . "// 映射到物理 {$adminDir}/ 目录\n"
            . "\$targetFile = __DIR__ . '/../{$adminDir}' . \$subPath;\n\n"
            . "if (file_exists(\$targetFile) && pathinfo(\$targetFile, PATHINFO_EXTENSION) === 'php') {\n"
            . "    \$_SERVER['SCRIPT_FILENAME'] = \$targetFile;\n"
            . "    \$_SERVER['SCRIPT_NAME'] = '/{$adminDir}' . \$subPath;\n"
            . "    \$_SERVER['PHP_SELF'] = '/{$adminDir}' . \$subPath;\n"
            . "    // 标记为自定义路径访问\n"
            . "    define('ADMIN_CUSTOM_ROUTE', true);\n"
            . "    define('ADMIN_CUSTOM_PATH', '{$customPath}');\n"
            . "    require \$targetFile;\n"
            . "    exit;\n"
            . "}\n\n"
            . "// 静态资源\n"
            . "if (file_exists(\$targetFile)) {\n"
            . "    \$ext = strtolower(pathinfo(\$targetFile, PATHINFO_EXTENSION));\n"
            . "    \$mimes = ['css'=>'text/css','js'=>'application/javascript','png'=>'image/png',\n"
            . "        'jpg'=>'image/jpeg','svg'=>'image/svg+xml','woff2'=>'font/woff2','ico'=>'image/x-icon'];\n"
            . "    if (isset(\$mimes[\$ext])) header('Content-Type: '.\$mimes[\$ext]);\n"
            . "    readfile(\$targetFile);\n"
            . "    exit;\n"
            . "}\n\n"
            . "http_response_code(404);\n"
            . "echo '<!DOCTYPE html><html lang=\"zh-CN\"><head><meta charset=\"UTF-8\"><title>404</title></head>'\n"
            . "   . '<body style=\"text-align:center;padding:80px;font-family:system-ui\"><h1>404</h1><p>页面未找到</p></body></html>';\n";
        
        @file_put_contents($dir . '/index.php', $indexContent);
        
        // 创建关键子路径的包装文件（nginx/Apache 需要实际文件来处理子路径请求）
        $wrappers = [
            'ajax.php'   => "<?php\n// 自动生成 - 包装器\nrequire __DIR__ . '/../{$adminDir}/ajax.php';\n",
            'logout.php' => "<?php\n// 自动生成 - 包装器\nrequire __DIR__ . '/../{$adminDir}/logout.php';\n",
            'login.php'  => "<?php\n// 自动生成 - 包装器\nrequire __DIR__ . '/../{$adminDir}/login.php';\n",
        ];
        foreach ($wrappers as $file => $content) {
            @file_put_contents($dir . '/' . $file, $content);
        }
        
        // 创建 .htaccess（确保该目录能正确处理 PHP）
        $dirHtaccess = "DirectoryIndex index.php\nOptions -Indexes\n";
        @file_put_contents($dir . '/.htaccess', $dirHtaccess);
    }
    
    /**
     * 递归删除目录
     */
    private function removeDir(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        return @rmdir($dir);
    }
    
    /**
     * 递归删除目录（静态版本，供外部调用）
     */
    public static function removeDirStatic(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeDirStatic($path);
            } else {
                @unlink($path);
            }
        }
        return @rmdir($dir);
    }
    
    /**
     * 保存恢复文件 .admin_path（忘记自定义路径时可通过 FTP/文件管理器查看）
     */
    private function saveRecoveryFile(string $adminPath): void
    {
        $content = "# 后台管理访问路径\n";
        $content .= "# 如果忘记了后台地址，查看此文件即可\n";
        $content .= "# 生成时间: " . date('Y-m-d H:i:s') . "\n";
        $content .= "ADMIN_PATH={$adminPath}\n";
        $content .= "ADMIN_URL=/{$adminPath}/\n";
        @file_put_contents(__DIR__ . '/.admin_path', $content);
    }
    
    /**
     * @deprecated 请使用 generateServerConfig() 代替
     */
    public function generateHtaccess(string $adminPath): array
    {
        return $this->generateServerConfig($adminPath);
    }
    
    // ============================================
    // Google 蜘蛛数据源管理
    // ============================================
    
    /**
     * 获取所有 Google 数据源
     */
    public function getGoogleDataSources(): array
    {
        $sources = [];
        try {
            // 确保表和默认数据存在（兼容旧数据库升级）
            $this->ensureGoogleSourcesTable();
            $result = $this->db->query("SELECT * FROM google_data_sources ORDER BY sort_order ASC, id ASC");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $sources[] = $row;
            }
        } catch (\Exception $e) {
            error_log('[AdminSettings] getGoogleDataSources error: ' . $e->getMessage());
        }
        return $sources;
    }
    
    /**
     * 确保 google_data_sources 表和默认数据存在（兼容旧数据库升级）
     */
    private function ensureGoogleSourcesTable(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS google_data_sources (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_key TEXT NOT NULL UNIQUE,
                source_name TEXT NOT NULL,
                endpoint_url TEXT NOT NULL,
                is_active INTEGER DEFAULT 1,
                sort_order INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            // 表为空时插入默认数据源
            $count = (int)$this->db->querySingle("SELECT COUNT(*) FROM google_data_sources");
            if ($count === 0) {
                $defaults = [
                    ['common-crawlers', 'Common Crawlers', 'https://developers.google.cn/crawling/ipranges/common-crawlers.json', 1],
                    ['special-crawlers', 'Special Crawlers', 'https://developers.google.cn/crawling/ipranges/special-crawlers.json', 2],
                    ['user-triggered-fetchers', 'User-Triggered Fetchers', 'https://developers.google.cn/crawling/ipranges/user-triggered-fetchers.json', 3],
                ];
                $stmt = $this->db->prepare("INSERT OR IGNORE INTO google_data_sources (source_key, source_name, endpoint_url, sort_order) VALUES (:k, :n, :u, :s)");
                foreach ($defaults as $d) {
                    $stmt->bindValue(':k', $d[0], SQLITE3_TEXT);
                    $stmt->bindValue(':n', $d[1], SQLITE3_TEXT);
                    $stmt->bindValue(':u', $d[2], SQLITE3_TEXT);
                    $stmt->bindValue(':s', $d[3], SQLITE3_INTEGER);
                    $stmt->execute();
                    $stmt->reset();
                }
            }
        } catch (\Exception $e) {
            error_log('[AdminSettings] ensureGoogleSourcesTable error: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取所有活跃的 Google 数据源端点
     * @return array ['source_key' => 'endpoint_url', ...]
     */
    public function getActiveGoogleEndpoints(): array
    {
        $endpoints = [];
        try {
            $this->ensureGoogleSourcesTable();
            $result = $this->db->query("SELECT source_key, endpoint_url, source_name FROM google_data_sources WHERE is_active = 1 ORDER BY sort_order ASC");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $endpoints[$row['source_key']] = [
                    'url' => $row['endpoint_url'],
                    'name' => $row['source_name'],
                ];
            }
        } catch (\Exception $e) {}
        return $endpoints;
    }
    
    /**
     * 添加 Google 数据源
     */
    public function addGoogleDataSource(string $key, string $name, string $url): array
    {
        if (empty($key) || empty($name) || empty($url)) {
            return ['success' => false, 'message' => '数据源标识、名称和URL不能为空'];
        }
        if (!preg_match('/^[a-z0-9_-]+$/', $key)) {
            return ['success' => false, 'message' => '数据源标识仅允许小写字母、数字、连字符和下划线'];
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'message' => '请输入有效的 URL'];
        }
        
        try {
            $maxSort = (int)$this->db->querySingle("SELECT MAX(sort_order) FROM google_data_sources");
            // INSERT OR IGNORE：主键冲突时静默跳过（而非抛异常），通过 changes() 判断
            $stmt = $this->db->prepare("INSERT OR IGNORE INTO google_data_sources (source_key, source_name, endpoint_url, sort_order) VALUES (:key, :name, :url, :sort)");
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':url', $url, SQLITE3_TEXT);
            $stmt->bindValue(':sort', $maxSort + 1, SQLITE3_INTEGER);
            $stmt->execute();
            
            if ($this->db->changes() > 0) {
                return ['success' => true, 'message' => '数据源添加成功'];
            }
            return ['success' => false, 'message' => '数据源标识已存在'];
        } catch (\Exception $e) {
            error_log('[AdminSettings] addGoogleDataSource error: ' . $e->getMessage());
            return ['success' => false, 'message' => '添加失败，请稍后重试'];
        }
    }
    
    /**
     * 更新 Google 数据源
     */
    public function updateGoogleDataSource(int $id, string $name, string $url, int $active): array
    {
        if ($id <= 0) return ['success' => false, 'message' => '无效的ID'];
        
        try {
            $stmt = $this->db->prepare("UPDATE google_data_sources SET source_name = :name, endpoint_url = :url, is_active = :active, updated_at = datetime('now','localtime') WHERE id = :id");
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':url', $url, SQLITE3_TEXT);
            $stmt->bindValue(':active', $active, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            
            if ($this->db->changes() > 0) {
                return ['success' => true, 'message' => '数据源更新成功'];
            }
            return ['success' => false, 'message' => '数据源未变更'];
        } catch (\Exception $e) {
            error_log('[AdminSettings] updateGoogleDataSource error: ' . $e->getMessage());
            return ['success' => false, 'message' => '更新失败，请稍后重试'];
        }
    }
    
    /**
     * 删除 Google 数据源
     */
    public function deleteGoogleDataSource(int $id): array
    {
        if ($id <= 0) return ['success' => false, 'message' => '无效的ID'];
        
        try {
            $stmt = $this->db->prepare("DELETE FROM google_data_sources WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            
            if ($this->db->changes() > 0) {
                return ['success' => true, 'message' => '数据源已删除'];
            }
            return ['success' => false, 'message' => '数据源不存在'];
        } catch (\Exception $e) {
            error_log('[AdminSettings] deleteGoogleDataSource error: ' . $e->getMessage());
            return ['success' => false, 'message' => '删除失败，请稍后重试'];
        }
    }
    
    /**
     * 切换数据源启用/禁用
     */
    public function toggleGoogleDataSource(int $id, int $active): array
    {
        if ($id <= 0) return ['success' => false, 'message' => '无效的ID'];
        
        try {
            $stmt = $this->db->prepare("UPDATE google_data_sources SET is_active = :active, updated_at = datetime('now','localtime') WHERE id = :id");
            $stmt->bindValue(':active', $active, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            
            return ['success' => true, 'message' => $active ? '已启用' : '已禁用'];
        } catch (\Exception $e) {
            error_log('[AdminSettings] toggleGoogleDataSource error: ' . $e->getMessage());
            return ['success' => false, 'message' => '操作失败'];
        }
    }
    
    // ============================================
    // 自定义反向DNS规则管理
    // ============================================
    
    /**
     * 获取所有自定义反向DNS规则
     */
    public function getCustomRdnsRules(): array
    {
        $rules = [];
        try {
            $this->ensureCustomRdnsTable();
            $result = $this->db->query("SELECT * FROM custom_rdns_rules ORDER BY sort_order ASC, id ASC");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rules[] = $row;
            }
        } catch (\Exception $e) {
            error_log('[AdminSettings] getCustomRdnsRules error: ' . $e->getMessage());
        }
        return $rules;
    }
    
    /**
     * 确保 custom_rdns_rules 表和默认数据存在（兼容旧数据库升级）
     */
    private function ensureCustomRdnsTable(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS custom_rdns_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hostname_pattern TEXT NOT NULL,
                spider_type TEXT NOT NULL,
                match_type TEXT DEFAULT 'contains',
                is_active INTEGER DEFAULT 1,
                sort_order INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_crr_active ON custom_rdns_rules(is_active)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_crr_sort ON custom_rdns_rules(sort_order)");
            
            // 表为空时插入默认规则
            $count = (int)$this->db->querySingle("SELECT COUNT(*) FROM custom_rdns_rules");
            if ($count === 0) {
                $defaults = [
                    ['googlebot.com', 'Googlebot', 'contains', 1],
                    ['crawl.baidu.com', 'Baiduspider', 'contains', 2],
                    ['search.msn.com', 'Bingbot', 'contains', 3],
                    ['msnbot', 'Bingbot', 'regex', 4],
                    ['.yandex.com', 'YandexBot', 'contains', 5],
                    ['.yandex.ru', 'YandexBot', 'contains', 6],
                    ['.sogou.com', 'Sogou Spider', 'contains', 7],
                    ['duckduckgo.com', 'DuckDuckBot', 'contains', 8],
                    ['.semrush.com', 'SemrushBot', 'contains', 9],
                    ['.ahrefs.com', 'AhrefsBot', 'contains', 10],
                    ['crawl.yahoo', 'Yahoo Slurp', 'contains', 11],
                ];
                $stmt = $this->db->prepare("INSERT INTO custom_rdns_rules (hostname_pattern, spider_type, match_type, sort_order) VALUES (:p, :t, :m, :s)");
                foreach ($defaults as $d) {
                    $stmt->bindValue(':p', $d[0], SQLITE3_TEXT);
                    $stmt->bindValue(':t', $d[1], SQLITE3_TEXT);
                    $stmt->bindValue(':m', $d[2], SQLITE3_TEXT);
                    $stmt->bindValue(':s', $d[3], SQLITE3_INTEGER);
                    $stmt->execute();
                    $stmt->reset();
                }
            }
        } catch (\Exception $e) {
            // 静默处理
        }
    }
    
    /**
     * 添加自定义反向DNS规则
     */
    public function addCustomRdnsRule(string $pattern, string $spiderType, string $matchType = 'contains'): array
    {
        $pattern = trim($pattern);
        $spiderType = trim($spiderType);
        
        if (empty($pattern) || empty($spiderType)) {
            return ['success' => false, 'message' => '主机名模式和蜘蛛类型不能为空'];
        }
        if (!in_array($matchType, ['contains', 'regex', 'exact'])) {
            return ['success' => false, 'message' => '无效的匹配模式'];
        }
        
        try {
            $this->ensureCustomRdnsTable();
            
            // 获取下一个排序序号
            $maxSort = (int)$this->db->querySingle("SELECT COALESCE(MAX(sort_order), 0) FROM custom_rdns_rules");
            
            $stmt = $this->db->prepare("
                INSERT INTO custom_rdns_rules (hostname_pattern, spider_type, match_type, sort_order, created_at, updated_at)
                VALUES (:p, :t, :m, :s, datetime('now','localtime'), datetime('now','localtime'))
            ");
            $stmt->bindValue(':p', $pattern, SQLITE3_TEXT);
            $stmt->bindValue(':t', $spiderType, SQLITE3_TEXT);
            $stmt->bindValue(':m', $matchType, SQLITE3_TEXT);
            $stmt->bindValue(':s', $maxSort + 1, SQLITE3_INTEGER);
            $stmt->execute();
            
            return ['success' => true, 'message' => '规则已添加', 'id' => $this->db->lastInsertRowID()];
        } catch (\Exception $e) {
            error_log('[AdminSettings] addCustomRdnsRule error: ' . $e->getMessage());
            return ['success' => false, 'message' => '添加失败，请稍后重试'];
        }
    }
    
    /**
     * 更新自定义反向DNS规则
     */
    public function updateCustomRdnsRule(int $id, string $pattern, string $spiderType, string $matchType = 'contains', int $isActive = 1): array
    {
        if ($id <= 0) return ['success' => false, 'message' => '无效的ID'];
        
        $pattern = trim($pattern);
        $spiderType = trim($spiderType);
        
        if (empty($pattern) || empty($spiderType)) {
            return ['success' => false, 'message' => '主机名模式和蜘蛛类型不能为空'];
        }
        if (!in_array($matchType, ['contains', 'regex', 'exact'])) {
            return ['success' => false, 'message' => '无效的匹配模式'];
        }
        
        try {
            $stmt = $this->db->prepare("
                UPDATE custom_rdns_rules SET
                    hostname_pattern = :p,
                    spider_type = :t,
                    match_type = :m,
                    is_active = :a,
                    updated_at = datetime('now','localtime')
                WHERE id = :id
            ");
            $stmt->bindValue(':p', $pattern, SQLITE3_TEXT);
            $stmt->bindValue(':t', $spiderType, SQLITE3_TEXT);
            $stmt->bindValue(':m', $matchType, SQLITE3_TEXT);
            $stmt->bindValue(':a', $isActive, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            
            if ($this->db->changes() > 0) {
                return ['success' => true, 'message' => '规则已更新'];
            }
            return ['success' => false, 'message' => '规则不存在或内容未变更'];
        } catch (\Exception $e) {
            error_log('[AdminSettings] updateCustomRdnsRule error: ' . $e->getMessage());
            return ['success' => false, 'message' => '更新失败，请稍后重试'];
        }
    }
    
    /**
     * 删除自定义反向DNS规则
     */
    public function deleteCustomRdnsRule(int $id): array
    {
        if ($id <= 0) return ['success' => false, 'message' => '无效的ID'];
        
        try {
            $stmt = $this->db->prepare("DELETE FROM custom_rdns_rules WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            
            if ($this->db->changes() > 0) {
                return ['success' => true, 'message' => '规则已删除'];
            }
            return ['success' => false, 'message' => '规则不存在'];
        } catch (\Exception $e) {
            error_log('[AdminSettings] deleteCustomRdnsRule error: ' . $e->getMessage());
            return ['success' => false, 'message' => '删除失败，请稍后重试'];
        }
    }
    
    /**
     * 切换规则启用/禁用
     */
    public function toggleCustomRdnsRule(int $id, int $active): array
    {
        if ($id <= 0) return ['success' => false, 'message' => '无效的ID'];
        
        try {
            $stmt = $this->db->prepare("UPDATE custom_rdns_rules SET is_active = :active, updated_at = datetime('now','localtime') WHERE id = :id");
            $stmt->bindValue(':active', $active, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            
            return ['success' => true, 'message' => $active ? '已启用' : '已禁用'];
        } catch (\Exception $e) {
            error_log('[AdminSettings] toggleCustomRdnsRule error: ' . $e->getMessage());
            return ['success' => false, 'message' => '操作失败'];
        }
    }
    
    /**
     * 批量添加自定义反向DNS规则
     * @param array $rules 每条: ['hostname_pattern' => ..., 'spider_type' => ..., 'match_type' => ...]
     */
    public function batchAddCustomRdnsRules(array $rules): array
    {
        if (empty($rules)) return ['success' => false, 'message' => '规则列表为空'];
        
        $added = 0;
        $skipped = 0;
        try {
            $this->ensureCustomRdnsTable();
            $maxSort = (int)$this->db->querySingle("SELECT COALESCE(MAX(sort_order), 0) FROM custom_rdns_rules");
            $stmt = $this->db->prepare("INSERT INTO custom_rdns_rules (hostname_pattern, spider_type, match_type, sort_order, created_at, updated_at) VALUES (:p, :t, :m, :s, datetime('now','localtime'), datetime('now','localtime'))");
            
            foreach ($rules as $i => $rule) {
                $pattern = trim($rule['hostname_pattern'] ?? '');
                $spiderType = trim($rule['spider_type'] ?? '');
                $matchType = trim($rule['match_type'] ?? 'contains');
                if (empty($pattern) || empty($spiderType)) { $skipped++; continue; }
                if (!in_array($matchType, ['contains', 'regex', 'exact'])) $matchType = 'contains';
                
                $stmt->bindValue(':p', $pattern, SQLITE3_TEXT);
                $stmt->bindValue(':t', $spiderType, SQLITE3_TEXT);
                $stmt->bindValue(':m', $matchType, SQLITE3_TEXT);
                $stmt->bindValue(':s', $maxSort + $i + 1, SQLITE3_INTEGER);
                $stmt->execute();
                $added++;
                $stmt->reset();
            }
            return ['success' => true, 'message' => "批量添加完成：新增 {$added} 条，跳过 {$skipped} 条", 'added' => $added, 'skipped' => $skipped];
        } catch (\Exception $e) {
            error_log('[AdminSettings] batchAddCustomRdnsRules error: ' . $e->getMessage());
            return ['success' => false, 'message' => '批量添加失败'];
        }
    }
    
    /**
     * 批量删除自定义反向DNS规则
     */
    public function batchDeleteCustomRdnsRules(array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
        if (empty($ids)) return ['success' => false, 'message' => '无效的ID列表'];
        
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("DELETE FROM custom_rdns_rules WHERE id IN ({$placeholders})");
            foreach ($ids as $i => $id) { $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER); }
            $stmt->execute();
            $deleted = $this->db->changes();
            return ['success' => true, 'message' => "已删除 {$deleted} 条规则"];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '批量删除失败'];
        }
    }
    
    /**
     * 恢复默认反向DNS规则（INSERT OR IGNORE，已存在的跳过）
     */
    public function resetDefaultRdnsRules(): array
    {
        $defaults = [
            ['googlebot.com', 'Googlebot', 'contains'],
            ['crawl.baidu.com', 'Baiduspider', 'contains'],
            ['search.msn.com', 'Bingbot', 'contains'],
            ['msnbot', 'Bingbot', 'regex'],
            ['.yandex.com', 'YandexBot', 'contains'],
            ['.yandex.ru', 'YandexBot', 'contains'],
            ['.sogou.com', 'Sogou Spider', 'contains'],
            ['duckduckgo.com', 'DuckDuckBot', 'contains'],
            ['.semrush.com', 'SemrushBot', 'contains'],
            ['.ahrefs.com', 'AhrefsBot', 'contains'],
            ['crawl.yahoo', 'Yahoo Slurp', 'contains'],
        ];
        
        $added = 0;
        $skipped = 0;
        try {
            $this->ensureCustomRdnsTable();
            $stmt = $this->db->prepare("
                INSERT OR IGNORE INTO custom_rdns_rules (hostname_pattern, spider_type, match_type, is_active, sort_order, created_at, updated_at)
                VALUES (:p, :t, :m, 1, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM custom_rdns_rules), datetime('now','localtime'), datetime('now','localtime'))
            ");
            foreach ($defaults as $d) {
                $stmt->bindValue(':p', $d[0], SQLITE3_TEXT);
                $stmt->bindValue(':t', $d[1], SQLITE3_TEXT);
                $stmt->bindValue(':m', $d[2], SQLITE3_TEXT);
                $stmt->execute();
                if ($this->db->changes() > 0) $added++; else $skipped++;
                $stmt->reset();
            }
            return ['success' => true, 'message' => "默认规则导入完成：新增 {$added} 条，跳过 {$skipped} 条（已存在）"];
        } catch (\Exception $e) {
            error_log('[AdminSettings] resetDefaultRdnsRules error: ' . $e->getMessage());
            return ['success' => false, 'message' => '导入失败'];
        }
    }
    
    // ============================================
    // 自定义 User-Agent 规则管理
    // ============================================
    
    /**
     * 获取所有自定义 UA 规则
     */
    public function getCustomUaRules(): array
    {
        $rules = [];
        try {
            $this->ensureCustomUaTable();
            $result = $this->db->query("SELECT * FROM custom_ua_rules ORDER BY sort_order ASC, id ASC");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rules[] = $row;
            }
        } catch (\Exception $e) {
            error_log('[AdminSettings] getCustomUaRules error: ' . $e->getMessage());
        }
        return $rules;
    }
    
    private function ensureCustomUaTable(): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS custom_ua_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ua_keyword TEXT NOT NULL,
                spider_type TEXT NOT NULL,
                match_type TEXT DEFAULT 'contains',
                is_active INTEGER DEFAULT 1,
                sort_order INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_cua_active ON custom_ua_rules(is_active)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_cua_sort ON custom_ua_rules(sort_order)");
            
            $count = (int)$this->db->querySingle("SELECT COUNT(*) FROM custom_ua_rules");
            if ($count === 0) {
                $defaults = [
                    ['Bingbot', 'Bingbot', 'contains', 1],
                    ['YandexBot', 'YandexBot', 'contains', 2],
                    ['Sogou web spider', 'Sogou Spider', 'contains', 3],
                    ['DuckDuckBot', 'DuckDuckBot', 'contains', 4],
                    ['SemrushBot', 'SemrushBot', 'contains', 5],
                    ['AhrefsBot', 'AhrefsBot', 'contains', 6],
                    ['Slurp', 'Yahoo Slurp', 'contains', 7],
                    ['Applebot', 'Applebot', 'contains', 8],
                    ['facebookexternalhit', 'Facebook Crawler', 'contains', 9],
                    ['Twitterbot', 'Twitterbot', 'contains', 10],
                ];
                $stmt = $this->db->prepare("INSERT INTO custom_ua_rules (ua_keyword, spider_type, match_type, sort_order) VALUES (:k, :t, :m, :s)");
                foreach ($defaults as $d) {
                    $stmt->bindValue(':k', $d[0], SQLITE3_TEXT);
                    $stmt->bindValue(':t', $d[1], SQLITE3_TEXT);
                    $stmt->bindValue(':m', $d[2], SQLITE3_TEXT);
                    $stmt->bindValue(':s', $d[3], SQLITE3_INTEGER);
                    $stmt->execute();
                    $stmt->reset();
                }
            }
        } catch (\Exception $e) {}
    }
    
    public function addCustomUaRule(string $keyword, string $spiderType, string $matchType = 'contains'): array
    {
        $keyword = trim($keyword);
        $spiderType = trim($spiderType);
        if (empty($keyword) || empty($spiderType)) return ['success' => false, 'message' => 'UA关键词和蜘蛛类型不能为空'];
        if (!in_array($matchType, ['contains', 'regex'])) return ['success' => false, 'message' => '无效的匹配模式'];
        
        try {
            $this->ensureCustomUaTable();
            $maxSort = (int)$this->db->querySingle("SELECT COALESCE(MAX(sort_order), 0) FROM custom_ua_rules");
            $stmt = $this->db->prepare("INSERT INTO custom_ua_rules (ua_keyword, spider_type, match_type, sort_order, created_at, updated_at) VALUES (:k, :t, :m, :s, datetime('now','localtime'), datetime('now','localtime'))");
            $stmt->bindValue(':k', $keyword, SQLITE3_TEXT);
            $stmt->bindValue(':t', $spiderType, SQLITE3_TEXT);
            $stmt->bindValue(':m', $matchType, SQLITE3_TEXT);
            $stmt->bindValue(':s', $maxSort + 1, SQLITE3_INTEGER);
            $stmt->execute();
            return ['success' => true, 'message' => '规则已添加', 'id' => $this->db->lastInsertRowID()];
        } catch (\Exception $e) {
            error_log('[AdminSettings] addCustomUaRule error: ' . $e->getMessage());
            return ['success' => false, 'message' => '添加失败，请稍后重试'];
        }
    }
    
    public function updateCustomUaRule(int $id, string $keyword, string $spiderType, string $matchType = 'contains', int $isActive = 1): array
    {
        if ($id <= 0) return ['success' => false, 'message' => '无效的ID'];
        $keyword = trim($keyword);
        $spiderType = trim($spiderType);
        if (empty($keyword) || empty($spiderType)) return ['success' => false, 'message' => 'UA关键词和蜘蛛类型不能为空'];
        if (!in_array($matchType, ['contains', 'regex'])) return ['success' => false, 'message' => '无效的匹配模式'];
        
        try {
            $stmt = $this->db->prepare("UPDATE custom_ua_rules SET ua_keyword=:k, spider_type=:t, match_type=:m, is_active=:a, updated_at=datetime('now','localtime') WHERE id=:id");
            $stmt->bindValue(':k', $keyword, SQLITE3_TEXT);
            $stmt->bindValue(':t', $spiderType, SQLITE3_TEXT);
            $stmt->bindValue(':m', $matchType, SQLITE3_TEXT);
            $stmt->bindValue(':a', $isActive, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            return $this->db->changes() > 0 ? ['success' => true, 'message' => '规则已更新'] : ['success' => false, 'message' => '规则不存在或内容未变更'];
        } catch (\Exception $e) {
            error_log('[AdminSettings] updateCustomUaRule error: ' . $e->getMessage());
            return ['success' => false, 'message' => '更新失败'];
        }
    }
    
    public function deleteCustomUaRule(int $id): array
    {
        if ($id <= 0) return ['success' => false, 'message' => '无效的ID'];
        try {
            $stmt = $this->db->prepare("DELETE FROM custom_ua_rules WHERE id=:id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            return $this->db->changes() > 0 ? ['success' => true, 'message' => '规则已删除'] : ['success' => false, 'message' => '规则不存在'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '删除失败'];
        }
    }
    
    public function toggleCustomUaRule(int $id, int $active): array
    {
        if ($id <= 0) return ['success' => false, 'message' => '无效的ID'];
        try {
            $stmt = $this->db->prepare("UPDATE custom_ua_rules SET is_active=:a, updated_at=datetime('now','localtime') WHERE id=:id");
            $stmt->bindValue(':a', $active, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            return ['success' => true, 'message' => $active ? '已启用' : '已禁用'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '操作失败'];
        }
    }
    
    /**
     * 批量添加自定义 UA 规则
     */
    public function batchAddCustomUaRules(array $rules): array
    {
        if (empty($rules)) return ['success' => false, 'message' => '规则列表为空'];
        
        $added = 0;
        $skipped = 0;
        try {
            $this->ensureCustomUaTable();
            $maxSort = (int)$this->db->querySingle("SELECT COALESCE(MAX(sort_order), 0) FROM custom_ua_rules");
            $stmt = $this->db->prepare("INSERT INTO custom_ua_rules (ua_keyword, spider_type, match_type, sort_order, created_at, updated_at) VALUES (:k, :t, :m, :s, datetime('now','localtime'), datetime('now','localtime'))");
            
            foreach ($rules as $i => $rule) {
                $keyword = trim($rule['ua_keyword'] ?? '');
                $spiderType = trim($rule['spider_type'] ?? '');
                $matchType = trim($rule['match_type'] ?? 'contains');
                if (empty($keyword) || empty($spiderType)) { $skipped++; continue; }
                if (!in_array($matchType, ['contains', 'regex'])) $matchType = 'contains';
                
                $stmt->bindValue(':k', $keyword, SQLITE3_TEXT);
                $stmt->bindValue(':t', $spiderType, SQLITE3_TEXT);
                $stmt->bindValue(':m', $matchType, SQLITE3_TEXT);
                $stmt->bindValue(':s', $maxSort + $i + 1, SQLITE3_INTEGER);
                $stmt->execute();
                $added++;
                $stmt->reset();
            }
            return ['success' => true, 'message' => "批量添加完成：新增 {$added} 条，跳过 {$skipped} 条", 'added' => $added, 'skipped' => $skipped];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '批量添加失败'];
        }
    }
    
    /**
     * 批量删除自定义 UA 规则
     */
    public function batchDeleteCustomUaRules(array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
        if (empty($ids)) return ['success' => false, 'message' => '无效的ID列表'];
        
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("DELETE FROM custom_ua_rules WHERE id IN ({$placeholders})");
            foreach ($ids as $i => $id) { $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER); }
            $stmt->execute();
            $deleted = $this->db->changes();
            return ['success' => true, 'message' => "已删除 {$deleted} 条规则"];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '批量删除失败'];
        }
    }
    
    /**
     * 恢复默认 User-Agent 规则（INSERT OR IGNORE，已存在的跳过）
     */
    public function resetDefaultUaRules(): array
    {
        $defaults = [
            ['Bingbot', 'Bingbot', 'contains'],
            ['YandexBot', 'YandexBot', 'contains'],
            ['Sogou web spider', 'Sogou Spider', 'contains'],
            ['DuckDuckBot', 'DuckDuckBot', 'contains'],
            ['SemrushBot', 'SemrushBot', 'contains'],
            ['AhrefsBot', 'AhrefsBot', 'contains'],
            ['Slurp', 'Yahoo Slurp', 'contains'],
            ['Applebot', 'Applebot', 'contains'],
            ['facebookexternalhit', 'Facebook Crawler', 'contains'],
            ['Twitterbot', 'Twitterbot', 'contains'],
        ];
        
        $added = 0;
        $skipped = 0;
        try {
            $this->ensureCustomUaTable();
            $stmt = $this->db->prepare("
                INSERT OR IGNORE INTO custom_ua_rules (ua_keyword, spider_type, match_type, is_active, sort_order, created_at, updated_at)
                VALUES (:k, :t, :m, 1, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM custom_ua_rules), datetime('now','localtime'), datetime('now','localtime'))
            ");
            foreach ($defaults as $d) {
                $stmt->bindValue(':k', $d[0], SQLITE3_TEXT);
                $stmt->bindValue(':t', $d[1], SQLITE3_TEXT);
                $stmt->bindValue(':m', $d[2], SQLITE3_TEXT);
                $stmt->execute();
                if ($this->db->changes() > 0) $added++; else $skipped++;
                $stmt->reset();
            }
            return ['success' => true, 'message' => "默认规则导入完成：新增 {$added} 条，跳过 {$skipped} 条（已存在）"];
        } catch (\Exception $e) {
            error_log('[AdminSettings] resetDefaultUaRules error: ' . $e->getMessage());
            return ['success' => false, 'message' => '导入失败'];
        }
    }
    
    public function __destruct()
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}

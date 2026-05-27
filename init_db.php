<?php
/**
 * SQLite 数据库初始化脚本
 * 创建蜘蛛 IP 段表和查询日志表
 */

require_once __DIR__ . '/config.php';

// 确保数据库目录可写
$dbDir = dirname(SQLITE_DB_PATH);
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

try {
    $db = new SQLite3(SQLITE_DB_PATH);
    $db->enableExceptions(true);
    
    // 启用 WAL 模式提升并发读写性能
    $db->exec("PRAGMA journal_mode=WAL");
    // 设置缓存大小为 8MB（默认仅 2MB，提升查询性能）
    $db->exec("PRAGMA cache_size=-8000");
    // 设置同步模式为 NORMAL（兼顾性能与安全性）
    $db->exec("PRAGMA synchronous=NORMAL");
    
    // 创建蜘蛛 IP 段表
    $db->exec("
        CREATE TABLE IF NOT EXISTS spider_ranges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_range TEXT NOT NULL UNIQUE,
            cidr_notation TEXT NOT NULL,
            ip_start TEXT NOT NULL,
            ip_end TEXT NOT NULL,
            ip_start_num INTEGER DEFAULT 0,
            ip_end_num INTEGER DEFAULT 0,
            spider_type TEXT DEFAULT 'Baiduspider',
            source TEXT DEFAULT 'manual',
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // 创建索引
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ip_range ON spider_ranges(ip_range)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_active ON spider_ranges(is_active)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ip_start_end ON spider_ranges(ip_start, ip_end)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ip_numeric ON spider_ranges(ip_start_num, ip_end_num)");
    
    // 创建全局查询统计表（仅用于统计计数，不存详细记录）
    $db->exec("
        CREATE TABLE IF NOT EXISTS query_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            is_spider INTEGER DEFAULT 0,
            query_time DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_stats_time ON query_stats(query_time)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_stats_spider ON query_stats(is_spider)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_stats_spider_ip ON query_stats(is_spider, ip_address)");
    
    // 创建已确认的百度蜘蛛IP表（去重存储）
    $db->exec("
        CREATE TABLE IF NOT EXISTS confirmed_spiders (
            ip_address TEXT PRIMARY KEY,
            spider_type TEXT,
            match_method TEXT,
            matched_range TEXT,
            rdns_hostname TEXT,
            confidence TEXT,
            first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            seen_count INTEGER DEFAULT 1
        )
    ");
    
    // 创建更新记录表
    $db->exec("
        CREATE TABLE IF NOT EXISTS update_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            update_type TEXT NOT NULL,
            status TEXT DEFAULT 'success',
            message TEXT,
            records_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // ============================================
    // 后台管理系统表
    // ============================================
    
    // 系统设置表（key-value 动态配置）
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT,
            setting_type TEXT DEFAULT 'string',
            description TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // 管理员账户表
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT 'admin',
            last_login DATETIME,
            login_ip TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_active INTEGER DEFAULT 1
        )
    ");
    
    // API密钥管理表
    $db->exec("
        CREATE TABLE IF NOT EXISTS api_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            api_name TEXT NOT NULL,
            api_key TEXT NOT NULL,
            api_secret TEXT,
            endpoint_url TEXT,
            is_active INTEGER DEFAULT 1,
            rate_limit INTEGER DEFAULT 100,
            rate_window INTEGER DEFAULT 3600,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // API调用日志表
    $db->exec("
        CREATE TABLE IF NOT EXISTS api_call_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            api_name TEXT,
            endpoint TEXT,
            caller_ip TEXT,
            user_agent TEXT,
            response_code INTEGER,
            execution_time_ms REAL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_api_logs_time ON api_call_logs(created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_api_logs_name ON api_call_logs(api_name)");
    
    // Google 蜘蛛数据源表（可动态增删）
    $db->exec("
        CREATE TABLE IF NOT EXISTS google_data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_key TEXT NOT NULL UNIQUE,
            source_name TEXT NOT NULL,
            endpoint_url TEXT NOT NULL,
            is_active INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // 为 google_data_sources 表创建索引（性能优化）
    $db->exec("CREATE INDEX IF NOT EXISTS idx_gds_active ON google_data_sources(is_active)");
    
    // ============================================
    // 自定义反向DNS规则表（主机名关键词 → 蜘蛛类型映射）
    // ============================================
    $db->exec("
        CREATE TABLE IF NOT EXISTS custom_rdns_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hostname_pattern TEXT NOT NULL,
            spider_type TEXT NOT NULL,
            match_type TEXT DEFAULT 'contains',
            is_active INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_crr_active ON custom_rdns_rules(is_active)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_crr_sort ON custom_rdns_rules(sort_order)");
    
    // ============================================
    // 自定义 User-Agent 规则表（UA关键词 → 蜘蛛类型映射）
    // ============================================
    $db->exec("
        CREATE TABLE IF NOT EXISTS custom_ua_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ua_keyword TEXT NOT NULL,
            spider_type TEXT NOT NULL,
            match_type TEXT DEFAULT 'contains',
            is_active INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cua_active ON custom_ua_rules(is_active)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cua_sort ON custom_ua_rules(sort_order)");
    
    // 插入默认 User-Agent 规则（仅当表为空时，作为内置规则的补充）
    $uaCount = $db->querySingle("SELECT COUNT(*) FROM custom_ua_rules");
    if ($uaCount == 0) {
        $defaultUaRules = [
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
            ['LinkedInBot', 'LinkedInBot', 'contains', 11],
            ['PetalBot', 'PetalBot', 'contains', 12],
        ];
        $uaStmt = $db->prepare("INSERT INTO custom_ua_rules (ua_keyword, spider_type, match_type, sort_order) VALUES (:kw, :type, :match, :sort)");
        foreach ($defaultUaRules as $rule) {
            $uaStmt->bindValue(':kw', $rule[0], SQLITE3_TEXT);
            $uaStmt->bindValue(':type', $rule[1], SQLITE3_TEXT);
            $uaStmt->bindValue(':match', $rule[2], SQLITE3_TEXT);
            $uaStmt->bindValue(':sort', $rule[3], SQLITE3_INTEGER);
            $uaStmt->execute();
            $uaStmt->reset();
        }
    }
    
    // 插入默认反向DNS规则（仅当表为空时）
    $rdnsCount = $db->querySingle("SELECT COUNT(*) FROM custom_rdns_rules");
    if ($rdnsCount == 0) {
        $defaultRdnsRules = [
            ['googlebot.com', 'Googlebot', 'contains', 1],
            ['crawl.baidu.com', 'Baiduspider', 'contains', 2],
            ['search.msn.com', 'Bingbot', 'contains', 3],
            ['msnbot', 'Bingbot', 'regex', 4],
            ['.yandex.com', 'YandexBot', 'contains', 5],
            ['.yandex.ru', 'YandexBot', 'contains', 6],
            ['.yandex.net', 'YandexBot', 'contains', 7],
            ['.sogou.com', 'Sogou Spider', 'contains', 8],
            ['duckduckgo.com', 'DuckDuckBot', 'contains', 9],
            ['.semrush.com', 'SemrushBot', 'contains', 10],
            ['.ahrefs.com', 'AhrefsBot', 'contains', 11],
            ['crawl.yahoo', 'Yahoo Slurp', 'contains', 12],
            ['spider.yahoo', 'Yahoo Slurp', 'contains', 13],
        ];
        $rdnsStmt = $db->prepare("INSERT INTO custom_rdns_rules (hostname_pattern, spider_type, match_type, sort_order) VALUES (:pattern, :type, :match, :sort)");
        foreach ($defaultRdnsRules as $rule) {
            $rdnsStmt->bindValue(':pattern', $rule[0], SQLITE3_TEXT);
            $rdnsStmt->bindValue(':type', $rule[1], SQLITE3_TEXT);
            $rdnsStmt->bindValue(':match', $rule[2], SQLITE3_TEXT);
            $rdnsStmt->bindValue(':sort', $rule[3], SQLITE3_INTEGER);
            $rdnsStmt->execute();
            $rdnsStmt->reset();
        }
    }
    
    // 插入默认 Google 数据源（仅当表为空时）
    $sourceCount = $db->querySingle("SELECT COUNT(*) FROM google_data_sources");
    if ($sourceCount == 0) {
        $defaultSources = [
            ['common-crawlers', 'Common Crawlers', 'https://developers.google.cn/crawling/ipranges/common-crawlers.json', 1],
            ['special-crawlers', 'Special Crawlers', 'https://developers.google.cn/crawling/ipranges/special-crawlers.json', 2],
            ['user-triggered-fetchers', 'User-Triggered Fetchers', 'https://developers.google.cn/crawling/ipranges/user-triggered-fetchers.json', 3],
        ];
        $srcStmt = $db->prepare("INSERT INTO google_data_sources (source_key, source_name, endpoint_url, sort_order) VALUES (:key, :name, :url, :sort)");
        foreach ($defaultSources as $src) {
            $srcStmt->bindValue(':key', $src[0], SQLITE3_TEXT);
            $srcStmt->bindValue(':name', $src[1], SQLITE3_TEXT);
            $srcStmt->bindValue(':url', $src[2], SQLITE3_TEXT);
            $srcStmt->bindValue(':sort', $src[3], SQLITE3_INTEGER);
            $srcStmt->execute();
            $srcStmt->reset();
        }
    }
    
    // 插入默认管理员账户
    // 首次安装时使用随机安全密码，仅通过 error_log 记录（绝不输出到 HTTP 响应）
    $exists = $db->querySingle("SELECT COUNT(*) FROM admin_users");
    if ($exists == 0) {
        $defaultPassword = substr(bin2hex(random_bytes(8)), 0, 16);
        $defaultHash = password_hash($defaultPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $db->prepare("INSERT INTO admin_users (username, password_hash, role, created_at) VALUES ('admin', :hash, 'admin', datetime('now','localtime'))");
        $stmt->bindValue(':hash', $defaultHash, SQLITE3_TEXT);
        $stmt->execute();
        // 安全：密码仅写入服务器错误日志，绝不输出到 HTTP 响应体
        error_log('[init_db] Default admin account created. Username: admin, Password: ' . $defaultPassword . ' (CHANGE IMMEDIATELY!)');
        if (php_sapi_name() === 'cli') {
            echo "[初始化] 默认管理员账户已创建。用户名: admin, 初始密码: {$defaultPassword}\n";
            echo "[安全提醒] 请立即登录后台修改密码！\n";
        }
    }
    
    // 插入默认系统设置
    $defaultSettings = [
        ['site_name', '蜘蛛识别查询系统', 'string', '网站名称'],
        ['site_description', '基于 GeoIP2 的 Baidu Spider / Googlebot 自动检测与 IP 地理位置查询', 'string', '网站描述'],
        ['maxmind_license_key', '', 'string', 'MaxMind GeoIP2 License Key（免费注册获取）'],
        ['google_spider_update_interval', '604800', 'int', 'Google蜘蛛IP更新间隔（秒），默认7天'],
        ['geoip_update_interval', '2592000', 'int', 'GeoIP数据库更新间隔（秒），默认30天'],
        ['auto_update_enabled', '1', 'bool', '是否启用自动更新'],
        ['query_log_retention_days', '90', 'int', '查询日志保留天数'],
        ['max_query_per_minute', '30', 'int', '每分钟最大查询次数（防滥用）'],
        ['enable_api', '1', 'bool', '是否启用API接口'],
        ['enable_geolocation', '1', 'bool', '是否启用IP地理位置查询'],
        ['enable_rdns_check', '1', 'bool', '是否启用反向DNS验证'],
        ['enable_spider_check', '1', 'bool', '是否启用蜘蛛检测'],
        ['maintenance_mode', '0', 'bool', '是否启用维护模式'],
        ['admin_theme', 'auto', 'string', '后台主题: light/dark/auto'],
        ['admin_path', 'admin', 'string', '后台管理访问路径（修改后需确保 .htaccess 可写）'],
        ['auto_import_confirmed', '0', 'bool', '是否自动将已确认蜘蛛IP导入到IP管理'],
    ];
    
    $stmt = $db->prepare("INSERT OR IGNORE INTO admin_settings (setting_key, setting_value, setting_type, description) VALUES (:key, :val, :type, :desc)");
    foreach ($defaultSettings as $setting) {
        $stmt->bindValue(':key', $setting[0], SQLITE3_TEXT);
        $stmt->bindValue(':val', $setting[1], SQLITE3_TEXT);
        $stmt->bindValue(':type', $setting[2], SQLITE3_TEXT);
        $stmt->bindValue(':desc', $setting[3], SQLITE3_TEXT);
        $stmt->execute();
        $stmt->reset();
    }
    
    // 插入默认蜘蛛 IP 段数据（如果表为空）
    $count = $db->querySingle("SELECT COUNT(*) FROM spider_ranges");
    if ($count == 0) {
        $ranges = getBaiduDefaultRanges();
        $stmt = $db->prepare("
            INSERT OR IGNORE INTO spider_ranges (ip_range, cidr_notation, ip_start, ip_end, ip_start_num, ip_end_num, spider_type, source) 
            VALUES (:range, :cidr, :start, :end, :startNum, :endNum, :type, 'builtin')
        ");
        
        foreach ($ranges as $cidr) {
            $rangeInfo = parseCidrRange($cidr);
            if ($rangeInfo) {
                $stmt->bindValue(':range', $cidr, SQLITE3_TEXT);
                $stmt->bindValue(':cidr', $cidr, SQLITE3_TEXT);
                $stmt->bindValue(':start', $rangeInfo['start'], SQLITE3_TEXT);
                $stmt->bindValue(':end', $rangeInfo['end'], SQLITE3_TEXT);
                $stmt->bindValue(':startNum', ip2long($rangeInfo['start']) ?: 0, SQLITE3_INTEGER);
                $stmt->bindValue(':endNum', ip2long($rangeInfo['end']) ?: 0, SQLITE3_INTEGER);
                $stmt->bindValue(':type', 'Baiduspider', SQLITE3_TEXT);
                $stmt->execute();
                $stmt->reset();
            }
        }
        
        if (php_sapi_name() === 'cli') {
            echo "✅ 数据库初始化完成，已导入 " . count($ranges) . " 个默认 IP 段。\n";
        }
    } else {
        if (php_sapi_name() === 'cli') {
            echo "✅ 数据库已存在，当前包含 {$count} 个 IP 段。\n";
        }
    }
    
    $db->close();
    
} catch (Exception $e) {
    error_log('[init_db] 数据库初始化失败: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo "❌ 数据库初始化失败: " . $e->getMessage() . "\n";
    }
    exit(1);
}

/**
 * 解析 CIDR 表示法，返回 IP 段的起始和结束地址
 */
function parseCidrRange(string $cidr): ?array
{
    if (strpos($cidr, '/') === false) {
        // 单个 IP
        return [
            'start' => $cidr,
            'end'   => $cidr,
        ];
    }
    
    list($subnet, $bits) = explode('/', $cidr);
    $bits = (int)$bits;
    
    $ipLong = ip2long($subnet);
    if ($ipLong === false) {
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

// 兼容 CLI 直接运行
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // 已在 try 块中执行
}

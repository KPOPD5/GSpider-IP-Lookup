<?php
/**
 * 百度蜘蛛检测类
 * 支持 IP 段匹配 + User-Agent 双重验证
 */

require_once __DIR__ . '/config.php';

class SpiderChecker
{
    private SQLite3 $db;
    
    /**
     * 反向DNS缓存，同一次请求中避免重复查询
     * @var array<string, array|null>
     */
    private array $dnsCache = [];
    
    /**
     * 百度 + Google UA 关键词缓存（避免每次调用 array_merge）
     * @var array<string>|null
     */
    private static ?array $allUAKeywords = null;
    
    public function __construct()
    {
        $this->db = new SQLite3(SQLITE_DB_PATH);
        $this->db->enableExceptions(true);
        $this->ensureNumericIPColumns();
    }
    
    /**
     * 确保 IP 数值列存在并填充（用于精确的 IP 范围比较）
     * 兼容旧数据库：自动添加 ip_start_num / ip_end_num 列
     */
    private function ensureNumericIPColumns(): void
    {
        try {
            $cols = $this->db->querySingle("SELECT COUNT(*) FROM pragma_table_info('spider_ranges') WHERE name = 'ip_start_num'");
            if ((int)$cols === 0) {
                $this->db->exec("ALTER TABLE spider_ranges ADD COLUMN ip_start_num INTEGER DEFAULT 0");
                $this->db->exec("ALTER TABLE spider_ranges ADD COLUMN ip_end_num INTEGER DEFAULT 0");
                // PHP 侧回填现有数据的数值列
                $rows = $this->db->query("SELECT id, ip_start, ip_end FROM spider_ranges WHERE ip_start_num = 0");
                $update = $this->db->prepare("UPDATE spider_ranges SET ip_start_num = :sn, ip_end_num = :en WHERE id = :id");
                while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                    $startNum = ip2long($row['ip_start']) ?: 0;
                    $endNum = ip2long($row['ip_end']) ?: 0;
                    $update->bindValue(':sn', $startNum, SQLITE3_INTEGER);
                    $update->bindValue(':en', $endNum, SQLITE3_INTEGER);
                    $update->bindValue(':id', $row['id'], SQLITE3_INTEGER);
                    $update->execute();
                    $update->reset();
                }
            }
        } catch (\Exception $e) {
            // 列可能已存在，忽略错误
        }
    }
    
    /**
     * 检查 IP 是否为百度蜘蛛
     * @param string $ip       要检查的 IP 地址
     * @param string $userAgent User-Agent 字符串（可选）
     * @return array 检测结果
     */
    public function check(string $ip, string $userAgent = ''): array
    {
        $result = [
            'ip'            => $ip,
            'is_spider'     => false,
            'spider_type'   => null,
            'match_method'  => null,
            'matched_range' => null,
        ];
        
        // 1. IP 格式验证
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $result['error'] = '无效的 IP 地址';
            return $result;
        }
        
        // 2. 检查是否为私有/保留 IP
        if (isPrivateIP($ip)) {
            $result['is_private'] = true;
            // 私有 IP 继续检查，但不记录
        }
        
        // 3. IP 段匹配检查
        $ipLong = ip2long($ip);
        // IPv6 暂不支持 IP 段匹配（ip2long 返回 false），但继续 UA + rDNS 验证
        if ($ipLong !== false) {
            $rangeMatch = $this->matchIPRange($ipLong);
            
            if ($rangeMatch) {
                $result['is_spider'] = true;
                $result['spider_type'] = $rangeMatch['spider_type'];
                $result['match_method'] = 'ip_range';
                $result['matched_range'] = $rangeMatch['ip_range'];
            }
        } else {
            $result['warning'] = 'IPv6 地址暂不支持 IP 段匹配，仅通过 UA 和反向 DNS 验证';
        }
        
        // 4. User-Agent 关键词匹配
        $uaMatch = null;
        if (!empty($userAgent)) {
            $uaMatch = $this->matchUserAgent($userAgent);
            if ($uaMatch) {
                if ($result['is_spider']) {
                    $result['match_method'] = 'ip_and_ua';
                } else {
                    // 仅 UA 匹配（可能百度使用了新的 IP 段）
                    $result['is_spider'] = true;
                    $result['spider_type'] = $uaMatch;
                    $result['match_method'] = 'user_agent_only';
                }
            }
        }
        
        // 5. 反向DNS验证（生产级验证）
        $rdnsResult = $this->checkReverseDNS($ip);
        $result['rdns_hostname'] = $rdnsResult['hostname'];
        $result['rdns_verified'] = $rdnsResult['verified'];
        $result['forward_confirmed'] = $rdnsResult['forward_confirmed'] ?? false;
        
        // 5.5 如果 spider_type 尚未确定但 rDNS 已验证，从主机名推断蜘蛛类型
        if (empty($result['spider_type']) && $rdnsResult['verified']) {
            $inferred = $this->inferSpiderTypeFromHostname($rdnsResult['hostname']);
            if ($inferred) {
                $result['spider_type'] = $inferred;
            }
        }
        
        // 6. 综合可信度评估
        $this->assessConfidence($result, $uaMatch, $rdnsResult);
        
        // 7. 如果确认为百度蜘蛛，保存到确认列表；反之从列表中移除
        if ($result['is_spider']) {
            $this->saveConfirmedSpider($result);
        } else {
            $this->removeConfirmedSpider($ip);
        }
        
        // 8. 记录查询日志
        $this->logQuery($ip, $userAgent, $result);
        
        return $result;
    }
    
    /**
     * IP 段匹配（使用数值比较，精确可靠）
     */
    private function matchIPRange(int $ipLong): ?array
    {
        $stmt = $this->db->prepare("
            SELECT ip_range, spider_type 
            FROM spider_ranges 
            WHERE is_active = 1 
              AND ip_start_num <= :ip_num 
              AND ip_end_num >= :ip_num2
            LIMIT 1
        ");
        $stmt->bindValue(':ip_num', $ipLong, SQLITE3_INTEGER);
        $stmt->bindValue(':ip_num2', $ipLong, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        return $row ?: null;
    }
    
    /**
     * User-Agent 关键词匹配（DB自定义规则优先 → 内置百度+Google兜底）
     */
    private function matchUserAgent(string $userAgent): ?string
    {
        // 第一优先级：数据库自定义 UA 规则
        $dbMatch = $this->matchCustomUaRules($userAgent);
        if ($dbMatch !== null) return $dbMatch;
        
        // 第二优先级：内置百度和 Google 的 UA 关键词（兜底）
        if (self::$allUAKeywords === null) {
            self::$allUAKeywords = array_merge(BAIDU_SPIDER_UA_KEYWORDS, GOOGLE_SPIDER_UA_KEYWORDS);
        }
        foreach (self::$allUAKeywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return $keyword;
            }
        }
        return null;
    }
    
    /**
     * 从数据库查询自定义 User-Agent 规则
     * @param string $userAgent User-Agent 字符串
     * @return string|null 匹配到的蜘蛛类型
     */
    private function matchCustomUaRules(string $userAgent): ?string
    {
        try {
            $tableCheck = $this->db->querySingle(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='custom_ua_rules'"
            );
            if ((int)$tableCheck === 0) return null;
            
            $stmt = $this->db->prepare(
                "SELECT id, ua_keyword, spider_type, match_type 
                 FROM custom_ua_rules 
                 WHERE is_active = 1 
                 ORDER BY sort_order ASC, id ASC"
            );
            $result = $stmt->execute();
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $pattern = $row['ua_keyword'];
                $matchType = $row['match_type'] ?? 'contains';
                
                $matched = false;
                if ($matchType === 'regex') {
                    $matched = (@preg_match('/' . $pattern . '/i', $userAgent) === 1);
                } else {
                    // contains（默认，不区分大小写）
                    $matched = (stripos($userAgent, $pattern) !== false);
                }
                
                if ($matched) {
                    return $row['spider_type'];
                }
            }
        } catch (\Exception $e) {
            // 表可能不存在
        }
        return null;
    }
    
    /**
     * 反向DNS查询（PTR记录）
     * 真正百度蜘蛛的IP做反向DNS会解析到 *.baidu.com 等域名
     * 
     * @param string $ip IP地址
     * @return array ['hostname' => string|null, 'verified' => bool]
     */
    private function checkReverseDNS(string $ip): array
    {
        $result = [
            'hostname' => null,
            'verified' => false,
        ];
        
        // 私有IP不做DNS查询
        if (isPrivateIP($ip)) {
            return $result;
        }
        
        // 使用缓存避免同一IP重复查询
        if (array_key_exists($ip, $this->dnsCache)) {
            return $this->dnsCache[$ip];
        }
        
        // 设置 DNS 查询超时保护（防止 gethostbyaddr 阻塞过久）
        $originalTimeout = ini_get('default_socket_timeout');
        @ini_set('default_socket_timeout', 3);
        
        // 执行反向DNS查询（gethostbyaddr）
        $hostname = @gethostbyaddr($ip);
        if ($hostname === false) {
            error_log("SpiderChecker: gethostbyaddr() failed for IP: {$ip}");
        }
        
        // 如果查询失败或返回原IP，说明没有PTR记录
        if ($hostname === false || $hostname === $ip) {
            $this->dnsCache[$ip] = $result;
            @ini_set('default_socket_timeout', $originalTimeout);
            return $result;
        }
        
        $result['hostname'] = $hostname;
        
        // 从主机名推断蜘蛛类型，若能识别则视为已验证
        $inferredType = $this->inferSpiderTypeFromHostname($hostname);
        if ($inferredType !== null) {
            $result['verified'] = true;
            
            // 正向DNS双重确认（可选，不影响验证结果）
            $forwardIP = @gethostbyname($hostname);
            if ($forwardIP === $hostname) {
                error_log("SpiderChecker: gethostbyname() failed for hostname: {$hostname}");
            }
            if ($forwardIP === $ip) {
                $result['forward_confirmed'] = true;
            }
        }
        
        $this->dnsCache[$ip] = $result;
        
        // 恢复原始 socket 超时设置
        @ini_set('default_socket_timeout', $originalTimeout);
        
        return $result;
    }
    
    /**
     * 从反向DNS主机名推断蜘蛛类型
     * 优先级：数据库自定义规则 > 硬编码内置规则
     * 支持 Google、Baidu、Bing、Yandex、Sogou、Yahoo、Applebot、DuckDuckGo 等
     */
    private function inferSpiderTypeFromHostname(?string $hostname): ?string
    {
        if (empty($hostname)) return null;
        $h = strtolower($hostname);
        
        // ============================================
        // 第一优先级：数据库中的自定义规则
        // ============================================
        $customRule = $this->matchCustomRdnsRules($h);
        if ($customRule !== null) return $customRule;
        
        // ============================================
        // 第二优先级：内置硬编码规则（保留作为兜底）
        // ============================================
        
        // Google 蜘蛛
        if (strpos($h, 'googlebot.com') !== false) return 'Googlebot';
        if (strpos($h, '.google.com') !== false && preg_match('/(?:crawl|bot|spider)/', $h)) return 'Googlebot';
        
        // 百度蜘蛛
        if (strpos($h, 'crawl.baidu.com') !== false) return 'Baiduspider';
        if (strpos($h, '.baidu.com') !== false && preg_match('/(?:spider|crawl)/', $h)) return 'Baiduspider';
        
        // Bing 蜘蛛
        if (strpos($h, 'search.msn.com') !== false) return 'Bingbot';
        if (preg_match('/msnbot/', $h)) return 'Bingbot';
        
        // Yandex 蜘蛛
        if (preg_match('/\.yandex\.(?:com|ru|net)/', $h)) return 'YandexBot';
        
        // Sogou 蜘蛛
        if (strpos($h, '.sogou.com') !== false) return 'Sogou Spider';
        
        // Yahoo 蜘蛛
        if (preg_match('/(?:crawl|spider)\.yahoo/', $h)) return 'Yahoo Slurp';
        
        // Applebot
        if (strpos($h, '.apple.com') !== false && preg_match('/bot/', $h)) return 'Applebot';
        
        // DuckDuckGo
        if (strpos($h, 'duckduckgo.com') !== false) return 'DuckDuckBot';
        
        // Semrush / Ahrefs 等 SEO 工具
        if (strpos($h, '.semrush.com') !== false) return 'SemrushBot';
        if (strpos($h, '.ahrefs.com') !== false) return 'AhrefsBot';
        
        // 通用爬虫关键词检测
        if (preg_match('/^(?:crawl|spider|bot|scraper|fetcher)[\.-]/', $h)) {
            // 尝试提取域名中的主体标识
            if (preg_match('/(?:crawl|spider|bot|scraper|fetcher)[\.-](?:[\w-]+\.)*?([\w-]+)\.(?:com|net|org|cn|io)/', $h, $m)) {
                $name = str_replace('-', ' ', $m[1]);
                return ucwords($name) . ' Spider';
            }
            return 'Web Crawler';
        }
        
        // 最后的兜底：任何包含 crawl/spider/bot 关键词的主机名
        if (preg_match('/(?:crawl|spider|bot)/', $h)) return 'Unknown Spider';
        
        return null;
    }
    
    /**
     * 从数据库查询自定义反向 DNS 规则
     * 支持三种匹配模式：contains（默认）、regex（正则）、exact（精确匹配）
     * 
     * @param string $hostnameLower 已转小写的主机名
     * @return string|null 匹配到的蜘蛛类型，未匹配返回 null
     */
    private function matchCustomRdnsRules(string $hostnameLower): ?string
    {
        try {
            // 确保表存在
            $tableCheck = $this->db->querySingle(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='custom_rdns_rules'"
            );
            if ((int)$tableCheck === 0) return null;
            
            $stmt = $this->db->prepare(
                "SELECT id, hostname_pattern, spider_type, match_type 
                 FROM custom_rdns_rules 
                 WHERE is_active = 1 
                 ORDER BY sort_order ASC, id ASC"
            );
            $result = $stmt->execute();
            
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $pattern = $row['hostname_pattern'];
                $matchType = $row['match_type'] ?? 'contains';
                
                $matched = false;
                
                switch ($matchType) {
                    case 'regex':
                        // 正则匹配（使用 @ 抑制格式错误的正则表达式警告）
                        $matched = (@preg_match('/' . $pattern . '/i', $hostnameLower) === 1);
                        break;
                    case 'exact':
                        // 精确匹配（全主机名完全一致）
                        $matched = ($hostnameLower === strtolower($pattern));
                        break;
                    case 'contains':
                    default:
                        // 包含匹配（默认模式，不区分大小写）
                        $matched = (strpos($hostnameLower, strtolower($pattern)) !== false);
                        break;
                }
                
                if ($matched) {
                    return $row['spider_type'];
                }
            }
        } catch (\Exception $e) {
            // 表可能不存在，静默处理
        }
        
        return null;
    }
    
    /**
     * 综合可信度评估
     * 综合 IP段、User-Agent、反向DNS 三重验证结果
     */
    private function assessConfidence(array &$result, ?string $uaMatch, array $rdnsResult): void
    {
        $ipMatch = ($result['match_method'] === 'ip_range' || $result['match_method'] === 'ip_and_ua');
        $uaProvided = ($uaMatch !== null);
        $forwardConfirmed = $rdnsResult['forward_confirmed'] ?? false;
        $spiderType = $result['spider_type'] ?? '';
        $isBingbot = (stripos($spiderType, 'Bingbot') !== false);
        
        // 构建匹配方式描述
        if ($ipMatch && $uaProvided && $rdnsResult['verified']) {
            // 🟢 三重验证全部通过 → 生产级确认
            $result['match_method'] = 'ip_ua_rdns';
            $result['confidence'] = 'verified';
            $result['spider_type'] = $result['spider_type'] ?? $uaMatch;
        } elseif ($ipMatch && $rdnsResult['verified']) {
            // 🟢 IP + rDNS 通过 → 高可信度；双向DNS确认为已验证
            $result['match_method'] = 'ip_and_rdns';
            $result['confidence'] = $forwardConfirmed ? 'verified' : 'high';
        } elseif ($ipMatch && $uaProvided) {
            // 🟡 IP + UA 通过 但 rDNS 失败
            $result['match_method'] = 'ip_and_ua';
            $result['confidence'] = 'medium';
            $result['warning'] = '反向DNS验证未通过，可能是新IP段或DNS未及时更新';
        } elseif ($uaProvided && $rdnsResult['verified']) {
            // 🟡 UA + rDNS 通过 但 IP段未知
            $result['is_spider'] = true;
            $result['spider_type'] = $uaMatch;
            $result['match_method'] = 'ua_and_rdns';
            $result['confidence'] = $forwardConfirmed ? 'high' : 'medium';
        } elseif ($ipMatch) {
            // 🟠 仅IP匹配
            $result['confidence'] = 'low';
            $result['warning'] = '仅IP段匹配，缺少UA和反向DNS验证，可能是伪造';
        } elseif ($rdnsResult['verified']) {
            // 🟠 仅rDNS通过；双向DNS确认则升级为高可信度
            $result['is_spider'] = true;
            $result['match_method'] = 'rdns_only';
            $result['confidence'] = $forwardConfirmed ? 'high' : 'low';
            // 从 rDNS 主机名推断蜘蛛类型
            $result['spider_type'] = $result['spider_type'] ?: $this->inferSpiderTypeFromHostname($rdnsResult['hostname']);
            // Bingbot 等无IP段数据的蜘蛛，提示使用官方验证工具
            if (stripos($result['spider_type'] ?? '', 'Bingbot') !== false) {
                $result['warning'] = '仅通过反向DNS识别为Bingbot，请使用页面下方的 Bingbot 官方验证工具进一步确认';
            } elseif (!$forwardConfirmed) {
                $result['warning'] = '仅反向DNS匹配，缺少IP段和UA验证';
            }
        } elseif ($uaProvided) {
            // 仅UA匹配（已在上面处理）
            $result['confidence'] = $result['confidence'] ?? 'medium';
        }
        
        // 极端情况：IP匹配但UA和rDNS都不匹配 → 高度可疑
        if ($ipMatch && !$uaProvided && !$rdnsResult['verified']) {
            $result['confidence'] = 'low';
            $result['warning'] = ($result['warning'] ?? '') ?: '仅IP段匹配，缺少UA和反向DNS验证，可能是伪造';
        }
    }
    
    /**
     * 记录查询日志到当前会话（仅当前用户可见，最多保留20条）
     * 同时写入全局统计表（仅用于计数）
     */
    private function logQuery(string $ip, string $userAgent, array $result): void
    {
        // 1. 写入当前会话（私有的查询记录）
        if (!isset($_SESSION['query_log'])) {
            $_SESSION['query_log'] = [];
        }
        
        array_unshift($_SESSION['query_log'], [
            'ip_address'  => $ip,
            'is_spider'   => $result['is_spider'] ? 1 : 0,
            'spider_type' => $result['spider_type'],
            'user_agent'  => mb_substr($userAgent, 0, 500),
            'country'     => null,
            'city'        => null,
            'query_time'  => date('Y-m-d H:i:s'),
        ]);
        
        if (count($_SESSION['query_log']) > 20) {
            $_SESSION['query_log'] = array_slice($_SESSION['query_log'], 0, 20);
        }
        
        // 2. 写入全局统计表（仅 IP + 是否蜘蛛，用于全局统计计数）
        try {
            // 确保统计表存在（兼容旧数据库）
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS query_stats (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip_address TEXT NOT NULL,
                    is_spider INTEGER DEFAULT 0,
                    query_time DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            $stmt = $this->db->prepare("
                INSERT INTO query_stats (ip_address, is_spider, query_time)
                VALUES (:ip, :is_spider, datetime('now', 'localtime'))
            ");
            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $stmt->bindValue(':is_spider', $result['is_spider'] ? 1 : 0, SQLITE3_INTEGER);
            $stmt->execute();
            
            // 查询日志自动清理已移至 auto_update.php 调度器（每天执行一次）
        } catch (Exception $e) {
            // 静默处理
        }
    }
    
    /**
     * 更新最近一条日志的地理位置信息
     * 在 GeoIP 查询完成后调用，补充国家/城市数据
     */
    public function updateLastLogGeo(string $ip, array $geo): void
    {
        if (empty($_SESSION['query_log'])) return;
        
        // 更新第一条记录（最近插入的）
        $_SESSION['query_log'][0]['country'] = $geo['country'] ?? '未知';
        $_SESSION['query_log'][0]['city'] = $geo['city'] ?? '未知';
    }
    
    /**
     * 获取所有活跃的蜘蛛 IP 段
     */
    public function getAllRanges(): array
    {
        $results = [];
        $query = $this->db->query("
            SELECT ip_range, spider_type, source, created_at 
            FROM spider_ranges 
            WHERE is_active = 1 
            ORDER BY ip_range
        ");
        while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
            $results[] = $row;
        }
        return $results;
    }
    
    /**
     * 获取所有蜘蛛 IP 段（包括非活跃的）- 仅供后台管理使用
     */
    public function getAllRangesAdmin(): array
    {
        $results = [];
        $query = $this->db->query("
            SELECT id, ip_range, spider_type, source, is_active, created_at 
            FROM spider_ranges 
            ORDER BY ip_range
        ");
        while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
            $results[] = $row;
        }
        return $results;
    }
    
    /**
     * 分页获取蜘蛛 IP 段（后台管理用，支持搜索和分类过滤）
     */
    public function getRangesPaginated(int $page = 1, int $perPage = 50, string $search = '', string $filterType = '', string $filterSource = ''): array
    {
        $offset = ($page - 1) * $perPage;
        $where = '';
        $params = [];
        
        // 构建 WHERE 条件（使用 prepared statements）
        if ($search !== '') {
            // 判断是否为完整 IP 地址：若是则用数值 CIDR 匹配，否则用 LIKE 模糊搜索
            $isFullIP = filter_var($search, FILTER_VALIDATE_IP) !== false;
            if ($isFullIP) {
                $ipNum = ip2long($search);
                if ($ipNum !== false) {
                    $where .= " AND ip_start_num <= :ip_num AND ip_end_num >= :ip_num2";
                    $params[':ip_num'] = $ipNum;
                    $params[':ip_num2'] = $ipNum;
                }
            } else {
                $where .= " AND ip_range LIKE :search";
                $params[':search'] = '%' . addcslashes($this->db->escapeString($search), '%_') . '%';
            }
        }
        if ($filterType !== '') {
            $where .= " AND spider_type = :type";
            $params[':type'] = $filterType;
        }
        if ($filterSource !== '') {
            $where .= " AND source = :source";
            $params[':source'] = $filterSource;
        }
        
        // 总数查询
        $countSql = "SELECT COUNT(*) FROM spider_ranges WHERE 1=1 {$where}";
        $countStmt = $this->db->prepare($countSql);
        foreach ($params as $k => $v) {
            $type = (strpos($k, 'ip_num') !== false) ? SQLITE3_INTEGER : SQLITE3_TEXT;
            $countStmt->bindValue($k, $v, $type);
        }
        $total = (int)$countStmt->execute()->fetchArray(SQLITE3_NUM)[0];
        
        // 数据查询
        $results = [];
        $dataSql = "SELECT id, ip_range, spider_type, source, confidence, is_active, created_at FROM spider_ranges WHERE 1=1 {$where} ORDER BY ip_range LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($dataSql);
        foreach ($params as $k => $v) {
            $type = (strpos($k, 'ip_num') !== false) ? SQLITE3_INTEGER : SQLITE3_TEXT;
            $stmt->bindValue($k, $v, $type);
        }
        $stmt->bindValue(':limit', $perPage, SQLITE3_INTEGER);
        $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
        $query = $stmt->execute();
        while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
            $results[] = $row;
        }
        
        return [
            'ranges' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / max($perPage, 1)),
        ];
    }
    
    /**
     * 获取全局统计信息
     */
    public function getStats(): array
    {
        // 获取最后更新时间（优先 update_records 表，回退到 GeoIP 数据库文件时间）
        $lastUpdate = null;
        try {
            $lastUpdate = $this->db->querySingle("SELECT MAX(created_at) FROM update_records WHERE status = 'success'");
        } catch (\Exception $e) {
            // update_records 表可能不存在
        }
        if (empty($lastUpdate) && file_exists(GEOIP_DB_PATH)) {
            $lastUpdate = date('Y-m-d H:i:s', filemtime(GEOIP_DB_PATH));
        }
        
        // 查询统计（兼容表不存在的情况）
        $totalQueries = 0;
        $spiderQueries = 0;
        $uniqueIPs = 0;
        try {
            $totalQueries = (int)$this->db->querySingle("SELECT COUNT(*) FROM query_stats");
            $spiderQueries = (int)$this->db->querySingle("SELECT COUNT(*) FROM query_stats WHERE is_spider = 1");
            $uniqueIPs = (int)$this->db->querySingle("SELECT COUNT(DISTINCT ip_address) FROM query_stats");
        } catch (\Exception $e) {
            // query_stats 表可能不存在
        }
        
        return [
            'total_ranges'    => $this->db->querySingle("SELECT COUNT(*) FROM spider_ranges WHERE is_active = 1"),
            'total_queries'   => $totalQueries,
            'spider_queries'  => $spiderQueries,
            'last_update'     => $lastUpdate,
            'unique_ips'      => $uniqueIPs,
        ];
    }
    
    /**
     * 获取当前会话的最近查询记录
     */
    public function getRecentQueries(int $limit = 50): array
    {
        $log = $_SESSION['query_log'] ?? [];
        return array_slice($log, 0, $limit);
    }
    
    /**
     * 清空当前会话的查询日志
     */
    public function clearLogs(): int
    {
        $count = count($_SESSION['query_log'] ?? []);
        $_SESSION['query_log'] = [];
        return $count;
    }
    
    /**
     * 保存确认为百度蜘蛛的IP到去重列表
     */
    private function saveConfirmedSpider(array $result): void
    {
        try {
            $this->ensureConfirmedTable();
            
            $ip = $result['ip'];
            
            // 使用 prepared statement 查询（而非字符串拼接）
            $stmtCheck = $this->db->prepare("SELECT ip_address FROM confirmed_spiders WHERE ip_address = :ip");
            $stmtCheck->bindValue(':ip', $ip, SQLITE3_TEXT);
            $existing = $stmtCheck->execute()->fetchArray(SQLITE3_ASSOC);
            
            if ($existing) {
                // IP 已存在，更新记录
                $stmt = $this->db->prepare("
                    UPDATE confirmed_spiders SET
                        match_method = COALESCE(:method, match_method),
                        matched_range = COALESCE(:range, matched_range),
                        rdns_hostname = COALESCE(:rdns, rdns_hostname),
                        confidence = :conf,
                        last_seen = datetime('now', 'localtime'),
                        seen_count = seen_count + 1
                    WHERE ip_address = :ip
                ");
            } else {
                // 新 IP，插入记录
                $stmt = $this->db->prepare("
                    INSERT INTO confirmed_spiders 
                        (ip_address, spider_type, match_method, matched_range, rdns_hostname, confidence, first_seen, last_seen)
                    VALUES (:ip, :type, :method, :range, :rdns, :conf, datetime('now','localtime'), datetime('now','localtime'))
                ");
            }
            
            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $stmt->bindValue(':type', $result['spider_type'], SQLITE3_TEXT);
            $stmt->bindValue(':method', $result['match_method'], SQLITE3_TEXT);
            $stmt->bindValue(':range', $result['matched_range'], SQLITE3_TEXT);
            $stmt->bindValue(':rdns', $result['rdns_hostname'], SQLITE3_TEXT);
            $stmt->bindValue(':conf', $result['confidence'] ?? '', SQLITE3_TEXT);
            $stmt->execute();
            
            // 自动导入到 spider_ranges（启用了自动导入 且 可信度为高或已验证）
            $conf = $result['confidence'] ?? '';
            if (in_array($conf, ['high', 'verified'], true)) {
                $this->autoImportToRanges($ip, $result['spider_type'], $conf);
            }
        } catch (Exception $e) {
            // 静默处理
        }
    }
    
    /**
     * 自动导入开关缓存（避免每次蜘蛛检测都查询 admin_settings 表）
     * @var bool|null
     */
    private static ?bool $autoImportEnabledCache = null;
    
    /**
     * 自动将确认的蜘蛛 IP 导入到 spider_ranges 表
     */
    private function autoImportToRanges(string $ip, string $spiderType, string $confidence = ''): void
    {
        try {
            // 检查自动导入开关（使用静态缓存，整个请求生命周期仅查询一次）
            if (self::$autoImportEnabledCache === null) {
                $checkStmt = $this->db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = 'auto_import_confirmed' LIMIT 1");
                $row = $checkStmt->execute()->fetchArray(SQLITE3_ASSOC);
                self::$autoImportEnabledCache = ($row && $row['setting_value'] === '1');
            }
            if (!self::$autoImportEnabledCache) return; // 未启用
            
            $ipLong = ip2long($ip);
            if ($ipLong === false && strpos($ip, ':') === false) return;
            
            // 确保 spider_ranges 表存在
            $this->db->exec("CREATE TABLE IF NOT EXISTS spider_ranges (
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
            
            $stmt = $this->db->prepare("
                INSERT OR IGNORE INTO spider_ranges 
                    (ip_range, cidr_notation, ip_start, ip_end, ip_start_num, ip_end_num, spider_type, source, confidence, is_active, created_at, updated_at)
                VALUES (:range, :cidr, :start, :end, :startNum, :endNum, :type, 'auto_import', :conf, 1, datetime('now','localtime'), datetime('now','localtime'))
            ");
            $stmt->bindValue(':range', $ip, SQLITE3_TEXT);
            $stmt->bindValue(':cidr', $ip, SQLITE3_TEXT);
            $stmt->bindValue(':start', $ip, SQLITE3_TEXT);
            $stmt->bindValue(':end', $ip, SQLITE3_TEXT);
            $stmt->bindValue(':startNum', $ipLong ?: 0, SQLITE3_INTEGER);
            $stmt->bindValue(':endNum', $ipLong ?: 0, SQLITE3_INTEGER);
            $stmt->bindValue(':type', $spiderType ?: 'Baiduspider', SQLITE3_TEXT);
            $stmt->bindValue(':conf', $confidence, SQLITE3_TEXT);
            $stmt->execute();
        } catch (\Exception $e) {
            // 静默处理 — 自动导入失败不影响主流程
        }
    }
    
    /**
     * 确保 confirmed_spiders 表存在（兼容旧数据库升级）
     */
    private function ensureConfirmedTable(): void
    {
        $this->db->exec("
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
    }
    
    /**
     * 获取所有已确认的百度蜘蛛IP列表
     */
    public function getConfirmedSpiders(string $search = '', string $filterType = '', string $filterConf = ''): array
    {
        $results = [];
        try {
            $this->ensureConfirmedTable();
            
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
            
            $sql = "SELECT ip_address, spider_type, match_method, matched_range, 
                       rdns_hostname, confidence, first_seen, last_seen, seen_count
                FROM confirmed_spiders 
                WHERE 1=1 {$where}
                ORDER BY last_seen DESC";
            
            $stmt = $this->db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, SQLITE3_TEXT);
            }
            $query = $stmt->execute();
            while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
                $results[] = $row;
            }
        } catch (Exception $e) {
            // 表可能不存在，返回空数组
        }
        return $results;
    }
    
    /**
     * 从确认列表中移除IP（该IP再次查询时不再被认定为蜘蛛）
     * 同时从 spider_ranges 中移除匹配的单IP条目（不影响 CIDR 网段）
     */
    private function removeConfirmedSpider(string $ip): void
    {
        try {
            $this->ensureConfirmedTable();
            
            // 1. 从确认表中移除
            $stmt = $this->db->prepare("DELETE FROM confirmed_spiders WHERE ip_address = :ip");
            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $stmt->execute();
            $removedFromConfirmed = $this->db->changes();
            
            // 2. 同步从 spider_ranges 中移除匹配的单IP条目
            //    CIDR 网段（如 116.179.32.0/24）不受影响，仅移除精确匹配的单IP
            if ($removedFromConfirmed > 0) {
                $stmt2 = $this->db->prepare("DELETE FROM spider_ranges WHERE ip_range = :ip");
                $stmt2->bindValue(':ip', $ip, SQLITE3_TEXT);
                $stmt2->execute();
                if ($this->db->changes() > 0) {
                    error_log("[SpiderChecker] IP {$ip} 不再被认定为蜘蛛，已从确认列表和IP管理中移除");
                }
            }
        } catch (Exception $e) {
            // 静默处理
        }
    }
    
    public function __destruct()
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}

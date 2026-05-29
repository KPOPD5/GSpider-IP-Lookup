<?php
/**
 * GeoIP2 地理位置查询类
 * 支持 MaxMind GeoLite2-City 数据库
 */

require_once __DIR__ . '/config.php';

class GeoIPLookup
{
    private string $dbPath;
    
    public function __construct()
    {
        $this->dbPath = GEOIP_DB_PATH;
    }
    
    /**
     * 查询 IP 的地理位置信息
     * @param string $ip IP 地址
     * @return array 地理位置信息
     */
    public function lookup(string $ip): array
    {
        $result = [
            'ip'            => $ip,
            'country'       => '未知',
            'country_code'  => '--',
            'city'          => '未知',
            'latitude'      => null,
            'longitude'     => null,
            'timezone'      => null,
            'is_private'    => false,
        ];
        
        // 验证 IP 格式
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $result['error'] = '无效的 IP 地址';
            return $result;
        }
        
        // 检查私有 IP
        if (isPrivateIP($ip)) {
            $result['is_private'] = true;
            $result['country'] = '局域网';
            $result['country_code'] = 'LAN';
            $result['city'] = '本地网络';
            return $result;
        }
        
        // 检查 GeoIP2 数据库文件是否存在
        if (!file_exists($this->dbPath)) {
            $result['error'] = 'GeoIP2 数据库未安装，请先下载数据库文件';
            $result['country'] = $this->fallbackLookup($ip)['country'] ?? '未知';
            return $result;
        }
        
        try {
            // 使用 MaxMind GeoIP2 Reader 查询
            if (class_exists('GeoIp2\Database\Reader')) {
                return $this->lookupWithGeoIP2($ip, $result);
            } else {
                // 回退到纯 PHP 解析（简化版）
                return $this->fallbackLookup($ip);
            }
        } catch (Exception $e) {
            error_log('[GeoIPLookup] lookup error: ' . $e->getMessage());
            $result['error'] = '查询失败，请稍后重试';
            return $result;
        }
    }
    
    /**
     * 使用 GeoIP2 PHP API 查询（GeoLite2 为主，DB-IP Lite 补充城市数据）
     */
    private function lookupWithGeoIP2(string $ip, array $result): array
    {
        $useGeoLite2 = $this->isDBEnabled('db_geolite2_enabled', true);
        
        if ($useGeoLite2) {
            try {
                // 指定中文优先、英语兜底的语言顺序
                $reader = new GeoIp2\Database\Reader($this->dbPath, ['zh-CN', 'en']);
                $record = $reader->city($ip);
            
            $result['country'] = $record->country->name ?? '未知';
            $result['country_code'] = $record->country->isoCode ?? '--';
            $result['city'] = $record->city->name ?? '未知';
            $result['latitude'] = $record->location->latitude ?? null;
            $result['longitude'] = $record->location->longitude ?? null;
            $result['timezone'] = $record->location->timeZone ?? null;
            $result['postal_code'] = $record->postal->code ?? null;
            $result['subdivision'] = $record->mostSpecificSubdivision->name ?? null;
            
            $reader->close();
            
            // 如果 GeoLite2 未返回城市数据，按优先级回退
            if ($result['city'] === '未知' || $result['city'] === null) {
                $this->runFallbacks($ip, $result);
            }
            
        } catch (GeoIp2\Exception\AddressNotFoundException $e) {
                $result['country'] = '未知';
                $result['country_code'] = '--';
                $result['city'] = '未找到';
                $this->runFallbacks($ip, $result);
            }
        } else {
            // GeoLite2 已禁用，直接使用回退数据库
            $this->runFallbacks($ip, $result);
        }
        
        return $result;
    }
    
    /**
     * 按优先级运行所有回退数据源
     */
    private function runFallbacks(string $ip, array &$result): void
    {
        // 1. IP2Location.io API
        if (($result['city'] === '未知' || $result['city'] === null || $result['city'] === '未找到') 
            && $this->isDBEnabled('db_ip2location_io_enabled', false)) {
            $this->supplementFromIP2LocationIO($ip, $result);
        }
        // 2. DB-IP Lite
        if (($result['city'] === '未知' || $result['city'] === null || $result['city'] === '未找到') 
            && $this->isDBEnabled('db_dbip_enabled', true) && file_exists(DBIP_DB_PATH)) {
            $this->supplementCityFromDBIP($ip, $result);
        }
        // 3. IP2Location LITE
        if (($result['city'] === '未知' || $result['city'] === null || $result['city'] === '未找到') 
            && $this->isDBEnabled('db_ip2location_enabled', false) && file_exists(__DIR__ . '/geoip/IP2Location-LITE-DB11.BIN')) {
            $this->supplementCityFromIP2Location($ip, $result);
        }
    }
    
    /**
     * 检查 IP 数据库是否在设置中启用
     * 使用 AdminSettings 缓存避免每次调用都创建新的数据库连接
     * @param string $key 设置键名
     * @param bool $default 默认值
     */
    private function isDBEnabled(string $key, bool $default = true): bool
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) return $cache[$key];
        
        $value = $default;
        
        // 优先使用 AdminSettings 的缓存读取（避免重复创建 SQLite 连接）
        try {
            if (file_exists(__DIR__ . '/AdminSettings.php') && class_exists('AdminSettings')) {
                // 如果 AdminSettings 已加载（如来自 ajax.php），直接使用
                // 此处需要延迟实例化以避免 Web 上下文中的循环依赖
            }
        } catch (\Throwable $e) {
            error_log('[GeoIPLookup] isDBEnabled AdminSettings check: ' . $e->getMessage());
        }
        
        if (file_exists(SQLITE_DB_PATH)) {
            try {
                $db = new SQLite3(SQLITE_DB_PATH);
                $stmt = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = :key");
                $stmt->bindValue(':key', $key, SQLITE3_TEXT);
                $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                if ($row && isset($row['setting_value'])) {
                    $value = $row['setting_value'] === '1' || $row['setting_value'] === 'true';
                }
                $db->close();
            } catch (\Exception $e) {
                error_log('[GeoIPLookup] isDBEnabled(' . $key . ') error: ' . $e->getMessage());
            }
        }
        $cache[$key] = $value;
        return $value;
    }
    
    /**
     * 从 IP2Location.io API 补充 IP 信息
     * 免费套餐每月 500 次查询，数据精度高，作为本地库的补充
     */
    private function supplementFromIP2LocationIO(string $ip, array &$result): void
    {
        // 跳过私有 IP
        if (isPrivateIP($ip)) return;
        
        // 读取 API Key
        $apiKey = '';
        if (file_exists(SQLITE_DB_PATH)) {
            try {
                $db = new SQLite3(SQLITE_DB_PATH);
                $row = $db->querySingle("SELECT setting_value FROM admin_settings WHERE setting_key = 'ip2location_io_key'", true);
                if ($row) $apiKey = $row['setting_value'] ?? '';
                $db->close();
            } catch (\Exception $e) {
                return;
            }
        }
        if (empty($apiKey)) return;
        
        try {
            $url = "https://api.ip2location.io/?key=" . urlencode($apiKey) . "&ip=" . urlencode($ip) . "&format=json";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || empty($response)) return;
            
            $data = json_decode($response, true);
            if (!is_array($data) || empty($data)) return;
            
            // 补充空值字段
            if (($result['city'] === '未知' || $result['city'] === null || $result['city'] === '未找到') 
                && !empty($data['city_name']) && $data['city_name'] !== '-') {
                $result['city'] = $data['city_name'];
            }
            
            if (($result['country'] === '未知' || $result['country'] === null) && !empty($data['country_name'])) {
                $result['country'] = $data['country_name'];
            }
            if (($result['country_code'] === '--' || $result['country_code'] === null) && !empty($data['country_code'])) {
                $result['country_code'] = $data['country_code'];
            }
            
            if (($result['subdivision'] ?? '') === '' || $result['subdivision'] === null) {
                if (!empty($data['region_name']) && $data['region_name'] !== '-') {
                    $result['subdivision'] = $data['region_name'];
                }
            }
            
            if ($result['latitude'] === null && isset($data['latitude'])) {
                $result['latitude'] = (float)$data['latitude'];
            }
            if ($result['longitude'] === null && isset($data['longitude'])) {
                $result['longitude'] = (float)$data['longitude'];
            }
            if (($result['timezone'] ?? '') === '' || $result['timezone'] === null) {
                if (!empty($data['time_zone'])) {
                    $result['timezone'] = $data['time_zone'];
                }
            }
            
        } catch (\Exception $e) {
            error_log('[GeoIPLookup] IP2Location.io API error: ' . $e->getMessage());
        }
    }
    
    /**
     * 从 DB-IP Lite 数据库补充城市和省份数据
     * DB-IP 免费版城市覆盖率远高于 GeoLite2，但中文支持不如 GeoLite2
     * 因此仅用于补充 GeoLite2 缺失的字段，不覆盖已有数据
     */
    private function supplementCityFromDBIP(string $ip, array &$result): void
    {
        try {
            $reader = new GeoIp2\Database\Reader(DBIP_DB_PATH, ['zh-CN', 'en']);
            $record = $reader->city($ip);
            
            // 仅补充空值字段
            if (($result['city'] === '未知' || $result['city'] === null || $result['city'] === '未找到') && ($record->city->name ?? '') !== '') {
                $result['city'] = $record->city->name;
            }
            if (($result['subdivision'] ?? '') === '' || $result['subdivision'] === null) {
                $result['subdivision'] = $record->mostSpecificSubdivision->name ?? null;
            }
            // 如果经纬度为空，也补充
            if ($result['latitude'] === null) {
                $result['latitude'] = $record->location->latitude ?? null;
            }
            if ($result['longitude'] === null) {
                $result['longitude'] = $record->location->longitude ?? null;
            }
            
            $reader->close();
        } catch (\Exception $e) {
            // DB-IP 查询失败不影响主流程
            error_log('[GeoIPLookup] DB-IP supplement error: ' . $e->getMessage());
        }
    }
    
    /**
     * 从 IP2Location LITE 数据库补充城市和省份数据
     * IP2Location 使用 BIN 格式，免费版覆盖率高，中文支持较好
     */
    private function supplementCityFromIP2Location(string $ip, array &$result): void
    {
        $binPath = __DIR__ . '/geoip/IP2Location-LITE-DB11.BIN';
        if (!file_exists($binPath)) return;
        
        try {
            if (!class_exists('IP2Location\\Database')) return;
            
            $db = new \IP2Location\Database($binPath, \IP2Location\Database::FILE_IO);
            $records = $db->lookup($ip, \IP2Location\Database::ALL);
            
            if (!$records || empty($records['countryCode']) || $records['countryCode'] === '-') {
                return;
            }
            
            // 仅补充空值字段
            if ($result['city'] === '未知' || $result['city'] === null || $result['city'] === '未找到') {
                $city = $records['cityName'] ?? '';
                if ($city !== '' && $city !== '-') {
                    $result['city'] = $city;
                }
            }
            
            if (($result['country'] === '未知' || $result['country'] === null) && !empty($records['countryName'])) {
                $result['country'] = $records['countryName'];
            }
            if (($result['country_code'] === '--' || $result['country_code'] === null) && !empty($records['countryCode'])) {
                $result['country_code'] = $records['countryCode'];
            }
            
            if (($result['subdivision'] ?? '') === '' || $result['subdivision'] === null) {
                $region = $records['regionName'] ?? '';
                if ($region !== '' && $region !== '-') {
                    $result['subdivision'] = $region;
                }
            }
            
            if ($result['latitude'] === null && !empty($records['latitude'])) {
                $result['latitude'] = (float)$records['latitude'];
            }
            if ($result['longitude'] === null && !empty($records['longitude'])) {
                $result['longitude'] = (float)$records['longitude'];
            }
            
        } catch (\Exception $e) {
            error_log('[GeoIPLookup] IP2Location supplement error: ' . $e->getMessage());
        }
    }
    
    /**
     * 纯 PHP 回退方案 — 直接解析 .mmdb 二进制文件（无需 Composer 依赖）
     * 支持提取：国家名称、国家代码、城市名称、经纬度、时区
     */
    private function fallbackLookup(string $ip): array
    {
        $result = [
            'ip'            => $ip,
            'country'       => '未知',
            'country_code'  => '--',
            'city'          => '未知',
            'subdivision'   => null,
            'latitude'      => null,
            'longitude'     => null,
            'timezone'      => null,
        ];
        
        if (isPrivateIP($ip)) {
            $result['is_private'] = true;
            $result['country'] = '局域网';
            $result['country_code'] = 'LAN';
            $result['city'] = '本地网络';
            return $result;
        }
        
        if (!file_exists($this->dbPath)) {
            return $result;
        }
        
        try {
            $data = $this->lookupWithPurePHP($ip);
            if ($data) {
                $result = array_merge($result, $data);
            }
        } catch (\Exception $e) {
            // 静默回退
        }
        
        return $result;
    }
    
    /**
     * 纯 PHP 解析 MMDB 文件（不需要 geoip2 扩展或 Composer 包）
     * 参考 MaxMind DB 文件格式规范实现
     */
    private function lookupWithPurePHP(string $ip): ?array
    {
        $fh = fopen($this->dbPath, 'rb');
        if (!$fh) return null;
        
        try {
            $fileSize = fstat($fh)['size'];
            if ($fileSize < 20) { fclose($fh); return null; }
            
            // 读取 metadata —— 位于文件末尾
            fseek($fh, -16, SEEK_END);
            $metadataPtrRaw = fread($fh, 16);
            // 解析 128 位无符号整数 (大端)
            $metaPtr = $this->mmdbReadUint128(substr($metadataPtrRaw, 0, 16));
            if ($metaPtr === null || $metaPtr >= $fileSize) { fclose($fh); return null; }
            
            // 读取 metadata 区
            fseek($fh, $metaPtr);
            $metaSection = $this->mmdbReadMap($fh, $metaPtr);
            if (!$metaSection) { fclose($fh); return null; }
            
            $nodeCount = $metaSection['node_count'] ?? 0;
            $recordSize = $metaSection['record_size'] ?? 28;
            $ipVersion = $metaSection['ip_version'] ?? 6;
            
            if ($nodeCount === 0) { fclose($fh); return null; }
            
            $nodeSize = (int)ceil($recordSize / 4); // 每个节点的字节数
            $treeSize = $nodeCount * $nodeSize;
            $dataStart = $treeSize + 16; // 搜索树后紧接着数据段
            
            // 将 IP 转为二进制大端字节
            $ipBinary = inet_pton($ip);
            if (!$ipBinary) { fclose($fh); return null; }
            
            // IPv4 在数据库中以 ::ffff:IPv4 形式存储
            if ($ipVersion === 6 && strlen($ipBinary) === 4) {
                $ipBinary = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . $ipBinary;
            }
            
            $bits = strlen($ipBinary) * 8;
            
            // 遍历二叉搜索树
            $nodeNum = 0;
            $dataPtr = null;
            
            for ($i = 0; $i < $bits && $nodeNum < $nodeCount; $i++) {
                $byteIdx = (int)($i / 8);
                $bitIdx = 7 - ($i % 8);
                $bit = (ord($ipBinary[$byteIdx]) >> $bitIdx) & 1;
                
                // 读取节点
                $nodeOffset = $nodeNum * $nodeSize;
                fseek($fh, $nodeOffset);
                $nodeRaw = fread($fh, $nodeSize);
                
                // 提取左/右指针 (record_size 位宽)
                $leftPtr = $this->mmdbReadRecord($nodeRaw, 0, $recordSize);
                $rightPtr = $this->mmdbReadRecord($nodeRaw, $recordSize, $recordSize);
                
                $ptr = ($bit === 0) ? $leftPtr : $rightPtr;
                
                if ($ptr >= $nodeCount) {
                    // 指向数据段
                    $dataPtr = $dataStart + ($ptr - $nodeCount) - 16;
                    break;
                }
                $nodeNum = $ptr;
            }
            
            if ($dataPtr === null || $dataPtr >= $fileSize) { fclose($fh); return null; }
            
            // 解码数据
            fseek($fh, $dataPtr);
            $decoded = $this->mmdbDecodeData($fh, $dataPtr, $fileSize);
            
            fclose($fh);
            
            if (!is_array($decoded)) return null;
            
            return [
                'country'       => $decoded['country']['names']['zh-CN'] ?? $decoded['country']['names']['en'] ?? '未知',
                'country_code'  => $decoded['country']['iso_code'] ?? '--',
                'city'          => $decoded['city']['names']['zh-CN'] ?? $decoded['city']['names']['en'] ?? '未知',
                'subdivision'   => $decoded['subdivisions'][0]['names']['zh-CN'] ?? $decoded['subdivisions'][0]['names']['en'] ?? null,
                'latitude'      => $decoded['location']['latitude'] ?? null,
                'longitude'     => $decoded['location']['longitude'] ?? null,
                'timezone'      => $decoded['location']['time_zone'] ?? null,
            ];
        } catch (\Exception $e) {
            fclose($fh);
            return null;
        }
    }
    
    // ---- MMDB 二进制解析辅助方法 ---- \\
    
    private function mmdbReadUint128(string $bytes): ?int
    {
        $len = strlen($bytes);
        if ($len === 0) return null;
        // 取高 8 字节（足够容纳文件偏移量）
        $val = 0;
        for ($i = 0; $i < min($len, 8); $i++) {
            $val = ($val << 8) | ord($bytes[$i]);
        }
        return $val;
    }
    
    private function mmdbReadRecord(string $nodeRaw, int $bitOffset, int $recordSize): int
    {
        $val = 0;
        for ($i = 0; $i < $recordSize; $i++) {
            $bytePos = (int)(($bitOffset + $i) / 8);
            $bitPos = 7 - (($bitOffset + $i) % 8);
            if ($bytePos < strlen($nodeRaw)) {
                $bit = (ord($nodeRaw[$bytePos]) >> $bitPos) & 1;
                $val = ($val << 1) | $bit;
            }
        }
        return $val;
    }
    
    private function mmdbReadMap($fh, int $baseOffset): ?array
    {
        fseek($fh, $baseOffset);
        $decoded = $this->mmdbDecodeData($fh, $baseOffset, fstat($fh)['size']);
        return is_array($decoded) ? $decoded : null;
    }
    
    private function mmdbDecodeData($fh, int $offset, int $fileSize, int $depth = 0): mixed
    {
        // 防止恶意构造的 MMDB 文件导致无限递归（栈溢出）
        if ($depth > 100) return null;
        
        fseek($fh, $offset);
        $ctrl = ord(fread($fh, 1));
        $type = ($ctrl >> 5) & 0x07;
        $size = $ctrl & 0x1f;
        
        // 扩展长度
        if ($size === 29) { $size = 29 + ord(fread($fh, 1)); }
        elseif ($size === 30) { $b = fread($fh, 2); $size = 285 + ((ord($b[0]) << 8) | ord($b[1])); }
        elseif ($size === 31) { $b = fread($fh, 3); $size = 65821 + ((ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2])); }
        
        switch ($type) {
            case 0: // 扩展类型
                $extType = ord(fread($fh, 1));
                if ($extType === 0) return null; // null/empty
                // 其他扩展类型返回原始字节
                return fread($fh, $size);
                
            case 1: // 指针
                $ptrBytes = fread($fh, ($size < 3) ? 2 : $size);
                $ptrVal = 0;
                for ($i = 0; $i < strlen($ptrBytes); $i++) { $ptrVal = ($ptrVal << 8) | ord($ptrBytes[$i]); }
                $realPtr = ($size < 3) ? (2048 + $ptrVal) : $ptrVal;
                // 保存当前位置，跟随指针读取后恢复（避免破坏父级 map 遍历）
                $savedPos = ftell($fh);
                $result = $this->mmdbDecodeData($fh, $realPtr, $fileSize, $depth + 1);
                fseek($fh, $savedPos);
                return $result;
                
            case 2: // UTF-8 字符串
                return fread($fh, $size);
                
            case 3: // double (8 bytes)
                $d = fread($fh, 8);
                if (strlen($d) < 8) return 0.0;
                return unpack('E', $d)[1];
                
            case 4: // 字节数组
                return fread($fh, $size);
                
            case 5: case 6: // uint16 / uint32
                $val = 0;
                for ($i = 0; $i < $size; $i++) { $val = ($val << 8) | ord(fread($fh, 1)); }
                return $val;
                
            case 7: // map (key-value 对)
                $map = [];
                for ($i = 0; $i < $size; $i++) {
                    $key = $this->mmdbDecodeData($fh, ftell($fh), $fileSize, $depth + 1);
                    $val = $this->mmdbDecodeData($fh, ftell($fh), $fileSize, $depth + 1);
                    if (is_string($key)) { $map[$key] = $val; }
                }
                return $map;
                
            case 8: // int32
                $val = 0;
                for ($i = 0; $i < $size; $i++) { $val = ($val << 8) | ord(fread($fh, 1)); }
                return $val;
                
            case 9: case 10: // uint64 / uint128 (作为整数返回)
                $val = 0;
                for ($i = 0; $i < $size; $i++) { $val = ($val << 8) | ord(fread($fh, 1)); }
                return $val;
                
            case 11: // 数组
                $arr = [];
                for ($i = 0; $i < $size; $i++) {
                    $arr[] = $this->mmdbDecodeData($fh, ftell($fh), $fileSize, $depth + 1);
                }
                return $arr;
                
            case 14: // boolean
                return (bool)$size;
                
            case 15: // float (4 bytes)
                $f = fread($fh, 4);
                if (strlen($f) < 4) return 0.0;
                return unpack('G', $f)[1];
                
            default:
                return null;
        }
    }
    
    /**
     * 下载最新的 GeoIP2 数据库
     */
    public function downloadDatabase(): array
    {
        $result = ['success' => false, 'message' => ''];
        
        // 创建临时目录
        $tmpDir = sys_get_temp_dir() . '/geoip_update_' . uniqid();
        if (!mkdir($tmpDir, 0755, true)) {
            $result['message'] = '无法创建临时目录';
            return $result;
        }
        
        try {
            $tarFile = $tmpDir . '/GeoLite2-City.tar.gz';
            
            // 流式下载数据库文件到临时文件（避免大文件占用内存）
            $fp = fopen($tarFile, 'wb');
            if (!$fp) {
                $result['message'] = '无法创建临时文件';
                return $result;
            }
            
            $ch = curl_init(getGeoipDbUrl());
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_USERAGENT      => 'MaxMind GeoIP Update Script/1.0',
                CURLOPT_FAILONERROR    => true,
            ]);
            
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            
            if ($httpCode !== 200 || !empty($curlError)) {
                $result['message'] = "下载失败，HTTP状态码: {$httpCode}" . ($curlError ? ", 错误: {$curlError}" : '');
                @unlink($tarFile);
                return $result;
            }
            
            if (filesize($tarFile) === 0) {
                $result['message'] = '下载的文件为空';
                @unlink($tarFile);
                return $result;
            }
            
            // 解压 tar.gz
            $phar = new PharData($tarFile);
            $phar->decompress(); // 得到 .tar 文件
            
            $tarPath = str_replace('.gz', '', $tarFile);
            $phar2 = new PharData($tarPath);
            $phar2->extractTo($tmpDir);
            
            // 查找 .mmdb 文件
            $mmdbFile = $this->findMMDBFile($tmpDir);
            if (!$mmdbFile) {
                $result['message'] = '未找到 .mmdb 数据库文件';
                return $result;
            }
            
            // 备份旧数据库
            if (file_exists($this->dbPath)) {
                copy($this->dbPath, $this->dbPath . '.bak');
            }
            
            // 确保目标目录存在
            $geoipDir = dirname($this->dbPath);
            if (!is_dir($geoipDir)) {
                mkdir($geoipDir, 0755, true);
            }
            
            // 移动新数据库到目标位置（优先 rename，失败则 copy+unlink）
            if (!@rename($mmdbFile, $this->dbPath)) {
                if (!@copy($mmdbFile, $this->dbPath)) {
                    $result['message'] = '无法移动数据库文件到目标位置（权限不足或磁盘空间不足）';
                    return $result;
                }
                @unlink($mmdbFile);
            }
            
            $result['success'] = true;
            $result['message'] = 'GeoIP2 数据库更新成功';
            $result['size'] = filesize($this->dbPath);
            
        } catch (Exception $e) {
            error_log('[GeoIPLookup] downloadDatabase error: ' . $e->getMessage());
            $result['message'] = '更新失败，请稍后重试';
        } finally {
            // 清理临时文件
            $this->rmdirRecursive($tmpDir);
        }
        
        return $result;
    }
    
    /**
     * 在目录中递归查找 .mmdb 文件
     */
    private function findMMDBFile(string $dir): ?string
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'mmdb') {
                return $file->getPathname();
            }
        }
        return null;
    }
    
    /**
     * 递归删除目录
     */
    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
    
    public function __destruct()
    {
        // 无需清理
    }
}

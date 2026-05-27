<?php
/**
 * 管理员认证类
 * 提供登录验证、会话管理、权限检查等功能
 * 
 * 2026 现代方案：bcrypt 密码哈希 + session 令牌 + CSRF 保护
 */

require_once __DIR__ . '/config.php';

class AdminAuth
{
    private SQLite3 $db;
    private int $sessionTimeout = 7200; // 会话超时 2 小时
    private int $maxLoginAttempts = 5;  // 最大登录尝试次数
    private int $lockoutDuration = 900; // 锁定时间 15 分钟
    
    public function __construct()
    {
        $this->ensureDB();
        $this->db = new SQLite3(SQLITE_DB_PATH);
        $this->db->enableExceptions(true);
        $this->ensureAdminTables();
    }
    
    /**
     * 确保数据库和管理相关表存在
     */
    private function ensureDB(): void
    {
        if (!file_exists(SQLITE_DB_PATH)) {
            require_once __DIR__ . '/init_db.php';
        }
        // 确保 session 已启动
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * 确保管理员相关表存在（兼容旧数据库升级）
     * 即使数据库文件已存在，也可能缺少后台管理表
     */
    private function ensureAdminTables(): void
    {
        try {
            // 创建管理员账户表（如不存在）
            $this->db->exec("
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
            
            // 检查是否需要插入默认管理员账户
            // 注意：init_db.php 会优先生成随机密码；此处仅做兜底（理论上不应到达）
            $exists = $this->db->querySingle("SELECT COUNT(*) FROM admin_users");
            if ($exists == 0) {
                // 生成随机安全密码，仅写入日志，绝不使用硬编码密码
                $defaultPassword = substr(bin2hex(random_bytes(8)), 0, 16);
                $defaultHash = password_hash($defaultPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $this->db->prepare("INSERT INTO admin_users (username, password_hash, role, created_at) VALUES ('admin', :hash, 'admin', datetime('now','localtime'))");
                $stmt->bindValue(':hash', $defaultHash, SQLITE3_TEXT);
                $stmt->execute();
                error_log('[AdminAuth] Created default admin user. Username: admin, Password: ' . $defaultPassword . ' (CHANGE IMMEDIATELY!)');
            }
            
            // 创建系统设置表（如不存在）
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
            error_log('[AdminAuth] ensureAdminTables error: ' . $e->getMessage());
        }
    }
    
    /**
     * 用户登录
     * @param string $username 用户名
     * @param string $password 明文密码
     * @return array ['success' => bool, 'message' => string]
     */
    public function login(string $username, string $password): array
    {
        // 检查是否被锁定
        if ($this->isLockedOut()) {
            return ['success' => false, 'message' => '登录尝试次数过多，请15分钟后再试'];
        }
        
        // 查找用户
        $stmt = $this->db->prepare("SELECT id, username, password_hash, role, is_active FROM admin_users WHERE username = :username LIMIT 1");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if (!$user) {
            $this->recordLoginAttempt(false);
            return ['success' => false, 'message' => '用户名或密码错误'];
        }
        
        if (!$user['is_active']) {
            return ['success' => false, 'message' => '该账户已被禁用'];
        }
        
        // 验证密码
        if (!password_verify($password, $user['password_hash'])) {
            $this->recordLoginAttempt(false);
            return ['success' => false, 'message' => '用户名或密码错误'];
        }
        
        // 检查是否需要重新哈希（算法升级）
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $update = $this->db->prepare("UPDATE admin_users SET password_hash = :hash WHERE id = :id");
            $update->bindValue(':hash', $newHash, SQLITE3_TEXT);
            $update->bindValue(':id', $user['id'], SQLITE3_INTEGER);
            $update->execute();
        }
        
        // 登录成功
        $this->recordLoginAttempt(true);
        
        // 防止会话固定攻击：登录成功后重新生成 Session ID
        session_regenerate_id(true);
        
        // 更新最后登录信息
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $update = $this->db->prepare("UPDATE admin_users SET last_login = datetime('now', 'localtime'), login_ip = :ip WHERE id = :id");
        $update->bindValue(':ip', $clientIP, SQLITE3_TEXT);
        $update->bindValue(':id', $user['id'], SQLITE3_INTEGER);
        $update->execute();
        
        // 设置会话
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_role'] = $user['role'];
        $_SESSION['admin_login_time'] = time();
        $_SESSION['admin_last_activity'] = time();
        
        // 生成并存储 CSRF Token
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
        
        return ['success' => true, 'message' => '登录成功'];
    }
    
    /**
     * 检查当前是否已登录
     */
    public function isLoggedIn(): bool
    {
        $sessionId = substr(session_id(), 0, 8);
        
        if (empty($_SESSION['admin_logged_in'])) {
            error_log("[AdminAuth] isLoggedIn: session={$sessionId} NOT logged in (no admin_logged_in flag)");
            return false;
        }
        
        // 检查会话是否过期
        $elapsed = time() - ($_SESSION['admin_last_activity'] ?? 0);
        if ($elapsed > $this->sessionTimeout) {
            error_log("[AdminAuth] isLoggedIn: session={$sessionId} EXPIRED (elapsed={$elapsed}s > timeout={$this->sessionTimeout}s)");
            $this->logout();
            return false;
        }
        
        // 更新最后活动时间
        $_SESSION['admin_last_activity'] = time();
        error_log("[AdminAuth] isLoggedIn: session={$sessionId} OK, user={$_SESSION['admin_username']}");
        return true;
    }
    
    /**
     * 要求登录（未登录则跳转到同一目录下的 login.php）
     */
    public function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            // 登录成功后统一跳转到 index.php（仪表盘首页），避免目录索引问题
            header('Location: login.php?redirect=index.php');
            exit;
        }
    }
    
    /**
     * 登出
     */
    public function logout(): void
    {
        $_SESSION['admin_logged_in'] = false;
        $_SESSION['admin_user_id'] = null;
        $_SESSION['admin_username'] = null;
        $_SESSION['admin_role'] = null;
        $_SESSION['admin_login_time'] = null;
        $_SESSION['admin_csrf_token'] = null;
        session_destroy();
        
        // 清除客户端 session cookie，防止旧 session ID 被重用
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
    }
    
    /**
     * 获取CSRF Token
     */
    public function getCsrfToken(): string
    {
        if (empty($_SESSION['admin_csrf_token'])) {
            $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['admin_csrf_token'];
    }
    
    /**
     * 验证CSRF Token
     */
    public function verifyCsrfToken(string $token): bool
    {
        return hash_equals($_SESSION['admin_csrf_token'] ?? '', $token);
    }
    
    /**
     * 修改密码
     */
    public function changePassword(string $username, string $oldPassword, string $newPassword): array
    {
        $stmt = $this->db->prepare("SELECT id, password_hash FROM admin_users WHERE username = :username LIMIT 1");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => '原密码错误'];
        }
        
        if (strlen($newPassword) < 6) {
            return ['success' => false, 'message' => '新密码长度不能少于6位'];
        }
        
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $update = $this->db->prepare("UPDATE admin_users SET password_hash = :hash WHERE id = :id");
        $update->bindValue(':hash', $newHash, SQLITE3_TEXT);
        $update->bindValue(':id', $user['id'], SQLITE3_INTEGER);
        $update->execute();
        
        return ['success' => true, 'message' => '密码修改成功'];
    }
    
    /**
     * 检查是否被锁定（基于登录尝试次数）
     */
    private function isLockedOut(): bool
    {
        $attempts = $_SESSION['admin_login_attempts'] ?? [];
        $attempts = array_filter($attempts, function($t) {
            return $t > (time() - $this->lockoutDuration);
        });
        
        return count($attempts) >= $this->maxLoginAttempts;
    }
    
    /**
     * 记录登录尝试
     */
    private function recordLoginAttempt(bool $success): void
    {
        if (!isset($_SESSION['admin_login_attempts'])) {
            $_SESSION['admin_login_attempts'] = [];
        }
        
        if ($success) {
            $_SESSION['admin_login_attempts'] = [];
        } else {
            $_SESSION['admin_login_attempts'][] = time();
            // 只保留最近15分钟的记录
            $_SESSION['admin_login_attempts'] = array_filter($_SESSION['admin_login_attempts'], function($t) {
                return $t > (time() - $this->lockoutDuration);
            });
        }
    }
    
    /**
     * 获取后台页面 URL（相对路径，避免 SCRIPT_NAME 双重拼接问题）
     */
    public function getAdminUrl(string $page = ''): string
    {
        // 返回相对于 admin/ 目录的路径
        // 由于所有后台页面都在 admin/ 下，使用简单相对路径即可
        return $page;
    }
    
    public function __destruct()
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}

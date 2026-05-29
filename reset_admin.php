<?php
/**
 * 管理员密码重置工具
 * 
 * 安全限制：
 * - 命令行模式（CLI）：直接使用
 * - 浏览器模式：仅允许本地访问 (127.0.0.1 / ::1)
 * - 禁止从远程网络通过浏览器访问
 * 
 * 使用方法：
 * 1. 命令行：php reset_admin.php
 * 2. 命令行设置自定义密码：SET ADMIN_RESET_PASSWORD=myPass123 && php reset_admin.php
 * 3. 浏览器：仅限 http://127.0.0.1/reset_admin.php 访问
 * 
 * 使用后请立即删除此文件！
 */

// ============================================
// 访问控制：仅允许 CLI 或本地浏览器访问
// ============================================
$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowedLocal = ['127.0.0.1', '::1', 'localhost'];
    if (!in_array($remoteAddr, $allowedLocal, true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        die("❌ 禁止访问：此脚本仅允许通过命令行（CLI）或本地浏览器（127.0.0.1）访问。\n"
          . "   请通过 SSH 执行：php reset_admin.php\n"
          . "   或设置环境变量 ADMIN_RESET_PASSWORD 后执行。\n");
    }
}

// 要设置的密码（优先级：环境变量 > 自动生成）
// 用法：SET ADMIN_RESET_PASSWORD=你的密码 && php reset_admin.php
$newPassword = '';
if (function_exists('getenv')) {
    $newPassword = getenv('ADMIN_RESET_PASSWORD') ?: '';
}
if (empty($newPassword)) {
    // 自动生成一个强随机密码
    $newPassword = substr(bin2hex(random_bytes(12)), 0, 16);
    echo "⚠️  未设置 ADMIN_RESET_PASSWORD 环境变量，已自动生成随机密码。\n\n";
}

// ====== 以下代码请勿修改 ======

require_once __DIR__ . '/config.php';

// 如果数据库不存在，先初始化
if (!file_exists(SQLITE_DB_PATH)) {
    require_once __DIR__ . '/init_db.php';
    echo "✅ 数据库已初始化完成\n";
}

try {
    $db = new SQLite3(SQLITE_DB_PATH);
    $db->enableExceptions(true);

    // 检查表是否存在
    $tableExists = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='admin_users'");
    if (!$tableExists) {
        // 手动创建 admin_users 表
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
    }

    // 生成新密码哈希
    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    // 检查是否已有 admin 用户
    $exists = $db->querySingle("SELECT COUNT(*) FROM admin_users WHERE username='admin'");
    
    if ($exists) {
        $stmt = $db->prepare("UPDATE admin_users SET password_hash=:hash WHERE username='admin'");
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->execute();
        echo "✅ 管理员密码已重置成功！\n";
    } else {
        $stmt = $db->prepare("INSERT INTO admin_users (username, password_hash, role, created_at) VALUES ('admin', :hash, 'admin', datetime('now','localtime'))");
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->execute();
        echo "✅ 管理员账户已创建成功！\n";
    }

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo " 用户名: admin\n";
    echo " 密　码: {$newPassword}\n";
    echo " 后台地址: 请访问 admin/login.php\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "⚠️  安全提醒：请立即删除本文件 (reset_admin.php)！\n";
    echo "⚠️  登录后请在后台及时修改密码！\n";

} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
}

<?php
/**
 * 管理员密码重置工具
 * 
 * 使用方法（二选一）：
 * 1. 命令行：php reset_admin.php
 * 2. 浏览器：访问 https://你的域名/reset_admin.php
 * 
 * 使用后请立即删除此文件！
 */

// 要设置的密码（修改引号内的文字即可）
$newPassword = 'admin888';

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

<?php
/**
 * 后台管理 - 登录页面
 */

// 诊断：确认 PHP 已执行（定位后删除）
error_log('[AdminLogin] PHP execution started, PHP version=' . PHP_VERSION);

// 极简错误捕获：记录所有致命错误到日志
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('[AdminLogin] FATAL: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    }
});

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../AdminAuth.php';

if (!file_exists(SQLITE_DB_PATH)) {
    require_once __DIR__ . '/../init_db.php';
}

$auth = new AdminAuth();

if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrfToken = $_POST['csrf_token'] ?? '';
    $sessionCsrfToken = $_SESSION['admin_csrf_token'] ?? '';
    
    // 诊断日志：记录 CSRF 状态
    error_log(sprintf(
        '[AdminLogin] POST user=%s session_id=%s csrf_session=%s csrf_post=%s match=%d',
        $_POST['username'] ?? '(empty)',
        substr(session_id(), 0, 8),
        $sessionCsrfToken ? substr($sessionCsrfToken, 0, 8) . '...' : '(empty)',
        $postCsrfToken ? substr($postCsrfToken, 0, 8) . '...' : '(empty)',
        hash_equals($sessionCsrfToken, $postCsrfToken) ? 1 : 0
    ));
    
    // 初始化 token（首次 GET 访问已设置，此处为防御性兜底）
    if (empty($_SESSION['admin_csrf_token'])) {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }
    
    $csrfValid = (!empty($postCsrfToken) && hash_equals($_SESSION['admin_csrf_token'], $postCsrfToken));
    
    if (!$csrfValid) {
        // CSRF token 不匹配：拒绝请求并记录安全日志
        // 注意：清除浏览器缓存/Cookie 后首次访问需先刷新页面获取新 token
        error_log('[AdminLogin] CSRF token mismatch - request rejected');
        $error = '安全验证失败，请刷新页面后重试';
        // 重新生成 token 供下次使用
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
        // 跳过登录逻辑处理
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = '请输入用户名和密码';
        } else {
            $result = $auth->login($username, $password);
            if ($result['success']) {
                // 防止开放重定向：仅允许相对路径跳转
                $redirect = $_GET['redirect'] ?? 'index.php';
                // 阻止绝对 URL（含协议）和协议相对 URL
                if (strpos($redirect, '://') !== false || strpos($redirect, '//') === 0) {
                    $redirect = 'index.php';
                }
                // 仅允许跳转到 admin/ 目录下的安全页面
                if (!preg_match('#^(index\.php|ajax\.php)$#', $redirect)) {
                    $redirect = 'index.php';
                }
                // 确保重定向前 session 数据已写入磁盘
                session_write_close();
                error_log('[AdminLogin] Login SUCCESS, redirecting to: ' . $redirect);
                header('Location: ' . $redirect);
                exit;
            } else {
                error_log('[AdminLogin] Login FAILED: ' . $result['message']);
                $error = $result['message'];
            }
        }
    }
}

// 生成CSRF Token
if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['admin_csrf_token'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理登录 - 蜘蛛识别查询系统</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        :root {
            --bg: #0f172a;
            --card-bg: rgba(255,255,255,0.03);
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --primary: #6366f1;
            --primary-glow: rgba(99,102,241,0.4);
            --border: rgba(255,255,255,0.08);
            --error: #ef4444;
            --success: #10b981;
            --radius: 16px;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        /* 动态背景网格 */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: 
                linear-gradient(rgba(99,102,241,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(99,102,241,0.03) 1px, transparent 1px);
            background-size: 60px 60px;
            mask-image: radial-gradient(ellipse at center, black 30%, transparent 70%);
            pointer-events: none;
        }
        /* 光晕装饰 */
        .glow-orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.15;
            pointer-events: none;
        }
        .glow-orb-1 {
            width: 400px; height: 400px;
            background: var(--primary);
            top: -100px; right: -100px;
            animation: float 8s ease-in-out infinite;
        }
        .glow-orb-2 {
            width: 300px; height: 300px;
            background: #8b5cf6;
            bottom: -80px; left: -80px;
            animation: float 10s ease-in-out infinite reverse;
        }
        @keyframes float {
            0%,100% { transform: translate(0,0); }
            50% { transform: translate(30px,-30px); }
        }
        /* 登录卡片 */
        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }
        .login-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 40px 32px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
        }
        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }
        .login-icon {
            width: 56px; height: 56px;
            margin: 0 auto 16px;
            background: linear-gradient(135deg, var(--primary), #8b5cf6);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            box-shadow: 0 0 30px var(--primary-glow);
        }
        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .login-header p {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 0.95rem;
            color: var(--text);
            outline: none;
            transition: all 0.2s;
            font-family: inherit;
        }
        .form-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
            background: rgba(255,255,255,0.08);
        }
        .form-group input::placeholder {
            color: #475569;
        }
        .btn-submit {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, var(--primary), #8b5cf6);
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            position: relative;
            overflow: hidden;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px var(--primary-glow);
        }
        .btn-submit:active {
            transform: translateY(0);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .alert-error {
            background: rgba(239,68,68,0.1);
            border: 1px solid rgba(239,68,68,0.3);
            color: #fca5a5;
        }
        .alert-success {
            background: rgba(16,185,129,0.1);
            border: 1px solid rgba(16,185,129,0.3);
            color: #6ee7b7;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: var(--text-muted);
            font-size: 0.85rem;
            text-decoration: none;
            transition: color 0.2s;
        }
        .back-link:hover {
            color: var(--text);
        }
        .default-pwd-hint {
            text-align: center;
            margin-top: 16px;
            font-size: 0.75rem;
            color: #475569;
        }
        .default-pwd-hint code {
            background: rgba(99,102,241,0.15);
            padding: 2px 8px;
            border-radius: 4px;
            color: #a5b4fc;
        }
    </style>
</head>
<body>
    <div class="glow-orb glow-orb-1"></div>
    <div class="glow-orb glow-orb-2"></div>
    
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-icon">🛡️</div>
                <h1>后台管理</h1>
                <p>蜘蛛识别查询系统 v2.0</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" placeholder="请输入管理员用户名" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" placeholder="请输入密码" required>
                </div>
                
                <button type="submit" class="btn-submit">登 录</button>
            </form>
            
            <?php if ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1'): ?>
            <p class="default-pwd-hint">
                默认账户: <code>admin</code> / <code>admin123</code>（首次登录后请修改密码）
            </p>
            <?php endif; ?>
        </div>
        
        <a href="../index.php" class="back-link">← 返回前台首页</a>
    </div>
</body>
</html>

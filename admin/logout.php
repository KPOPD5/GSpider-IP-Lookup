<?php
/**
 * 后台管理 - 登出
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../AdminAuth.php';

$auth = new AdminAuth();
$auth->logout();

header('Location: login.php?msg=logged_out');
exit;

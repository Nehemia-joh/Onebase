<?php
require_once __DIR__ . '/config.php';
if (isLoggedIn()) {
    logActivity('logout', 'User logged out');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: /login?msg=logged_out');
exit;

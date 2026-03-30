<?php
require_once __DIR__ . '/includes/session.php';

app_start_session();
session_unset();
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, [
        'path' => $params['path'] ?: '/',
        'domain' => (string) ($params['domain'] ?? ''),
        'secure' => (bool) ($params['secure'] ?? false),
        'httponly' => true,
        'samesite' => (string) ($params['samesite'] ?? 'Lax'),
    ]);
}
session_destroy();
header("Location: loginpage.php");
exit;

<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/helpers.php';

app_start_session();
session_unset();
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    $cookiePath = (string) ($params['path'] ?? '/');
    if ($cookiePath === '') {
        $cookiePath = '/';
    }

    $cookieDomain = (string) ($params['domain'] ?? '');
    $cookieSecure = (bool) ($params['secure'] ?? false);
    $cookieHttpOnly = true;

    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), '', time() - 42000, [
            'path' => $cookiePath,
            'domain' => $cookieDomain,
            'secure' => $cookieSecure,
            'httponly' => $cookieHttpOnly,
            'samesite' => (string) ($params['samesite'] ?? 'Lax'),
        ]);
    } else {
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookiePath . '; samesite=Lax',
            $cookieDomain,
            $cookieSecure,
            $cookieHttpOnly
        );
    }
}
session_destroy();
header('Location: ' . app_url('loginpage.php'));
exit;

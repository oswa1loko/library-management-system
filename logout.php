<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        isset($params['path']) && $params['path'] !== '' ? $params['path'] : '/',
        isset($params['domain']) ? $params['domain'] : '',
        !empty($params['secure']),
        true
    );
}

session_destroy();
header('Location: /librarymanage/loginpage.php');
exit;

<?php
function app_is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    return $forwardedProto === 'https';
}

function app_start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $sessionDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0700, true);
    }

    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $secureCookie = app_is_https_request();
    if ($secureCookie) {
        ini_set('session.cookie_secure', '1');
    }

    $cookieParams = session_get_cookie_params();
    $cookiePath = (string) ($cookieParams['path'] ?? '/');
    if ($cookiePath === '') {
        $cookiePath = '/';
    }

    $cookieDomain = (string) ($cookieParams['domain'] ?? '');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'domain' => $cookieDomain,
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(
            0,
            $cookiePath . '; samesite=Lax',
            $cookieDomain,
            $secureCookie,
            true
        );
    }

    session_start();
}

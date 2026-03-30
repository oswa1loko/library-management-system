<?php
require_once __DIR__ . '/includes/session.php';

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
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/librarymanage/logout.php'));
$baseDir = rtrim(str_replace('/logout.php', '', $scriptName), '/');
$loginPath = ($baseDir !== '' ? $baseDir : '/librarymanage') . '/loginpage.php';

if (!headers_sent()) {
    header('Location: ' . $loginPath);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="0;url=<?php echo htmlspecialchars($loginPath, ENT_QUOTES, 'UTF-8'); ?>">
<title>Redirecting...</title>
</head>
<body>
<script>
window.location.replace(<?php echo json_encode($loginPath, JSON_UNESCAPED_SLASHES); ?>);
</script>
</body>
</html>

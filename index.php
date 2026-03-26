<?php
$distIndex = __DIR__ . '/frontend/dist/index.html';
if (!is_file($distIndex)) {
    http_response_code(500);
    exit('Landing page build is missing.');
}

$requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requestDir = str_replace('\\', '/', dirname($requestPath));
$requestDir = $requestDir === '/' || $requestDir === '.' ? '' : rtrim($requestDir, '/');

if ($requestDir !== '' && preg_match('#/(frontend/dist)$#', $requestDir) === 1) {
    $requestDir = preg_replace('#/(frontend/dist)$#', '', $requestDir) ?? $requestDir;
}

$basePath = trim($requestDir, '/');
$basePath = $basePath === '' ? '' : '/' . $basePath;
$faviconFile = __DIR__ . '/assets/images/regismarielogo.png';
$faviconVersion = is_file($faviconFile) ? '?v=' . urlencode((string) filemtime($faviconFile)) : '';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: text/html; charset=UTF-8');

$html = (string) file_get_contents($distIndex);
if ($basePath === '') {
    $html = str_replace('/librarymanage/', '/', $html);
} elseif ($basePath !== '/librarymanage') {
    $html = str_replace('/librarymanage/', $basePath . '/', $html);
}

$html = str_replace('/assets/images/regismarielogo.png"', '/assets/images/regismarielogo.png' . $faviconVersion . '"', $html);

echo $html;
exit;

<?php
$distIndex = __DIR__ . '/frontend/dist/index.html';
if (!is_file($distIndex)) {
    http_response_code(500);
    exit('Landing page build is missing.');
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: text/html; charset=UTF-8');
readfile($distIndex);
exit;

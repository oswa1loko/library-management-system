<?php
$distIndex = __DIR__ . '/frontend/dist/index.html';
$version = file_exists($distIndex) ? (string) filemtime($distIndex) : (string) time();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Location: /librarymanage/frontend/dist/?v=' . urlencode($version));
exit;

<?php
$distIndex = __DIR__ . '/frontend/dist/index.html';
$version = file_exists($distIndex) ? (string) filemtime($distIndex) : (string) time();
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$basePath = trim(dirname($scriptName), '/.');
$basePath = $basePath === '' ? '' : '/' . $basePath;
$target = $basePath . '/frontend/dist/?v=' . urlencode($version);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Location: ' . $target);
exit;

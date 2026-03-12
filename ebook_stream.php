<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_roles(['student', 'faculty', 'librarian', 'admin']);

$ebookId = (int) ($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT id, title, file_path, is_active FROM ebooks WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $ebookId);
$stmt->execute();
$ebook = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ebook) {
    http_response_code(404);
    exit('eBook not found.');
}

$role = (string) ($_SESSION['role'] ?? '');
if (in_array($role, ['student', 'faculty'], true) && (int) ($ebook['is_active'] ?? 0) !== 1) {
    http_response_code(403);
    exit('eBook is not available.');
}

$relativePath = trim((string) ($ebook['file_path'] ?? ''));
$fullPath = __DIR__ . '/' . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
if ($relativePath === '' || !is_file($fullPath)) {
    http_response_code(404);
    exit('eBook file was not found.');
}

$safeFileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($ebook['title'] ?? 'ebook')) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $safeFileName . '"');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('Cache-Control: private, max-age=0, must-revalidate');
header('Accept-Ranges: none');
readfile($fullPath);
exit;

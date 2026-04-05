<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$notificationId = (int) ($_GET['notification_id'] ?? 0);
$defaultRedirect = app_url('admin/notifications.php');
$redirect = trim((string) ($_GET['redirect'] ?? $defaultRedirect));
$basePrefix = rtrim(app_url('/'), '/');

if (
    $redirect === ''
    || preg_match('#^(?:https?:)?//#i', $redirect) === 1
    || ($basePrefix !== '' && strpos($redirect, $basePrefix . '/') !== 0 && $redirect !== $basePrefix)
) {
    $redirect = $defaultRedirect;
}

if ($notificationId > 0) {
    $markStmt = $conn->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE id = ? AND " . admin_notification_inbox_where_sql() . " AND is_read = 0
        LIMIT 1
    ");
    $markStmt->bind_param('i', $notificationId);
    $markStmt->execute();
    $markStmt->close();
}

header('Location: ' . $redirect);
exit;

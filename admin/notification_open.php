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
    $sourceStmt = $conn->prepare("
        SELECT title, body
        FROM notifications
        WHERE id = ? AND " . admin_notification_inbox_where_sql() . "
        LIMIT 1
    ");
    $sourceStmt->bind_param('i', $notificationId);
    $sourceStmt->execute();
    $sourceRow = $sourceStmt->get_result()->fetch_assoc();
    $sourceStmt->close();

    if ($sourceRow) {
        $title = (string) ($sourceRow['title'] ?? '');
        $body = (string) ($sourceRow['body'] ?? '');
        $markStmt = $conn->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE " . admin_notification_inbox_where_sql() . " AND title = ? AND body = ? AND is_read = 0
        ");
        $markStmt->bind_param('ss', $title, $body);
        $markStmt->execute();
        $markStmt->close();
    }
}

header('Location: ' . $redirect);
exit;

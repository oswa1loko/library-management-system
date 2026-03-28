<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('faculty');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$notificationId = (int) ($_GET['notification_id'] ?? 0);
$borrowId = (int) ($_GET['borrow_id'] ?? 0);
$defaultRedirect = app_url('faculty/notifications.php');
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
        WHERE id = ? AND role = 'faculty' AND user_id = ?
        LIMIT 1
    ");
    $sourceStmt->bind_param('ii', $notificationId, $userId);
    $sourceStmt->execute();
    $sourceRow = $sourceStmt->get_result()->fetch_assoc();
    $sourceStmt->close();

    if ($sourceRow) {
        $title = (string) ($sourceRow['title'] ?? '');
        $body = (string) ($sourceRow['body'] ?? '');
        $markStmt = $conn->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE role = 'faculty' AND user_id = ? AND title = ? AND body = ? AND is_read = 0
        ");
        $markStmt->bind_param('iss', $userId, $title, $body);
        $markStmt->execute();
        $markStmt->close();
    }
}

if ($borrowId > 0) {
    $dismissed = array_map('intval', (array) ($_SESSION['faculty_read_due_alerts'][$userId] ?? []));
    $dismissed[] = $borrowId;
    $_SESSION['faculty_read_due_alerts'][$userId] = array_values(array_unique(array_filter($dismissed)));
}

header('Location: ' . $redirect);
exit;

<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPayload = json_decode((string) file_get_contents('php://input'), true);
    $payload = is_array($rawPayload) ? $rawPayload : $_POST;
    $action = trim((string) ($payload['action'] ?? ''));
    $notificationId = (int) ($payload['id'] ?? 0);

    if ($action === 'mark_read' && $notificationId > 0) {
        $markStmt = $conn->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE id = ? AND role = 'admin'
            LIMIT 1
        ");
        $markStmt->bind_param('i', $notificationId);
        $markStmt->execute();
        $changed = $markStmt->affected_rows === 1;
        $markStmt->close();

        if (!$changed) {
            $checkStmt = $conn->prepare("
                SELECT id
                FROM notifications
                WHERE id = ? AND role = 'admin'
                LIMIT 1
            ");
            $checkStmt->bind_param('i', $notificationId);
            $checkStmt->execute();
            $changed = (bool) $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
        }

        $countResult = $conn->query("SELECT COUNT(*) AS total FROM notifications WHERE role = 'admin' AND is_read = 0");
        $remainingUnread = (int) ($countResult->fetch_assoc()['total'] ?? 0);

        echo json_encode([
            'ok' => $changed,
            'unread_count' => $remainingUnread,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'mark_all_read') {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE role = 'admin' AND is_read = 0");
        echo json_encode([
            'ok' => true,
            'unread_count' => 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$notifications = $conn->query("
    SELECT id, title, body, severity, is_read, created_at
    FROM notifications
    WHERE role = 'admin'
    ORDER BY id DESC
    LIMIT 20
");

$items = [];
while ($notifications instanceof mysqli_result && ($row = $notifications->fetch_assoc())) {
    $destination = notification_destination_for_viewer('admin', $row);
    $items[] = [
        'id' => (int) ($row['id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'body' => (string) ($row['body'] ?? ''),
        'severity' => (string) ($row['severity'] ?? 'info'),
        'is_read' => (int) ($row['is_read'] ?? 0) === 1,
        'created_at' => format_display_datetime((string) ($row['created_at'] ?? ''), '-'),
        'kind' => 'notification',
        'destination_url' => (string) ($destination['url'] ?? ''),
        'destination_label' => (string) ($destination['label'] ?? ''),
    ];
}

$countResult = $conn->query("SELECT COUNT(*) AS total FROM notifications WHERE role = 'admin' AND is_read = 0");
$unreadCount = (int) ($countResult->fetch_assoc()['total'] ?? 0);

echo json_encode([
    'ok' => true,
    'unread_count' => $unreadCount,
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

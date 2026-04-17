<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

$notificationLimit = 60;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPayload = json_decode((string) file_get_contents('php://input'), true);
    $payload = is_array($rawPayload) ? $rawPayload : $_POST;
    $action = trim((string) ($payload['action'] ?? ''));
    $notificationId = (int) ($payload['id'] ?? 0);

    if ($action === 'mark_read' && $notificationId > 0) {
        $markStmt = $conn->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE id = ? AND role = 'librarian' AND is_read = 0
            LIMIT 1
        ");
        $markStmt->bind_param('i', $notificationId);
        $markStmt->execute();
        $changed = $markStmt->affected_rows > 0;
        $markStmt->close();

        $countResult = $conn->query("SELECT COUNT(*) AS total FROM notifications WHERE role = 'librarian' AND is_read = 0");
        $remainingUnread = (int) ($countResult->fetch_assoc()['total'] ?? 0);

        echo json_encode([
            'ok' => $changed,
            'unread_count' => $remainingUnread,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'mark_all_read') {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE role = 'librarian' AND is_read = 0");
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
    SELECT id, kind, entity_type, entity_id, batch_ref, title, body, severity, is_read, created_at
    FROM notifications
    WHERE role = 'librarian'
    ORDER BY id DESC
    LIMIT " . (int) $notificationLimit . "
");

$items = [];
while ($notifications instanceof mysqli_result && ($row = $notifications->fetch_assoc())) {
    $destination = notification_destination_for_viewer('librarian', $row);
    $items[] = [
        'id' => (int) ($row['id'] ?? 0),
        'kind' => (string) ($row['kind'] ?? 'notification'),
        'entity_type' => (string) ($row['entity_type'] ?? ''),
        'entity_id' => (int) ($row['entity_id'] ?? 0),
        'batch_ref' => (string) ($row['batch_ref'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'body' => (string) ($row['body'] ?? ''),
        'severity' => (string) ($row['severity'] ?? 'info'),
        'is_read' => (int) ($row['is_read'] ?? 0) === 1,
        'created_at' => format_display_datetime((string) ($row['created_at'] ?? ''), '-'),
        'relative_time' => format_relative_datetime((string) ($row['created_at'] ?? ''), 'Recent'),
        'category_label' => member_notification_category_label(
            (string) ($row['kind'] ?? ''),
            (string) ($row['entity_type'] ?? ''),
            (string) ($row['title'] ?? ''),
            (string) ($row['body'] ?? '')
        ),
        'created_at_raw' => (string) ($row['created_at'] ?? ''),
        'destination_url' => (string) ($destination['url'] ?? ''),
        'destination_label' => (string) ($destination['label'] ?? ''),
    ];
}

$countResult = $conn->query("SELECT COUNT(*) AS total FROM notifications WHERE role = 'librarian' AND is_read = 0");
$unreadCount = (int) ($countResult->fetch_assoc()['total'] ?? 0);

echo json_encode([
    'ok' => true,
    'unread_count' => $unreadCount,
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('faculty');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$dueSoonBooks = get_member_due_soon_books($conn, $userId, 5);
$overdueBooks = get_member_overdue_books($conn, $userId, 5);
$dismissedDueAlerts = array_map('intval', (array) ($_SESSION['faculty_read_due_alerts'][$userId] ?? []));
$countUnreadDueAlerts = static function (array $books) use ($dismissedDueAlerts): int {
    return count(array_filter($books, static function (array $dueBook) use ($dismissedDueAlerts): bool {
        $borrowId = (int) ($dueBook['id'] ?? 0);
        return $borrowId <= 0 || !in_array($borrowId, $dismissedDueAlerts, true);
    }));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPayload = json_decode((string) file_get_contents('php://input'), true);
    $payload = is_array($rawPayload) ? $rawPayload : $_POST;
    $action = trim((string) ($payload['action'] ?? ''));
    $notificationId = (int) ($payload['id'] ?? 0);
    $borrowId = (int) ($payload['borrow_id'] ?? 0);

    if ($action === 'mark_read' && $notificationId > 0) {
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

        $changed = false;
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
            $changed = $markStmt->affected_rows >= 1;
            $markStmt->close();

            if (!$changed) {
                $checkStmt = $conn->prepare("
                    SELECT id
                    FROM notifications
                    WHERE id = ? AND role = 'faculty' AND user_id = ?
                    LIMIT 1
                ");
                $checkStmt->bind_param('ii', $notificationId, $userId);
                $checkStmt->execute();
                $changed = (bool) $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();
            }
        }

        $countStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM notifications
            WHERE role = 'faculty' AND user_id = ? AND is_read = 0
        ");
        $countStmt->bind_param('i', $userId);
        $countStmt->execute();
        $remainingUnread = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();

        echo json_encode([
            'ok' => $changed,
            'unread_count' => $remainingUnread + $countUnreadDueAlerts($dueSoonBooks) + $countUnreadDueAlerts($overdueBooks),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'mark_all_read') {
        $markAllStmt = $conn->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE role = 'faculty' AND user_id = ? AND is_read = 0
        ");
        $markAllStmt->bind_param('i', $userId);
        $markAllStmt->execute();
        $markAllStmt->close();

        $_SESSION['faculty_read_due_alerts'][$userId] = array_values(array_unique(array_map(
            static fn(array $dueBook): int => (int) ($dueBook['id'] ?? 0),
            array_filter(array_merge($dueSoonBooks, $overdueBooks), static fn(array $dueBook): bool => (int) ($dueBook['id'] ?? 0) > 0)
        )));

        echo json_encode([
            'ok' => true,
            'unread_count' => 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'mark_alert_read' && $borrowId > 0) {
        $dismissedDueAlerts[] = $borrowId;
        $_SESSION['faculty_read_due_alerts'][$userId] = array_values(array_unique(array_filter(array_map('intval', $dismissedDueAlerts))));

        $countStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM notifications
            WHERE role = 'faculty' AND user_id = ? AND is_read = 0
        ");
        $countStmt->bind_param('i', $userId);
        $countStmt->execute();
        $remainingUnread = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();

        echo json_encode([
            'ok' => true,
            'unread_count' => $remainingUnread + $countUnreadDueAlerts($dueSoonBooks) + $countUnreadDueAlerts($overdueBooks),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$notificationsStmt = $conn->prepare("
    SELECT id, title, body, severity, is_read, created_at
    FROM notifications
    WHERE role = 'faculty' AND user_id = ?
    ORDER BY id DESC
    LIMIT 20
");
$notificationsStmt->bind_param('i', $userId);
$notificationsStmt->execute();
$notificationsResult = $notificationsStmt->get_result();
$storedNotifications = [];
while ($notificationsResult && ($row = $notificationsResult->fetch_assoc())) {
    $destination = notification_destination_for_viewer('faculty', $row);
    $storedNotifications[] = [
        'id' => (int) ($row['id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'body' => (string) ($row['body'] ?? ''),
        'severity' => (string) ($row['severity'] ?? 'info'),
        'is_read' => (int) ($row['is_read'] ?? 0) === 1,
        'created_at' => format_display_datetime((string) ($row['created_at'] ?? ''), '-'),
        'created_at_raw' => (string) ($row['created_at'] ?? ''),
        'kind' => 'notification',
        'destination_url' => (string) ($destination['url'] ?? ''),
        'destination_label' => (string) ($destination['label'] ?? ''),
    ];
}
$notificationsStmt->close();

$items = [];
foreach ($storedNotifications as $notification) {
    $items[] = $notification;
}

foreach ($dueSoonBooks as $dueBook) {
    $borrowId = (int) ($dueBook['id'] ?? 0);
    $isRead = $borrowId > 0 && in_array($borrowId, $dismissedDueAlerts, true);
    $body = (string) ($dueBook['title'] ?? 'Book') . ' is due on ' . format_display_date((string) ($dueBook['due_date'] ?? ''), '-');
    if (($dueBook['status'] ?? '') === 'return_requested') {
        $body .= ' and is waiting for librarian confirmation.';
    }

    $items[] = [
        'id' => 0,
        'borrow_id' => $borrowId,
        'title' => 'Due Date Alert',
        'body' => $body,
        'severity' => 'warning',
        'is_read' => $isRead,
        'created_at' => 'Due soon',
        'created_at_raw' => '',
        'kind' => 'due_soon',
        'destination_url' => '/librarymanage/faculty/borrow_return.php',
        'destination_label' => 'Open borrow status',
    ];
}

foreach ($overdueBooks as $dueBook) {
    $borrowId = (int) ($dueBook['id'] ?? 0);
    $isRead = $borrowId > 0 && in_array($borrowId, $dismissedDueAlerts, true);
    $dueDateRaw = (string) ($dueBook['due_date'] ?? '');
    $daysOverdue = 1;
    if ($dueDateRaw !== '') {
        $daysOverdue = max(1, (int) floor((strtotime(date('Y-m-d')) - strtotime($dueDateRaw)) / 86400));
    }
    $body = (string) ($dueBook['title'] ?? 'Book') . ' was due on ' . format_display_date($dueDateRaw, '-') . ' and is now overdue by ' . $daysOverdue . ' day' . ($daysOverdue === 1 ? '' : 's') . '.';
    if (($dueBook['status'] ?? '') === 'return_requested') {
        $body .= ' Return confirmation is still pending.';
    }

    $items[] = [
        'id' => 0,
        'borrow_id' => $borrowId,
        'title' => 'Overdue Alert',
        'body' => $body,
        'severity' => 'critical',
        'is_read' => $isRead,
        'created_at' => 'Overdue',
        'created_at_raw' => '',
        'kind' => 'overdue',
        'destination_url' => '/librarymanage/faculty/borrow_return.php',
        'destination_label' => 'Open borrow status',
    ];
}

$unreadCountStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE role = 'faculty' AND user_id = ? AND is_read = 0
");
$unreadCountStmt->bind_param('i', $userId);
$unreadCountStmt->execute();
$unreadCount = (int) ($unreadCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
$unreadCountStmt->close();

echo json_encode([
    'ok' => true,
    'unread_count' => $unreadCount + $countUnreadDueAlerts($dueSoonBooks) + $countUnreadDueAlerts($overdueBooks),
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

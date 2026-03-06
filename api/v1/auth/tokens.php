<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('GET');
$user = api_require_session_login();

global $conn;

$stmt = $conn->prepare("
    SELECT id, label, scopes, last_used_at, expires_at, created_at
    FROM api_tokens
    WHERE user_id = ?
    ORDER BY id DESC
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $items[] = $row;
}
$stmt->close();

api_json([
    'ok' => true,
    'count' => count($items),
    'items' => $items,
]);

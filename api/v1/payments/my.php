<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('GET');
$user = api_require_login();

global $conn;
$limit = api_query_limit(30, 100);

$stmt = $conn->prepare("
    SELECT id, penalty_id, amount, proof_path, status, created_at
    FROM payments
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT ?
");
$stmt->bind_param('ii', $user['id'], $limit);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $row['penalty_id'] = $row['penalty_id'] === null ? null : (int) $row['penalty_id'];
    $row['amount'] = (float) $row['amount'];
    $items[] = $row;
}
$stmt->close();

api_json([
    'ok' => true,
    'count' => count($items),
    'items' => $items,
]);


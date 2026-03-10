<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('GET');
$user = api_require_login();

global $conn;
$limit = api_query_limit(30, 100);

$stmt = $conn->prepare("
    SELECT br.id, br.book_id, br.request_batch, br.return_batch, b.title, b.author, br.borrow_date, br.due_date, br.return_date, br.status, br.created_at
    FROM borrows br
    JOIN books b ON b.id = br.book_id
    WHERE br.user_id = ?
    ORDER BY br.id DESC
    LIMIT ?
");
$stmt->bind_param('ii', $user['id'], $limit);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $row['book_id'] = (int) $row['book_id'];
    $items[] = $row;
}
$stmt->close();

api_json([
    'ok' => true,
    'count' => count($items),
    'items' => $items,
]);

<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('GET');
api_require_login();

global $conn;

$limit = api_query_limit(30, 100);
$q = trim((string) ($_GET['q'] ?? ''));
$availableOnly = isset($_GET['available_only']) && $_GET['available_only'] === '1';

$sql = "
    SELECT id, title, author, category, cover_path, qty_total, qty_available, created_at
    FROM books
    WHERE 1=1
";

$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (title LIKE ? OR author LIKE ? OR category LIKE ?)";
    $term = '%' . $q . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $types .= 'sss';
}

if ($availableOnly) {
    $sql .= " AND qty_available > 0";
}

$sql .= " ORDER BY title ASC LIMIT ?";
$params[] = $limit;
$types .= 'i';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $row['qty_total'] = (int) $row['qty_total'];
    $row['qty_available'] = (int) $row['qty_available'];
    $items[] = $row;
}
$stmt->close();

api_json([
    'ok' => true,
    'count' => count($items),
    'items' => $items,
]);


<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('GET');
api_require_role('admin');

global $conn;

$limit = api_query_limit(30, 200);
$search = trim((string) ($_GET['q'] ?? ''));
$role = trim((string) ($_GET['role'] ?? ''));
$allowedRoles = system_roles();

$sql = "
    SELECT id, fullname, email, username, role, created_at
    FROM users
    WHERE 1=1
";
$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (fullname LIKE ? OR email LIKE ? OR username LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $types .= 'sss';
}

if ($role !== '' && in_array($role, $allowedRoles, true)) {
    $sql .= " AND role = ?";
    $params[] = $role;
    $types .= 's';
}

$sql .= " ORDER BY id DESC LIMIT ?";
$params[] = $limit;
$types .= 'i';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
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

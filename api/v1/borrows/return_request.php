<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('POST');
$user = api_require_token_auth();
api_require_token_scope($user, 'write');

global $conn;

$borrowId = (int) ($_POST['borrow_id'] ?? 0);
if ($borrowId <= 0) {
    api_error('Invalid borrow_id.', 422);
}

$stmt = $conn->prepare("SELECT status FROM borrows WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->bind_param('ii', $borrowId, $user['id']);
$stmt->execute();
$stmt->bind_result($status);
$found = $stmt->fetch();
$stmt->close();

if (!$found) {
    api_error('Borrow record not found.', 404);
}

if ($status !== 'borrowed') {
    api_error('Only borrowed records can request return.', 409, [
        'current_status' => (string) $status,
    ]);
}

$returnRequestDate = date('Y-m-d');
$requestStmt = $conn->prepare("
    UPDATE borrows
    SET status = 'return_requested', return_date = ?
    WHERE id = ? AND user_id = ? AND status = 'borrowed'
");
$requestStmt->bind_param('sii', $returnRequestDate, $borrowId, $user['id']);
$requestStmt->execute();
$updated = $requestStmt->affected_rows === 1;
$requestStmt->close();

if (!$updated) {
    api_error('Unable to send return request right now.', 500);
}

audit_log($conn, 'api.borrow.return_request', [
    'borrow_id' => $borrowId,
    'return_date' => $returnRequestDate,
], $user['id'], $user['role']);

api_json([
    'ok' => true,
    'message' => 'Return request sent.',
    'borrow' => [
        'id' => $borrowId,
        'status' => 'return_requested',
        'return_date' => $returnRequestDate,
    ],
]);

<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('POST');
$user = api_require_token_auth();
api_require_token_scope($user, 'write');

global $conn;

$borrowIdsRaw = $_POST['borrow_ids'] ?? null;
if (!is_array($borrowIdsRaw)) {
    $singleBorrowId = (int) ($_POST['borrow_id'] ?? 0);
    $borrowIdsRaw = $singleBorrowId > 0 ? [$singleBorrowId] : [];
}

$borrowIds = array_values(array_unique(array_filter(array_map('intval', $borrowIdsRaw), static function (int $id): bool {
    return $id > 0;
})));

if ($borrowIds === []) {
    api_error('Select at least one borrowed record.', 422);
}

$placeholders = implode(',', array_fill(0, count($borrowIds), '?'));
$types = str_repeat('i', count($borrowIds) + 1);
$params = array_merge([$user['id']], $borrowIds);

$stmt = $conn->prepare("
    SELECT id, status, request_batch
    FROM borrows
    WHERE user_id = ? AND id IN ($placeholders)
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$rowsById = [];
foreach ($rows as $row) {
    $rowsById[(int) $row['id']] = $row;
}

$missingIds = [];
$invalidIds = [];
foreach ($borrowIds as $borrowId) {
    if (!isset($rowsById[$borrowId])) {
        $missingIds[] = $borrowId;
        continue;
    }

    if ((string) ($rowsById[$borrowId]['status'] ?? '') !== 'borrowed') {
        $invalidIds[] = $borrowId;
    }
}

if ($missingIds !== []) {
    api_error('One or more borrow records were not found.', 404, ['borrow_ids' => $missingIds]);
}

if ($invalidIds !== []) {
    api_error('Only borrowed records can request return.', 409, ['borrow_ids' => $invalidIds]);
}

$returnRequestDate = date('Y-m-d');
$returnBatch = 'ret-' . bin2hex(random_bytes(8));
$updatedBorrows = [];

$conn->begin_transaction();

try {
    $requestStmt = $conn->prepare("
        UPDATE borrows
        SET status = 'return_requested', return_date = ?, return_batch = ?
        WHERE id = ? AND user_id = ? AND status = 'borrowed'
    ");

    foreach ($borrowIds as $borrowId) {
        $requestStmt->bind_param('ssii', $returnRequestDate, $returnBatch, $borrowId, $user['id']);
        $requestStmt->execute();

        if ($requestStmt->affected_rows !== 1) {
            throw new RuntimeException('return_request_failed');
        }

        $updatedBorrows[] = [
            'id' => $borrowId,
            'status' => 'return_requested',
            'return_date' => $returnRequestDate,
            'return_batch' => $returnBatch,
            'request_batch' => (string) ($rowsById[$borrowId]['request_batch'] ?? ''),
        ];
    }

    $requestStmt->close();
    $conn->commit();
} catch (Throwable $e) {
    if (isset($requestStmt) && $requestStmt instanceof mysqli_stmt) {
        $requestStmt->close();
    }
    $conn->rollback();
    api_error('Unable to send return request right now.', 500);
}

audit_log($conn, 'api.borrow.return_request', [
    'borrow_ids' => $borrowIds,
    'return_date' => $returnRequestDate,
    'return_batch' => $returnBatch,
], $user['id'], $user['role']);

api_json([
    'ok' => true,
    'message' => count($updatedBorrows) > 1 ? 'Return requests sent.' : 'Return request sent.',
    'requested_count' => count($updatedBorrows),
    'return_batch' => $returnBatch,
    'borrows' => $updatedBorrows,
]);

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
    api_error('Select at least one pending borrow request.', 422);
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

    if ((string) ($rowsById[$borrowId]['status'] ?? '') !== 'pending') {
        $invalidIds[] = $borrowId;
    }
}

if ($missingIds !== []) {
    api_error('One or more pending borrow records were not found.', 404, ['borrow_ids' => $missingIds]);
}

if ($invalidIds !== []) {
    api_error('Only pending borrow requests can be canceled.', 409, ['borrow_ids' => $invalidIds]);
}

$deletedBorrows = [];

$conn->begin_transaction();

try {
    $deleteStmt = $conn->prepare("
        DELETE FROM borrows
        WHERE id = ? AND user_id = ? AND status = 'pending'
    ");

    foreach ($borrowIds as $borrowId) {
        $deleteStmt->bind_param('ii', $borrowId, $user['id']);
        $deleteStmt->execute();

        if ($deleteStmt->affected_rows !== 1) {
            throw new RuntimeException('cancel_request_failed');
        }

        $deletedBorrows[] = [
            'id' => $borrowId,
            'request_batch' => (string) ($rowsById[$borrowId]['request_batch'] ?? ''),
        ];
    }

    $deleteStmt->close();
    $conn->commit();
} catch (Throwable $e) {
    if (isset($deleteStmt) && $deleteStmt instanceof mysqli_stmt) {
        $deleteStmt->close();
    }
    $conn->rollback();
    api_error('Unable to cancel borrow request right now.', 500);
}

audit_log($conn, 'api.borrow.cancel_request', [
    'borrow_ids' => $borrowIds,
], $user['id'], $user['role']);

api_json([
    'ok' => true,
    'message' => count($deletedBorrows) > 1 ? 'Borrow requests canceled.' : 'Borrow request canceled.',
    'canceled_count' => count($deletedBorrows),
    'borrows' => $deletedBorrows,
]);

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
    SELECT
        br.id,
        br.status,
        br.request_batch,
        br.book_id,
        br.book_copy_id,
        b.title,
        bc.copy_id,
        (
            SELECT COUNT(*)
            FROM book_incidents bi
            WHERE (
                bi.borrow_id = br.id
                OR (br.book_copy_id IS NOT NULL AND br.book_copy_id > 0 AND bi.book_copy_id = br.book_copy_id)
            )
              AND bi.workflow_status IN ('open', 'for_payment', 'reported', 'under_review', 'awaiting_settlement')
        ) AS open_incident_count
    FROM borrows br
    JOIN books b ON b.id = br.book_id
    LEFT JOIN book_copies bc ON bc.id = br.book_copy_id
    WHERE br.user_id = ? AND br.id IN ($placeholders)
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
$blockedIds = [];
foreach ($borrowIds as $borrowId) {
    if (!isset($rowsById[$borrowId])) {
        $missingIds[] = $borrowId;
        continue;
    }

    if ((string) ($rowsById[$borrowId]['status'] ?? '') !== 'borrowed') {
        $invalidIds[] = $borrowId;
        continue;
    }

    if ((int) ($rowsById[$borrowId]['open_incident_count'] ?? 0) > 0) {
        $blockedIds[] = $borrowId;
    }
}

if ($missingIds !== []) {
    api_error('One or more borrow records were not found.', 404, ['borrow_ids' => $missingIds]);
}

if ($invalidIds !== []) {
    api_error('Only borrowed records can request return.', 409, ['borrow_ids' => $invalidIds]);
}

if ($blockedIds !== []) {
    $blockedLabels = [];
    $blockedItems = [];
    foreach ($blockedIds as $blockedId) {
        $blockedRow = $rowsById[$blockedId] ?? null;
        if (!is_array($blockedRow)) {
            continue;
        }

        $title = trim((string) ($blockedRow['title'] ?? ('Book #' . $blockedId)));
        $copyId = trim((string) ($blockedRow['copy_id'] ?? ''));
        $label = $title;
        if ($copyId !== '') {
            $label .= ' (' . $copyId . ')';
        }

        $blockedLabels[] = $label;
        $blockedItems[] = [
            'borrow_id' => $blockedId,
            'title' => $title,
            'copy_id' => $copyId,
        ];
    }

    $blockedLabels = array_values(array_unique($blockedLabels));
    $suffix = $blockedLabels !== [] ? ': ' . implode(', ', $blockedLabels) . '.' : '.';
    api_error(
        'Return request is blocked until the unresolved incident is resolved' . $suffix,
        409,
        [
            'borrow_ids' => $blockedIds,
            'blocked_items' => $blockedItems,
        ]
    );
}

$returnRequestedAt = date('Y-m-d H:i:s');
$returnBatch = 'ret-' . bin2hex(random_bytes(8));
$updatedBorrows = [];

$conn->begin_transaction();

try {
    $requestStmt = $conn->prepare("
        UPDATE borrows
        SET status = 'return_requested', return_requested_at = ?, return_batch = ?
        WHERE id = ? AND user_id = ? AND status = 'borrowed'
    ");

    foreach ($borrowIds as $borrowId) {
        $requestStmt->bind_param('ssii', $returnRequestedAt, $returnBatch, $borrowId, $user['id']);
        $requestStmt->execute();

        if ($requestStmt->affected_rows !== 1) {
            throw new RuntimeException('return_request_failed');
        }

        $updatedBorrows[] = [
            'id' => $borrowId,
            'status' => 'return_requested',
            'return_requested_at' => $returnRequestedAt,
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
    'return_requested_at' => $returnRequestedAt,
    'return_batch' => $returnBatch,
], $user['id'], $user['role']);

$titlesByBookId = [];
foreach ($borrowIds as $borrowId) {
    $row = $rowsById[$borrowId] ?? null;
    if (!is_array($row)) {
        continue;
    }

    $bookId = (int) ($row['book_id'] ?? 0);
    if ($bookId <= 0) {
        continue;
    }

    if (!isset($titlesByBookId[$bookId])) {
        $titlesByBookId[$bookId] = [
            'title' => trim((string) ($row['title'] ?? ('Book #' . $bookId))),
            'copies' => 0,
        ];
    }
    $titlesByBookId[$bookId]['copies']++;
}

$bookLabels = [];
foreach ($titlesByBookId as $item) {
    $bookLabels[] = (string) $item['title'] . ((int) ($item['copies'] ?? 0) > 1 ? ' (' . (int) $item['copies'] . ' copies)' : '');
}
$copyCount = count($updatedBorrows);
$copyLabel = $copyCount === 1 ? '1 copy' : $copyCount . ' copies';
$titleCount = count($titlesByBookId);
$titleLabel = $titleCount === 1 ? '1 title' : $titleCount . ' titles';
create_notification(
    $conn,
    'librarian',
    'New Return Request',
    role_label((string) $user['role']) . ' ' . $user['username'] . ' requested return for ' . $copyLabel . ' across ' . $titleLabel . ': ' . implode(', ', $bookLabels) . '. Return Ref ' . $returnBatch . '.',
    'warning',
    null,
    [
        'kind' => 'return_request_created',
        'entity_type' => 'return_request',
        'batch_ref' => $returnBatch,
    ]
);

api_json([
    'ok' => true,
    'message' => count($updatedBorrows) > 1 ? 'Return requests sent.' : 'Return request sent.',
    'requested_count' => count($updatedBorrows),
    'return_batch' => $returnBatch,
    'borrows' => $updatedBorrows,
]);

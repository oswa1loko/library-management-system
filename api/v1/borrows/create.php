<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('POST');
$user = api_require_token_auth();
api_require_token_scope($user, 'write');

global $conn;

$days = (int) ($_POST['days'] ?? 7);
$days = max(1, min($days, 30));

$bookIdsRaw = $_POST['book_ids'] ?? null;
$bookQtyRaw = $_POST['book_qty'] ?? [];
if (!is_array($bookIdsRaw)) {
    $singleBookId = (int) ($_POST['book_id'] ?? 0);
    $bookIdsRaw = $singleBookId > 0 ? [$singleBookId] : [];
}
if (!is_array($bookQtyRaw)) {
    $bookQtyRaw = [];
}

$bookIds = array_values(array_unique(array_filter(array_map('intval', $bookIdsRaw), static function (int $id): bool {
    return $id > 0;
})));

if ($bookIds === []) {
    api_error('Select at least one book.', 422);
}

$bookQuantities = [];
foreach ($bookIds as $bookId) {
    $bookQuantities[$bookId] = max(1, min(5, (int) ($bookQtyRaw[$bookId] ?? 1)));
}

$placeholders = implode(',', array_fill(0, count($bookIds), '?'));
$bookTypes = str_repeat('i', count($bookIds));
$bookStmt = $conn->prepare("SELECT id, title, qty_available FROM books WHERE id IN ($placeholders) ORDER BY title ASC");
$bookStmt->bind_param($bookTypes, ...$bookIds);
$bookStmt->execute();
$bookRows = $bookStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$bookStmt->close();

$booksById = [];
foreach ($bookRows as $bookRow) {
    $booksById[(int) $bookRow['id']] = $bookRow;
}

$missingIds = [];
$unavailableTitles = [];
$insufficientStock = [];
foreach ($bookIds as $bookId) {
    if (!isset($booksById[$bookId])) {
        $missingIds[] = $bookId;
        continue;
    }

    $availableCopies = (int) ($booksById[$bookId]['qty_available'] ?? 0);
    $requestedCopies = (int) ($bookQuantities[$bookId] ?? 1);

    if ($availableCopies <= 0) {
        $unavailableTitles[] = (string) ($booksById[$bookId]['title'] ?? ('Book #' . $bookId));
        continue;
    }

    if ($requestedCopies > $availableCopies) {
        $insufficientStock[] = (string) ($booksById[$bookId]['title'] ?? ('Book #' . $bookId))
            . ' (requested ' . $requestedCopies . ', available ' . $availableCopies . ')';
    }
}

if ($missingIds !== []) {
    api_error('One or more selected books were not found.', 404, ['book_ids' => $missingIds]);
}

if ($unavailableTitles !== []) {
    api_error('These books are not available right now: ' . implode(', ', $unavailableTitles) . '.', 409);
}

if ($insufficientStock !== []) {
    api_error('Requested quantity exceeds available copies for: ' . implode(', ', $insufficientStock) . '.', 409);
}

$requestedAt = date('Y-m-d H:i:s');
$createdBorrows = [];
$requestBatch = 'req-' . bin2hex(random_bytes(8));

$conn->begin_transaction();

try {
    $borrowStmt = $conn->prepare("
        INSERT INTO borrows (user_id, book_id, request_batch, requested_at, borrow_date, approved_at, due_date, due_at, borrow_days, status)
        VALUES (?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, 'pending')
    ");

    foreach ($bookIds as $bookId) {
        $requestedCopies = (int) ($bookQuantities[$bookId] ?? 1);
        for ($copy = 0; $copy < $requestedCopies; $copy++) {
            $borrowStmt->bind_param('iissi', $user['id'], $bookId, $requestBatch, $requestedAt, $days);
            $borrowStmt->execute();
            $borrowId = (int) $borrowStmt->insert_id;
            $createdBorrows[] = [
                'id' => $borrowId,
                'book_id' => $bookId,
                'request_batch' => $requestBatch,
                'title' => (string) ($booksById[$bookId]['title'] ?? ''),
                'requested_at' => $requestedAt,
                'requested_days' => $days,
                'status' => 'pending',
            ];
        }
    }

    $borrowStmt->close();
    $conn->commit();
} catch (Throwable $e) {
    if (isset($borrowStmt) && $borrowStmt instanceof mysqli_stmt) {
        $borrowStmt->close();
    }
    $conn->rollback();
    api_error('Unable to submit borrow request right now.', 500);
}

audit_log($conn, 'api.borrow.create', [
    'borrow_ids' => array_column($createdBorrows, 'id'),
    'book_ids' => $bookIds,
    'book_qty' => $bookQuantities,
    'request_batch' => $requestBatch,
    'requested_days' => $days,
    'requested_count' => count($createdBorrows),
], $user['id'], $user['role']);

api_json([
    'ok' => true,
    'message' => count($createdBorrows) > 1 ? 'Borrow requests submitted.' : 'Borrow request submitted.',
    'requested_count' => count($createdBorrows),
    'request_batch' => $requestBatch,
    'borrows' => $createdBorrows,
], 201);

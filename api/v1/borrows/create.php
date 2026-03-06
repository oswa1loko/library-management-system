<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('POST');
$user = api_require_token_auth();
api_require_token_scope($user, 'write');

global $conn;

$bookId = (int) ($_POST['book_id'] ?? 0);
$days = (int) ($_POST['days'] ?? 7);
$days = max(1, min($days, 30));

if ($bookId <= 0) {
    api_error('Invalid book_id.', 422);
}

$bookStmt = $conn->prepare("SELECT id, qty_available FROM books WHERE id = ? LIMIT 1");
$bookStmt->bind_param('i', $bookId);
$bookStmt->execute();
$book = $bookStmt->get_result()->fetch_assoc();
$bookStmt->close();

if (!$book) {
    api_error('Book not found.', 404);
}

if ((int) ($book['qty_available'] ?? 0) <= 0) {
    api_error('Book not available.', 409);
}

$borrowDate = date('Y-m-d');
$dueDate = date('Y-m-d', strtotime("+{$days} days"));

$conn->begin_transaction();

try {
    $borrowStmt = $conn->prepare("
        INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status)
        VALUES (?, ?, ?, ?, 'borrowed')
    ");
    $borrowStmt->bind_param('iiss', $user['id'], $bookId, $borrowDate, $dueDate);
    $borrowStmt->execute();
    $borrowId = (int) $borrowStmt->insert_id;
    $borrowStmt->close();

    $inventoryStmt = $conn->prepare("UPDATE books SET qty_available = qty_available - 1 WHERE id = ? AND qty_available > 0");
    $inventoryStmt->bind_param('i', $bookId);
    $inventoryStmt->execute();

    if ($inventoryStmt->affected_rows !== 1) {
        throw new RuntimeException('Book inventory update failed.');
    }

    $inventoryStmt->close();
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    api_error('Unable to borrow this book right now.', 500);
}

audit_log($conn, 'api.borrow.create', [
    'borrow_id' => $borrowId,
    'book_id' => $bookId,
    'due_date' => $dueDate,
], $user['id'], $user['role']);

api_json([
    'ok' => true,
    'message' => 'Borrow successful.',
    'borrow' => [
        'id' => $borrowId,
        'book_id' => $bookId,
        'borrow_date' => $borrowDate,
        'due_date' => $dueDate,
        'status' => 'borrowed',
    ],
], 201);

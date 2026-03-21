<?php

function approve_pending_borrow(mysqli $conn, int $borrowId): array
{
    $borrowStmt = $conn->prepare("
        SELECT user_id, book_id, borrow_days, status
        FROM borrows
        WHERE id = ?
        LIMIT 1
    ");
    $borrowStmt->bind_param('i', $borrowId);
    $borrowStmt->execute();
    $borrowStmt->bind_result($userId, $bookId, $borrowDays, $borrowStatus);
    $found = $borrowStmt->fetch();
    $borrowStmt->close();

    if (!$found || $borrowStatus !== 'pending') {
        return ['ok' => false, 'reason' => 'not_pending'];
    }

    $borrowDays = max(1, min((int) $borrowDays, 30));
    $approvedAt = date('Y-m-d H:i:s');
    $borrowDate = date('Y-m-d', strtotime($approvedAt));
    $dueAt = date('Y-m-d H:i:s', strtotime($approvedAt . " +{$borrowDays} days"));
    $dueDate = date('Y-m-d', strtotime($dueAt));

    $stockStmt = $conn->prepare("UPDATE books SET qty_available = qty_available - 1 WHERE id = ? AND qty_available > 0");
    $stockStmt->bind_param('i', $bookId);
    $stockStmt->execute();

    if ($stockStmt->affected_rows !== 1) {
        $stockStmt->close();
        return ['ok' => false, 'reason' => 'no_stock'];
    }
    $stockStmt->close();

    $approveStmt = $conn->prepare("
        UPDATE borrows
        SET status = 'borrowed', borrow_date = ?, approved_at = ?, due_date = ?, due_at = ?, return_date = NULL, returned_at = NULL, return_requested_at = NULL
        WHERE id = ? AND status = 'pending'
    ");
    $approveStmt->bind_param('ssssi', $borrowDate, $approvedAt, $dueDate, $dueAt, $borrowId);
    $approveStmt->execute();

    if ($approveStmt->affected_rows !== 1) {
        $approveStmt->close();
        return ['ok' => false, 'reason' => 'update_failed'];
    }
    $approveStmt->close();

    return [
        'ok' => true,
        'borrow_id' => $borrowId,
        'book_id' => $bookId,
        'user_id' => $userId,
        'approved_at' => $approvedAt,
        'borrow_date' => $borrowDate,
        'due_at' => $dueAt,
        'due_date' => $dueDate,
    ];
}

function confirm_requested_return(mysqli $conn, int $borrowId): array
{
    $borrowStmt = $conn->prepare("
        SELECT user_id, book_id, due_date, status
        FROM borrows
        WHERE id = ?
        LIMIT 1
    ");
    $borrowStmt->bind_param('i', $borrowId);
    $borrowStmt->execute();
    $borrowStmt->bind_result($userId, $bookId, $dueDate, $borrowStatus);
    $found = $borrowStmt->fetch();
    $borrowStmt->close();

    if (!$found || $borrowStatus !== 'return_requested') {
        return ['ok' => false, 'reason' => 'not_return_requested'];
    }

    $returnedAt = date('Y-m-d H:i:s');
    $returnDate = date('Y-m-d', strtotime($returnedAt));

    $returnStmt = $conn->prepare("
        UPDATE borrows
        SET status = 'returned', return_date = ?, returned_at = ?
        WHERE id = ? AND status = 'return_requested'
    ");
    $returnStmt->bind_param('ssi', $returnDate, $returnedAt, $borrowId);
    $returnStmt->execute();

    if ($returnStmt->affected_rows !== 1) {
        $returnStmt->close();
        return ['ok' => false, 'reason' => 'update_failed'];
    }
    $returnStmt->close();

    $stockStmt = $conn->prepare("UPDATE books SET qty_available = qty_available + 1 WHERE id = ?");
    $stockStmt->bind_param('i', $bookId);
    $stockStmt->execute();
    $stockStmt->close();

    create_penalty_if_late($conn, $borrowId, $userId, $dueDate, $returnDate);

    return [
        'ok' => true,
        'borrow_id' => $borrowId,
        'book_id' => $bookId,
        'user_id' => $userId,
        'returned_at' => $returnedAt,
        'return_date' => $returnDate,
    ];
}

function create_penalty_if_late(mysqli $conn, int $borrowId, int $userId, string $dueDate, string $returnDate): void
{
    $due = new DateTime($dueDate);
    $returned = new DateTime($returnDate);

    if ($returned <= $due) {
        return;
    }

    $daysLate = (int) $due->diff($returned)->format('%a');
    $amount = $daysLate * 2;

    $check = $conn->prepare("SELECT id, status FROM penalties WHERE borrow_id = ? LIMIT 1");
    $check->bind_param('i', $borrowId);
    $check->execute();
    $penalty = $check->get_result()->fetch_assoc();
    $check->close();

    $reason = "Overdue ({$daysLate} day/s)";

    if ($penalty && ($penalty['status'] ?? '') === 'paid') {
        return;
    }

    if ($penalty) {
        $penaltyId = (int) $penalty['id'];
        $update = $conn->prepare("UPDATE penalties SET amount = ?, reason = ?, status = 'unpaid' WHERE id = ?");
        $update->bind_param('dsi', $amount, $reason, $penaltyId);
        $update->execute();
        $update->close();
        return;
    }

    $insert = $conn->prepare("INSERT INTO penalties (borrow_id, user_id, amount, reason, status) VALUES (?, ?, ?, ?, 'unpaid')");
    $insert->bind_param('iids', $borrowId, $userId, $amount, $reason);
    $insert->execute();
    $insert->close();
}

function handle_librarian_borrow_workflow(mysqli $conn): array
{
    $msg = '';
    $msgType = 'success';

    if (isset($_POST['mark_returned'])) {
        $borrowId = (int) ($_POST['borrow_id'] ?? 0);
        $conn->begin_transaction();

        try {
            $result = confirm_requested_return($conn, $borrowId);
            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($result['reason'] ?? 'return_failed'));
            }

            $conn->commit();
            audit_log($conn, 'librarian.borrow.confirm_return', [
                'borrow_id' => $borrowId,
                'book_id' => (int) $result['book_id'],
                'user_id' => (int) $result['user_id'],
                'return_date' => (string) $result['return_date'],
            ]);
            create_notification(
                $conn,
                'admin',
                'Borrow Return Confirmed',
                'Borrow #' . $borrowId . ' was confirmed as returned by a librarian.',
                'info'
            );
            create_notification(
                $conn,
                'student',
                'Return Request Approved',
                'Your return request for borrow #' . $borrowId . ' was confirmed by the librarian.',
                'info',
                (int) $result['user_id']
            );

            $msg = 'Borrow record marked as returned.';
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = 'Unable to mark this borrow record as returned right now.';
            $msgType = 'error';
        }
    }

    if (isset($_POST['approve_borrow'])) {
        $borrowId = (int) ($_POST['borrow_id'] ?? 0);
        $conn->begin_transaction();

        try {
            $result = approve_pending_borrow($conn, $borrowId);
            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($result['reason'] ?? 'approve_failed'));
            }

            $conn->commit();
            $emailQueued = enqueue_borrow_approval_email_job($conn, $borrowId);
            audit_log($conn, 'librarian.borrow.approve', [
                'borrow_id' => $borrowId,
                'book_id' => (int) $result['book_id'],
                'user_id' => (int) $result['user_id'],
                'borrow_date' => (string) $result['borrow_date'],
                'due_date' => (string) $result['due_date'],
                'approval_email_queued' => $emailQueued,
            ]);

            $msg = 'Borrow request approved and book released.';
            if (!$emailQueued) {
                $msg .= ' The approval email could not be queued.';
                $msgType = 'warning';
            }
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = 'Unable to approve this borrow request right now.';
            $msgType = 'error';
        }
    }

    if (isset($_POST['approve_borrow_group'])) {
        $requestBatch = trim((string) ($_POST['request_batch'] ?? ''));
        $bookId = (int) ($_POST['book_id'] ?? 0);

        if ($requestBatch === '' || $bookId <= 0) {
            $msg = 'Borrow request group is missing.';
            $msgType = 'error';
        } else {
            $groupStmt = $conn->prepare("
                SELECT br.id, b.qty_available
                FROM borrows br
                JOIN books b ON b.id = br.book_id
                WHERE br.request_batch = ?
                  AND br.book_id = ?
                  AND br.status = 'pending'
                ORDER BY br.id ASC
            ");
            $groupStmt->bind_param('si', $requestBatch, $bookId);
            $groupStmt->execute();
            $groupRows = $groupStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $groupStmt->close();

            if ($groupRows === []) {
                $msg = 'No pending copies were left for this book group.';
                $msgType = 'error';
            } else {
                $requestedCopies = count($groupRows);
                $availableCopies = (int) ($groupRows[0]['qty_available'] ?? 0);

                if ($availableCopies < $requestedCopies) {
                    $msg = 'Not enough stock to approve all requested copies for this book yet.';
                    $msgType = 'error';
                } else {
                    $approved = [];
                    $conn->begin_transaction();

                    try {
                        foreach ($groupRows as $row) {
                            $result = approve_pending_borrow($conn, (int) $row['id']);
                            if (($result['ok'] ?? false) !== true) {
                                throw new RuntimeException((string) ($result['reason'] ?? 'approve_group_failed'));
                            }
                            $approved[] = $result;
                        }

                        $conn->commit();
                        $emailQueued = enqueue_borrow_approval_email_job($conn, (int) $approved[0]['borrow_id']);

                        foreach ($approved as $result) {
                            audit_log($conn, 'librarian.borrow.approve', [
                                'borrow_id' => (int) $result['borrow_id'],
                                'book_id' => (int) $result['book_id'],
                                'user_id' => (int) $result['user_id'],
                                'borrow_date' => (string) $result['borrow_date'],
                                'due_date' => (string) $result['due_date'],
                                'request_batch' => $requestBatch,
                                'approval_email_queued' => $emailQueued,
                                'grouped_copy_approval' => true,
                            ]);
                        }

                        $msg = $requestedCopies . ' cop' . ($requestedCopies === 1 ? 'y was' : 'ies were') . ' approved and released for this book.';
                        if (!$emailQueued) {
                            $msg .= ' The approval email could not be queued.';
                            $msgType = 'warning';
                        }
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $msg = 'Unable to approve this grouped book request right now.';
                        $msgType = 'error';
                    }
                }
            }
        }
    }

    if (isset($_POST['approve_batch'])) {
        $requestBatch = trim((string) ($_POST['request_batch'] ?? ''));

        if ($requestBatch === '') {
            $msg = 'Request batch is missing.';
            $msgType = 'error';
        } else {
            $batchIdsStmt = $conn->prepare("
                SELECT id
                FROM borrows
                WHERE request_batch = ? AND status = 'pending'
                ORDER BY id ASC
            ");
            $batchIdsStmt->bind_param('s', $requestBatch);
            $batchIdsStmt->execute();
            $batchRows = $batchIdsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $batchIdsStmt->close();

            if ($batchRows === []) {
                $msg = 'No pending requests were left in this batch.';
                $msgType = 'error';
            } else {
                $approved = [];
                $skipped = 0;
                $conn->begin_transaction();

                try {
                    foreach ($batchRows as $row) {
                        $result = approve_pending_borrow($conn, (int) $row['id']);
                        if (($result['ok'] ?? false) === true) {
                            $approved[] = $result;
                        } else {
                            $skipped++;
                        }
                    }

                    if ($approved === []) {
                        throw new RuntimeException('batch_approve_failed');
                    }

                    $conn->commit();
                    $emailQueuedCount = 0;
                    $emailFailedCount = 0;

                    foreach ($approved as $result) {
                        $emailQueued = enqueue_borrow_approval_email_job($conn, (int) $result['borrow_id']);
                        if ($emailQueued) {
                            $emailQueuedCount++;
                        } else {
                            $emailFailedCount++;
                        }

                        audit_log($conn, 'librarian.borrow.approve', [
                            'borrow_id' => (int) $result['borrow_id'],
                            'book_id' => (int) $result['book_id'],
                            'user_id' => (int) $result['user_id'],
                            'borrow_date' => (string) $result['borrow_date'],
                            'due_date' => (string) $result['due_date'],
                            'request_batch' => $requestBatch,
                            'approval_email_queued' => $emailQueued,
                        ]);
                    }

                    $msg = count($approved) . ' request' . (count($approved) === 1 ? '' : 's') . ' approved from this batch.';
                    if ($skipped > 0) {
                        $msg .= ' ' . $skipped . ' item' . ($skipped === 1 ? ' was' : 's were') . ' left pending because stock was no longer available.';
                    }
                    if ($emailQueuedCount > 0) {
                        $msg .= ' ' . $emailQueuedCount . ' approval email' . ($emailQueuedCount === 1 ? ' was' : 's were') . ' queued.';
                    }
                    if ($emailFailedCount > 0) {
                        $msg .= ' ' . $emailFailedCount . ' email notification' . ($emailFailedCount === 1 ? ' could' : 's could') . ' not be queued.';
                        $msgType = 'warning';
                    }
                } catch (Throwable $e) {
                    $conn->rollback();
                    $msg = 'Unable to approve this request batch right now.';
                    $msgType = 'error';
                }
            }
        }
    }

    if (isset($_POST['confirm_return_batch'])) {
        $returnBatch = trim((string) ($_POST['return_batch'] ?? ''));

        if ($returnBatch === '') {
            $msg = 'Return batch is missing.';
            $msgType = 'error';
        } else {
            $batchIdsStmt = $conn->prepare("
                SELECT id
                FROM borrows
                WHERE return_batch = ? AND status = 'return_requested'
                ORDER BY id ASC
            ");
            $batchIdsStmt->bind_param('s', $returnBatch);
            $batchIdsStmt->execute();
            $batchRows = $batchIdsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $batchIdsStmt->close();

            if ($batchRows === []) {
                $msg = 'No pending return requests were left in this batch.';
                $msgType = 'error';
            } else {
                $confirmed = [];
                $conn->begin_transaction();

                try {
                    foreach ($batchRows as $row) {
                        $result = confirm_requested_return($conn, (int) $row['id']);
                        if (($result['ok'] ?? false) === true) {
                            $confirmed[] = $result;
                        }
                    }

                    if ($confirmed === []) {
                        throw new RuntimeException('batch_return_failed');
                    }

                    $conn->commit();

                    foreach ($confirmed as $result) {
                        audit_log($conn, 'librarian.borrow.confirm_return', [
                            'borrow_id' => (int) $result['borrow_id'],
                            'book_id' => (int) $result['book_id'],
                            'user_id' => (int) $result['user_id'],
                            'return_date' => (string) $result['return_date'],
                            'return_batch' => $returnBatch,
                        ]);
                    }

                    $msg = count($confirmed) . ' return' . (count($confirmed) === 1 ? '' : 's') . ' confirmed from this batch.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $msg = 'Unable to confirm this return batch right now.';
                    $msgType = 'error';
                }
            }
        }
    }

    if (isset($_POST['confirm_return_group'])) {
        $returnBatch = trim((string) ($_POST['return_batch'] ?? ''));
        $bookId = (int) ($_POST['book_id'] ?? 0);

        if ($returnBatch === '' || $bookId <= 0) {
            $msg = 'Return request group is missing.';
            $msgType = 'error';
        } else {
            $groupIdsStmt = $conn->prepare("
                SELECT br.id, b.title
                FROM borrows br
                JOIN books b ON b.id = br.book_id
                WHERE br.return_batch = ? AND br.book_id = ? AND br.status = 'return_requested'
                ORDER BY br.id ASC
            ");
            $groupIdsStmt->bind_param('si', $returnBatch, $bookId);
            $groupIdsStmt->execute();
            $groupRows = $groupIdsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $groupIdsStmt->close();

            if ($groupRows === []) {
                $msg = 'No pending return requests were left for this book.';
                $msgType = 'error';
            } else {
                $confirmed = [];
                $conn->begin_transaction();

                try {
                    foreach ($groupRows as $row) {
                        $result = confirm_requested_return($conn, (int) $row['id']);
                        if (($result['ok'] ?? false) === true) {
                            $confirmed[] = $result;
                        }
                    }

                    if ($confirmed === []) {
                        throw new RuntimeException('return_group_failed');
                    }

                    $conn->commit();

                    foreach ($confirmed as $result) {
                        audit_log($conn, 'librarian.borrow.confirm_return', [
                            'borrow_id' => (int) $result['borrow_id'],
                            'book_id' => (int) $result['book_id'],
                            'user_id' => (int) $result['user_id'],
                            'return_date' => (string) $result['return_date'],
                            'return_batch' => $returnBatch,
                            'grouped_copy_return' => true,
                        ]);
                    }

                    $copyCount = count($confirmed);
                    $copyLabel = $copyCount === 1 ? '1 copy' : $copyCount . ' copies';
                    $bookTitle = trim((string) ($groupRows[0]['title'] ?? ''));
                    $studentUserId = (int) ($confirmed[0]['user_id'] ?? 0);

                    create_notification(
                        $conn,
                        'admin',
                        'Borrow Return Confirmed',
                        $copyLabel . ' of ' . ($bookTitle !== '' ? $bookTitle : 'a book') . ' were confirmed as returned by a librarian.',
                        'info'
                    );
                    if ($studentUserId > 0) {
                        create_notification(
                            $conn,
                            'student',
                            'Return Request Approved',
                            'Your return request for ' . $copyLabel . ' of ' . ($bookTitle !== '' ? $bookTitle : 'your book') . ' was confirmed by the librarian.',
                            'info',
                            $studentUserId
                        );
                    }

                    $msg = count($confirmed) . ' cop' . (count($confirmed) === 1 ? 'y was' : 'ies were') . ' confirmed for this book.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $msg = 'Unable to confirm this grouped return right now.';
                    $msgType = 'error';
                }
            }
        }
    }

    return ['message' => $msg, 'type' => $msgType];
}

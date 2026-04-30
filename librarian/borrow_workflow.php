<?php

function librarian_selected_student_borrow_days(): ?int
{
    $approvalDays = (int) ($_POST['approval_days'] ?? 0);
    return in_array($approvalDays, [5, 7], true) ? $approvalDays : null;
}

function approve_pending_borrow(mysqli $conn, int $borrowId, ?int $studentApprovalDays = null): array
{
    $borrowStmt = $conn->prepare("
        SELECT br.user_id, br.book_id, br.borrow_days, br.status, br.book_copy_id, br.request_batch, br.requested_at, u.role, b.title
        FROM borrows br
        JOIN users u ON u.id = br.user_id
        JOIN books b ON b.id = br.book_id
        WHERE br.id = ?
        LIMIT 1
    ");
    $borrowStmt->bind_param('i', $borrowId);
    $borrowStmt->execute();
    $borrowStmt->bind_result($userId, $bookId, $borrowDays, $borrowStatus, $bookCopyId, $requestBatch, $requestedAt, $userRole, $bookTitle);
    $found = $borrowStmt->fetch();
    $borrowStmt->close();

    if (!$found || $borrowStatus !== 'pending') {
        return ['ok' => false, 'reason' => 'not_pending'];
    }

    if (strtotime((string) $requestedAt) < strtotime('-5 days')) {
        $expireStmt = $conn->prepare("DELETE FROM borrows WHERE id = ? AND status = 'pending'");
        $expireStmt->bind_param('i', $borrowId);
        $expireStmt->execute();
        $expireStmt->close();
        return ['ok' => false, 'reason' => 'expired'];
    }

    if ((string) $userRole === 'student') {
        $borrowDays = in_array((int) $studentApprovalDays, [5, 7], true) ? (int) $studentApprovalDays : 7;
    }
    $borrowDays = max(1, min((int) $borrowDays, 30));
    $approvedAt = date('Y-m-d H:i:s');
    $borrowDate = date('Y-m-d', strtotime($approvedAt));
    $dueAt = date('Y-m-d H:i:s', strtotime($approvedAt . " +{$borrowDays} days"));
    $dueDate = date('Y-m-d', strtotime($dueAt));

    $assignedCopy = null;
    if ((int) $bookCopyId > 0) {
        $assignedCopy = ['id' => (int) $bookCopyId];
        $copied = set_book_copy_status($conn, (int) $bookCopyId, 'borrowed');
        if (!$copied) {
            return ['ok' => false, 'reason' => 'no_stock'];
        }
    } else {
        $assignedCopy = assign_available_book_copy($conn, $bookId);
    }

    if (!$assignedCopy || (int) ($assignedCopy['id'] ?? 0) <= 0) {
        return ['ok' => false, 'reason' => 'no_stock'];
    }

    $approveStmt = $conn->prepare("
        UPDATE borrows
        SET status = 'borrowed', book_copy_id = ?, borrow_date = ?, approved_at = ?, due_date = ?, due_at = ?, borrow_days = ?, return_date = NULL, returned_at = NULL, return_requested_at = NULL
        WHERE id = ? AND status = 'pending'
    ");
    $assignedCopyId = (int) ($assignedCopy['id'] ?? 0);
    $approveStmt->bind_param('issssii', $assignedCopyId, $borrowDate, $approvedAt, $dueDate, $dueAt, $borrowDays, $borrowId);
    $approveStmt->execute();

    if ($approveStmt->affected_rows !== 1) {
        $approveStmt->close();
        set_book_copy_status($conn, $assignedCopyId, 'available');
        return ['ok' => false, 'reason' => 'update_failed'];
    }
    $approveStmt->close();

    return [
        'ok' => true,
        'borrow_id' => $borrowId,
        'book_id' => $bookId,
        'book_copy_id' => $assignedCopyId,
        'user_id' => $userId,
        'user_role' => (string) $userRole,
        'request_batch' => (string) $requestBatch,
        'book_title' => (string) $bookTitle,
        'approved_at' => $approvedAt,
        'borrow_date' => $borrowDate,
        'borrow_days' => $borrowDays,
        'due_at' => $dueAt,
        'due_date' => $dueDate,
    ];
}

function confirm_requested_return(mysqli $conn, int $borrowId): array
{
    $borrowStmt = $conn->prepare("
        SELECT br.user_id, br.book_id, br.book_copy_id, br.due_date, br.status, br.return_requested_at, u.role
        FROM borrows br
        JOIN users u ON u.id = br.user_id
        WHERE br.id = ?
        LIMIT 1
    ");
    $borrowStmt->bind_param('i', $borrowId);
    $borrowStmt->execute();
    $borrowStmt->bind_result($userId, $bookId, $bookCopyId, $dueDate, $borrowStatus, $returnRequestedAt, $userRole);
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

    if ((int) $bookCopyId > 0) {
        set_book_copy_status($conn, (int) $bookCopyId, 'available');
    } else {
        $stockStmt = $conn->prepare("UPDATE books SET qty_available = qty_available + 1 WHERE id = ?");
        $stockStmt->bind_param('i', $bookId);
        $stockStmt->execute();
        $stockStmt->close();
    }

    create_penalty_if_late($conn, $borrowId, $userId, $dueDate, $returnDate, (string) $returnRequestedAt);

    return [
        'ok' => true,
        'borrow_id' => $borrowId,
        'book_id' => $bookId,
        'book_copy_id' => (int) $bookCopyId,
        'user_id' => $userId,
        'user_role' => (string) $userRole,
        'returned_at' => $returnedAt,
        'return_date' => $returnDate,
    ];
}

function create_penalty_if_late(mysqli $conn, int $borrowId, int $userId, string $dueDate, string $returnDate, string $returnRequestedAt = ''): void
{
    $due = new DateTime($dueDate);
    $returned = new DateTime($returnDate);

    $requestedOnTime = false;
    $returnRequestedAt = trim($returnRequestedAt);
    if ($returnRequestedAt !== '') {
        $requestTimestamp = strtotime($returnRequestedAt);
        $dueTimestamp = strtotime($dueDate);
        if ($requestTimestamp !== false && $dueTimestamp !== false) {
            $requestedOnTime = date('Y-m-d', $requestTimestamp) <= date('Y-m-d', $dueTimestamp);
        }
    }

    if ($requestedOnTime) {
        $deletePenalty = $conn->prepare("DELETE FROM penalties WHERE borrow_id = ? AND status = 'unpaid'");
        $deletePenalty->bind_param('i', $borrowId);
        $deletePenalty->execute();
        $deletePenalty->close();
        return;
    }

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
    expire_stale_pending_borrow_requests($conn);

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
            $memberRole = (string) ($result['user_role'] ?? '');
            if (in_array($memberRole, ['student', 'faculty'], true)) {
                create_notification(
                    $conn,
                    $memberRole,
                    'Return Request Approved',
                    'Your return request for borrow #' . $borrowId . ' was confirmed by the librarian.',
                    'info',
                    (int) $result['user_id']
                );
            }

            $msg = 'Borrow record marked as returned.';
            if (send_return_confirmation_email($conn, [$borrowId])) {
                $msg .= ' The return confirmation email was sent.';
            }
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = 'Unable to mark this borrow record as returned right now.';
            $msgType = 'error';
        }
    }

    if (isset($_POST['approve_borrow'])) {
        $borrowId = (int) ($_POST['borrow_id'] ?? 0);
        $studentApprovalDays = librarian_selected_student_borrow_days();
        $conn->begin_transaction();

        try {
            $result = approve_pending_borrow($conn, $borrowId, $studentApprovalDays);
            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($result['reason'] ?? 'approve_failed'));
            }

            $conn->commit();
            $emailQueued = enqueue_borrow_approval_email_job($conn, $borrowId);
            $emailDispatch = $emailQueued ? process_pending_email_jobs($conn, 3) : ['sent' => 0, 'failed' => 0];
            $memberRole = (string) ($result['user_role'] ?? '');
            $memberUserId = (int) ($result['user_id'] ?? 0);
            $bookTitle = trim((string) ($result['book_title'] ?? ''));
            $approvedDays = max(1, (int) ($result['borrow_days'] ?? 0));
            if ($memberUserId > 0 && in_array($memberRole, ['student', 'faculty'], true)) {
                create_notification(
                    $conn,
                    $memberRole,
                    'Borrow Request Approved',
                    'Your borrow request for ' . ($bookTitle !== '' ? $bookTitle : 'your selected book') . ' was approved by the librarian for ' . $approvedDays . ' day' . ($approvedDays === 1 ? '' : 's') . '.',
                    'info',
                    $memberUserId,
                    [
                        'kind' => 'borrow_request_approved',
                        'entity_type' => 'borrow',
                        'entity_id' => (int) ($result['borrow_id'] ?? 0),
                        'batch_ref' => (string) ($result['request_batch'] ?? ''),
                    ]
                );
            }
            audit_log($conn, 'librarian.borrow.approve', [
                'borrow_id' => $borrowId,
                'book_id' => (int) $result['book_id'],
                'user_id' => (int) $result['user_id'],
                'borrow_date' => (string) $result['borrow_date'],
                'borrow_days' => (int) ($result['borrow_days'] ?? 0),
                'due_date' => (string) $result['due_date'],
                'approval_email_queued' => $emailQueued,
                'approval_email_sent' => (int) ($emailDispatch['sent'] ?? 0) > 0,
                'approval_email_failed' => (int) ($emailDispatch['failed'] ?? 0) > 0,
            ]);

            $msg = 'Borrow request approved and book released.';
            if (!$emailQueued) {
                $msg .= ' The approval email could not be queued.';
                $msgType = 'warning';
            } elseif ((int) ($emailDispatch['sent'] ?? 0) > 0) {
                $msg .= ' The approval email was sent immediately.';
            } elseif ((int) ($emailDispatch['failed'] ?? 0) > 0) {
                $msg .= ' The approval email could not be sent right now.';
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
        $studentApprovalDays = librarian_selected_student_borrow_days();

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
                            $result = approve_pending_borrow($conn, (int) $row['id'], $studentApprovalDays);
                            if (($result['ok'] ?? false) !== true) {
                                throw new RuntimeException((string) ($result['reason'] ?? 'approve_group_failed'));
                            }
                            $approved[] = $result;
                        }

                        $conn->commit();
                        $emailQueued = enqueue_borrow_approval_email_job($conn, (int) $approved[0]['borrow_id']);
                        $emailDispatch = $emailQueued ? process_pending_email_jobs($conn, 3) : ['sent' => 0, 'failed' => 0];
                        $memberRole = (string) ($approved[0]['user_role'] ?? '');
                        $memberUserId = (int) ($approved[0]['user_id'] ?? 0);
                        $bookTitle = trim((string) ($approved[0]['book_title'] ?? ''));
                        $approvedDays = max(1, (int) ($approved[0]['borrow_days'] ?? 0));
                        if ($memberUserId > 0 && in_array($memberRole, ['student', 'faculty'], true)) {
                            create_notification(
                                $conn,
                                $memberRole,
                                'Borrow Request Approved',
                                'Your borrow request for ' . ($requestedCopies === 1 ? '1 copy' : $requestedCopies . ' copies') . ' of ' . ($bookTitle !== '' ? $bookTitle : 'your selected book') . ' was approved by the librarian for ' . $approvedDays . ' day' . ($approvedDays === 1 ? '' : 's') . '.',
                                'info',
                                $memberUserId,
                                [
                                    'kind' => 'borrow_request_approved',
                                    'entity_type' => 'borrow',
                                    'entity_id' => (int) ($approved[0]['borrow_id'] ?? 0),
                                    'batch_ref' => $requestBatch,
                                ]
                            );
                        }

                        foreach ($approved as $result) {
                            audit_log($conn, 'librarian.borrow.approve', [
                                'borrow_id' => (int) $result['borrow_id'],
                                'book_id' => (int) $result['book_id'],
                                'user_id' => (int) $result['user_id'],
                                'borrow_date' => (string) $result['borrow_date'],
                                'borrow_days' => (int) ($result['borrow_days'] ?? 0),
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
                        } elseif ((int) ($emailDispatch['sent'] ?? 0) > 0) {
                            $msg .= ' The approval email was sent immediately.';
                        } elseif ((int) ($emailDispatch['failed'] ?? 0) > 0) {
                            $msg .= ' The approval email could not be sent right now.';
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
        $studentApprovalDays = librarian_selected_student_borrow_days();

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
                        $result = approve_pending_borrow($conn, (int) $row['id'], $studentApprovalDays);
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

                        $memberRole = (string) ($result['user_role'] ?? '');
                        $memberUserId = (int) ($result['user_id'] ?? 0);
                        $bookTitle = trim((string) ($result['book_title'] ?? ''));
                        $approvedDays = max(1, (int) ($result['borrow_days'] ?? 0));
                        if ($memberUserId > 0 && in_array($memberRole, ['student', 'faculty'], true)) {
                            create_notification(
                                $conn,
                                $memberRole,
                                'Borrow Request Approved',
                                'Your borrow request for ' . ($bookTitle !== '' ? $bookTitle : 'your selected book') . ' was approved by the librarian for ' . $approvedDays . ' day' . ($approvedDays === 1 ? '' : 's') . '.',
                                'info',
                                $memberUserId,
                                [
                                    'kind' => 'borrow_request_approved',
                                    'entity_type' => 'borrow',
                                    'entity_id' => (int) ($result['borrow_id'] ?? 0),
                                    'batch_ref' => $requestBatch,
                                ]
                            );
                        }

                        audit_log($conn, 'librarian.borrow.approve', [
                            'borrow_id' => (int) $result['borrow_id'],
                            'book_id' => (int) $result['book_id'],
                            'user_id' => (int) $result['user_id'],
                            'borrow_date' => (string) $result['borrow_date'],
                            'borrow_days' => (int) ($result['borrow_days'] ?? 0),
                            'due_date' => (string) $result['due_date'],
                            'request_batch' => $requestBatch,
                            'approval_email_queued' => $emailQueued,
                        ]);
                    }

                    $emailDispatch = $emailQueuedCount > 0
                        ? process_pending_email_jobs($conn, max(5, min(20, $emailQueuedCount + 2)))
                        : ['sent' => 0, 'failed' => 0];

                    $msg = count($approved) . ' request' . (count($approved) === 1 ? '' : 's') . ' approved from this batch.';
                    if ($skipped > 0) {
                        $msg .= ' ' . $skipped . ' item' . ($skipped === 1 ? ' was' : 's were') . ' left pending because stock was no longer available.';
                    }
                    if ((int) ($emailDispatch['sent'] ?? 0) > 0) {
                        $msg .= ' ' . (int) $emailDispatch['sent'] . ' approval email' . ((int) $emailDispatch['sent'] === 1 ? ' was' : 's were') . ' sent immediately.';
                    }
                    if ($emailFailedCount > 0) {
                        $msg .= ' ' . $emailFailedCount . ' email notification' . ($emailFailedCount === 1 ? ' could' : 's could') . ' not be queued.';
                        $msgType = 'warning';
                    }
                    if ((int) ($emailDispatch['failed'] ?? 0) > 0) {
                        $msg .= ' ' . (int) $emailDispatch['failed'] . ' queued email job' . ((int) $emailDispatch['failed'] === 1 ? ' could' : 's could') . ' not be sent right now.';
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

                    $memberRole = (string) ($confirmed[0]['user_role'] ?? '');
                    $memberUserId = (int) ($confirmed[0]['user_id'] ?? 0);
                    if ($memberUserId > 0 && in_array($memberRole, ['student', 'faculty'], true)) {
                        create_notification(
                            $conn,
                            $memberRole,
                            'Return Request Approved',
                            count($confirmed) . ' return' . (count($confirmed) === 1 ? '' : 's') . ' in your batch were confirmed by the librarian.',
                            'info',
                            $memberUserId
                        );
                    }

                    $msg = count($confirmed) . ' return' . (count($confirmed) === 1 ? '' : 's') . ' confirmed from this batch.';
                    $confirmedBorrowIds = array_map(static fn(array $result): int => (int) ($result['borrow_id'] ?? 0), $confirmed);
                    if (send_return_confirmation_email($conn, $confirmedBorrowIds)) {
                        $msg .= ' The return confirmation email was sent.';
                    }
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
                    $memberUserId = (int) ($confirmed[0]['user_id'] ?? 0);
                    $memberRole = (string) ($confirmed[0]['user_role'] ?? '');

                    create_notification(
                        $conn,
                        'admin',
                        'Borrow Return Confirmed',
                        $copyLabel . ' of ' . ($bookTitle !== '' ? $bookTitle : 'a book') . ' were confirmed as returned by a librarian.',
                        'info'
                    );
                    if ($memberUserId > 0 && in_array($memberRole, ['student', 'faculty'], true)) {
                        create_notification(
                            $conn,
                            $memberRole,
                            'Return Request Approved',
                            'Your return request for ' . $copyLabel . ' of ' . ($bookTitle !== '' ? $bookTitle : 'your book') . ' was confirmed by the librarian.',
                            'info',
                            $memberUserId
                        );
                    }

                    $msg = count($confirmed) . ' cop' . (count($confirmed) === 1 ? 'y was' : 'ies were') . ' confirmed for this book.';
                    $confirmedBorrowIds = array_map(static fn(array $result): int => (int) ($result['borrow_id'] ?? 0), $confirmed);
                    if (send_return_confirmation_email($conn, $confirmedBorrowIds)) {
                        $msg .= ' The return confirmation email was sent.';
                    }
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

<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

$msg = '';
$msgType = 'success';
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');
$today = date('Y-m-d');

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

$summary = $conn->query("
    SELECT
      COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_approvals,
      COALESCE(SUM(CASE WHEN status IN ('borrowed', 'return_requested') THEN 1 ELSE 0 END), 0) AS active_borrows,
      COALESCE(SUM(CASE WHEN status IN ('borrowed', 'return_requested') AND due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_borrows,
      COALESCE(SUM(CASE WHEN status = 'return_requested' THEN 1 ELSE 0 END), 0) AS pending_returns
    FROM borrows
    WHERE status IN ('pending', 'borrowed', 'return_requested')
")->fetch_assoc();

$sql = "
    SELECT
      br.id,
      br.requested_at,
      br.approved_at,
      br.borrow_date,
      br.due_date,
      br.due_at,
      br.status,
      u.fullname,
      u.username,
      u.role,
      b.title,
      b.author,
      b.qty_available
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    JOIN books b ON b.id = br.book_id
    WHERE br.status IN ('pending', 'borrowed', 'return_requested')
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (u.fullname LIKE ? OR u.username LIKE ? OR b.title LIKE ? OR b.author LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $types .= 'ssss';
}

if ($statusFilter === 'overdue') {
    $sql .= " AND br.status IN ('borrowed', 'return_requested') AND br.due_date < CURDATE()";
} elseif ($statusFilter === 'due_today') {
    $sql .= " AND br.status IN ('borrowed', 'return_requested') AND br.due_date = CURDATE()";
}

$sql .= " ORDER BY br.due_date ASC, br.id DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$borrows = $stmt->get_result();

$recentRequests = $conn->query("
    SELECT
      br.id,
      u.username,
      b.title,
      br.due_date,
      br.due_at
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    JOIN books b ON b.id = br.book_id
    WHERE br.status = 'return_requested'
    ORDER BY br.id DESC
    LIMIT 4
");

$pendingBatches = [];
$pendingBatchRows = $conn->query("
    SELECT
      br.request_batch,
      br.requested_at,
      b.id AS book_id,
      b.qty_available,
      u.id AS user_id,
      u.fullname,
      u.username,
      u.role,
      b.title,
      b.author,
      br.id AS borrow_id
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    JOIN books b ON b.id = br.book_id
    WHERE br.status = 'pending'
    ORDER BY br.created_at DESC, br.id ASC
");

if ($pendingBatchRows instanceof mysqli_result) {
    while ($row = $pendingBatchRows->fetch_assoc()) {
        $batchKey = (string) ($row['request_batch'] ?? '');
        if ($batchKey === '') {
            $batchKey = 'legacy-' . (int) $row['borrow_id'];
        }

        if (!isset($pendingBatches[$batchKey])) {
            $pendingBatches[$batchKey] = [
                'request_batch' => $batchKey,
                'created_at' => (string) ($row['requested_at'] ?? ''),
                'fullname' => (string) ($row['fullname'] ?? ''),
                'username' => (string) ($row['username'] ?? ''),
                'role' => (string) ($row['role'] ?? ''),
                'actionable_items' => 0,
                'waiting_stock_items' => 0,
                'items' => [],
            ];
        }

        $waitingForStock = (int) ($row['qty_available'] ?? 0) <= 0;
        if ($waitingForStock) {
            $pendingBatches[$batchKey]['waiting_stock_items']++;
        } else {
            $pendingBatches[$batchKey]['actionable_items']++;
        }

        $pendingBatches[$batchKey]['items'][] = [
            'borrow_id' => (int) $row['borrow_id'],
            'title' => (string) ($row['title'] ?? ''),
            'author' => (string) ($row['author'] ?? ''),
            'waiting_for_stock' => $waitingForStock,
        ];
    }
}

$returnBatchStats = [];
$returnBatchStatsRows = $conn->query("
    SELECT
      request_batch,
      COUNT(*) AS total_items,
      SUM(CASE WHEN status = 'return_requested' THEN 1 ELSE 0 END) AS requested_items,
      SUM(CASE WHEN status = 'borrowed' THEN 1 ELSE 0 END) AS outstanding_items
    FROM borrows
    GROUP BY request_batch
");
if ($returnBatchStatsRows instanceof mysqli_result) {
    while ($row = $returnBatchStatsRows->fetch_assoc()) {
        $returnBatchStats[(string) ($row['request_batch'] ?? '')] = $row;
    }
}

$pendingReturnBatches = [];
$pendingReturnRows = $conn->query("
    SELECT
      br.id AS borrow_id,
      br.return_batch,
      br.request_batch,
      br.return_requested_at AS request_date,
      u.fullname,
      u.username,
      u.role,
      b.title,
      b.author
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    JOIN books b ON b.id = br.book_id
    WHERE br.status = 'return_requested'
      AND br.return_batch IS NOT NULL
      AND br.return_batch <> ''
    ORDER BY br.return_requested_at DESC, br.id ASC
");
if ($pendingReturnRows instanceof mysqli_result) {
    while ($row = $pendingReturnRows->fetch_assoc()) {
        $batchKey = (string) ($row['return_batch'] ?? '');
        if (!isset($pendingReturnBatches[$batchKey])) {
            $requestBatchKey = (string) ($row['request_batch'] ?? '');
            $stats = $returnBatchStats[$requestBatchKey] ?? ['total_items' => 0, 'requested_items' => 0, 'outstanding_items' => 0];
            $pendingReturnBatches[$batchKey] = [
                'return_batch' => $batchKey,
                'request_batch' => $requestBatchKey,
                'request_date' => (string) ($row['request_date'] ?? ''),
                'fullname' => (string) ($row['fullname'] ?? ''),
                'username' => (string) ($row['username'] ?? ''),
                'role' => (string) ($row['role'] ?? ''),
                'total_items' => (int) ($stats['total_items'] ?? 0),
                'requested_items' => (int) ($stats['requested_items'] ?? 0),
                'outstanding_items' => (int) ($stats['outstanding_items'] ?? 0),
                'items' => [],
            ];
        }

        $pendingReturnBatches[$batchKey]['items'][] = [
            'borrow_id' => (int) $row['borrow_id'],
            'title' => (string) ($row['title'] ?? ''),
            'author' => (string) ($row['author'] ?? ''),
        ];
    }
}

$selectedRequestBatch = trim((string) ($_GET['request_batch'] ?? ''));
$selectedReturnBatch = trim((string) ($_GET['return_batch'] ?? ''));
$selectedPendingBatch = $selectedRequestBatch !== '' ? ($pendingBatches[$selectedRequestBatch] ?? null) : null;
$selectedPendingReturnBatch = $selectedReturnBatch !== '' ? ($pendingReturnBatches[$selectedReturnBatch] ?? null) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Borrow Desk')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell librarian-shell member-shell js-member-sidebar" data-sidebar-key="librarian-borrows" data-sidebar-default="expanded">
  <?php
  $sidebarPage = 'borrows';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Librarian Borrow Desk';
  $pageSubtitle = 'Approve requests, track active borrows, and confirm returns';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <?php
    $noticeItems = [];
    if ($msg !== '') {
        $noticeItems[] = ['type' => $msgType, 'message' => $msg];
    }
    require __DIR__ . '/partials/notices.php';
    ?>

    <div class="panel">
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Active borrow operations</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo (int) ($summary['pending_approvals'] ?? 0); ?></strong>
          <span class="muted">Pending borrow approvals</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($summary['active_borrows'] ?? 0); ?></strong>
          <span class="muted">Currently borrowed</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($summary['overdue_borrows'] ?? 0); ?></strong>
          <span class="muted">Overdue returns</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($summary['pending_returns'] ?? 0); ?></strong>
          <span class="muted">Pending return requests</span>
        </div>
      </div>
    </div>

    <div class="grid cards librarian-desk-queue-grid">
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-recent" aria-hidden="true"></div>
          <div class="grow">
            <span class="chip">Today&apos;s Priority</span>
            <h3 class="heading-top-md heading-card">Pending Return Batches</h3>
            <p class="muted">Confirm physical returns first so copies go back into stock immediately.</p>
          </div>
        </div>
        <div class="grid cards flow-top-md librarian-borrow-batch-grid">
          <?php if ($pendingReturnBatches === []): ?>
            <div class="empty-state">No pending return batches right now.</div>
          <?php endif; ?>
          <?php foreach ($pendingReturnBatches as $batch): ?>
            <a
              class="panel librarian-batch-card librarian-batch-summary-card"
              href="manage_borrows.php?return_batch=<?php echo urlencode((string) $batch['return_batch']); ?>"
            >
              <div class="librarian-batch-head">
                <div>
                  <strong class="label-block"><?php echo h($batch['fullname']); ?></strong>
                  <span class="muted"><?php echo h($batch['username']); ?> | <?php echo h(role_label($batch['role'])); ?> | <?php echo h(format_batch_reference($batch['return_batch'], 'Return Ref')); ?></span>
                </div>
                <div class="librarian-batch-meta">
                  <span class="chip">Requested <?php echo h(format_display_date($batch['request_date'], '-')); ?></span>
                </div>
              </div>
              <div class="inline-actions chips-row batch-status-row">
                <span class="chip"><?php echo (int) $batch['total_items']; ?> total</span>
                <span class="chip"><?php echo (int) $batch['requested_items']; ?> requested</span>
                <span class="chip"><?php echo (int) $batch['outstanding_items']; ?> outstanding</span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-checklist" aria-hidden="true"></div>
        <div class="grow">
          <span class="chip">Release Queue</span>
          <h3 class="heading-top-md heading-card">Pending Borrow Approvals</h3>
          <p class="muted">Approve and release only the requests with stock available right now.</p>
        </div>
      </div>
        <div class="grid cards flow-top-md librarian-borrow-batch-grid">
          <?php if ($pendingBatches === []): ?>
            <div class="empty-state">No pending borrow batches right now.</div>
          <?php endif; ?>
          <?php foreach ($pendingBatches as $batch): ?>
            <a
              class="panel librarian-batch-card librarian-batch-summary-card"
              href="manage_borrows.php?request_batch=<?php echo urlencode((string) $batch['request_batch']); ?>"
            >
              <div class="librarian-batch-head">
                <div>
                  <strong class="label-block"><?php echo h($batch['fullname']); ?></strong>
                  <span class="muted"><?php echo h($batch['username']); ?> | <?php echo h(role_label($batch['role'])); ?> | <?php echo h(format_batch_reference($batch['request_batch'], 'Request Ref')); ?></span>
                </div>
                <div class="librarian-batch-meta">
                  <span class="chip"><?php echo h(format_display_date($batch['created_at'], '-')); ?></span>
                </div>
              </div>
              <div class="inline-actions chips-row batch-status-row">
                <span class="chip"><?php echo count($batch['items']); ?> request<?php echo count($batch['items']) === 1 ? '' : 's'; ?></span>
                <span class="chip"><?php echo (int) ($batch['actionable_items'] ?? 0); ?> ready to release</span>
              <span class="chip"><?php echo (int) ($batch['waiting_stock_items'] ?? 0); ?> waiting for stock</span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    </div>

    <div class="grid cards">
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-recent" aria-hidden="true"></div>
          <div>
            <span class="chip">Attention Needed</span>
            <h3 class="heading-top-md">Recent return requests</h3>
          </div>
        </div>
        <div class="stack">
          <?php if (!$recentRequests || $recentRequests->num_rows === 0): ?>
            <div class="empty-state">No recent return requests waiting for confirmation.</div>
          <?php endif; ?>
          <?php while ($recentRequests && ($request = $recentRequests->fetch_assoc())): ?>
            <div class="empty-state">
              <strong class="label-block meta-top-sm"><?php echo h($request['title']); ?></strong>
              <span class="muted"><?php echo h($request['username']); ?> | Due <?php echo h(format_display_datetime((string) (($request['due_at'] ?? '') ?: ($request['due_date'] ?? '')))); ?></span>
            </div>
          <?php endwhile; ?>
        </div>
      </div>
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
          <div>
            <span class="chip">Desk Rules</span>
            <h3 class="heading-top-md">Borrow handling notes</h3>
          </div>
        </div>
        <div class="stack">
          <div class="empty-state">Approve and release only the requests with stock available now.</div>
          <div class="empty-state">Confirm return only after the physical book has been handed over.</div>
          <div class="empty-state">Overdue penalties are automatic at PHP 2.00 per day after return confirmation.</div>
          <div class="empty-state">Use the records table below for lookup and verification, not as the main work queue.</div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-books" aria-hidden="true"></div>
        <div class="grow">
          <span class="chip">Lookup / Records</span>
          <h3 class="heading-top-md heading-card">Borrow Records</h3>
          <p class="muted">Search borrower name, username, title, or author when you need to verify a specific record.</p>
        </div>
      </div>
      <form method="get" class="toolbar flow-top-md borrow-records-toolbar">
          <div class="grow">
            <label for="search">Search</label>
            <input id="search" name="search" value="<?php echo h($search); ?>" placeholder="Borrower or book">
          </div>
          <div>
            <label for="status">View</label>
            <div class="ui-select-shell">
              <select id="status" name="status" class="ui-select">
                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All active</option>
                <option value="overdue" <?php echo $statusFilter === 'overdue' ? 'selected' : ''; ?>>Overdue only</option>
                <option value="due_today" <?php echo $statusFilter === 'due_today' ? 'selected' : ''; ?>>Due today</option>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
          </div>
          <div class="inline-actions">
            <button type="submit">Apply</button>
            <a class="button secondary" href="manage_borrows.php">Reset</a>
          </div>
      </form>

      <div class="table-wrap table-wrap-top">
        <table>
          <thead>
            <tr>
              <th>Borrow ID</th>
              <th>Borrower</th>
              <th>Role</th>
              <th>Book</th>
              <th>Author</th>
              <th>Borrow Date</th>
              <th>Due Date</th>
              <th>State</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($borrows->num_rows === 0): ?>
              <tr><td colspan="8" class="muted">No borrow records matched your filters.</td></tr>
            <?php endif; ?>
            <?php while ($borrow = $borrows->fetch_assoc()): ?>
              <?php
              $state = 'On time';
              $waitingForStock = $borrow['status'] === 'pending' && (int) ($borrow['qty_available'] ?? 0) <= 0;
              if ($borrow['status'] === 'pending') {
                  $state = $waitingForStock ? 'Waiting for stock' : 'Pending approval';
              } elseif ($borrow['status'] === 'return_requested') {
                  $state = 'Awaiting return confirmation';
              } elseif ($borrow['due_date'] < $today) {
                  $state = 'Overdue';
              } elseif ($borrow['due_date'] === $today) {
                  $state = 'Due today';
              }
              ?>
              <tr>
                <td><?php echo (int) $borrow['id']; ?></td>
                <td>
                  <strong class="label-block"><?php echo h($borrow['fullname']); ?></strong>
                  <span class="muted"><?php echo h($borrow['username']); ?></span>
                </td>
                <td><span class="badge"><?php echo h(role_label((string) $borrow['role'])); ?></span></td>
                <td>
                  <strong class="label-block"><?php echo h($borrow['title']); ?></strong>
                  <span class="muted">
                    <?php
                    if ($borrow['status'] === 'pending') {
                        echo $waitingForStock ? 'Waiting for available copy' : 'Pending approval';
                    } elseif ($borrow['status'] === 'return_requested') {
                        echo 'Return requested';
                    } else {
                        echo 'Active with borrower';
                    }
                    ?>
                  </span>
                </td>
                <td><?php echo h($borrow['author']); ?></td>
                <td><?php echo h(format_display_datetime((string) (($borrow['status'] === 'pending' ? ($borrow['requested_at'] ?? '') : ($borrow['approved_at'] ?? '')) ?: ($borrow['borrow_date'] ?? '')))); ?></td>
                <td><?php echo $borrow['status'] === 'pending' ? '-' : h(format_display_datetime((string) (($borrow['due_at'] ?? '') ?: ($borrow['due_date'] ?? '')))); ?></td>
                <td>
                  <span class="badge">
                    <span class="status-dot <?php echo $state === 'Overdue' ? 'overdue' : ($state === 'Due today' ? 'due' : ($state === 'Waiting for stock' ? 'waiting_stock' : ($borrow['status'] === 'pending' ? 'pending' : ($borrow['status'] === 'return_requested' ? 'return_requested' : 'approved')))); ?>"></span>
                    <?php echo h($state); ?>
                  </span>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  </div>
<?php if ($selectedPendingReturnBatch): ?>
  <div class="desk-modal" data-desk-modal>
    <a class="desk-modal-backdrop" href="manage_borrows.php" aria-label="Close return batch details"></a>
    <div class="desk-modal-dialog panel" role="dialog" aria-modal="true" aria-labelledby="return-batch-modal-title">
      <div class="desk-modal-head">
        <div>
          <p class="muted eyebrow-compact">Return Batch</p>
          <h3 id="return-batch-modal-title" class="heading-card"><?php echo h(format_batch_reference($selectedPendingReturnBatch['return_batch'], 'Return Ref')); ?></h3>
          <p class="muted">Confirm only the items physically received for this batch.</p>
        </div>
        <a class="button secondary" href="manage_borrows.php">Close</a>
      </div>

      <div class="panel">
        <div class="librarian-batch-head">
          <div>
            <strong class="label-block"><?php echo h($selectedPendingReturnBatch['fullname']); ?></strong>
            <span class="muted">
              <?php echo h($selectedPendingReturnBatch['username']); ?> | <?php echo h(role_label($selectedPendingReturnBatch['role'])); ?> |
              <?php echo (int) $selectedPendingReturnBatch['requested_items']; ?> of <?php echo (int) $selectedPendingReturnBatch['total_items']; ?> requested for return
            </span>
          </div>
          <div class="librarian-batch-meta">
            <span class="chip"><?php echo (int) $selectedPendingReturnBatch['requested_items']; ?> requested</span>
            <span class="chip"><?php echo (int) $selectedPendingReturnBatch['outstanding_items']; ?> outstanding</span>
            <span class="chip">Requested <?php echo h(format_display_date($selectedPendingReturnBatch['request_date'], '-')); ?></span>
          </div>
        </div>
        <div class="stack librarian-batch-list">
          <?php foreach ($selectedPendingReturnBatch['items'] as $item): ?>
            <div class="empty-state librarian-batch-item">
              <div>
                <strong class="label-block meta-top-sm"><?php echo h($item['title']); ?></strong>
                <span class="muted"><?php echo h($item['author']); ?> | Return request #<?php echo (int) $item['borrow_id']; ?> | Awaiting physical return</span>
              </div>
              <form method="post" class="inline-form" data-confirm="Confirm that the physical book has been returned?">
                <input type="hidden" name="borrow_id" value="<?php echo (int) $item['borrow_id']; ?>">
                <button type="submit" name="mark_returned" value="1">Confirm Physical Return</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (count($selectedPendingReturnBatch['items']) > 1): ?>
          <form method="post" class="inline-form flow-top-md" data-confirm="Confirm all physically received books in this return batch?">
            <input type="hidden" name="return_batch" value="<?php echo h($selectedPendingReturnBatch['return_batch']); ?>">
            <button type="submit" name="confirm_return_batch" value="1">Confirm <?php echo count($selectedPendingReturnBatch['items']); ?> Physical Returns</button>
          </form>
          <p class="muted meta-top-sm">Bulk confirm should be used only after you have physically received every requested item shown in this batch.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($selectedPendingBatch): ?>
  <div class="desk-modal" data-desk-modal>
    <a class="desk-modal-backdrop" href="manage_borrows.php" aria-label="Close borrow approval details"></a>
    <div class="desk-modal-dialog panel" role="dialog" aria-modal="true" aria-labelledby="borrow-batch-modal-title">
      <div class="desk-modal-head">
        <div>
          <p class="muted eyebrow-compact">Borrow Approval Batch</p>
          <h3 id="borrow-batch-modal-title" class="heading-card"><?php echo h(format_batch_reference($selectedPendingBatch['request_batch'], 'Request Ref')); ?></h3>
          <p class="muted">Approve and release only the requests in this batch that still have stock available.</p>
        </div>
        <a class="button secondary" href="manage_borrows.php">Close</a>
      </div>

      <div class="panel">
        <div class="librarian-batch-head">
          <div>
            <strong class="label-block"><?php echo h($selectedPendingBatch['fullname']); ?></strong>
            <span class="muted"><?php echo h($selectedPendingBatch['username']); ?> | <?php echo h(role_label($selectedPendingBatch['role'])); ?></span>
          </div>
          <div class="librarian-batch-meta">
            <span class="chip"><?php echo (int) ($selectedPendingBatch['actionable_items'] ?? 0); ?> ready to release</span>
            <span class="chip"><?php echo (int) ($selectedPendingBatch['waiting_stock_items'] ?? 0); ?> waiting for stock</span>
            <span class="chip"><?php echo h(format_display_date($selectedPendingBatch['created_at'], '-')); ?></span>
          </div>
        </div>
        <div class="stack librarian-batch-list">
          <?php foreach ($selectedPendingBatch['items'] as $item): ?>
            <div class="empty-state librarian-batch-item">
              <div>
                <strong class="label-block meta-top-sm"><?php echo h($item['title']); ?></strong>
                <span class="muted"><?php echo h($item['author']); ?> | Request #<?php echo (int) $item['borrow_id']; ?><?php echo !empty($item['waiting_for_stock']) ? ' | Waiting for stock' : ' | Ready to release'; ?></span>
              </div>
              <?php if (!empty($item['waiting_for_stock'])): ?>
                <span class="badge">
                  <span class="status-dot waiting_stock"></span>
                  Waiting for stock
                </span>
              <?php else: ?>
                <form method="post" class="inline-form" data-confirm="Approve this borrow request and release the book now?">
                  <input type="hidden" name="borrow_id" value="<?php echo (int) $item['borrow_id']; ?>">
                  <button type="submit" name="approve_borrow" value="1">Approve and Release</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ((int) ($selectedPendingBatch['actionable_items'] ?? 0) > 1): ?>
          <form method="post" class="inline-form flow-top-md" data-confirm="Approve all available requests in this batch now?">
            <input type="hidden" name="request_batch" value="<?php echo h($selectedPendingBatch['request_batch']); ?>">
            <button type="submit" name="approve_batch" value="1">Approve <?php echo (int) $selectedPendingBatch['actionable_items']; ?> and Release</button>
          </form>
          <p class="muted meta-top-sm">Bulk approval releases only the requests that still have stock available.</p>
        <?php elseif ((int) ($selectedPendingBatch['actionable_items'] ?? 0) === 0 && (int) ($selectedPendingBatch['waiting_stock_items'] ?? 0) > 0): ?>
          <p class="muted meta-top-sm">This batch is still pending, but every remaining item is waiting for stock.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/shared_confirm.js"></script>
<script src="/librarymanage/assets/librarian_manage_borrows.js"></script>
<script src="/librarymanage/assets/librarian_borrowdesk_modal.js"></script>
<script src="/librarymanage/assets/email_queue_worker.js"></script>
</body>
</html>

<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$statusFilter = trim($_GET['status'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$rolesAllowed = system_roles();
$statusOptions = payment_statuses();
$isValidStatusFilter = $statusFilter !== '' && in_array($statusFilter, $statusOptions, true);
$isValidRoleFilter = $roleFilter !== '' && in_array($roleFilter, $rolesAllowed, true);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$flash = trim($_GET['notice'] ?? '');

if (isset($_POST['approve']) || isset($_POST['reject'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $newStatus = isset($_POST['approve']) ? 'approved' : 'rejected';

    $fetch = $conn->prepare("SELECT penalty_id, amount, proof_path, status FROM payments WHERE id = ? LIMIT 1");
    $fetch->bind_param('i', $id);
    $fetch->execute();
    $current = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    if (!$current || $current['status'] !== 'pending') {
        header('Location: payments_records.php?notice=' . urlencode('Only pending payments can be reviewed.'));
        exit;
    }

    if ($newStatus === 'approved' && (int) ($current['penalty_id'] ?? 0) > 0) {
        $penaltyCheck = $conn->prepare("
            SELECT
                p.amount,
                p.status,
                br.status AS borrow_status
            FROM penalties p
            LEFT JOIN borrows br ON br.id = p.borrow_id
            WHERE p.id = ?
            LIMIT 1
        ");
        $penaltyCheck->bind_param('i', $current['penalty_id']);
        $penaltyCheck->execute();
        $penalty = $penaltyCheck->get_result()->fetch_assoc();
        $penaltyCheck->close();

        if (
            !$penalty
            || $penalty['status'] === 'paid'
            || ($penalty['borrow_status'] ?? '') !== 'returned'
            || (float) $current['amount'] !== (float) $penalty['amount']
        ) {
            header('Location: payments_records.php?notice=' . urlencode('This payment can no longer be approved safely.'));
            exit;
        }
    }

    if ($newStatus === 'rejected') {
        $stmt = $conn->prepare("UPDATE payments SET status = 'rejected', proof_path = NULL WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();

        if (!empty($current['proof_path'])) {
            remove_relative_file($current['proof_path']);
        }

        if ($changed) {
            audit_log($conn, 'admin.payment.reject', [
                'payment_id' => $id,
                'penalty_id' => (int) ($current['penalty_id'] ?? 0),
            ]);
            create_notification(
                $conn,
                'student',
                'Payment Rejected',
                'Payment #' . $id . ' was rejected by admin. Please resubmit with a valid proof.',
                'critical'
            );
            create_notification(
                $conn,
                'faculty',
                'Payment Rejected',
                'Payment #' . $id . ' was rejected by admin. Please resubmit with a valid proof.',
                'critical'
            );
        }
    } else {
        $stmt = $conn->prepare("UPDATE payments SET status = 'approved' WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();

        $sync = $conn->prepare("
            UPDATE penalties p
            JOIN payments pay ON pay.penalty_id = p.id
            SET p.status = 'paid'
            WHERE pay.id = ? AND pay.penalty_id IS NOT NULL AND pay.status = 'approved'
        ");
        $sync->bind_param('i', $id);
        $sync->execute();
        $sync->close();

        if ($changed) {
            audit_log($conn, 'admin.payment.approve', [
                'payment_id' => $id,
                'penalty_id' => (int) ($current['penalty_id'] ?? 0),
            ]);
            create_notification(
                $conn,
                'student',
                'Payment Approved',
                'Payment #' . $id . ' was approved by admin.',
                'info'
            );
            create_notification(
                $conn,
                'faculty',
                'Payment Approved',
                'Payment #' . $id . ' was approved by admin.',
                'info'
            );
        }
    }

    header('Location: payments_records.php');
    exit;
}

$summary = $conn->query("
    SELECT
      COUNT(*) AS total_records,
      SUM(status = 'pending') AS pending_records,
      SUM(status = 'approved') AS approved_records,
      SUM(status = 'rejected') AS rejected_records,
      COALESCE(SUM(amount), 0) AS total_amount,
      COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount
    FROM payments
")->fetch_assoc();

$recentQueue = $conn->query("
    SELECT pay.id, u.username, pay.amount, pay.status, pay.created_at
    FROM payments pay
    JOIN users u ON u.id = pay.user_id
    ORDER BY pay.id DESC
    LIMIT 5
");

$countSql = "
    SELECT COUNT(*) AS total
    FROM payments pay
    JOIN users u ON u.id = pay.user_id
    WHERE 1=1
";
$types = '';
$params = [];

if ($isValidStatusFilter) {
    $countSql .= " AND pay.status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}
if ($isValidRoleFilter) {
    $countSql .= " AND u.role = ?";
    $types .= 's';
    $params[] = $roleFilter;
}

$countStmt = $conn->prepare($countSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "
    SELECT pay.*, u.username, u.role, p.status AS penalty_status
    FROM payments pay
    JOIN users u ON u.id = pay.user_id
    LEFT JOIN penalties p ON p.id = pay.penalty_id
    WHERE 1=1
";
if ($isValidStatusFilter) {
    $sql .= " AND pay.status = ?";
}
if ($isValidRoleFilter) {
    $sql .= " AND u.role = ?";
}
$sql .= " ORDER BY pay.id DESC LIMIT ? OFFSET ?";

$queryTypes = $types . 'ii';
$queryParams = $params;
$queryParams[] = $perPage;
$queryParams[] = $offset;

$paymentsStmt = $conn->prepare($sql);
$paymentsStmt->bind_param($queryTypes, ...$queryParams);
$paymentsStmt->execute();
$payments = $paymentsStmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment Records</title>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css">
</head>
<body>
<div class="site-shell">
  <?php
  $pageTitle = 'Payment Records';
  $pageSubtitle = 'Review full payment proof submissions';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <?php
    $noticeItems = [];
    if ($flash !== '') {
        $noticeItems[] = ['type' => 'error', 'message' => $flash];
    }
    require __DIR__ . '/partials/notices.php';
    ?>

    <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-payments" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Overview</p>
          <h3 class="heading-card">Payment review summary</h3>
          <p class="muted">Track incoming full-payment submissions, clear the pending queue, and keep linked penalty balances synchronized with final decisions.</p>
        </div>
      </div>
      <div class="stat-grid">
        <div class="stat-card">
          <span class="code-pill">Records</span>
          <strong><?php echo (int) ($summary['total_records'] ?? 0); ?></strong>
          <span class="muted">All submitted payment records in the system.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Pending</span>
          <strong><?php echo (int) ($summary['pending_records'] ?? 0); ?></strong>
          <span class="muted">Records that still need admin review.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Approved</span>
          <strong><?php echo (int) ($summary['approved_records'] ?? 0); ?></strong>
          <span class="muted">Payments already accepted and applied.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Pending Amount</span>
          <strong><?php echo h(format_currency($summary['pending_amount'] ?? 0)); ?></strong>
          <span class="muted">Value currently waiting for approval or rejection.</span>
        </div>
      </div>
    </div>

    <div class="grid cards">
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-recent" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Recent Queue</p>
            <h3 class="heading-card">Latest payment submissions</h3>
            <p class="muted">Start from the newest uploads so unresolved balances do not stay open longer than necessary.</p>
          </div>
        </div>
        <div class="activity-feed">
          <?php if (!$recentQueue || $recentQueue->num_rows === 0): ?>
            <div class="empty-state">No payment submissions yet.</div>
          <?php endif; ?>
          <?php while ($queue = $recentQueue->fetch_assoc()): ?>
            <div class="activity-item">
              <strong><span class="status-dot <?php echo h($queue['status']); ?>"></span>Payment #<?php echo (int) $queue['id']; ?></strong>
              <div class="meta"><?php echo h($queue['username']); ?> submitted <?php echo h(format_currency($queue['amount'])); ?></div>
              <div class="meta meta-top"><?php echo h(date('F j, Y g:i A', strtotime($queue['created_at']))); ?></div>
            </div>
          <?php endwhile; ?>
        </div>
      </div>
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Review Notes</p>
            <h3 class="heading-card">Before approving</h3>
            <p class="muted">Keep payment decisions consistent so member balances, penalty state, and audit records all match.</p>
          </div>
        </div>
        <div class="stack">
          <div class="empty-state">
            <strong class="label-block-gap">Proof verification</strong>
            Check that the uploaded proof matches the full penalty amount and the expected borrower before approving.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Penalty sync</strong>
            Approving a linked full payment marks the related penalty as paid.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Rejection handling</strong>
            Rejecting removes the local proof file when one is attached, so only valid proof remains stored.
          </div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="toolbar toolbar-top">
        <div class="grow">
          <div class="card-head card-head-tight">
            <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Payment Queue</p>
              <h3 class="heading-card">Submission records and actions</h3>
              <p class="muted">Filter by status or user role, then approve or reject submissions page by page.</p>
            </div>
          </div>
        </div>
        <form method="get" class="toolbar grow">
          <div>
            <label for="status_filter">Status</label>
            <div class="ui-select-shell">
              <select id="status_filter" name="status" class="ui-select">
                <option value="">All statuses</option>
                <?php foreach ($statusOptions as $status): ?>
                  <option value="<?php echo h($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo h(ucfirst($status)); ?></option>
                <?php endforeach; ?>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
          </div>
          <div>
            <label for="role_filter">Role</label>
            <div class="ui-select-shell">
              <select id="role_filter" name="role" class="ui-select">
                <option value="">All roles</option>
                <?php foreach ($rolesAllowed as $role): ?>
                  <option value="<?php echo h($role); ?>" <?php echo $roleFilter === $role ? 'selected' : ''; ?>><?php echo h(ucfirst($role)); ?></option>
                <?php endforeach; ?>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
          </div>
          <div class="inline-actions">
            <button type="submit">Apply</button>
            <a class="button secondary" href="payments_records.php">Reset</a>
          </div>
        </form>
      </div>
      <div class="inline-actions chips-row">
        <span class="chip">Submitted amount: <?php echo h(format_currency($summary['total_amount'] ?? 0)); ?></span>
        <span class="chip">Pending amount: <?php echo h(format_currency($summary['pending_amount'] ?? 0)); ?></span>
        <span class="chip">Rejected: <?php echo (int) ($summary['rejected_records'] ?? 0); ?></span>
      </div>
      <div class="table-wrap table-wrap-top">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>User</th>
              <th>Role</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Proof</th>
              <th>Penalty</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($payments->num_rows === 0): ?>
              <tr><td colspan="8" class="muted">No payment records found.</td></tr>
            <?php endif; ?>
            <?php while ($payment = $payments->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int) $payment['id']; ?></td>
                <td><?php echo h($payment['username']); ?></td>
                <td><span class="badge"><?php echo h($payment['role']); ?></span></td>
                <td><?php echo h(format_currency($payment['amount'])); ?></td>
                <td><span class="badge"><span class="status-dot <?php echo h($payment['status']); ?>"></span><?php echo h($payment['status']); ?></span></td>
                <td><?php if (!empty($payment['proof_path'])): ?><a href="/librarymanage/<?php echo h($payment['proof_path']); ?>" target="_blank">View</a><?php else: ?><span class="muted">None</span><?php endif; ?></td>
                <td><?php echo (int) ($payment['penalty_id'] ?? 0) > 0 ? '#' . (int) $payment['penalty_id'] . ' / ' . h($payment['penalty_status'] ?: 'n/a') : 'Not linked'; ?></td>
                <td>
                  <?php if ($payment['status'] === 'pending'): ?>
                    <div class="inline-actions">
                      <form method="post" class="inline-form">
                        <input type="hidden" name="id" value="<?php echo (int) $payment['id']; ?>">
                        <button type="submit" name="approve" value="1">Approve</button>
                      </form>
                      <form method="post" class="inline-form">
                        <input type="hidden" name="id" value="<?php echo (int) $payment['id']; ?>">
                        <button type="submit" class="danger" name="reject" value="1">Reject</button>
                      </form>
                    </div>
                  <?php else: ?>
                    <span class="muted">Reviewed</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <div class="pagination">
        <span class="current">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
        <?php if ($page > 1): ?>
          <a class="button secondary" href="?status=<?php echo urlencode($statusFilter); ?>&role=<?php echo urlencode($roleFilter); ?>&page=<?php echo $page - 1; ?>">Previous</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a class="button secondary" href="?status=<?php echo urlencode($statusFilter); ?>&role=<?php echo urlencode($roleFilter); ?>&page=<?php echo $page + 1; ?>">Next</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>

<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

function payments_filter_query(string $search, string $statusFilter, string $roleFilter): string
{
    $query = http_build_query(array_filter([
        'search' => $search,
        'status' => $statusFilter,
        'role' => $roleFilter,
    ], static fn($value) => $value !== ''));

    return $query !== '' ? '?' . $query : '';
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$rolesAllowed = ['student', 'faculty'];
$statusOptions = payment_statuses();
$isValidStatusFilter = $statusFilter !== '' && in_array($statusFilter, $statusOptions, true);
$isValidRoleFilter = $roleFilter !== '' && in_array($roleFilter, $rolesAllowed, true);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$flash = trim($_GET['notice'] ?? '');

if (isset($_POST['approve']) || isset($_POST['reject'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $newStatus = isset($_POST['approve']) ? 'approved' : 'rejected';

    $fetch = $conn->prepare("SELECT penalty_id, payment_batch, amount, proof_path, status FROM payments WHERE id = ? LIMIT 1");
    $fetch->bind_param('i', $id);
    $fetch->execute();
    $current = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    if (!$current || $current['status'] !== 'pending') {
        header('Location: payments_records.php?notice=' . urlencode('Only pending payments can be reviewed.'));
        exit;
    }

    $linkedPenaltyIds = [];
    $linkedPenaltyStmt = $conn->prepare("SELECT penalty_id FROM payment_penalty_links WHERE payment_id = ? ORDER BY penalty_id ASC");
    $linkedPenaltyStmt->bind_param('i', $id);
    $linkedPenaltyStmt->execute();
    $linkedPenaltyRows = $linkedPenaltyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $linkedPenaltyStmt->close();

    foreach ($linkedPenaltyRows as $linkedPenaltyRow) {
        $linkedPenaltyIds[] = (int) ($linkedPenaltyRow['penalty_id'] ?? 0);
    }

    if ($linkedPenaltyIds === [] && (int) ($current['penalty_id'] ?? 0) > 0) {
        $linkedPenaltyIds[] = (int) $current['penalty_id'];
    }
    $linkedPenaltyIds = array_values(array_filter(array_unique($linkedPenaltyIds)));

    if ($newStatus === 'approved' && $linkedPenaltyIds !== []) {
        $placeholders = implode(',', array_fill(0, count($linkedPenaltyIds), '?'));
        $types = str_repeat('i', count($linkedPenaltyIds));
        $penaltyCheck = $conn->prepare("
            SELECT
                p.id,
                p.amount,
                p.status,
                br.status AS borrow_status
            FROM penalties p
            LEFT JOIN borrows br ON br.id = p.borrow_id
            WHERE p.id IN ($placeholders)
        ");
        $penaltyCheck->bind_param($types, ...$linkedPenaltyIds);
        $penaltyCheck->execute();
        $penaltyRows = $penaltyCheck->get_result()->fetch_all(MYSQLI_ASSOC);
        $penaltyCheck->close();

        $expectedAmount = 0.0;
        $validPenaltyCount = 0;
        foreach ($penaltyRows as $penalty) {
            if (
                !$penalty
                || ($penalty['status'] ?? '') === 'paid'
                || ($penalty['borrow_status'] ?? '') !== 'returned'
            ) {
                continue;
            }
            $validPenaltyCount++;
            $expectedAmount += (float) ($penalty['amount'] ?? 0);
        }

        if ($validPenaltyCount !== count($linkedPenaltyIds) || round((float) $current['amount'], 2) !== round($expectedAmount, 2)) {
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

        if ($linkedPenaltyIds !== []) {
            $placeholders = implode(',', array_fill(0, count($linkedPenaltyIds), '?'));
            $types = str_repeat('i', count($linkedPenaltyIds));
            $sync = $conn->prepare("UPDATE penalties SET status = 'paid' WHERE id IN ($placeholders)");
            $sync->bind_param($types, ...$linkedPenaltyIds);
            $sync->execute();
            $sync->close();
        }

        if ($changed) {
            audit_log($conn, 'admin.payment.approve', [
                'payment_id' => $id,
                'penalty_id' => (int) ($current['penalty_id'] ?? 0),
                'payment_batch' => (string) ($current['payment_batch'] ?? ''),
                'linked_penalty_ids' => $linkedPenaltyIds,
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
      COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) AS approved_amount,
      COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount
    FROM payments
")->fetch_assoc();

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
if ($search !== '') {
    $countSql .= " AND (CAST(pay.id AS CHAR) LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
    $term = '%' . $search . '%';
    $types .= 'sss';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
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
    SELECT
        pay.*,
        u.username,
        u.role,
        p.status AS penalty_status,
        b.title AS borrowed_book_title,
        COALESCE(balance.unpaid_total, 0) AS current_balance,
        COUNT(DISTINCT ppl.penalty_id) AS linked_penalty_count
    FROM payments pay
    JOIN users u ON u.id = pay.user_id
    LEFT JOIN penalties p ON p.id = pay.penalty_id
    LEFT JOIN payment_penalty_links ppl ON ppl.payment_id = pay.id
    LEFT JOIN borrows br ON br.id = p.borrow_id
    LEFT JOIN books b ON b.id = br.book_id
    LEFT JOIN (
        SELECT user_id, COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END), 0) AS unpaid_total
        FROM penalties
        GROUP BY user_id
    ) balance ON balance.user_id = pay.user_id
    WHERE 1=1
";
if ($isValidStatusFilter) {
    $sql .= " AND pay.status = ?";
}
if ($isValidRoleFilter) {
    $sql .= " AND u.role = ?";
}
if ($search !== '') {
    $sql .= " AND (CAST(pay.id AS CHAR) LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
}
$sql .= " GROUP BY pay.id ORDER BY pay.id DESC LIMIT ? OFFSET ?";

$queryTypes = $types . 'ii';
$queryParams = $params;
$queryParams[] = $perPage;
$queryParams[] = $offset;

$paymentsStmt = $conn->prepare($sql);
$paymentsStmt->bind_param($queryTypes, ...$queryParams);
$paymentsStmt->execute();
$payments = $paymentsStmt->get_result();
$filterQueryString = payments_filter_query($search, $statusFilter, $roleFilter);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment Records</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell admin-shell member-shell js-member-sidebar" data-sidebar-key="admin-payments" data-sidebar-default="expanded" data-sidebar-lock="expanded">
  <?php
  $sidebarPage = 'payments';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
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
          <span class="code-pill">Approved Value</span>
          <strong><?php echo h(format_currency($summary['approved_amount'] ?? 0)); ?></strong>
          <span class="muted">Total value of payments already approved by admin.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Pending Amount</span>
          <strong><?php echo h(format_currency($summary['pending_amount'] ?? 0)); ?></strong>
          <span class="muted">Value currently waiting for approval or rejection.</span>
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
        <form method="get" class="grow admin-record-filters">
            <div>
              <label for="payment_search">Search</label>
              <input id="payment_search" name="search" value="<?php echo h($search); ?>" placeholder="Payment ID, username, or email">
            </div>
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
                  <option value="<?php echo h($role); ?>" <?php echo $roleFilter === $role ? 'selected' : ''; ?>><?php echo h(role_label($role)); ?></option>
                <?php endforeach; ?>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
          </div>
          <div class="inline-actions">
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
              <th>Borrowed Book</th>
              <th>Amount</th>
              <th>Current Balance</th>
              <th>Status</th>
              <th>Proof</th>
              <th>Penalty</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($payments->num_rows === 0): ?>
              <tr><td colspan="10" class="muted">No payment records found.</td></tr>
            <?php endif; ?>
            <?php while ($payment = $payments->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int) $payment['id']; ?></td>
                <td><?php echo h($payment['username']); ?></td>
                <td><span class="badge"><?php echo h($payment['role']); ?></span></td>
                <td>
                  <?php if (trim((string) ($payment['borrowed_book_title'] ?? '')) !== ''): ?>
                    <?php echo h((string) $payment['borrowed_book_title']); ?>
                  <?php else: ?>
                    <span class="muted">Not linked</span>
                  <?php endif; ?>
                </td>
                <td><?php echo h(format_currency($payment['amount'])); ?></td>
                <td><?php echo h(format_currency($payment['current_balance'] ?? 0)); ?></td>
                <td><span class="badge"><span class="status-dot <?php echo h($payment['status']); ?>"></span><?php echo h($payment['status']); ?></span></td>
                <td><?php if (!empty($payment['proof_path'])): ?><a href="<?php echo h(app_url('proof_view.php?payment_id=' . (int) $payment['id'])); ?>" target="_blank">View</a><?php else: ?><span class="muted">None</span><?php endif; ?></td>
                <td>
                  <?php
                  $linkedPenaltyCount = max(0, (int) ($payment['linked_penalty_count'] ?? 0));
                  if ($linkedPenaltyCount > 1) {
                      echo h((string) ($payment['payment_batch'] ?: ('Payment #' . (int) $payment['id'])));
                      echo ' / ' . $linkedPenaltyCount . ' penalties';
                  } elseif ((int) ($payment['penalty_id'] ?? 0) > 0) {
                      echo '#' . (int) $payment['penalty_id'] . ' / ' . h($payment['penalty_status'] ?: 'n/a');
                  } else {
                      echo 'Not linked';
                  }
                  ?>
                </td>
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
                    <span class="review-state review-state-<?php echo h($payment['status']); ?>">
                      <?php echo $payment['status'] === 'rejected' ? 'Rejected by admin' : 'Reviewed'; ?>
                    </span>
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
          <a class="button secondary" href="<?php echo h(($filterQueryString !== '' ? $filterQueryString . '&' : '?') . 'page=' . ($page - 1)); ?>">Previous</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a class="button secondary" href="<?php echo h(($filterQueryString !== '' ? $filterQueryString . '&' : '?') . 'page=' . ($page + 1)); ?>">Next</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script>
(() => {
  const filterForm = document.querySelector('.admin-record-filters');
  const statusFilter = document.getElementById('status_filter');
  const roleFilter = document.getElementById('role_filter');

  if (!filterForm) {
    return;
  }

  const submitFilters = () => {
    if (filterForm.requestSubmit) {
      filterForm.requestSubmit();
      return;
    }
    filterForm.submit();
  };

  if (statusFilter) {
    statusFilter.addEventListener('change', submitFilters);
  }

  if (roleFilter) {
    roleFilter.addEventListener('change', submitFilters);
  }
})();
</script>
</body>
</html>


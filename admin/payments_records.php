<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/book_incidents.php';

require_role('admin');

function payments_filter_query(string $search, string $statusFilter, string $roleFilter, string $paymentScope): string
{
    $query = http_build_query(array_filter([
        'search' => $search,
        'status' => $statusFilter,
        'role' => $roleFilter,
        'scope' => $paymentScope !== 'all' ? $paymentScope : '',
    ], static fn($value) => $value !== ''));

    return $query !== '' ? '?' . $query : '';
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$paymentScope = trim($_GET['scope'] ?? 'all');
$rolesAllowed = ['student', 'faculty'];
$statusOptions = payment_statuses();
$paymentScopes = ['all', 'penalties', 'incidents'];
$isValidPaymentScope = in_array($paymentScope, $paymentScopes, true);
if (!$isValidPaymentScope) {
    $paymentScope = 'all';
}
$isValidStatusFilter = $statusFilter !== '' && in_array($statusFilter, $statusOptions, true);
$isValidRoleFilter = $roleFilter !== '' && in_array($roleFilter, $rolesAllowed, true);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$flash = trim($_GET['notice'] ?? '');
$flashType = trim($_GET['notice_type'] ?? '');

if (isset($_POST['approve']) || isset($_POST['reject'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $newStatus = isset($_POST['approve']) ? 'approved' : 'rejected';

    $fetch = $conn->prepare("SELECT penalty_id, incident_id, payment_batch, amount, proof_path, status, user_id FROM payments WHERE id = ? LIMIT 1");
    $fetch->bind_param('i', $id);
    $fetch->execute();
    $current = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    $redirectQuery = payments_filter_query($search, $statusFilter, $roleFilter, $paymentScope);
    $redirectPrefix = 'payments_records.php' . $redirectQuery . ($redirectQuery !== '' ? '&' : '?');

    if (!$current || $current['status'] !== 'pending') {
        header('Location: ' . $redirectPrefix . 'notice=' . urlencode('Only pending payments can be reviewed.') . '&notice_type=error');
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
            header('Location: ' . $redirectPrefix . 'notice=' . urlencode('This payment can no longer be approved safely.') . '&notice_type=error');
            exit;
        }
    }

    if ($newStatus === 'approved' && (int) ($current['incident_id'] ?? 0) > 0) {
        $incidentId = (int) $current['incident_id'];
        $incidentCheck = $conn->prepare("
            SELECT assessed_fee, settlement_status, workflow_status
            FROM book_incidents
            WHERE id = ?
            LIMIT 1
        ");
        $incidentCheck->bind_param('i', $incidentId);
        $incidentCheck->execute();
        $incident = $incidentCheck->get_result()->fetch_assoc();
        $incidentCheck->close();

        if (
            !$incident
            || (string) ($incident['settlement_status'] ?? '') !== 'pending'
            || round((float) $current['amount'], 2) <= 0
        ) {
            header('Location: ' . $redirectPrefix . 'notice=' . urlencode('This incident payment can no longer be approved.') . '&notice_type=error');
            exit;
        }
    }

    if ($newStatus === 'rejected') {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE payments SET status = 'rejected', proof_path = NULL WHERE id = ? AND status = 'pending'");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $changed = $stmt->affected_rows === 1;
            $stmt->close();

            if ($changed) {
                audit_log($conn, 'admin.payment.reject', [
                    'payment_id' => $id,
                    'penalty_id' => (int) ($current['penalty_id'] ?? 0),
                    'incident_id' => (int) ($current['incident_id'] ?? 0),
                ]);
                $targetRoleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
                $targetRoleStmt->bind_param('i', $current['user_id']);
                $targetRoleStmt->execute();
                $targetRole = (string) ($targetRoleStmt->get_result()->fetch_assoc()['role'] ?? '');
                $targetRoleStmt->close();
                if (in_array($targetRole, ['student', 'faculty'], true)) {
                    $isIncidentPayment = (int) ($current['incident_id'] ?? 0) > 0;
                    create_notification(
                        $conn,
                        $targetRole,
                        $isIncidentPayment ? 'Incident Payment Rejected' : 'Payment Rejected',
                        $isIncidentPayment
                            ? 'Your incident payment proof was rejected by admin. Please upload a new valid proof.'
                            : 'Payment #' . $id . ' was rejected by admin. Please resubmit with a valid proof.',
                        $isIncidentPayment ? 'warning' : 'critical',
                        (int) $current['user_id']
                    );
                }
            }

            $conn->commit();
            if (!empty($current['proof_path'])) {
                remove_relative_file($current['proof_path']);
            }
        } catch (Throwable $exception) {
            $conn->rollback();
            header('Location: ' . $redirectPrefix . 'notice=' . urlencode('Unable to reject this payment right now.') . '&notice_type=error');
            exit;
        }
    } else {
        $conn->begin_transaction();
        try {
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

            if ((int) ($current['incident_id'] ?? 0) > 0) {
                $incidentId = (int) $current['incident_id'];
                $resolvedAt = date('Y-m-d H:i:s');
                $incidentSync = $conn->prepare("
                    UPDATE book_incidents
                    SET assessed_fee = ?,
                        settlement_status = 'paid',
                        workflow_status = 'closed',
                        resolved_at = CASE WHEN resolved_at IS NULL THEN ? ELSE resolved_at END,
                        resolved_by = CASE WHEN resolved_by IS NULL THEN ? ELSE resolved_by END
                    WHERE id = ?
                ");
                $actorUserId = (int) ($_SESSION['user_id'] ?? 0);
                $approvedAmount = round((float) $current['amount'], 2);
                $incidentSync->bind_param('dsii', $approvedAmount, $resolvedAt, $actorUserId, $incidentId);
                $incidentSync->execute();
                $incidentSync->close();
            }

            if ($changed) {
                audit_log($conn, 'admin.payment.approve', [
                    'payment_id' => $id,
                    'penalty_id' => (int) ($current['penalty_id'] ?? 0),
                    'incident_id' => (int) ($current['incident_id'] ?? 0),
                    'payment_batch' => (string) ($current['payment_batch'] ?? ''),
                    'linked_penalty_ids' => $linkedPenaltyIds,
                ]);
                $targetRoleStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
                $targetRoleStmt->bind_param('i', $current['user_id']);
                $targetRoleStmt->execute();
                $targetRole = (string) ($targetRoleStmt->get_result()->fetch_assoc()['role'] ?? '');
                $targetRoleStmt->close();
                if (in_array($targetRole, ['student', 'faculty'], true)) {
                    $isIncidentPayment = (int) ($current['incident_id'] ?? 0) > 0;
                    create_notification(
                        $conn,
                        $targetRole,
                        $isIncidentPayment ? 'Incident Payment Approved' : 'Payment Approved',
                        $isIncidentPayment
                            ? 'Your incident payment proof was approved by admin.'
                            : 'Payment #' . $id . ' was approved by admin.',
                        'info',
                        (int) $current['user_id']
                    );
                }
            }

            $conn->commit();
            if ($changed && (int) ($current['incident_id'] ?? 0) > 0) {
                send_incident_payment_approval_email($conn, $id);
            }
        } catch (Throwable $exception) {
            $conn->rollback();
            header('Location: ' . $redirectPrefix . 'notice=' . urlencode('Unable to approve this payment right now.') . '&notice_type=error');
            exit;
        }
    }

    $decisionLabel = $newStatus === 'approved' ? 'approved' : 'rejected';
    header('Location: ' . $redirectPrefix . 'notice=' . urlencode('Payment #' . $id . ' was ' . $decisionLabel . '.') . '&notice_type=success');
    exit;
}

$summarySql = "
    SELECT
      COUNT(*) AS total_records,
      SUM(status = 'pending') AS pending_records,
      SUM(status = 'approved') AS approved_records,
      SUM(status = 'rejected') AS rejected_records,
      COALESCE(SUM(amount), 0) AS total_amount,
      COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) AS approved_amount,
      COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount
    FROM payments
    WHERE 1=1
";

$summaryTypes = '';
$summaryParams = [];
if ($paymentScope === 'penalties') {
    $summarySql .= " AND (incident_id IS NULL OR incident_id = 0)";
} elseif ($paymentScope === 'incidents') {
    $summarySql .= " AND incident_id > 0";
}

$summaryStmt = $conn->prepare($summarySql);
if ($summaryTypes !== '') {
    $summaryStmt->bind_param($summaryTypes, ...$summaryParams);
}
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

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
if ($paymentScope === 'penalties') {
    $countSql .= " AND (pay.incident_id IS NULL OR pay.incident_id = 0)";
} elseif ($paymentScope === 'incidents') {
    $countSql .= " AND pay.incident_id > 0";
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
        COALESCE(b.title, ib.title) AS borrowed_book_title,
        COALESCE(balance.unpaid_total, 0) AS current_balance,
        COUNT(DISTINCT ppl.penalty_id) AS linked_penalty_count,
        bi.incident_type,
        bi.settlement_status AS incident_settlement_status
    FROM payments pay
    JOIN users u ON u.id = pay.user_id
    LEFT JOIN penalties p ON p.id = pay.penalty_id
    LEFT JOIN payment_penalty_links ppl ON ppl.payment_id = pay.id
    LEFT JOIN borrows br ON br.id = p.borrow_id
    LEFT JOIN books b ON b.id = br.book_id
    LEFT JOIN book_incidents bi ON bi.id = pay.incident_id
    LEFT JOIN books ib ON ib.id = bi.book_id
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
if ($paymentScope === 'penalties') {
    $sql .= " AND (pay.incident_id IS NULL OR pay.incident_id = 0)";
} elseif ($paymentScope === 'incidents') {
    $sql .= " AND pay.incident_id > 0";
}
$sql .= " GROUP BY pay.id ORDER BY pay.id DESC LIMIT ? OFFSET ?";

$queryTypes = $types . 'ii';
$queryParams = $params;
$queryParams[] = $perPage;
$queryParams[] = $offset;

$paymentsStmt = $conn->prepare($sql);
$paymentsStmt->bind_param($queryTypes, ...$queryParams);
$paymentsStmt->execute();
$paymentsResult = $paymentsStmt->get_result();
$payments = [];
while ($paymentsResult && ($paymentRow = $paymentsResult->fetch_assoc())) {
    $payments[] = $paymentRow;
}
$filterQueryString = payments_filter_query($search, $statusFilter, $roleFilter, $paymentScope);
$scopeTitle = match ($paymentScope) {
    'penalties' => 'Overdue Penalty Payments',
    'incidents' => 'Incident Payments',
    default => 'Payment Records',
};
$scopeSubtitle = match ($paymentScope) {
    'penalties' => 'Review overdue penalty payment submissions only',
    'incidents' => 'Review lost and damaged incident payment submissions only',
    default => 'Review full payment proof submissions',
};
$summaryHeading = match ($paymentScope) {
    'penalties' => 'Penalty payment summary',
    'incidents' => 'Incident payment summary',
    default => 'Payment review summary',
};
$summaryDescription = match ($paymentScope) {
    'penalties' => 'Track overdue penalty submissions, clear the pending queue, and keep returned-book balances synchronized.',
    'incidents' => 'Track lost and damaged fee submissions, clear the pending queue, and keep incident settlements synchronized.',
    default => 'Track incoming full-payment submissions, clear the pending queue, and keep linked balances synchronized with final decisions.',
};
$summaryLabels = match ($paymentScope) {
    'penalties' => [
        'records' => 'Penalty Records',
        'records_copy' => 'All submitted overdue penalty payment records.',
        'pending' => 'Pending Review',
        'pending_copy' => 'Penalty payments that still need admin review.',
        'approved' => 'Approved Payments',
        'approved_copy' => 'Penalty payments already accepted and applied.',
        'approved_amount' => 'Approved Penalties',
        'approved_amount_copy' => 'Total approved value for overdue penalty payments.',
        'pending_amount' => 'Pending Penalties',
        'pending_amount_copy' => 'Penalty payment value currently waiting for approval.',
        'queue_title' => 'Penalty submissions and actions',
        'queue_copy' => 'Filter overdue penalty payment records, then approve or reject each submission directly from the table.',
        'submitted_chip' => 'Penalty submitted',
        'pending_chip' => 'Penalty pending',
    ],
    'incidents' => [
        'records' => 'Incident Records',
        'records_copy' => 'All submitted lost and damaged incident payment records.',
        'pending' => 'Pending Review',
        'pending_copy' => 'Incident fee payments that still need admin review.',
        'approved' => 'Approved Payments',
        'approved_copy' => 'Incident fee payments already accepted and applied.',
        'approved_amount' => 'Approved Incident Fees',
        'approved_amount_copy' => 'Total approved value for lost and damaged fee payments.',
        'pending_amount' => 'Pending Incident Fees',
        'pending_amount_copy' => 'Incident fee value currently waiting for approval.',
        'queue_title' => 'Incident submissions and actions',
        'queue_copy' => 'Filter incident payment records here, then approve or reject the uploaded proof directly from this payment-focused workspace.',
        'submitted_chip' => 'Incident submitted',
        'pending_chip' => 'Incident pending',
    ],
    default => [
        'records' => 'Records',
        'records_copy' => 'All submitted payment records in the system.',
        'pending' => 'Pending',
        'pending_copy' => 'Records that still need admin review.',
        'approved' => 'Approved',
        'approved_copy' => 'Payments already accepted and applied.',
        'approved_amount' => 'Approved Value',
        'approved_amount_copy' => 'Total value of payments already approved by admin.',
        'pending_amount' => 'Pending Amount',
        'pending_amount_copy' => 'Value currently waiting for approval or rejection.',
        'queue_title' => 'Submission records and actions',
        'queue_copy' => 'Filter by status or user role, then approve or reject submissions page by page.',
        'submitted_chip' => 'Submitted amount',
        'pending_chip' => 'Pending amount',
    ],
};
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
  $sidebarPage = $paymentScope === 'penalties' ? 'penalty_payments' : ($paymentScope === 'incidents' ? 'incident_payments' : 'payments');
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = $scopeTitle;
  $pageSubtitle = $scopeSubtitle;
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <?php
    $noticeItems = [];
    if ($flash !== '') {
        $noticeItems[] = [
            'type' => in_array($flashType, ['success', 'error', 'warning', 'info'], true) ? $flashType : 'info',
            'message' => $flash,
        ];
    }
    require __DIR__ . '/partials/notices.php';
    ?>

    <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-payments" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Overview</p>
          <h3 class="heading-card"><?php echo h($summaryHeading); ?></h3>
          <p class="muted"><?php echo h($summaryDescription); ?></p>
        </div>
      </div>
      <div class="stat-grid">
        <div class="stat-card">
          <span class="code-pill"><?php echo h($summaryLabels['records']); ?></span>
          <strong><?php echo (int) ($summary['total_records'] ?? 0); ?></strong>
          <span class="muted"><?php echo h($summaryLabels['records_copy']); ?></span>
        </div>
        <div class="stat-card">
          <span class="code-pill"><?php echo h($summaryLabels['pending']); ?></span>
          <strong><?php echo (int) ($summary['pending_records'] ?? 0); ?></strong>
          <span class="muted"><?php echo h($summaryLabels['pending_copy']); ?></span>
        </div>
        <div class="stat-card">
          <span class="code-pill"><?php echo h($summaryLabels['approved']); ?></span>
          <strong><?php echo (int) ($summary['approved_records'] ?? 0); ?></strong>
          <span class="muted"><?php echo h($summaryLabels['approved_copy']); ?></span>
        </div>
        <div class="stat-card">
          <span class="code-pill"><?php echo h($summaryLabels['approved_amount']); ?></span>
          <strong><?php echo h(format_currency($summary['approved_amount'] ?? 0)); ?></strong>
          <span class="muted"><?php echo h($summaryLabels['approved_amount_copy']); ?></span>
        </div>
        <div class="stat-card">
          <span class="code-pill"><?php echo h($summaryLabels['pending_amount']); ?></span>
          <strong><?php echo h(format_currency($summary['pending_amount'] ?? 0)); ?></strong>
          <span class="muted"><?php echo h($summaryLabels['pending_amount_copy']); ?></span>
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
              <h3 class="heading-card"><?php echo h($summaryLabels['queue_title']); ?></h3>
              <p class="muted"><?php echo h($summaryLabels['queue_copy']); ?></p>
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
        <span class="chip"><?php echo h($summaryLabels['submitted_chip']); ?>: <?php echo h(format_currency($summary['total_amount'] ?? 0)); ?></span>
        <span class="chip"><?php echo h($summaryLabels['pending_chip']); ?>: <?php echo h(format_currency($summary['pending_amount'] ?? 0)); ?></span>
        <span class="chip">Rejected: <?php echo (int) ($summary['rejected_records'] ?? 0); ?></span>
      </div>
      <div class="table-wrap table-wrap-top payment-records-table">
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
              <th class="payment-record-reference-head">Reference</th>
              <th class="payment-record-actions-head">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($payments === []): ?>
              <tr><td colspan="10" class="muted">No payment records found.</td></tr>
            <?php endif; ?>
            <?php foreach ($payments as $payment): ?>
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
                <td class="payment-record-reference">
                  <?php
                  $linkedPenaltyCount = max(0, (int) ($payment['linked_penalty_count'] ?? 0));
                  if ((int) ($payment['incident_id'] ?? 0) > 0) {
                      ?>
                      <div class="payment-record-reference-copy">
                        <strong class="label-block">Incident #<?php echo (int) $payment['incident_id']; ?></strong>
                        <span class="muted">
                          <?php echo h(book_incident_type_label((string) ($payment['incident_type'] ?? ''))); ?>
                          |
                          <?php echo h(book_incident_settlement_label((string) ($payment['incident_settlement_status'] ?? 'pending'))); ?>
                        </span>
                      </div>
                      <?php
                  } elseif ($linkedPenaltyCount > 1) {
                      echo h((string) ($payment['payment_batch'] ?: ('Payment #' . (int) $payment['id'])));
                      echo ' / ' . $linkedPenaltyCount . ' penalties';
                  } elseif ((int) ($payment['penalty_id'] ?? 0) > 0) {
                      echo '#' . (int) $payment['penalty_id'] . ' / ' . h($payment['penalty_status'] ?: 'n/a');
                  } else {
                      echo 'Not linked';
                  }
                  ?>
                </td>
                <td class="payment-record-actions payment-record-action-stack">
                  <?php if ((string) ($payment['status'] ?? '') === 'pending'): ?>
                    <form method="post" class="inline-form">
                      <input type="hidden" name="id" value="<?php echo (int) $payment['id']; ?>">
                      <button type="submit" name="approve" value="1">Approve</button>
                    </form>
                    <form method="post" class="inline-form">
                      <input type="hidden" name="id" value="<?php echo (int) $payment['id']; ?>">
                      <button type="submit" class="danger" name="reject" value="1">Reject</button>
                    </form>
                  <?php else: ?>
                    <span class="review-state review-state-<?php echo h((string) ($payment['status'] ?? 'approved')); ?>">
                      <?php echo (string) ($payment['status'] ?? '') === 'rejected' ? 'Rejected by admin' : 'Reviewed'; ?>
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
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


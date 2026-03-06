<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$syncNotice = (string) ($_SESSION['penalties_sync_notice'] ?? '');
$syncError = (string) ($_SESSION['penalties_sync_error'] ?? '');
unset($_SESSION['penalties_sync_notice'], $_SESSION['penalties_sync_error']);

if (isset($_POST['run_penalty_sync'])) {
    try {
        if (function_exists('sync_overdue_penalties')) {
            $result = sync_overdue_penalties($conn);
            $_SESSION['penalties_sync_notice'] = 'Penalty sync completed. Inserted: ' . (int) ($result['inserted'] ?? 0) . ', updated: ' . (int) ($result['updated'] ?? 0) . '.';
            audit_log($conn, 'admin.penalty_sync.run', $result);
        } else {
            $_SESSION['penalties_sync_error'] = 'Penalty sync helper is unavailable.';
        }
    } catch (Throwable $e) {
        $_SESSION['penalties_sync_error'] = 'Penalty sync failed. Please try again.';
    }

    header('Location: penalties_records.php');
    exit;
}

$statusFilter = trim($_GET['status'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$rolesAllowed = system_roles();
$statusOptions = penalty_statuses();
$isValidStatusFilter = $statusFilter !== '' && in_array($statusFilter, $statusOptions, true);
$isValidRoleFilter = $roleFilter !== '' && in_array($roleFilter, $rolesAllowed, true);

$summary = $conn->query("
    SELECT
      COUNT(*) AS total_penalties,
      SUM(status = 'unpaid') AS unpaid_penalties,
      SUM(status = 'paid') AS paid_penalties,
      COALESCE(SUM(amount), 0) AS total_amount,
      COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END), 0) AS unpaid_amount
    FROM penalties
")->fetch_assoc();

$recentPenalties = $conn->query("
    SELECT p.id, u.username, p.amount, p.status, p.created_at
    FROM penalties p
    JOIN users u ON u.id = p.user_id
    ORDER BY p.id DESC
    LIMIT 5
");

$sql = "
    SELECT p.*, u.username, u.role
    FROM penalties p
    JOIN users u ON u.id = p.user_id
    WHERE 1=1
";
$types = '';
$params = [];

if ($isValidStatusFilter) {
    $sql .= " AND p.status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}
if ($isValidRoleFilter) {
    $sql .= " AND u.role = ?";
    $types .= 's';
    $params[] = $roleFilter;
}
$sql .= " ORDER BY p.id DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$penalties = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Penalty Records</title>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css">
</head>
<body>
<div class="site-shell">
  <?php
  $pageTitle = 'Penalty Records';
  $pageSubtitle = 'All penalty entries across the library system';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <?php
    $noticeItems = [];
    if ($syncNotice !== '') {
        $noticeItems[] = ['type' => 'success', 'message' => $syncNotice];
    }
    if ($syncError !== '') {
        $noticeItems[] = ['type' => 'error', 'message' => $syncError];
    }
    require __DIR__ . '/partials/notices.php';
    ?>

    <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-penalties" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Overview</p>
          <h3 class="heading-card">Penalty summary</h3>
          <p class="muted">Monitor unpaid balances, compare cleared records, and keep the penalty ledger readable for account and payment review.</p>
        </div>
      </div>
      <div class="stat-grid">
        <div class="stat-card">
          <span class="code-pill">Records</span>
          <strong><?php echo (int) ($summary['total_penalties'] ?? 0); ?></strong>
          <span class="muted">All penalty entries across the system.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Unpaid</span>
          <strong><?php echo (int) ($summary['unpaid_penalties'] ?? 0); ?></strong>
          <span class="muted">Penalty records still waiting for settlement.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Paid</span>
          <strong><?php echo (int) ($summary['paid_penalties'] ?? 0); ?></strong>
          <span class="muted">Penalty records already cleared.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Unpaid Amount</span>
          <strong><?php echo h(format_currency($summary['unpaid_amount'] ?? 0)); ?></strong>
          <span class="muted">Open balance that still needs payment or validation.</span>
        </div>
      </div>
    </div>

    <div class="grid cards">
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-recent" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Recent Entries</p>
            <h3 class="heading-card">Latest penalty records</h3>
            <p class="muted">Use the newest entries to spot recent overdue returns and check whether related payment activity has started.</p>
          </div>
        </div>
        <div class="activity-feed">
          <?php if (!$recentPenalties || $recentPenalties->num_rows === 0): ?>
            <div class="empty-state">No penalties recorded yet.</div>
          <?php endif; ?>
          <?php while ($entry = $recentPenalties->fetch_assoc()): ?>
            <div class="activity-item">
              <strong><span class="status-dot <?php echo h($entry['status']); ?>"></span>Penalty #<?php echo (int) $entry['id']; ?></strong>
              <div class="meta"><?php echo h($entry['username']); ?> | <?php echo h(format_currency($entry['amount'])); ?></div>
              <div class="meta meta-top"><?php echo h(date('F j, Y g:i A', strtotime($entry['created_at']))); ?></div>
            </div>
          <?php endwhile; ?>
        </div>
      </div>
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Ledger Notes</p>
            <h3 class="heading-card">How to read records</h3>
            <p class="muted">These notes help keep ledger interpretation consistent when switching between penalties, payments, and account checks.</p>
          </div>
        </div>
        <div class="stack">
          <div class="empty-state">
            <strong class="label-block-gap">Unpaid meaning</strong>
            Unpaid means the penalty still needs full payment, verification, or a linked payment review.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Paid meaning</strong>
            Paid is set after an approved payment or a controlled settlement update when no payment review is pending.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Role filtering</strong>
            Use the role filter to isolate student or faculty balances quickly during account review.
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
              <p class="muted eyebrow-compact">Penalty Ledger</p>
              <h3 class="heading-card">System-wide records and balances</h3>
              <p class="muted">Filter by payment state or role, then review penalty history across the library system.</p>
            </div>
          </div>
        </div>
        <form method="post" data-confirm="Run penalty sync now?">
          <button type="submit" name="run_penalty_sync" value="1">Run Penalty Sync Now</button>
        </form>
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
            <a class="button secondary" href="penalties_records.php">Reset</a>
          </div>
        </form>
      </div>
      <div class="inline-actions chips-row">
        <span class="chip">Penalty amount: <?php echo h(format_currency($summary['total_amount'] ?? 0)); ?></span>
        <span class="chip">Outstanding: <?php echo h(format_currency($summary['unpaid_amount'] ?? 0)); ?></span>
        <span class="chip">Paid records: <?php echo (int) ($summary['paid_penalties'] ?? 0); ?></span>
      </div>
      <div class="table-wrap table-wrap-top">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>User</th>
              <th>Role</th>
              <th>Borrow ID</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Reason</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($penalties->num_rows === 0): ?>
              <tr><td colspan="8" class="muted">No penalties found.</td></tr>
            <?php endif; ?>
            <?php while ($penalty = $penalties->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int) $penalty['id']; ?></td>
                <td><?php echo h($penalty['username']); ?></td>
                <td><span class="badge"><?php echo h($penalty['role']); ?></span></td>
                <td><?php echo (int) $penalty['borrow_id']; ?></td>
                <td><?php echo h(format_currency($penalty['amount'])); ?></td>
                <td><span class="badge"><span class="status-dot <?php echo h($penalty['status']); ?>"></span><?php echo h($penalty['status']); ?></span></td>
                <td><?php echo h($penalty['reason']); ?></td>
                <td><?php echo h($penalty['created_at']); ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/shared_confirm.js"></script>
</body>
</html>

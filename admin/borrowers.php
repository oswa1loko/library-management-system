<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');
expire_stale_pending_borrow_requests($conn);

$search = trim((string) ($_GET['search'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? 'all'));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$selectedBorrowerId = max(0, (int) ($_GET['borrower_id'] ?? 0));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$allowedRoles = ['all', 'student', 'faculty'];
$allowedStatuses = ['all', 'overdue', 'due_today', 'pending_return', 'pending_approval', 'balances'];
if (!in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = 'all';
}
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$summary = $conn->query("
    SELECT
      COUNT(DISTINCT br.user_id) AS total_borrowers,
      COUNT(DISTINCT CASE WHEN u.role = 'student' THEN br.user_id END) AS student_borrowers,
      COUNT(DISTINCT CASE WHEN u.role = 'faculty' THEN br.user_id END) AS faculty_borrowers,
      COUNT(DISTINCT CASE WHEN br.status IN ('borrowed', 'return_requested') AND br.due_date < CURDATE() THEN br.user_id END) AS overdue_borrowers
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    WHERE br.status IN ('pending', 'borrowed', 'return_requested')
")->fetch_assoc();

$whereSql = "
    WHERE br.status IN ('pending', 'borrowed', 'return_requested')
";
$params = [];
$types = '';

if ($search !== '') {
    $whereSql .= " AND (u.fullname LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR b.title LIKE ? OR b.author LIKE ?)";
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
    $types .= 'sssss';
}

if ($roleFilter !== 'all') {
    $whereSql .= " AND u.role = ?";
    $params[] = $roleFilter;
    $types .= 's';
}

$havingSql = '';
if ($statusFilter === 'overdue') {
    $havingSql = 'HAVING overdue_count > 0';
} elseif ($statusFilter === 'due_today') {
    $havingSql = 'HAVING due_today_count > 0';
} elseif ($statusFilter === 'pending_return') {
    $havingSql = 'HAVING pending_return_count > 0';
} elseif ($statusFilter === 'pending_approval') {
    $havingSql = 'HAVING pending_approval_count > 0';
} elseif ($statusFilter === 'balances') {
    $havingSql = 'HAVING unpaid_penalty_total > 0 OR incident_balance > 0';
}

$borrowerSelectSql = "
    SELECT
      u.id,
      u.fullname,
      u.username,
      u.email,
      u.role,
      u.account_status,
      COUNT(*) AS borrow_record_count,
      COALESCE(SUM(CASE WHEN br.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_approval_count,
      COALESCE(SUM(CASE WHEN br.status IN ('borrowed', 'return_requested') THEN 1 ELSE 0 END), 0) AS active_borrow_count,
      COALESCE(SUM(CASE WHEN br.status IN ('borrowed', 'return_requested') AND br.due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_count,
      COALESCE(SUM(CASE WHEN br.status IN ('borrowed', 'return_requested') AND br.due_date = CURDATE() THEN 1 ELSE 0 END), 0) AS due_today_count,
      COALESCE(SUM(CASE WHEN br.status = 'return_requested' THEN 1 ELSE 0 END), 0) AS pending_return_count,
      MIN(CASE WHEN br.status IN ('borrowed', 'return_requested') THEN COALESCE(br.due_at, CONCAT(br.due_date, ' 23:59:59')) ELSE NULL END) AS next_due_at,
      MAX(COALESCE(br.approved_at, br.requested_at, br.borrow_date)) AS latest_activity_at,
      COALESCE(penalty_summary.unpaid_total, 0) AS unpaid_penalty_total,
      COALESCE(incident_summary.pending_total, 0) AS incident_balance,
      GROUP_CONCAT(b.title ORDER BY br.due_date ASC, br.id DESC SEPARATOR '||') AS borrowed_titles
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    JOIN books b ON b.id = br.book_id
    LEFT JOIN (
        SELECT user_id, SUM(amount) AS unpaid_total
        FROM penalties
        WHERE status = 'unpaid'
        GROUP BY user_id
    ) penalty_summary ON penalty_summary.user_id = u.id
    LEFT JOIN (
        SELECT user_id, SUM(CASE WHEN settlement_status = 'pending' THEN assessed_fee ELSE 0 END) AS pending_total
        FROM book_incidents
        GROUP BY user_id
    ) incident_summary ON incident_summary.user_id = u.id
    {$whereSql}
    GROUP BY u.id, u.fullname, u.username, u.email, u.role, u.account_status, penalty_summary.unpaid_total, incident_summary.pending_total
    {$havingSql}
";

$countSql = "SELECT COUNT(*) AS total FROM ({$borrowerSelectSql}) borrower_rows";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    http_response_code(500);
    die('Admin borrowers page could not load its grouped count query.');
}
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();
$countStmt->close();

$totalRows = (int) ($countRow['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$safeLimit = max(1, (int) $perPage);
$safeOffset = max(0, (int) $offset);
$sql = $borrowerSelectSql . "
    ORDER BY
      overdue_count DESC,
      unpaid_penalty_total DESC,
      incident_balance DESC,
      due_today_count DESC,
      pending_return_count DESC,
      next_due_at ASC,
      latest_activity_at DESC,
      u.fullname ASC
    LIMIT {$safeLimit} OFFSET {$safeOffset}
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    die('Admin borrowers page could not load its grouped borrower query.');
}
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$borrowers = $stmt->get_result();
$pageQuery = $_GET;
$closeQuery = $pageQuery;
unset($closeQuery['borrower_id']);
$closeHref = 'borrowers.php' . ($closeQuery !== [] ? '?' . http_build_query($closeQuery) : '');
$quickFilterBase = $pageQuery;
unset($quickFilterBase['role'], $quickFilterBase['status'], $quickFilterBase['page'], $quickFilterBase['borrower_id']);
$quickFilters = [
    ['label' => 'All', 'role' => 'all', 'status' => 'all', 'active' => $roleFilter === 'all' && $statusFilter === 'all'],
    ['label' => 'Students', 'role' => 'student', 'status' => 'all', 'active' => $roleFilter === 'student' && $statusFilter === 'all'],
    ['label' => 'Faculty', 'role' => 'faculty', 'status' => 'all', 'active' => $roleFilter === 'faculty' && $statusFilter === 'all'],
    ['label' => 'Overdue', 'role' => $roleFilter, 'status' => 'overdue', 'active' => $statusFilter === 'overdue'],
    ['label' => 'Due Today', 'role' => $roleFilter, 'status' => 'due_today', 'active' => $statusFilter === 'due_today'],
    ['label' => 'Balances', 'role' => $roleFilter, 'status' => 'balances', 'active' => $statusFilter === 'balances'],
];

$selectedBorrower = null;
$selectedBorrowRecords = [];
if ($selectedBorrowerId > 0) {
    $detailStmt = $conn->prepare("
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
          u.email,
          u.role,
          u.account_status,
          b.title,
          b.author,
          b.qty_available
        FROM borrows br
        JOIN users u ON u.id = br.user_id
        JOIN books b ON b.id = br.book_id
        WHERE br.user_id = ?
          AND br.status IN ('pending', 'borrowed', 'return_requested')
        ORDER BY br.status = 'return_requested' DESC, br.status = 'pending' DESC, br.due_date ASC, br.id DESC
    ");
    if ($detailStmt) {
        $detailStmt->bind_param('i', $selectedBorrowerId);
        $detailStmt->execute();
        $detailRows = $detailStmt->get_result();
        while ($detailRows && ($detailRow = $detailRows->fetch_assoc())) {
            if ($selectedBorrower === null) {
                $selectedBorrower = [
                    'id' => $selectedBorrowerId,
                    'fullname' => (string) ($detailRow['fullname'] ?? ''),
                    'username' => (string) ($detailRow['username'] ?? ''),
                    'email' => (string) ($detailRow['email'] ?? ''),
                    'role' => (string) ($detailRow['role'] ?? ''),
                    'account_status' => (string) ($detailRow['account_status'] ?? ''),
                ];
            }
            $selectedBorrowRecords[] = $detailRow;
        }
        $detailStmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('admin', 'Borrowers')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<?php $ajaxPanelVersion = (string) filemtime(__DIR__ . '/../assets/admin_ajax_panel.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell admin-shell member-shell js-member-sidebar" data-sidebar-key="admin-borrowers" data-sidebar-default="expanded" data-sidebar-lock="expanded">
  <?php
  $sidebarPage = 'borrowers';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Borrowers';
  $pageSubtitle = 'Monitor borrowers, balances, active books, and account context across the system';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <div class="panel">
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Borrower monitoring summary</h3>
      <div class="stat-grid">
        <div class="stat-card"><strong><?php echo (int) ($summary['total_borrowers'] ?? 0); ?></strong><span class="muted">Current borrowers</span></div>
        <div class="stat-card"><strong><?php echo (int) ($summary['student_borrowers'] ?? 0); ?></strong><span class="muted">Student borrowers</span></div>
        <div class="stat-card"><strong><?php echo (int) ($summary['faculty_borrowers'] ?? 0); ?></strong><span class="muted">Faculty borrowers</span></div>
        <div class="stat-card"><strong><?php echo (int) ($summary['overdue_borrowers'] ?? 0); ?></strong><span class="muted">Overdue borrowers</span></div>
      </div>
    </div>

    <div class="panel" data-ajax-panel-shell="admin-borrowers-panel">
      <div class="card-head">
        <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
        <div class="grow">
          <span class="chip">Admin View</span>
          <h3 class="heading-top-md heading-card">Borrowers List</h3>
          <p class="muted">Use this page to monitor active borrowers, account status, and open financial balances.</p>
        </div>
      </div>

      <form method="get" class="toolbar flow-top-md borrow-records-toolbar admin-borrowers-toolbar" data-ajax-filter-form>
        <div class="grow">
          <label for="search">Search</label>
          <input id="search" name="search" value="<?php echo h($search); ?>" placeholder="Borrower, username, email, or book" data-ajax-filter-search>
        </div>
        <div data-filter-panel>
          <label for="role">Role</label>
          <div class="ui-select-shell">
            <select id="role" name="role" class="ui-select" data-ajax-filter-control>
              <option value="all" <?php echo $roleFilter === 'all' ? 'selected' : ''; ?>>All borrowers</option>
              <option value="student" <?php echo $roleFilter === 'student' ? 'selected' : ''; ?>>Students</option>
              <option value="faculty" <?php echo $roleFilter === 'faculty' ? 'selected' : ''; ?>>Faculty</option>
            </select>
            <span class="ui-select-caret" aria-hidden="true"></span>
          </div>
        </div>
        <div data-filter-panel>
          <label for="status">View</label>
          <div class="ui-select-shell">
            <select id="status" name="status" class="ui-select" data-ajax-filter-control>
              <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All active borrowers</option>
              <option value="overdue" <?php echo $statusFilter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
              <option value="due_today" <?php echo $statusFilter === 'due_today' ? 'selected' : ''; ?>>Due today</option>
              <option value="pending_return" <?php echo $statusFilter === 'pending_return' ? 'selected' : ''; ?>>Pending return</option>
              <option value="pending_approval" <?php echo $statusFilter === 'pending_approval' ? 'selected' : ''; ?>>Pending approval</option>
              <option value="balances" <?php echo $statusFilter === 'balances' ? 'selected' : ''; ?>>With balances</option>
            </select>
            <span class="ui-select-caret" aria-hidden="true"></span>
          </div>
        </div>
        <div class="inline-actions">
          <button type="submit">Filter</button>
          <a class="button secondary" href="borrowers.php" data-ajax-filter-link>Reset</a>
        </div>
      </form>

      <div class="inline-actions chips-row borrower-quick-filters flow-top-sm">
        <span class="muted borrower-quick-label">Quick filters</span>
        <?php foreach ($quickFilters as $quickFilter): ?>
          <?php
          $quickQuery = $quickFilterBase;
          if ((string) $quickFilter['role'] !== 'all') {
              $quickQuery['role'] = (string) $quickFilter['role'];
          }
          if ((string) $quickFilter['status'] !== 'all') {
              $quickQuery['status'] = (string) $quickFilter['status'];
          }
          $quickHref = 'borrowers.php' . ($quickQuery !== [] ? '?' . http_build_query($quickQuery) : '');
          ?>
          <a class="chip borrower-filter-chip<?php echo !empty($quickFilter['active']) ? ' is-active' : ''; ?>" href="<?php echo h($quickHref); ?>" data-ajax-filter-link><?php echo h((string) $quickFilter['label']); ?></a>
        <?php endforeach; ?>
      </div>

      <div class="inline-actions flow-top-sm">
        <span class="muted">Showing <?php echo $totalRows === 0 ? 0 : ($offset + 1); ?>-<?php echo min($offset + $perPage, $totalRows); ?> of <?php echo $totalRows; ?> borrower<?php echo $totalRows === 1 ? '' : 's'; ?></span>
        <?php if ($totalPages > 1): ?>
          <?php $previousQuery = $pageQuery; $previousQuery['page'] = max(1, $page - 1); $nextQuery = $pageQuery; $nextQuery['page'] = min($totalPages, $page + 1); ?>
          <a class="button secondary<?php echo $page <= 1 ? ' is-disabled' : ''; ?>" href="<?php echo $page <= 1 ? '#' : ('borrowers.php?' . h(http_build_query($previousQuery))); ?>"<?php echo $page <= 1 ? ' aria-disabled="true"' : ''; ?> data-ajax-filter-link>Previous</a>
          <span class="badge">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
          <a class="button secondary<?php echo $page >= $totalPages ? ' is-disabled' : ''; ?>" href="<?php echo $page >= $totalPages ? '#' : ('borrowers.php?' . h(http_build_query($nextQuery))); ?>"<?php echo $page >= $totalPages ? ' aria-disabled="true"' : ''; ?> data-ajax-filter-link>Next</a>
        <?php endif; ?>
      </div>

      <div class="table-wrap table-wrap-top">
        <table>
          <thead>
            <tr>
              <th>Borrower</th>
              <th>Account</th>
              <th>Active</th>
              <th>Overdue</th>
              <th>Next Due</th>
              <th>Balances</th>
              <th>Current Books</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($borrowers->num_rows === 0): ?>
              <tr><td colspan="8" class="muted">No borrowers matched your filters.</td></tr>
            <?php endif; ?>
            <?php while ($borrower = $borrowers->fetch_assoc()): ?>
              <?php
              $titles = array_values(array_filter(explode('||', (string) ($borrower['borrowed_titles'] ?? ''))));
              $titlePreview = implode(', ', array_slice($titles, 0, 2));
              if (count($titles) > 2) {
                  $titlePreview .= ' +' . (count($titles) - 2) . ' more';
              }
              $borrowerModalQuery = $pageQuery;
              $borrowerModalQuery['borrower_id'] = (int) $borrower['id'];
              $borrowerModalHref = 'borrowers.php?' . http_build_query($borrowerModalQuery);
              $hasOverdue = (int) ($borrower['overdue_count'] ?? 0) > 0;
              $penaltyBalance = (float) ($borrower['unpaid_penalty_total'] ?? 0);
              $incidentBalance = (float) ($borrower['incident_balance'] ?? 0);
              ?>
              <tr class="borrower-row<?php echo $hasOverdue ? ' is-overdue' : ''; ?>" data-row-href="<?php echo h($borrowerModalHref); ?>" tabindex="0" aria-label="View books for <?php echo h((string) $borrower['fullname']); ?>">
                <td>
                  <strong class="label-block"><?php echo h((string) $borrower['fullname']); ?></strong>
                  <span class="muted"><?php echo h((string) $borrower['username']); ?><?php echo trim((string) ($borrower['email'] ?? '')) !== '' ? ' | ' . h((string) $borrower['email']) : ''; ?></span>
                  <span class="inline-actions chips-row meta-top-sm">
                    <?php if ($hasOverdue): ?><span class="chip">Overdue</span><?php endif; ?>
                    <?php if ((int) ($borrower['due_today_count'] ?? 0) > 0): ?><span class="chip">Due Today</span><?php endif; ?>
                    <?php if ((int) ($borrower['pending_return_count'] ?? 0) > 0): ?><span class="chip">Pending Return</span><?php endif; ?>
                    <?php if ($penaltyBalance > 0 || $incidentBalance > 0): ?><span class="chip">Balance</span><?php endif; ?>
                  </span>
                </td>
                <td>
                  <span class="badge"><?php echo h(role_label((string) $borrower['role'])); ?></span>
                  <span class="muted meta-top-sm"><?php echo h(ucfirst((string) ($borrower['account_status'] ?? 'active'))); ?></span>
                </td>
                <td><?php echo (int) ($borrower['active_borrow_count'] ?? 0); ?></td>
                <td><span class="badge"><span class="status-dot <?php echo $hasOverdue ? 'overdue' : 'approved'; ?>"></span><?php echo (int) ($borrower['overdue_count'] ?? 0); ?></span></td>
                <td><?php echo trim((string) ($borrower['next_due_at'] ?? '')) !== '' ? h(format_display_datetime((string) $borrower['next_due_at'])) : '-'; ?></td>
                <td>
                  <span class="muted borrower-balance-label">Current Balance</span>
                  <strong class="label-block"><?php echo h(format_currency($penaltyBalance + $incidentBalance)); ?></strong>
                  <span class="muted borrower-balance-breakdown">Penalty <?php echo h(format_currency($penaltyBalance)); ?> | Incident <?php echo h(format_currency($incidentBalance)); ?></span>
                </td>
                <td><span class="muted"><?php echo h($titlePreview !== '' ? $titlePreview : 'No active titles'); ?></span></td>
                <td>
                  <div class="inline-actions">
                    <a class="button secondary" href="<?php echo h($borrowerModalHref); ?>">View Books</a>
                    <a class="button secondary" href="view_user.php?id=<?php echo (int) $borrower['id']; ?>">Account</a>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  </div>
</div>
<?php if ($selectedBorrowerId > 0): ?>
  <div class="desk-modal" data-desk-modal>
    <a class="desk-modal-backdrop" href="<?php echo h($closeHref); ?>" aria-label="Close borrower details"></a>
    <div class="desk-modal-dialog panel" role="dialog" aria-modal="true" aria-labelledby="admin-borrower-details-modal-title">
      <div class="desk-modal-head">
        <div>
          <p class="muted eyebrow-compact">Borrower Details</p>
          <h3 id="admin-borrower-details-modal-title" class="heading-card"><?php echo h((string) ($selectedBorrower['fullname'] ?? 'Borrower')); ?></h3>
          <p class="muted">
            <?php if ($selectedBorrower): ?>
              <?php echo h(role_label((string) ($selectedBorrower['role'] ?? ''))); ?> |
              <?php echo h(ucfirst((string) ($selectedBorrower['account_status'] ?? 'active'))); ?> |
              <?php echo h((string) ($selectedBorrower['username'] ?? '')); ?>
              <?php echo trim((string) ($selectedBorrower['email'] ?? '')) !== '' ? ' | ' . h((string) $selectedBorrower['email']) : ''; ?>
            <?php else: ?>
              This borrower has no current active, pending, or return-requested records.
            <?php endif; ?>
          </p>
        </div>
        <div class="inline-actions">
          <?php if ($selectedBorrower): ?><a class="button secondary" href="view_user.php?id=<?php echo (int) ($selectedBorrower['id'] ?? 0); ?>">View Account</a><?php endif; ?>
          <a class="button secondary" href="<?php echo h($closeHref); ?>">Close</a>
        </div>
      </div>

      <?php if ($selectedBorrowRecords === []): ?>
        <div class="empty-state">No current borrow records are available for this borrower.</div>
      <?php else: ?>
        <div class="table-wrap table-wrap-top">
          <table>
            <thead>
              <tr><th>Borrow ID</th><th>Book</th><th>Author</th><th>Borrowed / Requested</th><th>Due Date</th><th>State</th></tr>
            </thead>
            <tbody>
              <?php foreach ($selectedBorrowRecords as $record): ?>
                <?php
                $state = 'On time';
                $waitingForStock = (string) ($record['status'] ?? '') === 'pending' && (int) ($record['qty_available'] ?? 0) <= 0;
                if ((string) ($record['status'] ?? '') === 'pending') {
                    $state = $waitingForStock ? 'Waiting for stock' : 'Pending approval';
                } elseif ((string) ($record['status'] ?? '') === 'return_requested') {
                    $state = 'Awaiting return confirmation';
                } elseif ((string) ($record['due_date'] ?? '') < date('Y-m-d')) {
                    $state = 'Overdue';
                } elseif ((string) ($record['due_date'] ?? '') === date('Y-m-d')) {
                    $state = 'Due today';
                }
                ?>
                <tr>
                  <td>#<?php echo (int) ($record['id'] ?? 0); ?></td>
                  <td><strong class="label-block"><?php echo h((string) ($record['title'] ?? '')); ?></strong></td>
                  <td><?php echo h((string) ($record['author'] ?? '')); ?></td>
                  <td><?php echo h(format_display_datetime((string) (((string) ($record['status'] ?? '') === 'pending' ? ($record['requested_at'] ?? '') : ($record['approved_at'] ?? '')) ?: ($record['borrow_date'] ?? '')), '-')); ?></td>
                  <td><?php echo (string) ($record['status'] ?? '') === 'pending' ? '-' : h(format_display_datetime((string) (($record['due_at'] ?? '') ?: ($record['due_date'] ?? '')), '-')); ?></td>
                  <td><span class="badge"><span class="status-dot <?php echo $state === 'Overdue' ? 'overdue' : ($state === 'Due today' ? 'due' : ($state === 'Waiting for stock' ? 'waiting_stock' : ((string) ($record['status'] ?? '') === 'pending' ? 'pending' : ((string) ($record['status'] ?? '') === 'return_requested' ? 'return_requested' : 'approved')))); ?>"></span><?php echo h($state); ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/admin_ajax_panel.js?v=<?php echo urlencode($ajaxPanelVersion); ?>"></script>
<script>
document.addEventListener('click', function (event) {
  var row = event.target.closest('[data-row-href]');
  if (!row || event.target.closest('a, button, input, select, textarea')) {
    return;
  }
  window.location.href = row.getAttribute('data-row-href');
});

document.addEventListener('keydown', function (event) {
  if (event.key !== 'Enter' && event.key !== ' ') {
    return;
  }
  var row = event.target.closest('[data-row-href]');
  if (!row) {
    return;
  }
  event.preventDefault();
  window.location.href = row.getAttribute('data-row-href');
});
</script>
</body>
</html>

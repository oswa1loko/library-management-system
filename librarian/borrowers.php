<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

$search = trim((string) ($_GET['search'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? 'all'));
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$allowedRoles = ['all', 'student', 'faculty'];
$allowedStatuses = ['all', 'overdue', 'due_today', 'pending_return', 'pending_approval'];
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
      COUNT(DISTINCT CASE WHEN br.status IN ('borrowed', 'return_requested') AND br.due_date < CURDATE() THEN br.user_id END) AS overdue_borrowers,
      COUNT(DISTINCT CASE WHEN br.status IN ('borrowed', 'return_requested') AND br.due_date = CURDATE() THEN br.user_id END) AS due_today_borrowers,
      COUNT(DISTINCT CASE WHEN br.status = 'return_requested' THEN br.user_id END) AS pending_return_borrowers
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
}

$borrowerSelectSql = "
    SELECT
      u.id,
      u.fullname,
      u.username,
      u.email,
      u.role,
      COUNT(*) AS borrow_record_count,
      COALESCE(SUM(CASE WHEN br.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_approval_count,
      COALESCE(SUM(CASE WHEN br.status IN ('borrowed', 'return_requested') THEN 1 ELSE 0 END), 0) AS active_borrow_count,
      COALESCE(SUM(CASE WHEN br.status IN ('borrowed', 'return_requested') AND br.due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_count,
      COALESCE(SUM(CASE WHEN br.status IN ('borrowed', 'return_requested') AND br.due_date = CURDATE() THEN 1 ELSE 0 END), 0) AS due_today_count,
      COALESCE(SUM(CASE WHEN br.status = 'return_requested' THEN 1 ELSE 0 END), 0) AS pending_return_count,
      MIN(CASE WHEN br.status IN ('borrowed', 'return_requested') THEN COALESCE(br.due_at, CONCAT(br.due_date, ' 23:59:59')) ELSE NULL END) AS next_due_at,
      MAX(COALESCE(br.approved_at, br.requested_at, br.borrow_date)) AS latest_activity_at,
      GROUP_CONCAT(b.title ORDER BY br.due_date ASC, br.id DESC SEPARATOR '||') AS borrowed_titles
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    JOIN books b ON b.id = br.book_id
    {$whereSql}
    GROUP BY u.id, u.fullname, u.username, u.email, u.role
    {$havingSql}
";

$countSql = "SELECT COUNT(*) AS total FROM ({$borrowerSelectSql}) borrower_rows";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    http_response_code(500);
    die('Borrowers page could not load its grouped count query. Please contact the system administrator.');
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

$sql = $borrowerSelectSql . "
    ORDER BY
      overdue_count DESC,
      due_today_count DESC,
      pending_return_count DESC,
      next_due_at IS NULL,
      next_due_at ASC,
      latest_activity_at DESC,
      fullname ASC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    die('Borrowers page could not load its grouped borrower query. Please contact the system administrator.');
}
$queryParams = $params;
$queryTypes = $types . 'ii';
$queryParams[] = $perPage;
$queryParams[] = $offset;
$stmt->bind_param($queryTypes, ...$queryParams);
$stmt->execute();
$borrowers = $stmt->get_result();
$pageQuery = $_GET;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Borrowers')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<?php $ajaxPanelVersion = (string) filemtime(__DIR__ . '/../assets/admin_ajax_panel.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell librarian-shell member-shell js-member-sidebar" data-sidebar-key="librarian-borrowers" data-sidebar-default="expanded">
  <?php
  $sidebarPage = 'borrowers';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Borrowers';
  $pageSubtitle = 'See active borrowers first, then drill into their borrow records when needed';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <div class="panel">
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Borrower summary</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo (int) ($summary['total_borrowers'] ?? 0); ?></strong>
          <span class="muted">Borrowers with active, pending, or return-requested records</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($summary['student_borrowers'] ?? 0); ?></strong>
          <span class="muted">Student borrowers</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($summary['faculty_borrowers'] ?? 0); ?></strong>
          <span class="muted">Faculty borrowers</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($summary['overdue_borrowers'] ?? 0); ?></strong>
          <span class="muted">Borrowers with overdue books</span>
        </div>
      </div>
    </div>

    <div class="panel" data-ajax-panel-shell="librarian-borrowers-panel">
      <div class="card-head">
        <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
        <div class="grow">
          <span class="chip">Grouped View</span>
          <h3 class="heading-top-md heading-card">Borrowers List</h3>
          <p class="muted">Use this view when you need people first instead of scanning every individual borrow record.</p>
        </div>
      </div>

      <form method="get" class="toolbar flow-top-md borrow-records-toolbar" data-ajax-filter-form>
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
            </select>
            <span class="ui-select-caret" aria-hidden="true"></span>
          </div>
        </div>
        <div class="inline-actions">
          <button type="submit">Filter</button>
          <a class="button secondary" href="borrowers.php" data-ajax-filter-link>Reset</a>
        </div>
      </form>

      <div class="inline-actions flow-top-sm">
        <span class="muted">
          Showing <?php echo $totalRows === 0 ? 0 : ($offset + 1); ?>-<?php echo min($offset + $perPage, $totalRows); ?>
          of <?php echo $totalRows; ?> borrower<?php echo $totalRows === 1 ? '' : 's'; ?>
        </span>
        <?php if ($totalPages > 1): ?>
          <?php
          $previousQuery = $pageQuery;
          $previousQuery['page'] = max(1, $page - 1);
          $nextQuery = $pageQuery;
          $nextQuery['page'] = min($totalPages, $page + 1);
          ?>
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
              <th>Role</th>
              <th>Active</th>
              <th>Overdue</th>
              <th>Due Today</th>
              <th>Pending Return</th>
              <th>Next Due</th>
              <th>Books</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($borrowers->num_rows === 0): ?>
              <tr><td colspan="9" class="muted">No borrowers matched your filters.</td></tr>
            <?php endif; ?>
            <?php while ($borrower = $borrowers->fetch_assoc()): ?>
              <?php
              $titles = array_values(array_filter(explode('||', (string) ($borrower['borrowed_titles'] ?? ''))));
              $titlePreview = implode(', ', array_slice($titles, 0, 3));
              if (count($titles) > 3) {
                  $titlePreview .= ' +' . (count($titles) - 3) . ' more';
              }
              $borrowerSearch = trim((string) ($borrower['username'] ?? '')) !== ''
                  ? (string) $borrower['username']
                  : (string) $borrower['fullname'];
              ?>
              <tr>
                <td>
                  <strong class="label-block"><?php echo h((string) $borrower['fullname']); ?></strong>
                  <span class="muted"><?php echo h((string) $borrower['username']); ?><?php echo trim((string) ($borrower['email'] ?? '')) !== '' ? ' | ' . h((string) $borrower['email']) : ''; ?></span>
                </td>
                <td><span class="badge"><?php echo h(role_label((string) $borrower['role'])); ?></span></td>
                <td><?php echo (int) ($borrower['active_borrow_count'] ?? 0); ?></td>
                <td>
                  <span class="badge">
                    <span class="status-dot <?php echo (int) ($borrower['overdue_count'] ?? 0) > 0 ? 'overdue' : 'approved'; ?>"></span>
                    <?php echo (int) ($borrower['overdue_count'] ?? 0); ?>
                  </span>
                </td>
                <td><?php echo (int) ($borrower['due_today_count'] ?? 0); ?></td>
                <td><?php echo (int) ($borrower['pending_return_count'] ?? 0); ?></td>
                <td><?php echo trim((string) ($borrower['next_due_at'] ?? '')) !== '' ? h(format_display_datetime((string) $borrower['next_due_at'])) : '-'; ?></td>
                <td>
                  <span class="muted"><?php echo h($titlePreview !== '' ? $titlePreview : 'No active titles'); ?></span>
                  <?php if ((int) ($borrower['pending_approval_count'] ?? 0) > 0): ?>
                    <span class="chip meta-top-sm"><?php echo (int) $borrower['pending_approval_count']; ?> pending approval</span>
                  <?php endif; ?>
                </td>
                <td>
                  <a class="button secondary" href="manage_borrow_records.php?search=<?php echo urlencode($borrowerSearch); ?>">View Records</a>
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
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/admin_ajax_panel.js?v=<?php echo urlencode($ajaxPanelVersion); ?>"></script>
</body>
</html>

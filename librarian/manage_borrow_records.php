<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$today = date('Y-m-d');

$summary = $conn->query("
    SELECT
      COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_approvals,
      COALESCE(SUM(CASE WHEN status IN ('borrowed', 'return_requested') THEN 1 ELSE 0 END), 0) AS active_borrows,
      COALESCE(SUM(CASE WHEN status IN ('borrowed', 'return_requested') AND due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_borrows,
      COALESCE(SUM(CASE WHEN status = 'return_requested' THEN 1 ELSE 0 END), 0) AS pending_returns
    FROM borrows
    WHERE status IN ('pending', 'borrowed', 'return_requested')
")->fetch_assoc();

$whereSql = "
    WHERE br.status IN ('pending', 'borrowed', 'return_requested')
";

$params = [];
$types = '';

if ($search !== '') {
    $whereSql .= " AND (u.fullname LIKE ? OR u.username LIKE ? OR b.title LIKE ? OR b.author LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $types .= 'ssss';
}

if ($statusFilter === 'overdue') {
    $whereSql .= " AND br.status IN ('borrowed', 'return_requested') AND br.due_date < CURDATE()";
} elseif ($statusFilter === 'due_today') {
    $whereSql .= " AND br.status IN ('borrowed', 'return_requested') AND br.due_date = CURDATE()";
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    JOIN books b ON b.id = br.book_id
    {$whereSql}
";

$countStmt = $conn->prepare($countSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countRow = $countStmt->get_result()->fetch_assoc();
$totalRows = (int) ($countRow['total'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

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
    {$whereSql}
    ORDER BY br.due_date ASC, br.id DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
$queryParams = $params;
$queryTypes = $types . 'ii';
$queryParams[] = $perPage;
$queryParams[] = $offset;
$stmt->bind_param($queryTypes, ...$queryParams);
$stmt->execute();
$borrows = $stmt->get_result();
$pageQuery = $_GET;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Borrow Records')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell librarian-shell member-shell js-member-sidebar" data-sidebar-key="librarian-borrow-records" data-sidebar-default="expanded">
  <?php
  $sidebarPage = 'borrow_records';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Borrow Records';
  $pageSubtitle = 'Track active borrows, due dates, and return state';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <div class="panel">
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Borrow tracking summary</h3>
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

    <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-books" aria-hidden="true"></div>
        <div class="grow">
          <span class="chip">Lookup / Records</span>
          <h3 class="heading-top-md heading-card">Borrow Tracking Table</h3>
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
          <a class="button secondary" href="manage_borrow_records.php">Reset</a>
        </div>
      </form>

      <div class="inline-actions flow-top-sm">
        <span class="muted">
          Showing <?php echo $totalRows === 0 ? 0 : ($offset + 1); ?>-<?php echo min($offset + $perPage, $totalRows); ?>
          of <?php echo $totalRows; ?> active records
        </span>
        <?php if ($totalPages > 1): ?>
          <?php
          $previousQuery = $pageQuery;
          $previousQuery['page'] = max(1, $page - 1);
          $nextQuery = $pageQuery;
          $nextQuery['page'] = min($totalPages, $page + 1);
          ?>
          <a class="button secondary<?php echo $page <= 1 ? ' is-disabled' : ''; ?>" href="<?php echo $page <= 1 ? '#' : ('manage_borrow_records.php?' . h(http_build_query($previousQuery))); ?>"<?php echo $page <= 1 ? ' aria-disabled="true"' : ''; ?>>Previous</a>
          <span class="badge">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
          <a class="button secondary<?php echo $page >= $totalPages ? ' is-disabled' : ''; ?>" href="<?php echo $page >= $totalPages ? '#' : ('manage_borrow_records.php?' . h(http_build_query($nextQuery))); ?>"<?php echo $page >= $totalPages ? ' aria-disabled="true"' : ''; ?>>Next</a>
        <?php endif; ?>
      </div>

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
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
</body>
</html>

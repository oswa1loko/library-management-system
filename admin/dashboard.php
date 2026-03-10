<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$syncNotice = (string) ($_SESSION['admin_sync_notice'] ?? '');
$syncError = (string) ($_SESSION['admin_sync_error'] ?? '');
unset($_SESSION['admin_sync_notice'], $_SESSION['admin_sync_error']);

if (isset($_POST['run_penalty_sync'])) {
    try {
        if (function_exists('sync_overdue_penalties')) {
            $result = sync_overdue_penalties($conn);
            $_SESSION['admin_sync_notice'] = 'Penalty sync completed. Inserted: ' . (int) ($result['inserted'] ?? 0) . ', updated: ' . (int) ($result['updated'] ?? 0) . '.';
            audit_log($conn, 'admin.penalty_sync.run', $result);
        } else {
            $_SESSION['admin_sync_error'] = 'Penalty sync helper is unavailable.';
        }
    } catch (Throwable $e) {
        $_SESSION['admin_sync_error'] = 'Penalty sync failed. Please try again.';
    }

    header('Location: dashboard.php');
    exit;
}

$userStats = $conn->query("
    SELECT
      COUNT(*) AS total_users,
      SUM(role = 'student') AS students,
      SUM(role = 'faculty') AS faculty,
      SUM(role = 'librarian') AS librarians
    FROM users
")->fetch_assoc();

$paymentStats = $conn->query("
    SELECT
      COUNT(*) AS total_payments,
      SUM(status = 'pending') AS pending_payments
    FROM payments
")->fetch_assoc();

$penaltyStats = $conn->query("
    SELECT
      COUNT(*) AS total_penalties,
      SUM(status = 'unpaid') AS unpaid_penalties,
      COALESCE(SUM(amount), 0) AS total_penalty_amount,
      COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END), 0) AS unpaid_penalty_amount
    FROM penalties
")->fetch_assoc();

$complaintStats = $conn->query("
    SELECT
      COUNT(*) AS total_complaints,
      COALESCE(SUM(status = 'new'), 0) AS new_complaints
    FROM complaints
")->fetch_assoc();

$monthlyTopBooks = $conn->query("
    SELECT ranked.borrow_month, ranked.title, ranked.author, ranked.borrow_count
    FROM (
        SELECT
            DATE_FORMAT(br.borrow_date, '%Y-%m') AS borrow_month,
            b.title,
            b.author,
            COUNT(*) AS borrow_count,
            ROW_NUMBER() OVER (
                PARTITION BY DATE_FORMAT(br.borrow_date, '%Y-%m')
                ORDER BY COUNT(*) DESC, b.title ASC
            ) AS row_num
        FROM borrows br
        JOIN books b ON b.id = br.book_id
        GROUP BY DATE_FORMAT(br.borrow_date, '%Y-%m'), b.id, b.title, b.author
    ) AS ranked
    WHERE ranked.row_num = 1
    ORDER BY ranked.borrow_month DESC
    LIMIT 12
");
$topBooksByMonth = [];
if ($monthlyTopBooks) {
    while ($row = $monthlyTopBooks->fetch_assoc()) {
        $topBooksByMonth[] = $row;
    }
}

$monthlyBorrowBreakdownResult = $conn->query("
    SELECT
      DATE_FORMAT(br.borrow_date, '%Y-%m') AS borrow_month,
      b.title,
      b.author,
      COUNT(*) AS borrow_count
    FROM borrows br
    JOIN books b ON b.id = br.book_id
    GROUP BY DATE_FORMAT(br.borrow_date, '%Y-%m'), b.id, b.title, b.author
    ORDER BY borrow_month DESC, borrow_count DESC, b.title ASC
    LIMIT 60
");

$monthlyBorrowBreakdown = [];
if ($monthlyBorrowBreakdownResult) {
    while ($row = $monthlyBorrowBreakdownResult->fetch_assoc()) {
        $monthlyBorrowBreakdown[$row['borrow_month']][] = $row;
    }
}

$monthlyBorrowTotals = $conn->query("
    SELECT
      DATE_FORMAT(borrow_date, '%Y-%m') AS borrow_month,
      COUNT(*) AS borrow_count
    FROM borrows
    GROUP BY DATE_FORMAT(borrow_date, '%Y-%m')
    ORDER BY borrow_month DESC
    LIMIT 6
");

$borrowTrend = [];
$maxBorrowCount = 0;
if ($monthlyBorrowTotals) {
    while ($trendRow = $monthlyBorrowTotals->fetch_assoc()) {
        $borrowTrend[] = $trendRow;
        $maxBorrowCount = max($maxBorrowCount, (int) $trendRow['borrow_count']);
    }
    $borrowTrend = array_reverse($borrowTrend);
}

$recentActivity = $conn->query("
    SELECT *
    FROM (
        SELECT
            'payment' AS activity_type,
            CONCAT('Payment submission #', pay.id) AS headline,
            CONCAT(u.username, ' submitted ', FORMAT(pay.amount, 2), ' with status ', pay.status) AS details,
            pay.created_at AS activity_at,
            pay.status AS status_tag
        FROM payments pay
        JOIN users u ON u.id = pay.user_id

        UNION ALL

        SELECT
            'penalty' AS activity_type,
            CONCAT('Penalty #', p.id) AS headline,
            CONCAT(u.username, ' penalty recorded at ', FORMAT(p.amount, 2)) AS details,
            p.created_at AS activity_at,
            p.status AS status_tag
        FROM penalties p
        JOIN users u ON u.id = p.user_id

        UNION ALL

        SELECT
            'borrow' AS activity_type,
            CONCAT('Borrow #', br.id) AS headline,
            CONCAT(u.username, ' borrowed ', b.title) AS details,
            br.created_at AS activity_at,
            br.status AS status_tag
        FROM borrows br
        JOIN users u ON u.id = br.user_id
        JOIN books b ON b.id = br.book_id
    ) AS activity_log
    ORDER BY activity_at DESC
    LIMIT 8
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell admin-shell member-shell js-member-sidebar" data-sidebar-key="admin-dashboard" data-sidebar-default="expanded" data-sidebar-lock="expanded">
  <?php
  $sidebarPage = 'dashboard';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
    <?php
    $pageTitle = 'Admin Dashboard';
    $pageSubtitle = 'Signed in as ' . (string) ($_SESSION['username'] ?? '');
    require __DIR__ . '/partials/topbar.php';
    ?>

    <div class="stack">
    <?php if ($syncNotice !== ''): ?>
      <div class="notice success"><?php echo h($syncNotice); ?></div>
    <?php endif; ?>
    <?php if ($syncError !== ''): ?>
      <div class="notice error"><?php echo h($syncError); ?></div>
    <?php endif; ?>

    <div class="panel">
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Administrative summary</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo (int) ($userStats['total_users'] ?? 0); ?></strong>
          <span class="muted">Total accounts</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($paymentStats['pending_payments'] ?? 0); ?></strong>
          <span class="muted">Pending payments</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($penaltyStats['unpaid_penalties'] ?? 0); ?></strong>
          <span class="muted">Unpaid penalties</span>
        </div>
        <div class="stat-card">
          <strong><?php echo h(format_currency($penaltyStats['unpaid_penalty_amount'] ?? 0)); ?></strong>
          <span class="muted">Open penalty balance</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($complaintStats['new_complaints'] ?? 0); ?></strong>
          <span class="muted">New complaints</span>
        </div>
      </div>
      <div class="toolbar toolbar-top flow-top-md admin-maintenance-bar">
        <div class="grow admin-maintenance-copy">
          <span class="code-pill">Maintenance</span>
          <h3 class="heading-top-md">Penalty Sync</h3>
          <p class="muted">Use this only when overdue penalties need a manual refresh. Main navigation is now in the left sidebar.</p>
        </div>
        <form method="post" class="admin-maintenance-action" data-confirm="Run penalty sync now?">
          <button type="submit" name="run_penalty_sync" value="1">Run Penalty Sync Now</button>
        </form>
      </div>
    </div>

    <div class="panel">
      <p class="muted eyebrow-compact stack-copy">Analytics</p>
      <h3 class="heading-card">Borrowing performance</h3>
      <p class="muted copy-bottom">Monthly borrowing activity and top title leaders based on the <code>borrows</code> table.</p>

      <div class="dashboard-chart">
        <div class="inline-actions inline-actions-spread">
          <span class="muted">Monthly borrow volume</span>
          <span class="code-pill">Last <?php echo count($borrowTrend); ?> month(s)</span>
        </div>
        <div class="chart-grid">
          <?php if (count($borrowTrend) === 0): ?>
            <div class="empty-state chart-empty">No borrow trend data yet.</div>
          <?php endif; ?>
          <?php foreach ($borrowTrend as $trendRow): ?>
            <div class="chart-col">
              <div class="chart-value"><?php echo (int) $trendRow['borrow_count']; ?></div>
              <div class="chart-bar-wrap">
                <div
                  class="chart-bar"
                  data-chart-bar
                  data-value="<?php echo (int) $trendRow['borrow_count']; ?>"
                  data-max="<?php echo max(1, $maxBorrowCount); ?>"
                ></div>
              </div>
              <div class="chart-label"><?php echo h(date('M Y', strtotime($trendRow['borrow_month'] . '-01'))); ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="table-wrap flow-top-md">
        <table>
          <thead>
            <tr>
              <th>Month</th>
              <th>Book</th>
              <th>Author</th>
              <th>Borrow Count</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($topBooksByMonth) === 0): ?>
              <tr><td colspan="4" class="muted">No borrow analytics available yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($topBooksByMonth as $row): ?>
              <tr>
                <td><?php echo h(date('F Y', strtotime($row['borrow_month'] . '-01'))); ?></td>
                <td><?php echo h($row['title']); ?></td>
                <td><?php echo h($row['author']); ?></td>
                <td><span class="badge"><?php echo (int) $row['borrow_count']; ?> borrow<?php echo (int) $row['borrow_count'] === 1 ? '' : 's'; ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="grid cards flow-top-md">
        <div class="panel">
          <p class="muted eyebrow-compact stack-copy">Full Breakdown</p>
          <h3 class="heading-top-md">All borrowed books per month</h3>
          <div class="stack">
            <?php if (count($monthlyBorrowBreakdown) === 0): ?>
              <div class="empty-state">No monthly borrow breakdown available yet.</div>
            <?php endif; ?>
            <?php foreach ($monthlyBorrowBreakdown as $month => $items): ?>
              <div class="panel panel-pad-sm">
                <div class="inline-actions inline-actions-spread">
                  <strong><?php echo h(date('F Y', strtotime($month . '-01'))); ?></strong>
                  <span class="code-pill"><?php echo count($items); ?> title<?php echo count($items) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="table-wrap flow-top-sm">
                  <table>
                    <thead>
                      <tr>
                        <th>Book</th>
                        <th>Author</th>
                        <th>Borrow Count</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($items as $item): ?>
                        <tr>
                          <td><?php echo h($item['title']); ?></td>
                          <td><?php echo h($item['author']); ?></td>
                          <td><span class="badge"><?php echo (int) $item['borrow_count']; ?> borrow<?php echo (int) $item['borrow_count'] === 1 ? '' : 's'; ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="panel">
      <p class="muted eyebrow-compact stack-copy">Recent Activity</p>
      <h3 class="heading-top-md">Latest system events</h3>
      <div class="activity-feed">
        <?php if (!$recentActivity || $recentActivity->num_rows === 0): ?>
          <div class="empty-state">No recent activity found yet.</div>
        <?php endif; ?>
        <?php while ($activity = $recentActivity->fetch_assoc()): ?>
          <div class="activity-item">
            <strong>
              <span class="status-dot <?php echo h($activity['status_tag']); ?>"></span>
              <?php echo h($activity['headline']); ?>
            </strong>
            <div class="meta"><?php echo h($activity['details']); ?></div>
            <div class="meta meta-top"><?php echo h(date('F j, Y g:i A', strtotime($activity['activity_at']))); ?></div>
          </div>
        <?php endwhile; ?>
      </div>
    </div>
    </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/shared_confirm.js"></script>
<script src="/librarymanage/assets/admin_dashboard.js"></script>
</body>
</html>

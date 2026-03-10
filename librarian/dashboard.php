<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

$stats = $conn->query("
    SELECT
      COUNT(*) AS total_titles,
      COALESCE(SUM(qty_total), 0) AS total_copies,
      COALESCE(SUM(qty_available), 0) AS available_copies
    FROM books
")->fetch_assoc();

$penaltyStats = $conn->query("
    SELECT
      COUNT(*) AS total_penalties,
      COALESCE(SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_penalties
    FROM penalties
")->fetch_assoc();

$paymentStats = $conn->query("
    SELECT
      COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_payments
    FROM payments
")->fetch_assoc();

$borrowStats = $conn->query("
    SELECT
      COUNT(*) AS active_borrows,
      COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_borrows,
      COALESCE(SUM(CASE WHEN status = 'return_requested' THEN 1 ELSE 0 END), 0) AS pending_returns
    FROM borrows
    WHERE status IN ('borrowed', 'return_requested')
")->fetch_assoc();

$recentBorrows = $conn->query("
    SELECT
      br.id,
      u.username,
      b.title,
      br.due_date
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    JOIN books b ON b.id = br.book_id
    WHERE br.status IN ('borrowed', 'return_requested')
    ORDER BY br.due_date ASC, br.id DESC
    LIMIT 5
");

$recentActivity = $conn->query("
    SELECT *
    FROM (
        SELECT
            'borrowed' AS status_tag,
            CONCAT('Borrow #', br.id) AS headline,
            CONCAT(u.username, ' borrowed ', b.title) AS details,
            br.created_at AS activity_at
        FROM borrows br
        JOIN users u ON u.id = br.user_id
        JOIN books b ON b.id = br.book_id

        UNION ALL

        SELECT
            'unpaid' AS status_tag,
            CONCAT('Penalty #', p.id) AS headline,
            CONCAT(u.username, ' penalty recorded for ', FORMAT(p.amount, 2)) AS details,
            p.created_at AS activity_at
        FROM penalties p
        JOIN users u ON u.id = p.user_id

        UNION ALL

        SELECT
            pay.status AS status_tag,
            CONCAT('Payment #', pay.id) AS headline,
            CONCAT(u.username, ' submitted payment proof for ', FORMAT(pay.amount, 2)) AS details,
            pay.created_at AS activity_at
        FROM payments pay
        JOIN users u ON u.id = pay.user_id

        UNION ALL

        SELECT
            'approved' AS status_tag,
            CONCAT('Book #', b.id) AS headline,
            CONCAT('Catalog entry added: ', b.title) AS details,
            b.created_at AS activity_at
        FROM books b
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
<title>Librarian Dashboard</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell librarian-shell member-shell js-member-sidebar" data-sidebar-key="librarian-dashboard" data-sidebar-default="expanded">
  <?php
  $sidebarPage = 'dashboard';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Librarian Dashboard';
  $pageSubtitle = 'Signed in as ' . (string) ($_SESSION['username'] ?? '');
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <div class="panel librarian-dashboard-overview">
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Library operations snapshot</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo (int) ($stats['total_titles'] ?? 0); ?></strong>
          <span class="muted">Titles in catalog</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($stats['total_copies'] ?? 0); ?></strong>
          <span class="muted">Total copies recorded</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($stats['available_copies'] ?? 0); ?></strong>
          <span class="muted">Copies available now</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($penaltyStats['unpaid_penalties'] ?? 0); ?></strong>
          <span class="muted">Unpaid penalties</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($paymentStats['pending_payments'] ?? 0); ?></strong>
          <span class="muted">Pending payment reviews</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($borrowStats['active_borrows'] ?? 0); ?></strong>
          <span class="muted">Books not yet returned</span>
        </div>
      </div>
    </div>

    <div class="grid cards librarian-dashboard-grid">
      <div class="panel librarian-dashboard-focus">
        <p class="muted eyebrow-compact stack-copy">Priority Work</p>
        <h3 class="stack-copy-md">Today&apos;s focus</h3>
        <div class="stack">
          <div class="empty-state">Overdue borrowed books: <strong><?php echo (int) ($borrowStats['overdue_borrows'] ?? 0); ?></strong></div>
          <div class="empty-state">Pending return confirmations: <strong><?php echo (int) ($borrowStats['pending_returns'] ?? 0); ?></strong></div>
          <div class="empty-state">Pending admin payment reviews: <strong><?php echo (int) ($paymentStats['pending_payments'] ?? 0); ?></strong></div>
          <div class="empty-state">Unpaid penalties: <strong><?php echo (int) ($penaltyStats['unpaid_penalties'] ?? 0); ?></strong></div>
          <div class="empty-state">Available copies in stock: <strong><?php echo (int) ($stats['available_copies'] ?? 0); ?></strong></div>
        </div>
      </div>
      <div class="panel librarian-dashboard-queue">
        <p class="muted eyebrow-compact stack-copy">Recent Borrow Queue</p>
        <h3 class="stack-copy-md">Books still out</h3>
        <div class="stack">
          <?php if (!$recentBorrows || $recentBorrows->num_rows === 0): ?>
            <div class="empty-state">No active borrowed books right now.</div>
          <?php endif; ?>
          <?php while ($recent = $recentBorrows->fetch_assoc()): ?>
            <div class="empty-state">
              <strong class="label-block meta-top-sm"><?php echo h($recent['title']); ?></strong>
              <span class="muted"><?php echo h($recent['username']); ?> | Due <?php echo h(format_display_date((string) $recent['due_date'])); ?></span>
            </div>
          <?php endwhile; ?>
        </div>
      </div>
    </div>

    <div class="panel librarian-dashboard-activity">
      <p class="muted eyebrow-compact stack-copy">Recent Activity</p>
      <h3 class="stack-copy-md">Latest circulation and catalog updates</h3>
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
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
</body>
</html>

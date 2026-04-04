<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

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
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-desktop-header member-mobile-hide">
  <a class="site-footer-brand" href="/librarymanage/index.php">
    <img class="site-footer-brand-mark" src="/librarymanage/assets/images/regismarielogo.png" alt="Regis Marie College logo">
    <span class="site-footer-copy">
      <strong>Regis Marie College</strong>
      <span>Library Management System</span>
    </span>
  </a>
  <div class="site-desktop-header-theme"></div>
</div>
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
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/shared_confirm.js"></script>
</body>
</html>


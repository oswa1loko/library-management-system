<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('student');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$dashboardSummary = get_member_dashboard_summary($conn, $userId);
$catalogHighlights = get_member_catalog_highlights($conn, 4);
$studentUnreadNotifications = (int) ($conn->query("SELECT COUNT(*) AS total FROM notifications WHERE role = 'student' AND user_id = " . (int) $userId . " AND is_read = 0")->fetch_assoc()['total'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-desktop-header member-mobile-hide">
  <a class="site-footer-brand" href="/librarymanage/index.php">
    <img class="site-footer-brand-mark" src="/librarymanage/assets/images/RMLOGO.jfif" alt="Regis Marie College logo">
    <span class="site-footer-copy">
      <strong>Regis Marie College</strong>
      <span>Library Management System</span>
    </span>
  </a>
  <div class="site-desktop-header-theme"></div>
</div>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="student-dashboard">
  <aside class="panel member-sidebar">
    <div class="member-sidebar-head">
      <div class="member-sidebar-toggle" aria-hidden="true">
        <span class="member-sidebar-label">Main Menu</span>
      </div>
    </div>
    <nav class="member-sidebar-nav">
      <a class="member-sidebar-link is-active" href="dashboard.php" data-tooltip="Dashboard">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Dashboard</span>
      </a>
      <a class="member-sidebar-link" href="books.php" data-tooltip="Books">
        <span class="dashboard-icon icon-books" aria-hidden="true"></span>
        <span class="member-sidebar-label">Books</span>
      </a>
      <a class="member-sidebar-link" href="catalog.php" data-tooltip="Catalog">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">Catalog</span>
      </a>
      <a class="member-sidebar-link" href="ebooks.php" data-tooltip="eBooks">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">eBooks</span>
      </a>
      <a class="member-sidebar-link" href="borrow_return.php" data-tooltip="Returns">
        <span class="dashboard-icon icon-checklist" aria-hidden="true"></span>
        <span class="member-sidebar-label">Returns</span>
      </a>
      <a class="member-sidebar-link" href="tracking.php" data-tooltip="Records Tracking">
        <span class="dashboard-icon icon-ledger" aria-hidden="true"></span>
        <span class="member-sidebar-label">Records Tracking</span>
      </a>
      <a class="member-sidebar-link" href="payment_upload.php" data-tooltip="Payments">
        <span class="dashboard-icon icon-payments" aria-hidden="true"></span>
        <span class="member-sidebar-label">Payments</span>
      </a>
    </nav>
    <p class="member-sidebar-section member-sidebar-label">Account</p>
    <div class="topbar-nav member-sidebar-utilities">
      <a class="member-sidebar-link" href="profile.php" data-tooltip="Profile">
        <span class="dashboard-icon icon-edit" aria-hidden="true"></span>
        <span class="member-sidebar-label">Profile</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/index.php" data-tooltip="Home">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">Home</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/logout.php" data-tooltip="Logout">
        <span class="member-sidebar-label">Logout</span>
      </a>
    </div>
  </aside>

  <div class="member-main">
    <div class="topbar">
      <div>
        <h1>Student Dashboard</h1>
        <p>Signed in as <?php echo h($_SESSION['username']); ?></p>
      </div>
    </div>

    <div class="stack">
    <div class="panel member-dashboard-overview">
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Your library snapshot</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo (int) ($dashboardSummary['active_borrows'] ?? 0); ?></strong>
          <span class="muted">Books currently borrowed</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($dashboardSummary['overdue_borrows'] ?? 0); ?></strong>
          <span class="muted">Overdue returns</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($dashboardSummary['unpaid_penalties'] ?? 0); ?></strong>
          <span class="muted">Unpaid penalties</span>
        </div>
        <div class="stat-card">
          <strong><?php echo h(format_currency($dashboardSummary['unpaid_total'] ?? 0)); ?></strong>
          <span class="muted">Outstanding balance</span>
        </div>
      </div>
    </div>

    <div class="grid cards member-dashboard-grid">
      <div class="panel member-dashboard-focus">
        <p class="muted eyebrow-compact stack-copy">Attention</p>
        <h3 class="stack-copy-md">What to check next</h3>
        <div class="stack">
          <div class="empty-state">Books not yet returned: <strong><?php echo (int) ($dashboardSummary['active_borrows'] ?? 0); ?></strong></div>
          <div class="empty-state">Pending payment reviews: <strong><?php echo (int) ($dashboardSummary['pending_payments'] ?? 0); ?></strong></div>
          <div class="empty-state">Overdue returns need attention to avoid added penalties.</div>
        </div>
      </div>
      <div class="panel member-dashboard-shortcuts">
        <p class="muted eyebrow-compact stack-copy">Quick Actions</p>
        <h3 class="stack-copy-md">Go directly where needed</h3>
        <div class="inline-actions member-dashboard-shortcuts-row">
          <a class="button" href="borrow_return.php">Check My Borrows</a>
          <a class="button secondary" href="notifications.php">Notifications<?php echo $studentUnreadNotifications > 0 ? ' (' . $studentUnreadNotifications . ')' : ''; ?></a>
          <a class="button secondary" href="payment_upload.php">See Penalties</a>
          <a class="button secondary" href="books.php">Browse Books</a>
        </div>
      </div>
    </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
</body>
</html>

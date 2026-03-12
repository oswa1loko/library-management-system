<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('student');

$userId = (int) ($_SESSION['user_id'] ?? 0);

$borrowStats = $conn->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN status IN ('borrowed', 'return_requested') THEN 1 ELSE 0 END), 0) AS active_borrows,
      COALESCE(SUM(CASE WHEN status IN ('borrowed', 'return_requested') AND due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_borrows
    FROM borrows
    WHERE user_id = ?
");
$borrowStats->bind_param('i', $userId);
$borrowStats->execute();
$borrowSummary = $borrowStats->get_result()->fetch_assoc();
$borrowStats->close();

$dueSoonStmt = $conn->prepare("
    SELECT b.title, br.due_date, br.status
    FROM borrows br
    JOIN books b ON b.id = br.book_id
    WHERE br.user_id = ?
      AND br.status IN ('borrowed', 'return_requested')
      AND br.due_date >= CURDATE()
      AND br.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ORDER BY br.due_date ASC
    LIMIT 5
");
$dueSoonStmt->bind_param('i', $userId);
$dueSoonStmt->execute();
$dueSoonBooks = $dueSoonStmt->get_result();
$dueSoonStmt->close();

$paymentStats = $conn->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_payments
    FROM payments
    WHERE user_id = ?
");
$paymentStats->bind_param('i', $userId);
$paymentStats->execute();
$paymentSummary = $paymentStats->get_result()->fetch_assoc();
$paymentStats->close();

$penaltyStats = $conn->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_penalties,
      COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END), 0) AS unpaid_total
    FROM penalties
    WHERE user_id = ?
");
$penaltyStats->bind_param('i', $userId);
$penaltyStats->execute();
$penaltySummary = $penaltyStats->get_result()->fetch_assoc();
$penaltyStats->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="student-dashboard">
  <aside class="panel member-sidebar">
    <div class="member-sidebar-head">
      <button type="button" class="member-sidebar-toggle js-sidebar-toggle" aria-expanded="true" aria-label="Collapse sidebar">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Main Menu</span>
      </button>
    </div>
    <p class="member-sidebar-section member-sidebar-label">Main</p>
    <nav class="member-sidebar-nav">
      <a class="member-sidebar-link is-active" href="dashboard.php" data-tooltip="Dashboard">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Dashboard</span>
      </a>
      <a class="member-sidebar-link" href="borrow_return.php" data-tooltip="Borrow and Return">
        <span class="dashboard-icon icon-books" aria-hidden="true"></span>
        <span class="member-sidebar-label">Borrow and Return</span>
      </a>
      <a class="member-sidebar-link" href="ebooks.php" data-tooltip="eBooks">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">eBooks</span>
      </a>
      <a class="member-sidebar-link" href="payment_upload.php" data-tooltip="Payments">
        <span class="dashboard-icon icon-payments" aria-hidden="true"></span>
        <span class="member-sidebar-label">Payments</span>
      </a>
      <a class="member-sidebar-link" href="books.php" data-tooltip="Catalog">
        <span class="dashboard-icon icon-ledger" aria-hidden="true"></span>
        <span class="member-sidebar-label">Catalog</span>
      </a>
    </nav>
    <p class="member-sidebar-section member-sidebar-label">Account</p>
    <div class="topbar-nav member-sidebar-utilities">
      <a class="member-sidebar-link" href="/librarymanage/index.php" data-tooltip="Home">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">Home</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/logout.php" data-tooltip="Logout">
        <span class="dashboard-icon icon-logout" aria-hidden="true"></span>
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
    <?php if ($dueSoonBooks->num_rows > 0): ?>
      <div class="notice warning member-dashboard-alert">
        <strong class="label-block stack-copy">Due Date Alert</strong>
        <?php while ($dueBook = $dueSoonBooks->fetch_assoc()): ?>
          <div class="muted meta-top-sm">
            <?php echo h($dueBook['title']); ?> is due on <?php echo h(format_display_date((string) $dueBook['due_date'])); ?>
            <?php if ($dueBook['status'] === 'return_requested'): ?>
              and is waiting for librarian confirmation.
            <?php endif; ?>
          </div>
        <?php endwhile; ?>
      </div>
    <?php endif; ?>

    <div class="panel member-dashboard-overview">
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Your library snapshot</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo (int) ($borrowSummary['active_borrows'] ?? 0); ?></strong>
          <span class="muted">Books currently borrowed</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($borrowSummary['overdue_borrows'] ?? 0); ?></strong>
          <span class="muted">Overdue returns</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($penaltySummary['unpaid_penalties'] ?? 0); ?></strong>
          <span class="muted">Unpaid penalties</span>
        </div>
        <div class="stat-card">
          <strong><?php echo h(format_currency($penaltySummary['unpaid_total'] ?? 0)); ?></strong>
          <span class="muted">Outstanding balance</span>
        </div>
      </div>
    </div>

    <div class="grid cards member-dashboard-grid">
      <div class="panel member-dashboard-focus">
        <p class="muted eyebrow-compact stack-copy">Attention</p>
        <h3 class="stack-copy-md">What to check next</h3>
        <div class="stack">
          <div class="empty-state">Books not yet returned: <strong><?php echo (int) ($borrowSummary['active_borrows'] ?? 0); ?></strong></div>
          <div class="empty-state">Pending payment reviews: <strong><?php echo (int) ($paymentSummary['pending_payments'] ?? 0); ?></strong></div>
          <div class="empty-state">Overdue returns need attention to avoid added penalties.</div>
        </div>
      </div>
      <div class="panel member-dashboard-shortcuts">
        <p class="muted eyebrow-compact stack-copy">Quick Actions</p>
        <h3 class="stack-copy-md">Go directly where needed</h3>
        <div class="inline-actions member-dashboard-shortcuts-row">
          <a class="button" href="borrow_return.php">Check My Borrows</a>
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

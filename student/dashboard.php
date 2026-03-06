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
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css">
</head>
<body>
<div class="site-shell">
  <div class="topbar">
    <div>
      <h1>Student Dashboard</h1>
      <p>Signed in as <?php echo h($_SESSION['username']); ?></p>
    </div>
    <div class="topbar-nav">
      <a href="/librarymanage/index.php">Home</a>
      <a href="/librarymanage/logout.php">Logout</a>
    </div>
  </div>

  <div class="stack">
    <?php if ($dueSoonBooks->num_rows > 0): ?>
      <div class="notice warning member-dashboard-alert">
        <strong class="label-block stack-copy">Due Date Alert</strong>
        <?php while ($dueBook = $dueSoonBooks->fetch_assoc()): ?>
          <div class="muted meta-top-sm">
            <?php echo h($dueBook['title']); ?> is due on <?php echo h($dueBook['due_date']); ?>
            <?php if ($dueBook['status'] === 'return_requested'): ?>
              and is waiting for custodian confirmation.
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

    <div class="grid cards member-dashboard-actions">
      <div class="action-card member-action-card">
        <div class="card-head">
          <div class="dashboard-icon icon-books" aria-hidden="true"></div>
          <div>
            <span class="chip">Borrowing</span>
            <h3 class="heading-top-md">Borrow and Return</h3>
          </div>
        </div>
        <p>Borrow available books, review due dates, and return active borrow records.</p>
        <div class="row row-top"><a class="button" href="borrow_return.php">Open Borrowing</a></div>
      </div>
      <div class="action-card member-action-card">
        <div class="card-head">
          <div class="dashboard-icon icon-payments" aria-hidden="true"></div>
          <div>
            <span class="chip">Payments</span>
            <h3 class="heading-top-md">Penalty Payments</h3>
          </div>
        </div>
        <p>Upload payment proof and track submission status for your unpaid penalties.</p>
        <div class="row row-top"><a class="button" href="payment_upload.php">Open Payments</a></div>
      </div>
      <div class="action-card member-action-card">
        <div class="card-head">
          <div class="dashboard-icon icon-books" aria-hidden="true"></div>
          <div>
            <span class="chip">Catalog</span>
            <h3 class="heading-top-md">Books Catalog</h3>
          </div>
        </div>
        <p>Review available titles, categories, and current stock counts before borrowing.</p>
        <div class="row row-top"><a class="button" href="books.php">Open Catalog</a></div>
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
</body>
</html>

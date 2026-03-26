<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('student');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$message = '';
$dueSoonBooks = get_member_due_soon_books($conn, $userId, 5);
$overdueBooks = get_member_overdue_books($conn, $userId, 5);

if (isset($_POST['mark_read'])) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND role = 'student' AND user_id = ?");
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        if ($changed) {
            $message = 'Notification marked as read.';
        }
    }
}

if (isset($_POST['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE role = 'student' AND user_id = ? AND is_read = 0");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    $message = 'All notifications marked as read.';
}

$notificationsStmt = $conn->prepare("
    SELECT id, title, body, severity, is_read, created_at
    FROM notifications
    WHERE role = 'student' AND user_id = ?
    ORDER BY id DESC
    LIMIT 60
");
$notificationsStmt->bind_param('i', $userId);
$notificationsStmt->execute();
$notifications = $notificationsStmt->get_result();
$notificationsStmt->close();

$studentUnreadNotifications = (int) ($conn->query("SELECT COUNT(*) AS total FROM notifications WHERE role = 'student' AND user_id = " . (int) $userId . " AND is_read = 0")->fetch_assoc()['total'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Notifications</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="student-notifications">
  <aside class="panel member-sidebar">
    <div class="member-sidebar-head">
      <div class="member-sidebar-toggle" aria-hidden="true">
        <span class="member-sidebar-label">Main Menu</span>
      </div>
    </div>
    <nav class="member-sidebar-nav">
      <a class="member-sidebar-link" href="dashboard.php" data-tooltip="Dashboard">
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
        <span class="dashboard-icon icon-logout" aria-hidden="true"></span>
        <span class="member-sidebar-label">Logout</span>
      </a>
    </div>
  </aside>

  <div class="member-main">
    <div class="topbar">
      <div>
        <h1>Student Notifications</h1>
        <p>Click into your latest return and account updates</p>
      </div>
    </div>

    <div class="stack">
      <?php if ($message !== ''): ?>
        <div class="notice success"><?php echo h($message); ?></div>
      <?php endif; ?>

      <div class="panel">
        <div class="toolbar toolbar-top">
          <div class="grow">
            <p class="muted eyebrow-compact">Inbox</p>
            <h3 class="heading-card">Your notifications and alerts</h3>
          </div>
          <form method="post" class="inline-form">
            <button type="submit" name="mark_all_read" value="1">Mark All Read</button>
          </form>
        </div>
        <div class="activity-feed">
          <?php foreach ($overdueBooks as $dueBook): ?>
            <?php
              $dueDateRaw = (string) ($dueBook['due_date'] ?? '');
              $daysOverdue = $dueDateRaw !== '' ? max(1, (int) floor((strtotime(date('Y-m-d')) - strtotime($dueDateRaw)) / 86400)) : 1;
            ?>
            <div class="activity-item">
              <strong>
                <span class="status-dot unpaid"></span>
                Overdue Alert
              </strong>
              <div class="meta">
                <?php echo h((string) ($dueBook['title'] ?? 'Book')); ?> was due on <?php echo h(format_display_date($dueDateRaw)); ?> and is now overdue by <?php echo (int) $daysOverdue; ?> day<?php echo $daysOverdue === 1 ? '' : 's'; ?>.
                <?php if (($dueBook['status'] ?? '') === 'return_requested'): ?>
                  Return confirmation is still pending.
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php foreach ($dueSoonBooks as $dueBook): ?>
            <div class="activity-item">
              <strong>
                <span class="status-dot due"></span>
                Due Date Alert
              </strong>
              <div class="meta">
                <?php echo h((string) ($dueBook['title'] ?? 'Book')); ?> is due on <?php echo h(format_display_date((string) ($dueBook['due_date'] ?? ''))); ?>
                <?php if (($dueBook['status'] ?? '') === 'return_requested'): ?>
                  and is waiting for librarian confirmation.
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (!$notifications || $notifications->num_rows === 0): ?>
            <?php if ($dueSoonBooks === [] && $overdueBooks === []): ?>
              <div class="empty-state">No notifications yet.</div>
            <?php endif; ?>
          <?php endif; ?>
          <?php while ($notifications && ($row = $notifications->fetch_assoc())): ?>
            <div class="activity-item">
              <strong>
                <span class="status-dot <?php echo h($row['severity'] === 'critical' ? 'unpaid' : ($row['severity'] === 'warning' ? 'due' : 'approved')); ?>"></span>
                <?php echo h($row['title']); ?>
                <?php if ((int) $row['is_read'] === 0): ?><span class="chip">Unread</span><?php endif; ?>
              </strong>
              <div class="meta"><?php echo h($row['body']); ?></div>
              <div class="inline-actions meta-top">
                <span class="muted"><?php echo h(date('F j, Y g:i A', strtotime((string) $row['created_at']))); ?></span>
                <?php if ((int) $row['is_read'] === 0): ?>
                  <form method="post" class="inline-form">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <button type="submit" name="mark_read" value="1">Mark Read</button>
                  </form>
                <?php endif; ?>
              </div>
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

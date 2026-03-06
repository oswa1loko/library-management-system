<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$message = '';
$messageType = 'success';

if (isset($_POST['mark_read'])) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND role = 'admin'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        if ($changed) {
            $message = 'Notification marked as read.';
            audit_log($conn, 'admin.notification.read', ['notification_id' => $id]);
        }
    }
}

if (isset($_POST['mark_all_read'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE role = 'admin' AND is_read = 0");
    $message = 'All admin notifications marked as read.';
    audit_log($conn, 'admin.notification.read_all');
}

$liveStats = $conn->query("
    SELECT
      COALESCE(SUM(CASE WHEN status IN ('borrowed', 'return_requested') AND due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_borrows,
      (SELECT COALESCE(SUM(status = 'pending'), 0) FROM payments) AS pending_payments,
      (SELECT COALESCE(SUM(status = 'new'), 0) FROM complaints) AS new_complaints
    FROM borrows
")->fetch_assoc();

$notifications = $conn->query("
    SELECT id, title, body, severity, is_read, created_at
    FROM notifications
    WHERE role = 'admin'
    ORDER BY id DESC
    LIMIT 60
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Notifications</title>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css">
</head>
<body>
<div class="site-shell">
  <div class="topbar">
    <div>
      <h1>Admin Notifications</h1>
      <p>Operational alerts and system events</p>
    </div>
    <div class="topbar-nav">
      <a href="dashboard.php">Dashboard</a>
      <a href="/librarymanage/logout.php">Logout</a>
    </div>
  </div>

  <div class="stack">
    <?php if ($message !== ''): ?>
      <div class="notice <?php echo $messageType === 'error' ? 'error' : 'success'; ?>"><?php echo h($message); ?></div>
    <?php endif; ?>

    <div class="panel">
      <p class="muted eyebrow-compact stack-copy">Live Alerts</p>
      <h3 class="heading-card">Current system attention points</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo (int) ($liveStats['overdue_borrows'] ?? 0); ?></strong>
          <span class="muted">Overdue borrows</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($liveStats['pending_payments'] ?? 0); ?></strong>
          <span class="muted">Pending payments</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($liveStats['new_complaints'] ?? 0); ?></strong>
          <span class="muted">New complaints</span>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="toolbar toolbar-top">
        <div class="grow">
          <p class="muted eyebrow-compact">Inbox</p>
          <h3 class="heading-card">Stored notifications</h3>
        </div>
        <form method="post" class="inline-form">
          <button type="submit" name="mark_all_read" value="1">Mark All Read</button>
        </form>
      </div>
      <div class="activity-feed">
        <?php if (!$notifications || $notifications->num_rows === 0): ?>
          <div class="empty-state">No notifications yet.</div>
        <?php endif; ?>
        <?php while ($row = $notifications->fetch_assoc()): ?>
          <div class="activity-item">
            <strong>
              <span class="status-dot <?php echo h($row['severity'] === 'critical' ? 'unpaid' : ($row['severity'] === 'warning' ? 'due' : 'approved')); ?>"></span>
              <?php echo h($row['title']); ?>
              <?php if ((int) $row['is_read'] === 0): ?><span class="chip">Unread</span><?php endif; ?>
            </strong>
            <div class="meta"><?php echo h($row['body']); ?></div>
            <div class="inline-actions meta-top">
              <span class="muted"><?php echo h(date('F j, Y g:i A', strtotime($row['created_at']))); ?></span>
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
</body>
</html>


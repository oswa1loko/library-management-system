<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('faculty');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$message = '';
$dueSoonBooks = get_member_due_soon_books($conn, $userId, 5);
$overdueBooks = get_member_overdue_books($conn, $userId, 5);

if (isset($_POST['mark_read'])) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $sourceStmt = $conn->prepare("SELECT title, body FROM notifications WHERE id = ? AND role = 'faculty' AND user_id = ? LIMIT 1");
        $sourceStmt->bind_param('ii', $id, $userId);
        $sourceStmt->execute();
        $sourceRow = $sourceStmt->get_result()->fetch_assoc();
        $sourceStmt->close();

        $changed = false;
        if ($sourceRow) {
            $title = (string) ($sourceRow['title'] ?? '');
            $body = (string) ($sourceRow['body'] ?? '');
            $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE role = 'faculty' AND user_id = ? AND title = ? AND body = ? AND is_read = 0");
            $stmt->bind_param('iss', $userId, $title, $body);
            $stmt->execute();
            $changed = $stmt->affected_rows >= 1;
            $stmt->close();
        }
        if ($changed) {
            $message = 'Notification marked as read.';
        }
    }
}

if (isset($_POST['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE role = 'faculty' AND user_id = ? AND is_read = 0");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    $message = 'All notifications marked as read.';
}

$notificationsStmt = $conn->prepare("
    SELECT id, title, body, severity, is_read, created_at
    FROM notifications
    WHERE role = 'faculty' AND user_id = ?
    ORDER BY id DESC
    LIMIT 60
");
$notificationsStmt->bind_param('i', $userId);
$notificationsStmt->execute();
$notifications = $notificationsStmt->get_result();
$notificationsStmt->close();

$notificationFeedUrl = app_url('faculty/notifications_feed.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Faculty Notifications</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="faculty-notifications">
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
        <h1>Faculty Notifications</h1>
        <p>Open the latest return, account, and payment updates</p>
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
              $borrowId = (int) ($dueBook['id'] ?? 0);
            ?>
            <a
              class="activity-item activity-item-link<?php echo $borrowId > 0 ? ' is-unread' : ''; ?>"
              href="<?php echo h(app_url('faculty/borrow_return.php')); ?>"
              data-notification-link
              data-destination-url="<?php echo h(app_url('faculty/borrow_return.php')); ?>"
              <?php if ($borrowId > 0): ?>data-alert-borrow-id="<?php echo $borrowId; ?>" data-alert-unread="true"<?php endif; ?>
            >
              <strong>
                <span class="status-dot unpaid"></span>
                Overdue Alert
                <?php if ($borrowId > 0): ?><span class="chip">Unread</span><?php endif; ?>
              </strong>
              <div class="meta">
                <?php echo h((string) ($dueBook['title'] ?? 'Book')); ?> was due on <?php echo h(format_display_date($dueDateRaw)); ?> and is now overdue by <?php echo (int) $daysOverdue; ?> day<?php echo $daysOverdue === 1 ? '' : 's'; ?>.
                <?php if (($dueBook['status'] ?? '') === 'return_requested'): ?>
                  Return confirmation is still pending.
                <?php endif; ?>
              </div>
              <div class="inline-actions meta-top">
                <span class="muted">Open borrow status</span>
              </div>
            </a>
          <?php endforeach; ?>
          <?php foreach ($dueSoonBooks as $dueBook): ?>
            <?php $borrowId = (int) ($dueBook['id'] ?? 0); ?>
            <a
              class="activity-item activity-item-link<?php echo $borrowId > 0 ? ' is-unread' : ''; ?>"
              href="<?php echo h(app_url('faculty/borrow_return.php')); ?>"
              data-notification-link
              data-destination-url="<?php echo h(app_url('faculty/borrow_return.php')); ?>"
              <?php if ($borrowId > 0): ?>data-alert-borrow-id="<?php echo $borrowId; ?>" data-alert-unread="true"<?php endif; ?>
            >
              <strong>
                <span class="status-dot due"></span>
                Due Date Alert
                <?php if ($borrowId > 0): ?><span class="chip">Unread</span><?php endif; ?>
              </strong>
              <div class="meta">
                <?php echo h((string) ($dueBook['title'] ?? 'Book')); ?> is due on <?php echo h(format_display_date((string) ($dueBook['due_date'] ?? ''))); ?>
                <?php if (($dueBook['status'] ?? '') === 'return_requested'): ?>
                  and is waiting for librarian confirmation.
                <?php endif; ?>
              </div>
              <div class="inline-actions meta-top">
                <span class="muted">Open borrow status</span>
              </div>
            </a>
          <?php endforeach; ?>
          <?php if (!$notifications || $notifications->num_rows === 0): ?>
            <?php if ($dueSoonBooks === [] && $overdueBooks === []): ?>
              <div class="empty-state">No notifications yet.</div>
            <?php endif; ?>
          <?php endif; ?>
          <?php while ($notifications && ($row = $notifications->fetch_assoc())): ?>
            <?php
              $destination = notification_destination_for_viewer('faculty', $row);
              $destinationUrl = (string) ($destination['url'] ?? '');
              $destinationLabel = (string) ($destination['label'] ?? 'Open notification');
              $isUnread = (int) ($row['is_read'] ?? 0) === 0;
            ?>
            <a
              class="activity-item activity-item-link<?php echo $isUnread ? ' is-unread' : ''; ?>"
              href="<?php echo h($destinationUrl !== '' ? $destinationUrl : app_url('faculty/notifications.php')); ?>"
              data-notification-link
              data-destination-url="<?php echo h($destinationUrl !== '' ? $destinationUrl : app_url('faculty/notifications.php')); ?>"
              <?php if ($isUnread): ?>data-notification-id="<?php echo (int) $row['id']; ?>" data-notification-unread="true"<?php endif; ?>
            >
              <strong>
                <span class="status-dot <?php echo h($row['severity'] === 'critical' ? 'unpaid' : ($row['severity'] === 'warning' ? 'due' : 'approved')); ?>"></span>
                <?php echo h($row['title']); ?>
                <?php if ($isUnread): ?><span class="chip">Unread</span><?php else: ?><span class="chip">Read</span><?php endif; ?>
              </strong>
              <div class="meta"><?php echo h($row['body']); ?></div>
              <div class="inline-actions meta-top">
                <span class="muted"><?php echo h(date('F j, Y g:i A', strtotime((string) $row['created_at']))); ?></span>
                <span class="muted"><?php echo h($destinationLabel); ?></span>
              </div>
            </a>
          <?php endwhile; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var endpoint = <?php echo json_encode($notificationFeedUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

  document.querySelectorAll('[data-notification-link]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      var destinationUrl = link.getAttribute('data-destination-url') || link.getAttribute('href') || '';
      var notificationId = Number(link.getAttribute('data-notification-id') || 0);
      var borrowId = Number(link.getAttribute('data-alert-borrow-id') || 0);
      var isNotificationUnread = link.getAttribute('data-notification-unread') === 'true';
      var isAlertUnread = link.getAttribute('data-alert-unread') === 'true';

      if (!destinationUrl || (!isNotificationUnread && !isAlertUnread)) {
        return;
      }

      event.preventDefault();

      var payload = new URLSearchParams();
      if (isNotificationUnread && notificationId > 0) {
        payload.set('action', 'mark_read');
        payload.set('id', String(notificationId));
      } else if (isAlertUnread && borrowId > 0) {
        payload.set('action', 'mark_alert_read');
        payload.set('borrow_id', String(borrowId));
      } else {
        window.location.assign(destinationUrl);
        return;
      }

      fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: payload.toString()
      })
        .catch(function () {
          return null;
        })
        .finally(function () {
          window.location.assign(destinationUrl);
        });
    });
  });
});
</script>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
</body>
</html>

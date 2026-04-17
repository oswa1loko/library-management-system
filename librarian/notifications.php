<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

$notificationLimit = 60;
$message = '';
$messageType = 'success';

if (isset($_POST['mark_all_read'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE role = 'librarian' AND is_read = 0");
    $message = 'All librarian notifications marked as read.';
    audit_log($conn, 'librarian.notification.read_all');
}

$notifications = $conn->query("
    SELECT id, kind, entity_type, entity_id, batch_ref, title, body, severity, is_read, created_at
    FROM notifications
    WHERE role = 'librarian'
    ORDER BY id DESC
    LIMIT " . (int) $notificationLimit . "
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Librarian Notifications</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="librarian-notifications" data-sidebar-default="expanded" data-sidebar-lock="expanded">
  <?php
  $sidebarPage = 'notifications';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
    <?php
    $pageTitle = 'Notifications';
    $pageSubtitle = 'Borrow, return, and workflow activity for the librarian desk';
    require __DIR__ . '/partials/topbar.php';
    ?>

    <div class="stack">
    <?php if ($message !== ''): ?>
      <div class="notice <?php echo $messageType === 'error' ? 'error' : 'success'; ?>"><?php echo h($message); ?></div>
    <?php endif; ?>

    <div class="panel">
      <div class="toolbar toolbar-top">
        <div class="grow">
          <p class="muted eyebrow-compact">Inbox</p>
          <h3 class="heading-card">Workflow notifications</h3>
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
          <?php
            $destination = notification_destination_for_viewer('librarian', $row);
            $notificationId = (int) ($row['id'] ?? 0);
            $isUnread = (int) ($row['is_read'] ?? 0) === 0;
            $destinationUrl = trim((string) ($destination['url'] ?? ''));
            $destinationLabel = trim((string) ($destination['label'] ?? 'Open notification'));
            $categoryLabel = member_notification_category_label((string) ($row['kind'] ?? ''), (string) ($row['entity_type'] ?? ''), (string) ($row['title'] ?? ''), (string) ($row['body'] ?? ''));
            $relativeTime = format_relative_datetime((string) ($row['created_at'] ?? ''), 'Recent');
            $exactTime = format_display_datetime((string) ($row['created_at'] ?? ''), '-');
            $severityClass = $row['severity'] === 'critical'
              ? 'member-notification-card--critical'
              : ($row['severity'] === 'warning' ? 'member-notification-card--warning' : 'member-notification-card--info');
            $openHref = $destinationUrl !== ''
              ? app_url('librarian/notification_open.php?notification_id=' . $notificationId . '&redirect=' . urlencode($destinationUrl))
              : '';
          ?>
          <?php if ($openHref !== ''): ?>
            <a class="activity-item activity-item-link member-notification-card <?php echo h($severityClass); ?><?php echo $isUnread ? ' is-unread' : ''; ?>" href="<?php echo h($openHref); ?>">
              <div class="member-notification-card-head">
                <span class="chip member-notification-pill"><?php echo h($categoryLabel); ?></span>
                <span class="member-notification-time"><?php echo h($relativeTime); ?></span>
              </div>
              <strong class="member-notification-card-title">
                <span class="status-dot <?php echo h($row['severity'] === 'critical' ? 'unpaid' : ($row['severity'] === 'warning' ? 'due' : 'approved')); ?>"></span>
                <span class="member-notification-card-title-copy"><?php echo h($row['title']); ?></span>
                <?php if ($isUnread): ?><span class="chip member-notification-state-chip">Unread</span><?php endif; ?>
              </strong>
              <div class="meta member-notification-card-copy"><?php echo h($row['body']); ?></div>
              <div class="inline-actions meta-top member-notification-card-meta">
                <span class="muted member-notification-time"><?php echo h($exactTime); ?></span>
                <span class="member-notification-link-cta"><?php echo h($destinationLabel); ?></span>
              </div>
            </a>
          <?php else: ?>
            <div class="activity-item member-notification-card <?php echo h($severityClass); ?><?php echo $isUnread ? ' is-unread' : ''; ?>">
              <div class="member-notification-card-head">
                <span class="chip member-notification-pill"><?php echo h($categoryLabel); ?></span>
                <span class="member-notification-time"><?php echo h($relativeTime); ?></span>
              </div>
              <strong class="member-notification-card-title">
                <span class="status-dot <?php echo h($row['severity'] === 'critical' ? 'unpaid' : ($row['severity'] === 'warning' ? 'due' : 'approved')); ?>"></span>
                <span class="member-notification-card-title-copy"><?php echo h($row['title']); ?></span>
                <?php if ($isUnread): ?><span class="chip member-notification-state-chip">Unread</span><?php endif; ?>
              </strong>
              <div class="meta member-notification-card-copy"><?php echo h($row['body']); ?></div>
              <div class="inline-actions meta-top member-notification-card-meta">
                <span class="muted member-notification-time"><?php echo h($exactTime); ?></span>
              </div>
            </div>
          <?php endif; ?>
        <?php endwhile; ?>
      </div>
    </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
</body>
</html>

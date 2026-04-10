<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$message = '';
$messageType = 'success';
$title = trim((string) ($_POST['title'] ?? ''));
$body = trim((string) ($_POST['body'] ?? ''));
$audience = trim((string) ($_POST['audience'] ?? 'both'));
$severity = 'info';

$allowedAudiences = ['student', 'faculty', 'both'];

if (!in_array($audience, $allowedAudiences, true)) {
    $audience = 'both';
}

if (isset($_POST['send_announcement'])) {
    if ($title === '' || $body === '') {
        $message = 'Title and message are required.';
        $messageType = 'error';
    }

    if ($message === '') {
        $announcementAt = date('Y-m-d H:i:s');
        $targetRoles = $audience === 'both' ? ['student', 'faculty'] : [$audience];
        $recipientIds = [];

        foreach ($targetRoles as $targetRole) {
            $usersStmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE role = ?
                ORDER BY id ASC
            ");
            $usersStmt->bind_param('s', $targetRole);
            $usersStmt->execute();
            $userRows = $usersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $usersStmt->close();

            foreach ($userRows as $userRow) {
                $recipientIds[] = [
                    'user_id' => (int) ($userRow['id'] ?? 0),
                    'role' => $targetRole,
                ];
            }
        }

        if ($recipientIds === []) {
            $message = 'No matching student or faculty accounts were found for this announcement.';
            $messageType = 'error';
        } else {
            $recipientCount = 0;
            $adminUserId = (int) ($_SESSION['user_id'] ?? 0);
            $conn->begin_transaction();

            try {
                $announcementStmt = $conn->prepare("
                    INSERT INTO announcements (audience, title, body, severity, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $announcementStmt->bind_param('ssssis', $audience, $title, $body, $severity, $adminUserId, $announcementAt);
                $announcementStmt->execute();
                $announcementId = (int) $announcementStmt->insert_id;
                $announcementStmt->close();

                $notificationStmt = $conn->prepare("
                    INSERT INTO notifications (role, user_id, kind, entity_type, entity_id, title, body, severity, is_read, created_at)
                    VALUES (?, ?, 'announcement_sent', 'announcement', ?, ?, ?, ?, 0, ?)
                ");

                foreach ($recipientIds as $recipient) {
                    $targetRole = (string) ($recipient['role'] ?? '');
                    $targetUserId = (int) ($recipient['user_id'] ?? 0);
                    if ($targetRole === '' || $targetUserId <= 0) {
                        continue;
                    }

                    $notificationStmt->bind_param('siissss', $targetRole, $targetUserId, $announcementId, $title, $body, $severity, $announcementAt);
                    $notificationStmt->execute();
                    $recipientCount++;
                }

                $notificationStmt->close();
                $conn->commit();

                audit_log($conn, 'admin.announcement.send', [
                    'announcement_id' => $announcementId,
                    'audience' => $audience,
                    'severity' => $severity,
                    'recipient_count' => $recipientCount,
                    'title' => $title,
                ]);
                create_notification(
                    $conn,
                    'admin',
                    'Announcement Sent',
                    'Announcement "' . $title . '" was sent to ' . $recipientCount . ' member account(s).',
                    'info'
                );

                $message = 'Announcement sent to ' . $recipientCount . ' member account(s).';
                $messageType = 'success';
                $title = '';
                $body = '';
                $audience = 'both';
                $severity = 'info';
            } catch (Throwable $e) {
                $conn->rollback();
                $message = 'Unable to send this announcement right now.';
                $messageType = 'error';
            }
        }
    }
}

$announcements = $conn->query("
    SELECT a.id, a.audience, a.title, a.body, a.severity, a.created_at, u.fullname AS created_by_name
    FROM announcements a
    LEFT JOIN users u ON u.id = a.created_by
    ORDER BY a.id DESC
    LIMIT 30
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Announcements</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell admin-shell member-shell js-member-sidebar" data-sidebar-key="admin-announcements" data-sidebar-default="expanded" data-sidebar-lock="expanded">
  <?php
  $sidebarPage = 'announcements';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
    <?php
    $pageTitle = 'Announcements';
    $pageSubtitle = 'Send updates to student and faculty notification icons';
    require __DIR__ . '/partials/topbar.php';
    ?>

    <div class="stack">
      <?php if ($message !== ''): ?>
        <div class="notice <?php echo $messageType === 'error' ? 'error' : 'success'; ?>"><?php echo h($message); ?></div>
      <?php endif; ?>

      <div class="panel">
        <p class="muted eyebrow-compact stack-copy">Broadcast</p>
        <h3 class="heading-card">Create announcement</h3>
        <form method="post" class="stack flow-top-md">
          <div class="grid cards">
            <div>
              <label for="announcement_title">Title</label>
              <input id="announcement_title" type="text" name="title" maxlength="160" value="<?php echo h($title); ?>" placeholder="Library schedule update">
            </div>
            <div>
              <label for="announcement_audience">Audience</label>
              <div class="ui-select-shell">
                <select id="announcement_audience" name="audience" class="ui-select">
                  <option value="both" <?php echo $audience === 'both' ? 'selected' : ''; ?>>Students and Faculty</option>
                  <option value="student" <?php echo $audience === 'student' ? 'selected' : ''; ?>>Students only</option>
                  <option value="faculty" <?php echo $audience === 'faculty' ? 'selected' : ''; ?>>Faculty only</option>
                </select>
                <span class="ui-select-caret" aria-hidden="true"></span>
              </div>
            </div>
          </div>
          <div>
            <label for="announcement_body">Message</label>
            <textarea id="announcement_body" name="body" rows="5" maxlength="500" placeholder="Write the announcement that should appear in the student and faculty notification popups."><?php echo h($body); ?></textarea>
          </div>
          <div class="inline-actions">
            <button type="submit" name="send_announcement" value="1">Send Announcement</button>
          </div>
        </form>
      </div>

      <div class="panel">
        <p class="muted eyebrow-compact stack-copy">History</p>
        <h3 class="heading-card">Recent announcements</h3>
        <div class="activity-feed flow-top-md">
          <?php if (!$announcements || $announcements->num_rows === 0): ?>
            <div class="empty-state">No announcements have been sent yet.</div>
          <?php endif; ?>
          <?php while ($announcement = $announcements->fetch_assoc()): ?>
            <div class="activity-item">
              <strong>
                <span class="status-dot <?php echo h(($announcement['severity'] ?? 'info') === 'critical' ? 'unpaid' : (($announcement['severity'] ?? 'info') === 'warning' ? 'due' : 'approved')); ?>"></span>
                <?php echo h((string) ($announcement['title'] ?? 'Announcement')); ?>
                <span class="chip"><?php echo h(ucfirst((string) ($announcement['audience'] ?? 'both'))); ?></span>
              </strong>
              <div class="meta"><?php echo nl2br(h((string) ($announcement['body'] ?? ''))); ?></div>
              <div class="inline-actions meta-top">
                <span class="muted"><?php echo h(format_display_datetime((string) ($announcement['created_at'] ?? ''))); ?></span>
                <span class="muted">By <?php echo h((string) (($announcement['created_by_name'] ?? '') !== '' ? $announcement['created_by_name'] : 'Admin')); ?></span>
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

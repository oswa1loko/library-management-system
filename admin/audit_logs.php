<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$eventFilter = trim((string) ($_GET['event'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where = ' WHERE 1=1 ';
$types = '';
$params = [];

if ($eventFilter !== '') {
    $where .= ' AND event_name LIKE ? ';
    $types .= 's';
    $params[] = '%' . $eventFilter . '%';
}
if ($roleFilter !== '') {
    $where .= ' AND actor_role = ? ';
    $types .= 's';
    $params[] = $roleFilter;
}

$countSql = 'SELECT COUNT(*) AS total FROM audit_logs' . $where;
$countStmt = $conn->prepare($countSql);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = '
    SELECT id, actor_user_id, actor_role, event_name, context_json, created_at
    FROM audit_logs
' . $where . '
    ORDER BY id DESC
    LIMIT ? OFFSET ?
';
$queryTypes = $types . 'ii';
$queryParams = $params;
$queryParams[] = $perPage;
$queryParams[] = $offset;

$stmt = $conn->prepare($sql);
$stmt->bind_param($queryTypes, ...$queryParams);
$stmt->execute();
$logs = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Logs</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell admin-shell member-shell js-member-sidebar" data-sidebar-key="admin-audit" data-sidebar-default="expanded" data-sidebar-lock="expanded">
  <?php
  $sidebarPage = 'audit';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
    <?php
    $pageTitle = 'Audit Logs';
    $pageSubtitle = 'Critical action history and traceability';
    require __DIR__ . '/partials/topbar.php';
    ?>

  <div class="stack">
    <div class="panel">
      <div class="toolbar toolbar-top">
        <div class="grow">
          <p class="muted eyebrow-compact">Filter</p>
          <h3 class="heading-card">Audit stream</h3>
        </div>
        <form method="get" class="toolbar grow admin-record-filters">
          <div class="grow">
            <label for="event">Event</label>
            <input id="event" name="event" value="<?php echo h($eventFilter); ?>" placeholder="event name contains">
          </div>
          <div>
            <label for="role">Actor role</label>
            <div class="ui-select-shell">
              <select id="role" name="role" class="ui-select">
                <option value="">All roles</option>
                <?php foreach (['admin', 'student', 'faculty', 'librarian', 'system'] as $role): ?>
                  <option value="<?php echo h($role); ?>" <?php echo $roleFilter === $role ? 'selected' : ''; ?>><?php echo h(role_label($role)); ?></option>
                <?php endforeach; ?>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
          </div>
          <div class="inline-actions">
            <button type="submit">Apply</button>
            <a class="button secondary" href="audit_logs.php">Reset</a>
          </div>
        </form>
      </div>

      <div class="table-wrap table-wrap-top">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>When</th>
              <th>Event</th>
              <th>Actor Role</th>
              <th>Actor User ID</th>
              <th>Context</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($logs->num_rows === 0): ?>
              <tr><td colspan="6" class="muted">No audit logs found.</td></tr>
            <?php endif; ?>
            <?php while ($log = $logs->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int) $log['id']; ?></td>
                <td><?php echo h($log['created_at']); ?></td>
                <td><?php echo h($log['event_name']); ?></td>
                <td><span class="badge"><?php echo h(role_label((string) $log['actor_role'])); ?></span></td>
                <td><?php echo $log['actor_user_id'] !== null ? (int) $log['actor_user_id'] : '-'; ?></td>
                <td><code><?php echo h((string) ($log['context_json'] ?? '')); ?></code></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <span class="current">Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?></span>
        <?php if ($page > 1): ?>
          <a class="button secondary" href="?event=<?php echo urlencode($eventFilter); ?>&role=<?php echo urlencode($roleFilter); ?>&page=<?php echo $page - 1; ?>">Previous</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a class="button secondary" href="?event=<?php echo urlencode($eventFilter); ?>&role=<?php echo urlencode($roleFilter); ?>&page=<?php echo $page + 1; ?>">Next</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
</body>
</html>

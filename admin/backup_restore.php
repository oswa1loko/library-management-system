<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$message = '';
$messageType = 'success';
$tables = ['users', 'books', 'borrows', 'penalties', 'payments', 'complaints', 'api_tokens', 'audit_logs', 'notifications'];

function sql_dump_value(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return "'" . $conn->real_escape_string((string) $value) . "'";
}

if (isset($_POST['export_backup'])) {
    $filename = 'librarymanage_backup_' . date('Ymd_His') . '.sql';
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo "-- LibraryManage SQL Backup\n";
    echo "-- Generated at: " . date('Y-m-d H:i:s') . "\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        $createResult = $conn->query("SHOW CREATE TABLE `{$table}`");
        if (!$createResult || $createResult->num_rows === 0) {
            continue;
        }

        $createRow = $createResult->fetch_assoc();
        echo "-- ----------------------------\n";
        echo "-- Table: {$table}\n";
        echo "-- ----------------------------\n";
        echo "DROP TABLE IF EXISTS `{$table}`;\n";
        echo $createRow['Create Table'] . ";\n\n";

        $rows = $conn->query("SELECT * FROM `{$table}`");
        if ($rows && $rows->num_rows > 0) {
            while ($row = $rows->fetch_assoc()) {
                $columns = array_map(static fn($col) => "`{$col}`", array_keys($row));
                $values = array_map(static fn($val) => sql_dump_value($conn, $val), array_values($row));
                echo "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
            }
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    audit_log($conn, 'admin.backup.export', ['tables' => count($tables)]);
    exit;
}

if (isset($_POST['import_backup'])) {
    $file = $_FILES['backup_sql'] ?? null;
    if (!$file || empty($file['tmp_name'])) {
        $message = 'Please upload an SQL backup file.';
        $messageType = 'error';
    } elseif (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'sql') {
        $message = 'Only .sql files are allowed.';
        $messageType = 'error';
    } elseif ((int) ($file['size'] ?? 0) > 20 * 1024 * 1024) {
        $message = 'Backup file must be 20MB or smaller.';
        $messageType = 'error';
    } else {
        $sql = (string) file_get_contents((string) $file['tmp_name']);
        if (trim($sql) === '') {
            $message = 'The uploaded SQL file is empty.';
            $messageType = 'error';
        } else {
            $ok = $conn->multi_query($sql);
            while ($conn->more_results() && $conn->next_result()) {
                // Drain results for multi_query.
            }

            if ($ok) {
                $message = 'Backup restore completed successfully.';
                audit_log($conn, 'admin.backup.import', [
                    'filename' => (string) ($file['name'] ?? ''),
                    'size' => (int) ($file['size'] ?? 0),
                ]);
            } else {
                $message = 'Backup restore failed: ' . $conn->error;
                $messageType = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Backup and Restore</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell admin-shell member-shell js-member-sidebar" data-sidebar-key="admin-backup" data-sidebar-default="expanded" data-sidebar-lock="expanded">
  <?php
  $sidebarPage = 'backup';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Backup and Restore';
  $pageSubtitle = 'Export and restore the library database';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <?php
    $noticeItems = [];
    if ($message !== '') {
        $noticeItems[] = ['type' => $messageType, 'message' => $message];
    }
    require __DIR__ . '/partials/notices.php';
    ?>

    <div class="grid cards">
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Backup</p>
            <h3 class="heading-card">Export SQL backup</h3>
          </div>
        </div>
        <p class="muted">Download a full SQL snapshot of core tables.</p>
        <form method="post" class="inline-actions">
          <button type="submit" name="export_backup" value="1">Download Backup</button>
        </form>
      </div>

      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-upload" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Restore</p>
            <h3 class="heading-card">Import SQL backup</h3>
          </div>
        </div>
        <p class="muted">Upload a previously exported `.sql` backup file (max 20MB).</p>
        <form method="post" enctype="multipart/form-data" class="stack" data-confirm="Importing a backup can overwrite current data. Continue?">
          <div>
            <label for="backup_sql">SQL file</label>
            <input id="backup_sql" type="file" name="backup_sql" accept=".sql" required>
          </div>
          <div class="inline-actions">
            <button type="submit" class="danger" name="import_backup" value="1">Restore Backup</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/shared_confirm.js"></script>
</body>
</html>

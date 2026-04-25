<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$message = '';
$messageType = 'success';
$tables = ['users', 'books', 'borrows', 'penalties', 'payments', 'payment_penalty_links', 'complaints', 'api_tokens', 'audit_logs', 'notifications'];

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

function backup_amounts_match(float $left, float $right): bool
{
    return abs(round($left, 2) - round($right, 2)) < 0.01;
}

function repair_grouped_penalty_payment_links(mysqli $conn): array
{
    $checked = 0;
    $repairedPayments = 0;
    $linkedPenalties = 0;
    $markedPaid = 0;
    $skipped = 0;

    $payments = $conn->query("
        SELECT pay.id, pay.user_id, pay.penalty_id, pay.amount
        FROM payments pay
        LEFT JOIN payment_penalty_links ppl ON ppl.payment_id = pay.id
        WHERE pay.status = 'approved'
          AND (pay.incident_id IS NULL OR pay.incident_id = 0)
          AND pay.penalty_id IS NOT NULL
          AND pay.penalty_id > 0
        GROUP BY pay.id, pay.user_id, pay.penalty_id, pay.amount
        HAVING COUNT(ppl.penalty_id) = 0
        ORDER BY pay.id ASC
    ");

    if (!$payments instanceof mysqli_result) {
        return [
            'checked' => 0,
            'repaired_payments' => 0,
            'linked_penalties' => 0,
            'marked_paid' => 0,
            'skipped' => 0,
        ];
    }

    $representativeStmt = $conn->prepare("
        SELECT
            p.id,
            p.amount,
            p.status,
            br.book_id,
            br.status AS borrow_status
        FROM penalties p
        JOIN borrows br ON br.id = p.borrow_id
        WHERE p.id = ?
          AND p.user_id = ?
        LIMIT 1
    ");
    $groupStmt = $conn->prepare("
        SELECT p.id, p.amount
        FROM penalties p
        JOIN borrows br ON br.id = p.borrow_id
        WHERE p.user_id = ?
          AND br.book_id = ?
          AND p.status = 'unpaid'
          AND br.status = 'returned'
        ORDER BY p.id ASC
    ");
    $linkStmt = $conn->prepare("INSERT IGNORE INTO payment_penalty_links (payment_id, penalty_id) VALUES (?, ?)");

    while ($payment = $payments->fetch_assoc()) {
        $checked++;
        $paymentId = (int) ($payment['id'] ?? 0);
        $userId = (int) ($payment['user_id'] ?? 0);
        $representativePenaltyId = (int) ($payment['penalty_id'] ?? 0);
        $paymentAmount = round((float) ($payment['amount'] ?? 0), 2);

        $representativeStmt->bind_param('ii', $representativePenaltyId, $userId);
        $representativeStmt->execute();
        $representativePenalty = $representativeStmt->get_result()->fetch_assoc();
        if (!$representativePenalty || (string) ($representativePenalty['borrow_status'] ?? '') !== 'returned') {
            $skipped++;
            continue;
        }

        $candidatePenaltyIds = [$representativePenaltyId];
        $candidateAmount = (float) ($representativePenalty['amount'] ?? 0);
        $bookId = (int) ($representativePenalty['book_id'] ?? 0);

        if ($bookId > 0) {
            $groupStmt->bind_param('ii', $userId, $bookId);
            $groupStmt->execute();
            $groupRows = $groupStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($groupRows as $groupRow) {
                $groupPenaltyId = (int) ($groupRow['id'] ?? 0);
                if ($groupPenaltyId <= 0 || $groupPenaltyId === $representativePenaltyId) {
                    continue;
                }
                $candidatePenaltyIds[] = $groupPenaltyId;
                $candidateAmount += (float) ($groupRow['amount'] ?? 0);
            }
        }

        $candidatePenaltyIds = array_values(array_filter(array_unique($candidatePenaltyIds)));
        if ($candidatePenaltyIds === [] || !backup_amounts_match($candidateAmount, $paymentAmount)) {
            $skipped++;
            continue;
        }

        $conn->begin_transaction();
        try {
            foreach ($candidatePenaltyIds as $candidatePenaltyId) {
                $linkStmt->bind_param('ii', $paymentId, $candidatePenaltyId);
                $linkStmt->execute();
                if ($linkStmt->affected_rows > 0) {
                    $linkedPenalties++;
                }
            }

            $placeholders = implode(',', array_fill(0, count($candidatePenaltyIds), '?'));
            $types = str_repeat('i', count($candidatePenaltyIds));
            $markPaid = $conn->prepare("UPDATE penalties SET status = 'paid' WHERE status = 'unpaid' AND id IN ($placeholders)");
            $markPaid->bind_param($types, ...$candidatePenaltyIds);
            $markPaid->execute();
            $markedPaid += max(0, $markPaid->affected_rows);
            $markPaid->close();

            $conn->commit();
            $repairedPayments++;
        } catch (Throwable $exception) {
            $conn->rollback();
            $skipped++;
        }
    }

    $representativeStmt->close();
    $groupStmt->close();
    $linkStmt->close();

    return [
        'checked' => $checked,
        'repaired_payments' => $repairedPayments,
        'linked_penalties' => $linkedPenalties,
        'marked_paid' => $markedPaid,
        'skipped' => $skipped,
    ];
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
            $backupIncludesPaymentPenaltyLinks = stripos($sql, '`payment_penalty_links`') !== false;
            $ok = $conn->multi_query($sql);
            while ($conn->more_results() && $conn->next_result()) {
                // Drain results for multi_query.
            }

            if ($ok) {
                if (!$backupIncludesPaymentPenaltyLinks) {
                    $conn->query('DELETE FROM payment_penalty_links');
                }
                $repairResult = repair_grouped_penalty_payment_links($conn);
                $message = 'Backup restore completed successfully.';
                if (($repairResult['repaired_payments'] ?? 0) > 0) {
                    $message .= ' Grouped penalty links repaired for ' . (int) $repairResult['repaired_payments'] . ' payment(s).';
                }
                audit_log($conn, 'admin.backup.import', [
                    'filename' => (string) ($file['name'] ?? ''),
                    'size' => (int) ($file['size'] ?? 0),
                    'grouped_penalty_repair' => $repairResult,
                ]);
            } else {
                $message = 'Backup restore failed: ' . $conn->error;
                $messageType = 'error';
            }
        }
    }
} elseif (isset($_POST['repair_grouped_penalties'])) {
    $repairResult = repair_grouped_penalty_payment_links($conn);
    $message = 'Grouped penalty repair checked ' . (int) $repairResult['checked'] . ' approved payment(s). '
        . 'Repaired ' . (int) $repairResult['repaired_payments'] . ' payment(s), linked '
        . (int) $repairResult['linked_penalties'] . ' penalt' . ((int) $repairResult['linked_penalties'] === 1 ? 'y' : 'ies')
        . ', and marked ' . (int) $repairResult['marked_paid'] . ' unpaid penalt' . ((int) $repairResult['marked_paid'] === 1 ? 'y' : 'ies') . ' as paid.';
    if ((int) $repairResult['skipped'] > 0) {
        $message .= ' Skipped ' . (int) $repairResult['skipped'] . ' payment(s) that did not match safely.';
        $messageType = (int) $repairResult['repaired_payments'] > 0 ? 'warning' : 'info';
    }
    audit_log($conn, 'admin.backup.repair_grouped_penalties', $repairResult);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Backup and Restore</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
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

      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-penalties" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Repair</p>
            <h3 class="heading-card">Grouped penalty payment links</h3>
          </div>
        </div>
        <p class="muted">Rebuild missing grouped penalty links only when the approved payment amount exactly matches the related returned-book penalties.</p>
        <form method="post" class="inline-actions" data-confirm="Repair safely matched grouped penalty payment links now?">
          <button type="submit" name="repair_grouped_penalties" value="1">Repair Grouped Penalties</button>
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


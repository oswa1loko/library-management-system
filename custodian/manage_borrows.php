<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('custodian');

$msg = '';
$msgType = 'success';
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');
$today = date('Y-m-d');

function create_penalty_if_late(mysqli $conn, int $borrowId, int $userId, string $dueDate, string $returnDate): void
{
    $due = new DateTime($dueDate);
    $returned = new DateTime($returnDate);

    if ($returned <= $due) {
        return;
    }

    $daysLate = (int) $due->diff($returned)->format('%a');
    $amount = $daysLate * 2;

    $check = $conn->prepare("SELECT id, status FROM penalties WHERE borrow_id = ? LIMIT 1");
    $check->bind_param('i', $borrowId);
    $check->execute();
    $penalty = $check->get_result()->fetch_assoc();
    $check->close();

    $reason = "Overdue ({$daysLate} day/s)";

    if ($penalty && ($penalty['status'] ?? '') === 'paid') {
        return;
    }

    if ($penalty) {
        $penaltyId = (int) $penalty['id'];
        $update = $conn->prepare("UPDATE penalties SET amount = ?, reason = ?, status = 'unpaid' WHERE id = ?");
        $update->bind_param('dsi', $amount, $reason, $penaltyId);
        $update->execute();
        $update->close();
        return;
    }

    $insert = $conn->prepare("INSERT INTO penalties (borrow_id, user_id, amount, reason, status) VALUES (?, ?, ?, ?, 'unpaid')");
    $insert->bind_param('iids', $borrowId, $userId, $amount, $reason);
    $insert->execute();
    $insert->close();
}

if (isset($_POST['mark_returned'])) {
    $borrowId = (int) ($_POST['borrow_id'] ?? 0);

    $borrowStmt = $conn->prepare("
        SELECT user_id, book_id, due_date, return_date, status
        FROM borrows
        WHERE id = ?
        LIMIT 1
    ");
    $borrowStmt->bind_param('i', $borrowId);
    $borrowStmt->execute();
    $borrowStmt->bind_result($userId, $bookId, $dueDate, $recordedReturnDate, $borrowStatus);
    $found = $borrowStmt->fetch();
    $borrowStmt->close();

    if (!$found || $borrowStatus !== 'return_requested') {
        $msg = 'Borrow record must have a return request before confirmation.';
        $msgType = 'error';
    } else {
        $returnDate = $recordedReturnDate ?: date('Y-m-d');
        $conn->begin_transaction();

        try {
            $returnStmt = $conn->prepare("
                UPDATE borrows
                SET status = 'returned', return_date = ?
                WHERE id = ? AND status = 'return_requested'
            ");
            $returnStmt->bind_param('si', $returnDate, $borrowId);
            $returnStmt->execute();

            if ($returnStmt->affected_rows !== 1) {
                throw new RuntimeException('Borrow record update failed.');
            }
            $returnStmt->close();

            $stockStmt = $conn->prepare("UPDATE books SET qty_available = qty_available + 1 WHERE id = ?");
            $stockStmt->bind_param('i', $bookId);
            $stockStmt->execute();
            $stockStmt->close();

            create_penalty_if_late($conn, $borrowId, $userId, $dueDate, $returnDate);
            $conn->commit();
            audit_log($conn, 'custodian.borrow.confirm_return', [
                'borrow_id' => $borrowId,
                'book_id' => $bookId,
                'user_id' => $userId,
                'return_date' => $returnDate,
            ]);
            create_notification(
                $conn,
                'admin',
                'Borrow Return Confirmed',
                'Borrow #' . $borrowId . ' was confirmed as returned by a custodian.',
                'info'
            );

            $msg = 'Borrow record marked as returned.';
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = 'Unable to mark this borrow record as returned right now.';
            $msgType = 'error';
        }
    }
}

$summary = $conn->query("
    SELECT
      COUNT(*) AS active_borrows,
      COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_borrows,
      COALESCE(SUM(CASE WHEN status = 'return_requested' THEN 1 ELSE 0 END), 0) AS pending_returns
    FROM borrows
    WHERE status IN ('borrowed', 'return_requested')
")->fetch_assoc();

$sql = "
    SELECT
      br.id,
      br.borrow_date,
      br.due_date,
      br.status,
      u.fullname,
      u.username,
      u.role,
      b.title,
      b.author
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    JOIN books b ON b.id = br.book_id
    WHERE br.status IN ('borrowed', 'return_requested')
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (u.fullname LIKE ? OR u.username LIKE ? OR b.title LIKE ? OR b.author LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $types .= 'ssss';
}

if ($statusFilter === 'overdue') {
    $sql .= " AND br.due_date < CURDATE()";
} elseif ($statusFilter === 'due_today') {
    $sql .= " AND br.due_date = CURDATE()";
}

$sql .= " ORDER BY br.due_date ASC, br.id DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$borrows = $stmt->get_result();

$recentRequests = $conn->query("
    SELECT
      br.id,
      u.username,
      b.title,
      br.due_date
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    JOIN books b ON b.id = br.book_id
    WHERE br.status = 'return_requested'
    ORDER BY br.id DESC
    LIMIT 4
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Active Borrowed Books</title>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css">
</head>
<body>
<div class="site-shell">
  <?php
  $pageTitle = 'Active Borrowed Books';
  $pageSubtitle = 'Books currently out and not yet returned';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <?php
    $noticeItems = [];
    if ($msg !== '') {
        $noticeItems[] = ['type' => $msgType, 'message' => $msg];
    }
    require __DIR__ . '/partials/notices.php';
    ?>

    <div class="panel">
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Active borrow operations</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo (int) ($summary['active_borrows'] ?? 0); ?></strong>
          <span class="muted">Currently borrowed</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($summary['overdue_borrows'] ?? 0); ?></strong>
          <span class="muted">Overdue returns</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($summary['pending_returns'] ?? 0); ?></strong>
          <span class="muted">Pending return requests</span>
        </div>
      </div>
    </div>

    <div class="grid cards">
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-recent" aria-hidden="true"></div>
          <div>
            <span class="chip">Priority Queue</span>
            <h3 class="heading-top-md">Recent return requests</h3>
          </div>
        </div>
        <div class="stack">
          <?php if (!$recentRequests || $recentRequests->num_rows === 0): ?>
            <div class="empty-state">No recent return requests waiting for confirmation.</div>
          <?php endif; ?>
          <?php while ($request = $recentRequests->fetch_assoc()): ?>
            <div class="empty-state">
              <strong class="label-block meta-top-sm"><?php echo h($request['title']); ?></strong>
              <span class="muted"><?php echo h($request['username']); ?> | Due <?php echo h($request['due_date']); ?></span>
            </div>
          <?php endwhile; ?>
        </div>
      </div>
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
          <div>
            <span class="chip">Workflow</span>
            <h3 class="heading-top-md">Borrow handling notes</h3>
          </div>
        </div>
        <div class="stack">
          <div class="empty-state">Only `return requested` records can be confirmed as returned.</div>
          <div class="empty-state">Overdue penalties are automatic at PHP 2.00 per day, and return confirmation restores stock.</div>
          <div class="empty-state">Use search to find borrowers, titles, or due items faster.</div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-books" aria-hidden="true"></div>
        <div class="grow">
          <span class="chip">Records</span>
          <h3 class="heading-top-md heading-card">Borrow Records</h3>
          <p class="muted">Search borrower name, username, title, or author.</p>
        </div>
      </div>
      <div class="toolbar flow-top-md">
        <div class="grow">
        </div>
        <form method="get" class="toolbar grow">
          <div class="grow">
            <label for="search">Search</label>
            <input id="search" name="search" value="<?php echo h($search); ?>" placeholder="Borrower or book">
          </div>
          <div>
            <label for="status">View</label>
            <div class="ui-select-shell">
              <select id="status" name="status" class="ui-select">
                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All active</option>
                <option value="overdue" <?php echo $statusFilter === 'overdue' ? 'selected' : ''; ?>>Overdue only</option>
                <option value="due_today" <?php echo $statusFilter === 'due_today' ? 'selected' : ''; ?>>Due today</option>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
          </div>
          <div class="inline-actions">
            <button type="submit">Apply</button>
            <a class="button secondary" href="manage_borrows.php">Reset</a>
          </div>
        </form>
      </div>

      <div class="table-wrap table-wrap-top">
        <table>
          <thead>
            <tr>
              <th>Borrow ID</th>
              <th>Borrower</th>
              <th>Role</th>
              <th>Book</th>
              <th>Author</th>
              <th>Borrow Date</th>
              <th>Due Date</th>
              <th>State</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($borrows->num_rows === 0): ?>
              <tr><td colspan="9" class="muted">No active borrow records matched your filters.</td></tr>
            <?php endif; ?>
            <?php while ($borrow = $borrows->fetch_assoc()): ?>
              <?php
              $state = $borrow['status'] === 'return_requested' ? 'Awaiting confirmation' : 'On time';
              if ($borrow['status'] !== 'return_requested' && $borrow['due_date'] < $today) {
                  $state = 'Overdue';
              } elseif ($borrow['status'] !== 'return_requested' && $borrow['due_date'] === $today) {
                  $state = 'Due today';
              }
              ?>
              <tr>
                <td><?php echo (int) $borrow['id']; ?></td>
                <td>
                  <strong class="label-block"><?php echo h($borrow['fullname']); ?></strong>
                  <span class="muted"><?php echo h($borrow['username']); ?></span>
                </td>
                <td><span class="badge"><?php echo h(ucfirst($borrow['role'])); ?></span></td>
                <td>
                  <strong class="label-block"><?php echo h($borrow['title']); ?></strong>
                  <span class="muted"><?php echo $borrow['status'] === 'return_requested' ? 'Return requested' : 'Still borrowed'; ?></span>
                </td>
                <td><?php echo h($borrow['author']); ?></td>
                <td><?php echo h($borrow['borrow_date']); ?></td>
                <td><?php echo h($borrow['due_date']); ?></td>
                <td>
                  <span class="badge">
                    <span class="status-dot <?php echo $state === 'Overdue' ? 'overdue' : ($state === 'Due today' ? 'due' : ($borrow['status'] === 'return_requested' ? 'return_requested' : 'approved')); ?>"></span>
                    <?php echo h($state); ?>
                  </span>
                </td>
                <td>
                  <?php if ($borrow['status'] === 'return_requested'): ?>
                    <form method="post" class="inline-form" data-confirm="Confirm that the physical book has been returned?">
                      <input type="hidden" name="borrow_id" value="<?php echo (int) $borrow['id']; ?>">
                      <button type="submit" name="mark_returned" value="1">Confirm Return</button>
                    </form>
                  <?php else: ?>
                    <span class="muted">Waiting for student/faculty</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/shared_confirm.js"></script>
</body>
</html>

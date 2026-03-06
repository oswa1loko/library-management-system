<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_roles(['student', 'faculty']);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$msg = '';
$msgType = 'success';
$role = (string) $_SESSION['role'];
$today = date('Y-m-d');
$historyFilter = trim((string) ($_GET['history'] ?? 'all'));
$historyFilterOptions = ['all', 'overdue', 'due_today', 'active', 'returned'];
if (!in_array($historyFilter, $historyFilterOptions, true)) {
    $historyFilter = 'all';
}

if (isset($_POST['borrow'])) {
    $bookId = (int) ($_POST['book_id'] ?? 0);
    $days = (int) ($_POST['days'] ?? 7);
    $days = max(1, min($days, 30));
    $apiToken = ensure_member_api_token($conn, $userId);

    if ($apiToken === '') {
        $msg = 'Unable to initialize API token right now.';
        $msgType = 'error';
    } else {
        $response = member_api_post_request('borrows/create.php', [
            'book_id' => $bookId,
            'days' => $days,
        ], $apiToken);

        $json = $response['json'] ?? null;
        $isSuccess = is_array($json) && ($json['ok'] ?? false) === true;

        if ($isSuccess) {
            $dueDate = (string) ($json['borrow']['due_date'] ?? '');
            $msg = 'Borrow successful.' . ($dueDate !== '' ? ' Due date: ' . $dueDate : '');
        } else {
            $msg = (string) ($json['error'] ?? '');
            if ($msg === '' && (string) ($response['transport_error'] ?? '') !== '') {
                $msg = 'API request failed: ' . (string) $response['transport_error'];
            }
            if ($msg === '') {
                $msg = 'Unable to borrow this book right now.';
            }
            $msgType = 'error';
        }
    }
}

if (isset($_POST['return_book'])) {
    $borrowId = (int) ($_POST['borrow_id'] ?? 0);
    $apiToken = ensure_member_api_token($conn, $userId);

    if ($apiToken === '') {
        $msg = 'Unable to initialize API token right now.';
        $msgType = 'error';
    } else {
        $response = member_api_post_request('borrows/return_request.php', [
            'borrow_id' => $borrowId,
        ], $apiToken);

        $json = $response['json'] ?? null;
        $isSuccess = is_array($json) && ($json['ok'] ?? false) === true;

        if ($isSuccess) {
            $msg = 'Return request sent. The request date is now recorded while the custodian waits for the physical handover.';
        } else {
            $msg = (string) ($json['error'] ?? '');
            if ($msg === '' && (string) ($response['transport_error'] ?? '') !== '') {
                $msg = 'API request failed: ' . (string) $response['transport_error'];
            }
            if ($msg === '') {
                $msg = 'Unable to send return request right now.';
            }
            $msgType = 'error';
        }
    }
}

$books = $conn->query("SELECT id, title, author, qty_available FROM books ORDER BY title ASC");

$overviewStmt = $conn->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN status = 'borrowed' THEN 1 ELSE 0 END), 0) AS active_borrows,
      COALESCE(SUM(CASE WHEN status = 'return_requested' THEN 1 ELSE 0 END), 0) AS pending_returns,
      COALESCE(SUM(CASE WHEN status IN ('borrowed', 'return_requested') AND due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_borrows
    FROM borrows
    WHERE user_id = ?
");
$overviewStmt->bind_param('i', $userId);
$overviewStmt->execute();
$overview = $overviewStmt->get_result()->fetch_assoc();
$overviewStmt->close();

$catalogStats = $conn->query("
    SELECT
      COUNT(*) AS total_titles,
      COALESCE(SUM(CASE WHEN qty_available > 0 THEN 1 ELSE 0 END), 0) AS available_titles
    FROM books
")->fetch_assoc();

$historySql = "
    SELECT br.id, b.title, br.borrow_date, br.due_date, br.return_date, br.status
    FROM borrows br
    JOIN books b ON b.id = br.book_id
    WHERE br.user_id = ?
";
$historyTypes = 'i';
$historyParams = [$userId];

if ($historyFilter === 'overdue') {
    $historySql .= " AND br.status IN ('borrowed', 'return_requested') AND br.due_date < CURDATE()";
} elseif ($historyFilter === 'due_today') {
    $historySql .= " AND br.status IN ('borrowed', 'return_requested') AND br.due_date = CURDATE()";
} elseif ($historyFilter === 'active') {
    $historySql .= " AND br.status IN ('borrowed', 'return_requested')";
} elseif ($historyFilter === 'returned') {
    $historySql .= " AND br.status = 'returned'";
}

$historySql .= " ORDER BY br.id DESC LIMIT 30";
$history = $conn->prepare($historySql);
$history->bind_param($historyTypes, ...$historyParams);
$history->execute();
$myBorrows = $history->get_result();
$history->close();

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title($role, 'Borrow and Return')); ?></title>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css">
</head>
<body>
<div class="site-shell">
  <div class="topbar">
    <div>
      <h1><?php echo h(ucfirst($role)); ?> Portal</h1>
      <p>Borrow and return books</p>
    </div>
    <div class="topbar-nav">
      <a href="/librarymanage/<?php echo h($role); ?>/dashboard.php">Dashboard</a>
      <a href="/librarymanage/logout.php">Logout</a>
    </div>
  </div>

  <div class="stack">
    <?php if ($msg !== ''): ?>
      <div class="notice <?php echo $msgType === 'error' ? 'error' : 'success'; ?>"><?php echo h($msg); ?></div>
    <?php endif; ?>

    <?php if ($dueSoonBooks->num_rows > 0): ?>
      <div class="notice warning member-workspace-alert">
        <strong class="label-block stack-copy">Upcoming Due Dates</strong>
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

    <div class="panel member-workspace-overview">
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Borrowing workspace</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo (int) ($overview['active_borrows'] ?? 0); ?></strong>
          <span class="muted">Active borrowed books</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($overview['pending_returns'] ?? 0); ?></strong>
          <span class="muted">Pending return confirmations</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($overview['overdue_borrows'] ?? 0); ?></strong>
          <span class="muted">Overdue items</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($catalogStats['available_titles'] ?? 0); ?></strong>
          <span class="muted">Titles available to borrow</span>
        </div>
      </div>
    </div>

    <div class="grid cards member-workspace-grid">
      <div class="panel member-workspace-main">
        <div class="card-head">
          <div class="dashboard-icon icon-books" aria-hidden="true"></div>
          <div>
            <span class="chip">Borrowing</span>
            <h3 class="heading-top-md">Borrow a Book</h3>
          </div>
        </div>
        <p class="muted">Choose a title and set the borrowing period up to 30 days.</p>
        <form method="post" class="stack chips-row member-workspace-form">
          <div>
            <label for="book_id">Book</label>
            <div class="ui-select-shell">
              <select id="book_id" name="book_id" class="ui-select" required>
                <option value="" disabled selected>Select a book</option>
                <?php while ($book = $books->fetch_assoc()): ?>
                  <option value="<?php echo (int) $book['id']; ?>">
                    <?php echo h($book['title']); ?> by <?php echo h($book['author']); ?> (Available: <?php echo (int) $book['qty_available']; ?>)
                  </option>
                <?php endwhile; ?>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
          </div>
          <div>
            <label for="days">Days to borrow</label>
            <input id="days" type="number" name="days" value="7" min="1" max="30">
          </div>
          <div class="inline-actions member-workspace-actions">
            <button type="submit" name="borrow" value="1">Borrow Book</button>
            <span class="muted">Available stock is reduced immediately after a successful borrow.</span>
          </div>
        </form>
      </div>

      <div class="panel member-workspace-side">
        <div class="card-head">
          <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
          <div>
            <span class="chip">Notes</span>
            <h3 class="heading-top-md">Borrowing Notes</h3>
          </div>
        </div>
        <div class="stack">
          <div class="empty-state">Overdue borrows automatically accrue a penalty at PHP 2.00 per day.</div>
          <div class="empty-state">Stock is added back only after the custodian confirms the physical handover.</div>
          <div class="empty-state">Books can be borrowed for up to 30 days per transaction.</div>
        </div>
      </div>
    </div>

    <div class="panel member-workspace-history">
      <div class="card-head">
        <div class="dashboard-icon icon-checklist" aria-hidden="true"></div>
        <div>
          <span class="chip">History</span>
          <h3 class="heading-top-md">My Borrow Records</h3>
        </div>
      </div>
      <p class="muted copy-bottom">Track due dates, recorded return requests, and completed borrow history in one list.</p>
      <div class="inline-actions chips-row">
        <span class="chip">Status = workflow stage (Borrowed, Return Requested, Returned)</span>
        <span class="chip">Borrow State = due-date condition (On Time, Due Today, Overdue)</span>
      </div>
      <form method="get" class="toolbar">
        <div>
          <label for="history_filter">View</label>
          <div class="ui-select-shell">
            <select id="history_filter" name="history" class="ui-select">
              <option value="all" <?php echo $historyFilter === 'all' ? 'selected' : ''; ?>>All records</option>
              <option value="overdue" <?php echo $historyFilter === 'overdue' ? 'selected' : ''; ?>>Overdue only</option>
              <option value="due_today" <?php echo $historyFilter === 'due_today' ? 'selected' : ''; ?>>Due today</option>
              <option value="active" <?php echo $historyFilter === 'active' ? 'selected' : ''; ?>>Active only</option>
              <option value="returned" <?php echo $historyFilter === 'returned' ? 'selected' : ''; ?>>Returned only</option>
            </select>
            <span class="ui-select-caret" aria-hidden="true"></span>
          </div>
        </div>
        <div class="inline-actions">
          <button type="submit">Apply</button>
          <a class="button secondary" href="/librarymanage/<?php echo h($role); ?>/borrow_return.php">Reset</a>
        </div>
      </form>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Book</th>
              <th>Borrow Date</th>
              <th>Due Date</th>
              <th>Return Date</th>
              <th>Borrow State</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($myBorrows->num_rows === 0): ?>
              <tr><td colspan="8" class="muted">No borrow records yet.</td></tr>
            <?php endif; ?>
            <?php while ($record = $myBorrows->fetch_assoc()): ?>
              <?php
              $workflowStatus = (string) ($record['status'] ?? '');
              $derivedLabel = 'Completed';
              $derivedDot = 'approved';

              if ($workflowStatus === 'borrowed') {
                  if ($record['due_date'] < $today) {
                      $derivedLabel = 'Overdue';
                      $derivedDot = 'overdue';
                  } elseif ($record['due_date'] === $today) {
                      $derivedLabel = 'Due Today';
                      $derivedDot = 'due';
                  } else {
                      $derivedLabel = 'On Time';
                      $derivedDot = 'approved';
                  }
              } elseif ($workflowStatus === 'return_requested') {
                  if ($record['due_date'] < $today) {
                      $derivedLabel = 'Overdue (Awaiting confirmation)';
                      $derivedDot = 'overdue';
                  } elseif ($record['due_date'] === $today) {
                      $derivedLabel = 'Due Today (Awaiting confirmation)';
                      $derivedDot = 'due';
                  } else {
                      $derivedLabel = 'Awaiting confirmation';
                      $derivedDot = 'return_requested';
                  }
              } elseif ($workflowStatus === 'returned') {
                  $derivedLabel = 'Returned';
                  $derivedDot = 'approved';
              }
              ?>
              <tr>
                <td><?php echo (int) $record['id']; ?></td>
                <td><?php echo h($record['title']); ?></td>
                <td><?php echo h($record['borrow_date']); ?></td>
                <td><?php echo h($record['due_date']); ?></td>
                <td><?php echo $record['status'] === 'returned' ? h($record['return_date'] ?: '-') : '-'; ?></td>
                <td>
                  <span class="badge">
                    <span class="status-dot <?php echo h($derivedDot); ?>"></span>
                    <?php echo h($derivedLabel); ?>
                  </span>
                </td>
                <td>
                  <span class="badge">
                    <span class="status-dot <?php echo h($record['status']); ?>"></span>
                    <?php echo h(ucfirst(str_replace('_', ' ', $record['status']))); ?>
                  </span>
                </td>
                <td>
                  <?php if ($record['status'] === 'borrowed'): ?>
                    <form method="post" class="inline-form">
                      <input type="hidden" name="borrow_id" value="<?php echo (int) $record['id']; ?>">
                      <button type="submit" name="return_book" value="1">Request Return</button>
                    </form>
                  <?php elseif ($record['status'] === 'return_requested'): ?>
                    <span class="muted">Waiting for custodian</span>
                  <?php else: ?>
                    <span class="muted">Completed</span>
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
</body>
</html>

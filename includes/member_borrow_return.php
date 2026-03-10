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

if (isset($_POST['return_book']) || isset($_POST['return_books'])) {
    $borrowIdsRaw = $_POST['borrow_ids'] ?? [];
    if (!is_array($borrowIdsRaw)) {
        $borrowIdsRaw = [];
    }

    $singleBorrowId = (int) ($_POST['borrow_id'] ?? 0);
    if ($singleBorrowId > 0) {
        $borrowIdsRaw[] = $singleBorrowId;
    }

    $borrowIds = array_values(array_unique(array_filter(array_map('intval', $borrowIdsRaw), static function (int $id): bool {
        return $id > 0;
    })));
    $apiToken = ensure_member_api_token($conn, $userId);

    if ($borrowIds === []) {
        $msg = 'Select at least one borrowed item first.';
        $msgType = 'error';
    } elseif ($apiToken === '') {
        $msg = 'Unable to initialize API token right now.';
        $msgType = 'error';
    } else {
        $response = member_api_post_request('borrows/return_request.php', [
            'borrow_ids' => $borrowIds,
        ], $apiToken);

        $json = $response['json'] ?? null;
        $isSuccess = is_array($json) && ($json['ok'] ?? false) === true;

        if ($isSuccess) {
            $requestedCount = (int) ($json['requested_count'] ?? count($borrowIds));
            $returnBatch = trim((string) ($json['return_batch'] ?? ''));
            $copyLabel = $requestedCount === 1 ? '1 item' : $requestedCount . ' items';
            $msg = 'Return request batch sent for ' . $copyLabel . '. The librarian will confirm only the books physically handed over.';
            if ($returnBatch !== '') {
                $msg .= ' Return batch ref: ' . $returnBatch . '.';
            }
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

$overviewStmt = $conn->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_approvals,
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

$historySql = "
    SELECT br.id, b.title, b.qty_available, br.borrow_date, br.due_date, br.return_date, br.status
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
    $historySql .= " AND br.status IN ('pending', 'borrowed', 'return_requested')";
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

$activeBatchStmt = $conn->prepare("
    SELECT
      br.id,
      br.request_batch,
      br.return_batch,
      br.status,
      br.borrow_date,
      br.due_date,
      b.title,
      b.author
    FROM borrows br
    JOIN books b ON b.id = br.book_id
    WHERE br.user_id = ?
      AND br.status IN ('borrowed', 'return_requested')
    ORDER BY br.borrow_date DESC, br.id ASC
");
$activeBatchStmt->bind_param('i', $userId);
$activeBatchStmt->execute();
$activeBatchRows = $activeBatchStmt->get_result();
$activeBatchStmt->close();

$activeReturnGroups = [];
while ($activeRow = $activeBatchRows->fetch_assoc()) {
    $groupKey = (string) ($activeRow['request_batch'] ?? '');
    if ($groupKey === '') {
        $groupKey = 'legacy-' . (int) $activeRow['id'];
    }

    if (!isset($activeReturnGroups[$groupKey])) {
        $activeReturnGroups[$groupKey] = [
            'request_batch' => $groupKey,
            'borrow_date' => (string) ($activeRow['borrow_date'] ?? ''),
            'total_items' => 0,
            'return_requested_items' => 0,
            'borrowed_items' => 0,
            'items' => [],
        ];
    }

    $activeReturnGroups[$groupKey]['total_items']++;
    if ((string) $activeRow['status'] === 'return_requested') {
        $activeReturnGroups[$groupKey]['return_requested_items']++;
    } else {
        $activeReturnGroups[$groupKey]['borrowed_items']++;
    }

    $activeReturnGroups[$groupKey]['items'][] = $activeRow;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title($role, 'My Borrows and Returns')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<?php $memberBorrowReturnVersion = (string) filemtime(__DIR__ . '/../assets/member_borrow_return.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="<?php echo h($role); ?>-borrows-returns">
  <aside class="panel member-sidebar">
    <div class="member-sidebar-head">
      <button type="button" class="member-sidebar-toggle js-sidebar-toggle" aria-expanded="true" aria-label="Collapse sidebar">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Main Menu</span>
      </button>
    </div>
    <p class="member-sidebar-section member-sidebar-label">Main</p>
    <nav class="member-sidebar-nav">
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/dashboard.php" data-tooltip="Dashboard">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Dashboard</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/books.php" data-tooltip="Books and Borrow">
        <span class="dashboard-icon icon-books" aria-hidden="true"></span>
        <span class="member-sidebar-label">Books / Borrow</span>
      </a>
      <a class="member-sidebar-link is-active" href="/librarymanage/<?php echo h($role); ?>/borrow_return.php" data-tooltip="My Borrows and Returns">
        <span class="dashboard-icon icon-checklist" aria-hidden="true"></span>
        <span class="member-sidebar-label">My Borrows / Returns</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/payment_upload.php" data-tooltip="Payments">
        <span class="dashboard-icon icon-payments" aria-hidden="true"></span>
        <span class="member-sidebar-label">Payments</span>
      </a>
    </nav>
    <p class="member-sidebar-section member-sidebar-label">Account</p>
    <div class="topbar-nav member-sidebar-utilities">
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
        <h1><?php echo h(role_label($role)); ?> Portal</h1>
        <p>Track active borrows, due dates, and return requests</p>
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
              <?php echo h($dueBook['title']); ?> is due on <?php echo h(format_display_date((string) $dueBook['due_date'])); ?>
              <?php if ($dueBook['status'] === 'return_requested'): ?>
                and is waiting for librarian confirmation.
              <?php endif; ?>
            </div>
          <?php endwhile; ?>
        </div>
      <?php endif; ?>

      <div class="panel member-workspace-overview">
        <p class="muted eyebrow-compact stack-copy">Overview</p>
        <h3 class="heading-panel">My borrowing workspace</h3>
        <div class="stat-grid">
          <div class="stat-card">
            <strong><?php echo (int) ($overview['pending_approvals'] ?? 0); ?></strong>
            <span class="muted">Pending borrow approvals</span>
          </div>
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
        </div>
      </div>

      <div class="panel member-workspace-history">
        <div class="card-head">
          <div class="dashboard-icon icon-checklist" aria-hidden="true"></div>
          <div>
            <span class="chip">Return Batches</span>
            <h3 class="heading-top-md">Request Returns by Batch</h3>
          </div>
        </div>
        <p class="muted copy-bottom">Select only the books you are physically handing over now. Partial returns are allowed.</p>
        <div class="stack">
          <?php if ($activeReturnGroups === []): ?>
            <div class="empty-state">No active borrowed items are available for return request right now.</div>
          <?php endif; ?>
          <?php foreach ($activeReturnGroups as $group): ?>
            <?php $singleReturnable = (int) $group['borrowed_items'] === 1; ?>
            <form method="post" class="panel member-return-batch-card" data-return-batch-form<?php echo $singleReturnable ? ' data-return-batch-single="1"' : ''; ?>>
              <div class="member-return-batch-head">
                <div>
                  <strong class="label-block"><?php echo h(format_batch_reference($group['request_batch'], 'Borrow Ref')); ?></strong>
                  <span class="muted">
                    Borrowed on <?php echo h(format_display_date($group['borrow_date'], '-')); ?> |
                    <?php echo (int) $group['return_requested_items']; ?> of <?php echo (int) $group['total_items']; ?> already requested for return
                  </span>
                  <div class="inline-actions chips-row batch-status-row">
                    <span class="chip"><?php echo (int) $group['total_items']; ?> total</span>
                    <span class="chip"><?php echo (int) $group['return_requested_items']; ?> requested</span>
                    <span class="chip"><?php echo (int) $group['borrowed_items']; ?> outstanding</span>
                  </div>
                </div>
                <span class="chip">
                  <?php echo (int) $group['borrowed_items']; ?> still returnable
                </span>
              </div>
              <div class="stack member-return-batch-list">
                <?php foreach ($group['items'] as $item): ?>
                  <label class="empty-state member-return-batch-item">
                    <span class="member-return-batch-check">
                      <?php if ($item['status'] === 'borrowed'): ?>
                        <?php if ($singleReturnable): ?>
                          <input type="hidden" name="borrow_ids[]" value="<?php echo (int) $item['id']; ?>">
                          <span class="member-return-batch-check-label muted">Ready</span>
                        <?php else: ?>
                          <input type="checkbox" name="borrow_ids[]" value="<?php echo (int) $item['id']; ?>" data-return-batch-checkbox>
                        <?php endif; ?>
                      <?php else: ?>
                        <input type="checkbox" checked disabled>
                      <?php endif; ?>
                    </span>
                    <span class="grow">
                      <strong class="label-block meta-top-sm"><?php echo h($item['title']); ?></strong>
                      <span class="muted">
                        <?php echo h($item['author']); ?> |
                        Due <?php echo h(format_display_date((string) $item['due_date'])); ?>
                      </span>
                    </span>
                    <span class="badge">
                      <span class="status-dot <?php echo h($item['status']); ?>"></span>
                      <?php echo h(ucfirst(str_replace('_', ' ', (string) $item['status']))); ?>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="inline-actions member-workspace-actions">
                <button type="submit" name="return_books" value="1" data-return-batch-submit disabled>Request Return for Selected</button>
                <span class="muted" data-return-batch-note>Items already marked as `return requested` stay pending until the librarian confirms physical receipt.</span>
              </div>
            </form>
          <?php endforeach; ?>
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
          <span class="chip">Status = workflow stage (Pending approval, Borrowed, Return requested, Returned)</span>
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
                $waitingForStock = $workflowStatus === 'pending' && (int) ($record['qty_available'] ?? 0) <= 0;

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
                        $derivedLabel = 'Overdue (Awaiting return confirmation)';
                        $derivedDot = 'overdue';
                    } elseif ($record['due_date'] === $today) {
                        $derivedLabel = 'Due Today (Awaiting return confirmation)';
                        $derivedDot = 'due';
                    } else {
                        $derivedLabel = 'Awaiting return confirmation';
                        $derivedDot = 'return_requested';
                    }
                } elseif ($workflowStatus === 'pending') {
                    $derivedLabel = $waitingForStock ? 'Waiting for stock' : 'Pending approval';
                    $derivedDot = $waitingForStock ? 'waiting_stock' : 'pending';
                } elseif ($workflowStatus === 'returned') {
                    $derivedLabel = 'Returned';
                    $derivedDot = 'approved';
                }
                ?>
                <tr>
                  <td><?php echo (int) $record['id']; ?></td>
                  <td><?php echo h($record['title']); ?></td>
                  <td><?php echo h(format_display_date((string) $record['borrow_date'])); ?></td>
                  <td><?php echo $record['status'] === 'pending' ? '-' : h(format_display_date((string) $record['due_date'])); ?></td>
                  <td><?php echo $record['status'] === 'returned' ? h(format_display_date((string) ($record['return_date'] ?: ''), '-')) : '-'; ?></td>
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
                    <?php elseif ($record['status'] === 'pending'): ?>
                      <span class="muted"><?php echo $waitingForStock ? 'Waiting for available copy' : 'Pending approval'; ?></span>
                    <?php elseif ($record['status'] === 'return_requested'): ?>
                      <span class="muted">Awaiting return confirmation</span>
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
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/member_borrow_return.js?v=<?php echo urlencode($memberBorrowReturnVersion); ?>"></script>
</body>
</html>

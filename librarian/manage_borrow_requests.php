<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/borrow_workflow.php';

require_role('librarian');

$workflowNotice = handle_librarian_borrow_workflow($conn);
$msg = (string) ($workflowNotice['message'] ?? '');
$msgType = (string) ($workflowNotice['type'] ?? 'success');
$search = trim((string) ($_GET['search'] ?? ''));
$selectedRequestBatch = trim((string) ($_GET['request_batch'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

function render_student_approval_days_control(string $role): void
{
    if ($role !== 'student') {
        return;
    }
    ?>
    <label class="approval-days-control">
      <span class="muted">Approval duration</span>
      <select name="approval_days">
        <option value="7">7 days</option>
        <option value="5">5 days</option>
      </select>
    </label>
    <?php
}

$pendingBatches = [];
$pendingBatchSql = "
    SELECT
      br.request_batch,
      br.requested_at,
      b.id AS book_id,
      b.qty_available,
      u.id AS user_id,
      u.fullname,
      u.username,
      u.role,
      b.title,
      b.author,
      br.id AS borrow_id
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    JOIN books b ON b.id = br.book_id
    WHERE br.status = 'pending'
";
$pendingBatchParams = [];
$pendingBatchTypes = '';
if ($search !== '') {
    $pendingBatchSql .= " AND (br.request_batch LIKE ? OR u.fullname LIKE ? OR u.username LIKE ? OR u.role LIKE ? OR b.title LIKE ? OR b.author LIKE ? OR CAST(br.id AS CHAR) LIKE ?)";
    $term = '%' . $search . '%';
    array_push($pendingBatchParams, $term, $term, $term, $term, $term, $term, $term);
    $pendingBatchTypes .= 'sssssss';
}
$pendingBatchSql .= " ORDER BY br.created_at DESC, br.id ASC";

$pendingBatchRows = false;
if ($pendingBatchParams !== []) {
    $pendingBatchStmt = $conn->prepare($pendingBatchSql);
    if ($pendingBatchStmt) {
        $pendingBatchStmt->bind_param($pendingBatchTypes, ...$pendingBatchParams);
        $pendingBatchStmt->execute();
        $pendingBatchRows = $pendingBatchStmt->get_result();
    }
} else {
    $pendingBatchRows = $conn->query($pendingBatchSql);
}

if ($pendingBatchRows instanceof mysqli_result) {
    while ($row = $pendingBatchRows->fetch_assoc()) {
        $batchKey = (string) ($row['request_batch'] ?? '');
        if ($batchKey === '') {
            $batchKey = 'legacy-' . (int) $row['borrow_id'];
        }

        $searchHaystack = implode(' ', [
            (string) ($row['request_batch'] ?? ''),
            (string) ($row['fullname'] ?? ''),
            (string) ($row['username'] ?? ''),
            (string) ($row['role'] ?? ''),
            (string) ($row['title'] ?? ''),
            (string) ($row['author'] ?? ''),
            (string) ($row['borrow_id'] ?? ''),
        ]);
        if ($search !== '' && stripos($searchHaystack, $search) === false) {
            continue;
        }

        if (!isset($pendingBatches[$batchKey])) {
            $pendingBatches[$batchKey] = [
                'request_batch' => $batchKey,
                'created_at' => (string) ($row['requested_at'] ?? ''),
                'fullname' => (string) ($row['fullname'] ?? ''),
                'username' => (string) ($row['username'] ?? ''),
                'role' => (string) ($row['role'] ?? ''),
                'actionable_items' => 0,
                'waiting_stock_items' => 0,
                'actionable_copies' => 0,
                'waiting_stock_copies' => 0,
                'items' => [],
            ];
        }

        $itemGroupKey = (int) ($row['book_id'] ?? 0);
        if (!isset($pendingBatches[$batchKey]['items'][$itemGroupKey])) {
            $pendingBatches[$batchKey]['items'][$itemGroupKey] = [
                'book_id' => (int) $row['book_id'],
                'title' => (string) ($row['title'] ?? ''),
                'author' => (string) ($row['author'] ?? ''),
                'qty_available' => (int) ($row['qty_available'] ?? 0),
                'copy_count' => 0,
                'borrow_ids' => [],
            ];
        }

        $pendingBatches[$batchKey]['items'][$itemGroupKey]['copy_count']++;
        $pendingBatches[$batchKey]['items'][$itemGroupKey]['borrow_ids'][] = (int) $row['borrow_id'];
    }
}

foreach ($pendingBatches as &$batch) {
    $batch['items'] = array_values($batch['items']);
    foreach ($batch['items'] as &$item) {
        $item['waiting_for_stock'] = (int) ($item['qty_available'] ?? 0) < (int) ($item['copy_count'] ?? 0);
        if ($item['waiting_for_stock']) {
            $batch['waiting_stock_items']++;
            $batch['waiting_stock_copies'] += (int) $item['copy_count'];
        } else {
            $batch['actionable_items']++;
            $batch['actionable_copies'] += (int) $item['copy_count'];
        }
    }
}
unset($batch, $item);

$totalPendingBatches = count($pendingBatches);
$totalPages = max(1, (int) ceil($totalPendingBatches / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$visiblePendingBatches = array_slice($pendingBatches, $offset, $perPage, true);

$pendingCount = 0;
foreach ($visiblePendingBatches as $batch) {
    foreach ($batch['items'] as $item) {
        $pendingCount += (int) ($item['copy_count'] ?? 0);
    }
}

$allPendingCount = 0;
foreach ($pendingBatches as $batch) {
    foreach ($batch['items'] as $item) {
        $allPendingCount += (int) ($item['copy_count'] ?? 0);
    }
}

$pageQuery = $_GET;
$selectedPendingBatch = $selectedRequestBatch !== '' ? ($pendingBatches[$selectedRequestBatch] ?? null) : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Pending Borrow Requests')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell librarian-shell member-shell js-member-sidebar" data-sidebar-key="librarian-borrow-requests" data-sidebar-default="expanded">
  <?php
  $sidebarPage = 'borrow_requests';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Pending Borrow Requests';
  $pageSubtitle = 'Approve and release available borrow requests';
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
      <p class="muted eyebrow-compact stack-copy">Queue Overview</p>
      <h3 class="heading-panel">Borrow approval queue</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo $totalPendingBatches; ?></strong>
          <span class="muted"><?php echo $search !== '' ? 'Matching request batches' : 'Pending request batches'; ?></span>
        </div>
        <div class="stat-card">
          <strong><?php echo $allPendingCount; ?></strong>
          <span class="muted"><?php echo $search !== '' ? 'Matching borrow items' : 'Pending borrow items'; ?></span>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-checklist" aria-hidden="true"></div>
        <div class="grow">
          <span class="chip">Release Queue</span>
          <h3 class="heading-top-md heading-card">Pending Borrow Approvals</h3>
          <p class="muted">Approve and release only the requests with stock available right now.</p>
        </div>
      </div>
      <form method="get" class="borrow-records-toolbar librarian-queue-searchbar flow-top-md">
        <div class="grow">
          <label for="borrow_request_search">Search pending requests</label>
          <input
            id="borrow_request_search"
            type="search"
            name="search"
            value="<?php echo h($search); ?>"
            placeholder="Search by student, book title, request ref, or borrow ID"
          >
        </div>
        <div class="inline-actions">
          <button type="submit">Search</button>
          <?php if ($search !== ''): ?>
            <a class="button secondary" href="manage_borrow_requests.php">Clear</a>
          <?php endif; ?>
        </div>
      </form>
      <div class="inline-actions flow-top-sm">
        <span class="muted">
          Showing <?php echo $totalPendingBatches === 0 ? 0 : ($offset + 1); ?>-<?php echo min($offset + $perPage, $totalPendingBatches); ?>
          of <?php echo $totalPendingBatches; ?> request batches
        </span>
        <?php if ($totalPages > 1): ?>
          <?php
          $previousQuery = $pageQuery;
          $previousQuery['page'] = max(1, $page - 1);
          $nextQuery = $pageQuery;
          $nextQuery['page'] = min($totalPages, $page + 1);
          ?>
          <a class="button secondary<?php echo $page <= 1 ? ' is-disabled' : ''; ?>" href="<?php echo $page <= 1 ? '#' : ('manage_borrow_requests.php?' . h(http_build_query($previousQuery))); ?>"<?php echo $page <= 1 ? ' aria-disabled="true"' : ''; ?>>Previous</a>
          <span class="badge">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
          <a class="button secondary<?php echo $page >= $totalPages ? ' is-disabled' : ''; ?>" href="<?php echo $page >= $totalPages ? '#' : ('manage_borrow_requests.php?' . h(http_build_query($nextQuery))); ?>"<?php echo $page >= $totalPages ? ' aria-disabled="true"' : ''; ?>>Next</a>
        <?php endif; ?>
      </div>
      <div class="grid cards flow-top-md librarian-borrow-batch-grid">
        <?php if ($visiblePendingBatches === []): ?>
          <div class="empty-state">No pending borrow batches right now.</div>
        <?php endif; ?>
        <?php foreach ($visiblePendingBatches as $batch): ?>
          <?php
          $batchTitles = array_map(static function (array $item): string {
              $title = (string) ($item['title'] ?? '');
              $copyCount = (int) ($item['copy_count'] ?? 0);
              return $copyCount > 1 ? $title . ' x' . $copyCount : $title;
          }, $batch['items']);
          $batchTitlePreview = implode(', ', array_slice($batchTitles, 0, 3));
          if (count($batchTitles) > 3) {
              $batchTitlePreview .= ' +' . (count($batchTitles) - 3) . ' more';
          }
          $totalCopies = array_sum(array_map(static fn(array $item): int => (int) ($item['copy_count'] ?? 0), $batch['items']));
          $batchStateClass = (int) ($batch['actionable_items'] ?? 0) > 0 ? 'is-actionable' : 'is-blocked';
          $batchStateLabel = (int) ($batch['actionable_items'] ?? 0) > 0 ? 'Ready To Release' : 'Waiting For Stock';
          $expiresAt = date('Y-m-d H:i:s', strtotime((string) ($batch['created_at'] ?? 'now') . ' +5 days'));
          ?>
          <div class="panel librarian-batch-card librarian-batch-summary-card <?php echo h($batchStateClass); ?>">
            <div class="librarian-batch-head">
              <div>
                <strong class="label-block"><?php echo h($batch['fullname']); ?></strong>
                <span class="muted"><?php echo h($batch['username']); ?> | <?php echo h(role_label($batch['role'])); ?> | <?php echo h(format_batch_reference($batch['request_batch'], 'Request Ref')); ?></span>
                <div class="librarian-batch-status-line">
                  <span class="badge librarian-batch-status-badge <?php echo h($batchStateClass); ?>">
                    <span class="status-dot <?php echo (int) ($batch['actionable_items'] ?? 0) > 0 ? 'approved' : 'waiting_stock'; ?>"></span>
                    <?php echo h($batchStateLabel); ?>
                  </span>
                </div>
                <p class="librarian-batch-preview">
                  <span class="muted">Books requested:</span>
                  <?php echo h($batchTitlePreview); ?>
                </p>
              </div>
              <div class="librarian-batch-meta">
                <span class="chip">Requested <?php echo h(format_display_datetime($batch['created_at'], '-')); ?></span>
                <span class="chip">Expires <?php echo h(format_display_datetime($expiresAt, '-')); ?></span>
              </div>
            </div>
            <div class="inline-actions chips-row batch-status-row">
              <span class="chip"><?php echo $totalCopies; ?> copie<?php echo $totalCopies === 1 ? '' : 's'; ?></span>
              <span class="chip"><?php echo (int) ($batch['actionable_copies'] ?? 0); ?> ready to release</span>
              <span class="chip"><?php echo (int) ($batch['waiting_stock_copies'] ?? 0); ?> waiting for stock</span>
            </div>
            <div class="stack librarian-batch-list flow-top-md">
              <?php foreach ($batch['items'] as $item): ?>
                <div class="empty-state librarian-batch-item<?php echo !empty($item['waiting_for_stock']) ? ' is-blocked' : ' is-actionable'; ?>">
                  <div>
                    <strong class="label-block meta-top-sm"><?php echo h($item['title']); ?></strong>
                    <span class="muted"><?php echo h($item['author']); ?> | <?php echo (int) ($item['copy_count'] ?? 0); ?> copie<?php echo (int) ($item['copy_count'] ?? 0) === 1 ? '' : 's'; ?> requested<?php echo !empty($item['waiting_for_stock']) ? ' | Waiting for enough stock' : ' | Ready to release'; ?></span>
                  </div>
                  <?php if (!empty($item['waiting_for_stock'])): ?>
                    <span class="badge">
                      <span class="status-dot waiting_stock"></span>
                      Waiting for stock
                    </span>
                  <?php else: ?>
                    <form method="post" class="inline-form" data-confirm="Approve all requested copies of this book and release them now?">
                      <input type="hidden" name="request_batch" value="<?php echo h($batch['request_batch']); ?>">
                      <input type="hidden" name="book_id" value="<?php echo (int) $item['book_id']; ?>">
                      <?php render_student_approval_days_control((string) ($batch['role'] ?? '')); ?>
                      <button type="submit" name="approve_borrow_group" value="1">Approve <?php echo (int) ($item['copy_count'] ?? 0); ?> Cop<?php echo (int) ($item['copy_count'] ?? 0) === 1 ? 'y' : 'ies'; ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if ((int) ($batch['actionable_items'] ?? 0) > 1): ?>
              <div class="librarian-batch-primary-action flow-top-md">
                <form method="post" class="inline-form" data-confirm="Approve all available requests in this batch now?">
                  <input type="hidden" name="request_batch" value="<?php echo h($batch['request_batch']); ?>">
                  <?php render_student_approval_days_control((string) ($batch['role'] ?? '')); ?>
                  <button type="submit" name="approve_batch" value="1">Approve <?php echo (int) $batch['actionable_items']; ?> and Release</button>
                </form>
                <p class="muted meta-top-sm">Bulk approval releases all book groups in this batch that have enough stock for their requested copies.</p>
              </div>
            <?php elseif ((int) ($batch['actionable_items'] ?? 0) === 0 && (int) ($batch['waiting_stock_items'] ?? 0) > 0): ?>
              <div class="librarian-batch-primary-action is-blocked flow-top-md">
                <p class="muted meta-top-sm">This batch is still pending, but every remaining book group is waiting for enough stock.</p>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  </div>
<?php if ($selectedPendingBatch): ?>
  <div class="desk-modal" data-desk-modal>
    <a class="desk-modal-backdrop" href="manage_borrow_requests.php" aria-label="Close borrow request details"></a>
    <div class="desk-modal-dialog panel" role="dialog" aria-modal="true" aria-labelledby="borrow-request-modal-title">
      <div class="desk-modal-head">
        <div>
          <p class="muted eyebrow-compact">Borrow Approval Batch</p>
          <h3 id="borrow-request-modal-title" class="heading-card"><?php echo h(format_batch_reference($selectedPendingBatch['request_batch'], 'Request Ref')); ?></h3>
          <p class="muted">Approve and release only the requests in this batch that still have enough stock available.</p>
        </div>
        <a class="button secondary" href="manage_borrow_requests.php">Close</a>
      </div>

      <div class="panel">
        <div class="librarian-batch-head">
          <div>
            <strong class="label-block"><?php echo h($selectedPendingBatch['fullname']); ?></strong>
            <span class="muted"><?php echo h($selectedPendingBatch['username']); ?> | <?php echo h(role_label($selectedPendingBatch['role'])); ?></span>
          </div>
          <div class="librarian-batch-meta">
            <span class="chip"><?php echo (int) ($selectedPendingBatch['actionable_copies'] ?? 0); ?> ready to release</span>
            <span class="chip"><?php echo (int) ($selectedPendingBatch['waiting_stock_copies'] ?? 0); ?> waiting for stock</span>
            <span class="chip">Requested <?php echo h(format_display_datetime((string) ($selectedPendingBatch['created_at'] ?? ''), '-')); ?></span>
            <span class="chip">Expires <?php echo h(format_display_datetime(date('Y-m-d H:i:s', strtotime((string) ($selectedPendingBatch['created_at'] ?? 'now') . ' +5 days')), '-')); ?></span>
          </div>
        </div>
        <div class="stack librarian-batch-list">
          <?php foreach ($selectedPendingBatch['items'] as $item): ?>
            <div class="empty-state librarian-batch-item<?php echo !empty($item['waiting_for_stock']) ? ' is-blocked' : ' is-actionable'; ?>">
              <div>
                <strong class="label-block meta-top-sm"><?php echo h($item['title']); ?></strong>
                <span class="muted"><?php echo h($item['author']); ?> | <?php echo (int) ($item['copy_count'] ?? 0); ?> copie<?php echo (int) ($item['copy_count'] ?? 0) === 1 ? '' : 's'; ?> requested<?php echo !empty($item['waiting_for_stock']) ? ' | Waiting for enough stock' : ' | Ready to release'; ?></span>
              </div>
              <?php if (!empty($item['waiting_for_stock'])): ?>
                <span class="badge">
                  <span class="status-dot waiting_stock"></span>
                  Waiting for stock
                </span>
              <?php else: ?>
                <form method="post" class="inline-form" data-confirm="Approve all requested copies of this book and release them now?">
                  <input type="hidden" name="request_batch" value="<?php echo h($selectedPendingBatch['request_batch']); ?>">
                  <input type="hidden" name="book_id" value="<?php echo (int) $item['book_id']; ?>">
                  <?php render_student_approval_days_control((string) ($selectedPendingBatch['role'] ?? '')); ?>
                  <button type="submit" name="approve_borrow_group" value="1">Approve <?php echo (int) ($item['copy_count'] ?? 0); ?> Cop<?php echo (int) ($item['copy_count'] ?? 0) === 1 ? 'y' : 'ies'; ?></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ((int) ($selectedPendingBatch['actionable_items'] ?? 0) > 1): ?>
          <form method="post" class="inline-form flow-top-md" data-confirm="Approve all available requests in this batch now?">
            <input type="hidden" name="request_batch" value="<?php echo h($selectedPendingBatch['request_batch']); ?>">
            <?php render_student_approval_days_control((string) ($selectedPendingBatch['role'] ?? '')); ?>
            <button type="submit" name="approve_batch" value="1">Approve <?php echo (int) $selectedPendingBatch['actionable_items']; ?> and Release</button>
          </form>
          <p class="muted meta-top-sm">Bulk approval releases all book groups in this batch that have enough stock for their requested copies.</p>
        <?php elseif ((int) ($selectedPendingBatch['actionable_items'] ?? 0) === 0 && (int) ($selectedPendingBatch['waiting_stock_items'] ?? 0) > 0): ?>
          <p class="muted meta-top-sm">This batch is still pending, but every remaining book group is waiting for enough stock.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/shared_confirm.js"></script>
<script src="/librarymanage/assets/email_queue_worker.js"></script>
</body>
</html>

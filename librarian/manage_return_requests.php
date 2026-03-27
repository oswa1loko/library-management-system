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
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

$returnBatchStats = [];
$returnBatchStatsRows = $conn->query("
    SELECT
      request_batch,
      COUNT(*) AS total_items,
      SUM(CASE WHEN status = 'return_requested' THEN 1 ELSE 0 END) AS requested_items,
      SUM(CASE WHEN status = 'borrowed' THEN 1 ELSE 0 END) AS outstanding_items
    FROM borrows
    GROUP BY request_batch
");
if ($returnBatchStatsRows instanceof mysqli_result) {
    while ($row = $returnBatchStatsRows->fetch_assoc()) {
        $returnBatchStats[(string) ($row['request_batch'] ?? '')] = $row;
    }
}

$pendingReturnBatches = [];
$pendingReturnSql = "
    SELECT
      br.id AS borrow_id,
      b.id AS book_id,
      br.return_batch,
      br.request_batch,
      br.return_requested_at AS request_date,
      u.fullname,
      u.username,
      u.role,
      b.title,
      b.author
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    JOIN books b ON b.id = br.book_id
    WHERE br.status = 'return_requested'
      AND br.return_batch IS NOT NULL
      AND br.return_batch <> ''
";
$pendingReturnParams = [];
$pendingReturnTypes = '';
if ($search !== '') {
    $pendingReturnSql .= " AND (br.return_batch LIKE ? OR br.request_batch LIKE ? OR u.fullname LIKE ? OR u.username LIKE ? OR u.role LIKE ? OR b.title LIKE ? OR b.author LIKE ? OR CAST(br.id AS CHAR) LIKE ?)";
    $term = '%' . $search . '%';
    array_push($pendingReturnParams, $term, $term, $term, $term, $term, $term, $term, $term);
    $pendingReturnTypes .= 'ssssssss';
}
$pendingReturnSql .= " ORDER BY br.return_requested_at DESC, br.id ASC";

$pendingReturnRows = false;
if ($pendingReturnParams !== []) {
    $pendingReturnStmt = $conn->prepare($pendingReturnSql);
    if ($pendingReturnStmt) {
        $pendingReturnStmt->bind_param($pendingReturnTypes, ...$pendingReturnParams);
        $pendingReturnStmt->execute();
        $pendingReturnRows = $pendingReturnStmt->get_result();
    }
} else {
    $pendingReturnRows = $conn->query($pendingReturnSql);
}
if ($pendingReturnRows instanceof mysqli_result) {
    while ($row = $pendingReturnRows->fetch_assoc()) {
        $batchKey = (string) ($row['return_batch'] ?? '');
        $searchHaystack = implode(' ', [
            (string) ($row['return_batch'] ?? ''),
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
        if (!isset($pendingReturnBatches[$batchKey])) {
            $requestBatchKey = (string) ($row['request_batch'] ?? '');
            $stats = $returnBatchStats[$requestBatchKey] ?? ['total_items' => 0, 'requested_items' => 0, 'outstanding_items' => 0];
            $pendingReturnBatches[$batchKey] = [
                'return_batch' => $batchKey,
                'request_batch' => $requestBatchKey,
                'request_date' => (string) ($row['request_date'] ?? ''),
                'fullname' => (string) ($row['fullname'] ?? ''),
                'username' => (string) ($row['username'] ?? ''),
                'role' => (string) ($row['role'] ?? ''),
                'total_items' => (int) ($stats['total_items'] ?? 0),
                'requested_items' => (int) ($stats['requested_items'] ?? 0),
                'outstanding_items' => (int) ($stats['outstanding_items'] ?? 0),
                'items' => [],
                'book_labels' => [],
            ];
        }

        $itemGroupKey = (string) ($row['book_id'] ?? 0);
        if (!isset($pendingReturnBatches[$batchKey]['items'][$itemGroupKey])) {
            $pendingReturnBatches[$batchKey]['items'][$itemGroupKey] = [
                'book_id' => (int) ($row['book_id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'author' => (string) ($row['author'] ?? ''),
                'copy_count' => 0,
                'borrow_ids' => [],
            ];
            $pendingReturnBatches[$batchKey]['book_labels'][] = (string) ($row['title'] ?? '');
        }

        $pendingReturnBatches[$batchKey]['items'][$itemGroupKey]['copy_count']++;
        $pendingReturnBatches[$batchKey]['items'][$itemGroupKey]['borrow_ids'][] = (int) $row['borrow_id'];
    }
}

$totalPendingReturnBatches = count($pendingReturnBatches);
$totalPages = max(1, (int) ceil($totalPendingReturnBatches / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$visiblePendingReturnBatches = array_slice($pendingReturnBatches, $offset, $perPage, true);

$pendingReturnCount = 0;
foreach ($visiblePendingReturnBatches as $batch) {
    $pendingReturnCount += (int) ($batch['requested_items'] ?? 0);
}

$allPendingReturnCount = 0;
foreach ($pendingReturnBatches as $batch) {
    $allPendingReturnCount += (int) ($batch['requested_items'] ?? 0);
}

$pageQuery = $_GET;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Return Requests')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell librarian-shell member-shell js-member-sidebar" data-sidebar-key="librarian-return-requests" data-sidebar-default="expanded">
  <?php
  $sidebarPage = 'return_requests';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Return Requests';
  $pageSubtitle = 'Confirm physical returns and restore stock';
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
      <h3 class="heading-panel">Return confirmation queue</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo $totalPendingReturnBatches; ?></strong>
          <span class="muted"><?php echo $search !== '' ? 'Matching return batches' : 'Pending return batches'; ?></span>
        </div>
        <div class="stat-card">
          <strong><?php echo $allPendingReturnCount; ?></strong>
          <span class="muted"><?php echo $search !== '' ? 'Matching return items' : 'Items waiting for return confirmation'; ?></span>
        </div>
      </div>
    </div>

    <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-recent" aria-hidden="true"></div>
          <div class="grow">
            <span class="chip">Today&apos;s Priority</span>
            <h3 class="heading-top-md heading-card">Pending Return Batches</h3>
          <p class="muted">Confirm physical returns first so copies go back into stock immediately.</p>
        </div>
      </div>
      <form method="get" class="borrow-records-toolbar librarian-queue-searchbar flow-top-md">
        <div class="grow">
          <label for="return_request_search">Search return requests</label>
          <input
            id="return_request_search"
            type="search"
            name="search"
            value="<?php echo h($search); ?>"
            placeholder="Search by student, book title, return ref, or borrow ID"
          >
        </div>
        <div class="inline-actions">
          <button type="submit">Search</button>
          <?php if ($search !== ''): ?>
            <a class="button secondary" href="manage_return_requests.php">Clear</a>
          <?php endif; ?>
        </div>
      </form>
      <div class="inline-actions flow-top-sm">
        <span class="muted">
          Showing <?php echo $totalPendingReturnBatches === 0 ? 0 : ($offset + 1); ?>-<?php echo min($offset + $perPage, $totalPendingReturnBatches); ?>
          of <?php echo $totalPendingReturnBatches; ?> return batches
        </span>
        <?php if ($totalPages > 1): ?>
          <?php
          $previousQuery = $pageQuery;
          $previousQuery['page'] = max(1, $page - 1);
          $nextQuery = $pageQuery;
          $nextQuery['page'] = min($totalPages, $page + 1);
          ?>
          <a class="button secondary<?php echo $page <= 1 ? ' is-disabled' : ''; ?>" href="<?php echo $page <= 1 ? '#' : ('manage_return_requests.php?' . h(http_build_query($previousQuery))); ?>"<?php echo $page <= 1 ? ' aria-disabled="true"' : ''; ?>>Previous</a>
          <span class="badge">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
          <a class="button secondary<?php echo $page >= $totalPages ? ' is-disabled' : ''; ?>" href="<?php echo $page >= $totalPages ? '#' : ('manage_return_requests.php?' . h(http_build_query($nextQuery))); ?>"<?php echo $page >= $totalPages ? ' aria-disabled="true"' : ''; ?>>Next</a>
        <?php endif; ?>
      </div>
      <div class="grid cards flow-top-md librarian-borrow-batch-grid">
          <?php if ($visiblePendingReturnBatches === []): ?>
            <div class="empty-state">No pending return batches right now.</div>
          <?php endif; ?>
          <?php foreach ($visiblePendingReturnBatches as $batch): ?>
            <div class="panel librarian-batch-card librarian-batch-summary-card">
              <div class="librarian-batch-head">
                <div>
                  <strong class="label-block"><?php echo h($batch['fullname']); ?></strong>
                  <span class="muted"><?php echo h($batch['username']); ?> | <?php echo h(role_label($batch['role'])); ?> | <?php echo h(format_batch_reference($batch['return_batch'], 'Return Ref')); ?></span>
                  <?php if (!empty($batch['book_labels'])): ?>
                    <p class="librarian-batch-preview">
                      <span class="muted">Books requested:</span>
                      <?php echo h(implode(', ', $batch['book_labels'])); ?>
                    </p>
                  <?php endif; ?>
                </div>
                <div class="librarian-batch-meta">
                  <span class="chip">Requested <?php echo h(format_display_datetime($batch['request_date'], '-')); ?></span>
                </div>
              </div>
              <div class="inline-actions chips-row batch-status-row">
                <span class="chip"><?php echo (int) $batch['total_items']; ?> total</span>
                <span class="chip"><?php echo (int) $batch['requested_items']; ?> requested</span>
                <span class="chip"><?php echo (int) $batch['outstanding_items']; ?> outstanding</span>
              </div>
              <div class="stack librarian-batch-list flow-top-md">
                <?php foreach ($batch['items'] as $item): ?>
                  <div class="empty-state librarian-batch-item">
                    <div>
                      <strong class="label-block meta-top-sm"><?php echo h($item['title']); ?></strong>
                      <span class="muted">
                        <?php echo h($item['author']); ?> |
                        <?php echo (int) ($item['copy_count'] ?? 0); ?> <?php echo (int) ($item['copy_count'] ?? 0) === 1 ? 'copy' : 'copies'; ?> requested |
                        Awaiting physical return
                      </span>
                    </div>
                    <form method="post" class="inline-form" data-confirm="Confirm that all requested copies of this book have been physically returned?">
                      <input type="hidden" name="return_batch" value="<?php echo h($batch['return_batch']); ?>">
                      <input type="hidden" name="book_id" value="<?php echo (int) ($item['book_id'] ?? 0); ?>">
                      <button type="submit" name="confirm_return_group" value="1">Confirm <?php echo (int) ($item['copy_count'] ?? 0); ?> Physical Return<?php echo (int) ($item['copy_count'] ?? 0) === 1 ? '' : 's'; ?></button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  </div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/shared_confirm.js"></script>
</body>
</html>

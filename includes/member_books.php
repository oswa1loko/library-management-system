<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_roles(['student', 'faculty']);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) $_SESSION['role'];
$msg = '';
$msgType = 'success';
$requestedBookLimit = 5;

if (isset($_POST['borrow'])) {
    $bookIdsRaw = $_POST['book_ids'] ?? [];
    $bookQtyRaw = $_POST['book_qty'] ?? [];
    if (!is_array($bookIdsRaw)) {
        $bookIdsRaw = [$bookIdsRaw];
    }
    if (!is_array($bookQtyRaw)) {
        $bookQtyRaw = [];
    }

    $bookIds = array_values(array_unique(array_filter(array_map('intval', $bookIdsRaw), static function (int $id): bool {
        return $id > 0;
    })));
    $bookQuantities = [];
    foreach ($bookIds as $bookId) {
        $bookQuantities[$bookId] = max(1, min(5, (int) ($bookQtyRaw[$bookId] ?? 1)));
    }
    $requestedCopies = array_sum($bookQuantities);
    $days = (int) ($_POST['days'] ?? 7);
    $days = max(1, min($days, 30));
    $apiToken = ensure_member_api_token($conn, $userId);

    if ($bookIds === []) {
        $msg = 'Select at least one book first.';
        $msgType = 'error';
    } elseif ($requestedCopies > $requestedBookLimit) {
        $limitLabel = $requestedBookLimit === 1 ? '1 book copy' : $requestedBookLimit . ' book copies';
        $msg = 'You can only request up to ' . $limitLabel . ' in this submission.';
        $msgType = 'error';
    } elseif ($apiToken === '') {
        $msg = 'Unable to initialize API token right now.';
        $msgType = 'error';
    } else {
        $response = member_api_post_request('borrows/create.php', [
            'book_ids' => $bookIds,
            'book_qty' => $bookQuantities,
            'days' => $days,
        ], $apiToken);

        $json = $response['json'] ?? null;
        $isSuccess = is_array($json) && ($json['ok'] ?? false) === true;

        if ($isSuccess) {
            $requestedCount = (int) ($json['requested_count'] ?? $requestedCopies);
            $requestedTitles = count($bookIds);
            $requestBatch = trim((string) ($json['request_batch'] ?? ''));
            $copyLabel = $requestedCount === 1 ? '1 copy' : $requestedCount . ' copies';
            $titleLabel = $requestedTitles === 1 ? '1 title' : $requestedTitles . ' titles';
            $msg = 'Borrow request batch submitted for ' . $copyLabel . ' across ' . $titleLabel . '. Wait for librarian approval before pickup.';
            if ($requestBatch !== '') {
                $msg .= ' Batch ref: ' . $requestBatch . '.';
            }
        } else {
            $msg = (string) ($json['error'] ?? '');
            if ($msg === '' && (string) ($response['transport_error'] ?? '') !== '') {
                $msg = 'API request failed: ' . (string) $response['transport_error'];
            }
            if ($msg === '') {
                $msg = 'Unable to submit borrow request right now.';
            }
            $msgType = 'error';
        }
    }
}

$catalogStats = $conn->query("
    SELECT
      COUNT(*) AS total_titles,
      COALESCE(SUM(qty_total), 0) AS total_copies,
      COALESCE(SUM(qty_available), 0) AS available_copies,
      COALESCE(SUM(CASE WHEN qty_available > 0 THEN 1 ELSE 0 END), 0) AS available_titles
    FROM books
")->fetch_assoc();

$categoryRows = $conn->query("SELECT DISTINCT category FROM books WHERE category <> '' ORDER BY category ASC");
$bookCategories = [];
while ($categoryRows && ($categoryRow = $categoryRows->fetch_assoc())) {
    $bookCategories[] = (string) $categoryRow['category'];
}

$books = $conn->query("
    SELECT b.id, b.title, b.author, b.category, b.cover_path, b.qty_total, b.qty_available,
           COUNT(br.id) AS times_borrowed
    FROM books b
    LEFT JOIN borrows br ON br.book_id = b.id
    GROUP BY b.id, b.title, b.author, b.category, b.cover_path, b.qty_total, b.qty_available
    ORDER BY b.title ASC
");
$availableBooks = [];
$unavailableBooks = [];
while ($books && ($bookRow = $books->fetch_assoc())) {
    if ((int) ($bookRow['qty_available'] ?? 0) > 0) {
        $availableBooks[] = $bookRow;
    } else {
        $unavailableBooks[] = $bookRow;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title($role, 'Books and Borrow')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<?php $memberBorrowReturnVersion = (string) filemtime(__DIR__ . '/../assets/member_borrow_return.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="<?php echo h($role); ?>-books-borrow">
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
      <a class="member-sidebar-link is-active" href="/librarymanage/<?php echo h($role); ?>/books.php" data-tooltip="Books and Borrow">
        <span class="dashboard-icon icon-books" aria-hidden="true"></span>
        <span class="member-sidebar-label">Books / Borrow</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/ebooks.php" data-tooltip="eBooks">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">eBooks</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/borrow_return.php" data-tooltip="My Borrows and Returns">
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
        <p>Browse the catalog and request books</p>
      </div>
    </div>

    <div class="stack">
      <?php if ($msg !== ''): ?>
        <div class="notice <?php echo $msgType === 'error' ? 'error' : 'success'; ?>"><?php echo h($msg); ?></div>
      <?php endif; ?>

      <div class="panel member-workspace-overview">
        <p class="muted eyebrow-compact stack-copy">Overview</p>
        <h3 class="heading-panel">Catalog snapshot</h3>
        <div class="stat-grid">
          <div class="stat-card">
            <strong><?php echo (int) ($catalogStats['total_titles'] ?? 0); ?></strong>
            <span class="muted">Titles in catalog</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($catalogStats['total_copies'] ?? 0); ?></strong>
            <span class="muted">Total copies</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($catalogStats['available_titles'] ?? 0); ?></strong>
            <span class="muted">Titles available now</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($catalogStats['available_copies'] ?? 0); ?></strong>
            <span class="muted">Available copies</span>
          </div>
        </div>
      </div>

      <div class="grid cards member-workspace-grid member-workspace-grid-borrow">
        <div class="panel member-workspace-main">
          <div class="card-head">
            <div class="dashboard-icon icon-books" aria-hidden="true"></div>
            <div>
              <span class="chip">Borrowing</span>
              <h3 class="heading-top-md">Select Books to Borrow</h3>
            </div>
          </div>
          <p class="muted">Choose one or more titles and set the borrowing period up to 30 days.</p>
          <form method="post" class="stack chips-row member-workspace-form">
            <div>
              <label for="book_ids">Books</label>
              <div class="member-book-filters">
                <input id="member-book-search" type="search" class="member-book-search" placeholder="Search title or author" autocomplete="off" data-book-search>
                <div class="ui-select-shell member-book-category-shell">
                  <select class="ui-select" data-book-category>
                    <option value="">All categories</option>
                    <?php foreach ($bookCategories as $bookCategory): ?>
                      <option value="<?php echo h(strtolower($bookCategory)); ?>"><?php echo h($bookCategory); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <span class="ui-select-caret" aria-hidden="true"></span>
                </div>
              </div>
              <div class="member-book-picker" id="book_ids">
                <?php if ($availableBooks !== []): ?>
                  <section class="member-book-group" data-book-group>
                    <p class="member-book-group-title" data-book-group-title>Available now</p>
                    <div class="member-book-group-grid">
                      <?php foreach ($availableBooks as $book): ?>
                        <label class="member-book-option" data-book-option data-book-category-value="<?php echo h(strtolower((string) $book['category'])); ?>" data-book-search-text="<?php echo h(strtolower($book['title'] . ' ' . $book['author'] . ' ' . $book['category'])); ?>">
                          <input type="checkbox" name="book_ids[]" value="<?php echo (int) $book['id']; ?>">
                          <?php if (!empty($book['cover_path'])): ?>
                            <img class="member-book-option-cover" src="/librarymanage/<?php echo h($book['cover_path']); ?>" alt="<?php echo h($book['title']); ?>">
                          <?php else: ?>
                            <div class="member-book-option-cover member-book-option-cover-placeholder">No Cover</div>
                          <?php endif; ?>
                            <span class="member-book-option-copy">
                            <strong><?php echo h($book['title']); ?></strong>
                            <span class="muted"><?php echo h($book['author']); ?> - <?php echo h($book['category']); ?></span>
                            <span class="member-book-option-meta">
                              <span class="badge"><?php echo (int) $book['qty_available']; ?> available</span>
                              <span class="chip"><?php echo (int) $book['qty_total']; ?> total copies</span>
                              <span class="chip"><?php echo (int) $book['times_borrowed']; ?> borrows</span>
                            </span>
                            <span class="member-book-quantity">
                              <span class="muted">Quantity</span>
                              <span class="ui-select-shell member-book-quantity-shell">
                                <select
                                  name="book_qty[<?php echo (int) $book['id']; ?>]"
                                  class="ui-select member-book-quantity-select"
                                  data-book-quantity
                                  data-book-id="<?php echo (int) $book['id']; ?>"
                                  data-book-available="<?php echo (int) $book['qty_available']; ?>"
                                  disabled
                                >
                                  <?php $bookQtyCap = max(1, min(5, (int) $book['qty_available'])); ?>
                                  <?php for ($qty = 1; $qty <= $bookQtyCap; $qty++): ?>
                                    <option value="<?php echo $qty; ?>"><?php echo $qty; ?> copy<?php echo $qty === 1 ? '' : 'ies'; ?></option>
                                  <?php endfor; ?>
                                </select>
                                <span class="ui-select-caret" aria-hidden="true"></span>
                              </span>
                            </span>
                          </span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </section>
                <?php endif; ?>
                <?php if ($unavailableBooks !== []): ?>
                  <section class="member-book-group" data-book-group>
                    <p class="member-book-group-title" data-book-group-title>Unavailable right now</p>
                    <div class="member-book-group-grid">
                      <?php foreach ($unavailableBooks as $book): ?>
                        <label class="member-book-option is-unavailable" data-book-option data-book-category-value="<?php echo h(strtolower((string) $book['category'])); ?>" data-book-search-text="<?php echo h(strtolower($book['title'] . ' ' . $book['author'] . ' ' . $book['category'])); ?>">
                          <input type="checkbox" name="book_ids[]" value="<?php echo (int) $book['id']; ?>" disabled>
                          <?php if (!empty($book['cover_path'])): ?>
                            <img class="member-book-option-cover" src="/librarymanage/<?php echo h($book['cover_path']); ?>" alt="<?php echo h($book['title']); ?>">
                          <?php else: ?>
                            <div class="member-book-option-cover member-book-option-cover-placeholder">No Cover</div>
                          <?php endif; ?>
                            <span class="member-book-option-copy">
                            <strong><?php echo h($book['title']); ?></strong>
                            <span class="muted"><?php echo h($book['author']); ?> - <?php echo h($book['category']); ?></span>
                            <span class="member-book-option-meta">
                              <span class="badge">Unavailable</span>
                              <span class="chip"><?php echo (int) $book['qty_total']; ?> total copies</span>
                              <span class="chip"><?php echo (int) $book['times_borrowed']; ?> borrows</span>
                            </span>
                            <span class="member-book-quantity">
                              <span class="muted">Quantity</span>
                              <span class="ui-select-shell member-book-quantity-shell">
                                <select class="ui-select member-book-quantity-select" disabled>
                                  <option value="0">Unavailable</option>
                                </select>
                                <span class="ui-select-caret" aria-hidden="true"></span>
                              </span>
                            </span>
                          </span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </section>
                <?php endif; ?>
                <?php if ($availableBooks === [] && $unavailableBooks === []): ?>
                  <div class="empty-state">No books are available for request right now.</div>
                <?php endif; ?>
              </div>
              <div class="empty-state member-book-empty" data-book-empty hidden>No books matched your search.</div>
              <div class="inline-actions chips-row member-book-summary">
                <span class="chip" data-book-selected-count>0 selected - 5 book copies max</span>
                <button type="button" class="button secondary member-book-clear" data-book-clear disabled>Clear selected</button>
                <span class="muted meta-top-sm" data-book-selection-note>Select one or more titles and set the quantity per title.</span>
              </div>
            </div>
            <input type="hidden" name="book_limit" value="<?php echo $requestedBookLimit; ?>" data-book-limit>
            <div>
              <label for="days">Days to borrow</label>
              <input id="days" type="number" name="days" value="7" min="1" max="30">
            </div>
            <div class="inline-actions member-workspace-actions">
              <button type="submit" name="borrow" value="1" data-book-submit disabled>Request Selected Books</button>
              <span class="muted">Available stock is reduced only after librarian approval.</span>
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
            <div class="empty-state">Books already out of stock stay visible here, but they cannot be selected for request.</div>
            <div class="empty-state">Borrow requests stay pending until the librarian approves the release.</div>
            <div class="empty-state">You can request multiple titles at once, but each title is still reviewed separately.</div>
            <div class="empty-state">Use the My Borrows / Returns page to request returns and check due dates.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/member_borrow_return.js?v=<?php echo urlencode($memberBorrowReturnVersion); ?>"></script>
</body>
</html>

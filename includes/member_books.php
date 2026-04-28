<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_roles(['student', 'faculty']);

function normalize_member_book_category(string $value): string
{
    $value = preg_replace('/\s+/', ' ', trim($value));
    return strtolower((string) $value);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) $_SESSION['role'];
$msg = '';
$msgType = 'success';
$requestedBookLimit = $role === 'student' ? 1 : 5;

if (isset($_POST['borrow'])) {
    $bookIdsRaw = $_POST['book_ids'] ?? ($_POST['book_id'] ?? []);
    $bookQtyRaw = $_POST['book_qty'] ?? [];
    if (!is_array($bookIdsRaw)) {
        $bookIdsRaw = [$bookIdsRaw];
    }

    $bookIds = array_values(array_unique(array_filter(array_map('intval', $bookIdsRaw), static function (int $id): bool {
        return $id > 0;
    })));
    $bookQuantities = [];
    if (is_array($bookQtyRaw)) {
        foreach ($bookIds as $bookId) {
            $bookQuantities[$bookId] = max(1, min($requestedBookLimit, (int) ($bookQtyRaw[$bookId] ?? 1)));
        }
    } else {
        $singleQty = max(1, min($requestedBookLimit, (int) $bookQtyRaw));
        foreach ($bookIds as $bookId) {
            $bookQuantities[$bookId] = $singleQty;
        }
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
        $msg = $role === 'student'
            ? 'Students can only request 1 book and cannot request again while they still have a pending borrow, borrowed book, return request, or unpaid penalty.'
            : 'You can only request up to ' . $limitLabel . ' in this submission.';
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

$categoryRows = $conn->query("SELECT DISTINCT category FROM books WHERE category <> '' ORDER BY category ASC");
$bookCategories = [];
while ($categoryRows && ($categoryRow = $categoryRows->fetch_assoc())) {
    $bookCategories[] = (string) $categoryRow['category'];
}
$initialSearchFilter = trim((string) ($_GET['search'] ?? ''));
$initialBookFilter = max(0, (int) ($_GET['book'] ?? 0));
$catalogScopeFilter = normalize_member_book_category((string) ($_GET['catalog'] ?? ''));
$normalizedBookCategories = array_map('normalize_member_book_category', $bookCategories);
$initialCategoryFilter = normalize_member_book_category((string) ($_GET['category'] ?? ''));
if ($catalogScopeFilter !== '' && !in_array($catalogScopeFilter, $normalizedBookCategories, true)) {
    $catalogScopeFilter = '';
}
if ($initialCategoryFilter !== '' && !in_array($initialCategoryFilter, $normalizedBookCategories, true)) {
    $initialCategoryFilter = '';
}
$effectiveCategoryFilter = $catalogScopeFilter !== '' ? $catalogScopeFilter : $initialCategoryFilter;
$initialCategoryLabel = '';
if ($effectiveCategoryFilter !== '') {
    $matchedCategoryIndex = array_search($effectiveCategoryFilter, $normalizedBookCategories, true);
    if ($matchedCategoryIndex !== false) {
        $initialCategoryLabel = $bookCategories[$matchedCategoryIndex] ?? '';
    }
}

$booksSql = "
    SELECT b.id, b.title, b.author, b.category, b.description, b.cover_path, b.qty_available
    FROM books b
";

if ($effectiveCategoryFilter !== '') {
    $booksSql .= "
        WHERE LOWER(TRIM(b.category)) = ?
        ORDER BY b.title ASC
    ";
    $booksStmt = $conn->prepare($booksSql);
    if ($booksStmt) {
        $booksStmt->bind_param('s', $effectiveCategoryFilter);
        $booksStmt->execute();
        $books = $booksStmt->get_result();
    } else {
        $books = false;
    }
} else {
    $booksSql .= "
        ORDER BY b.title ASC
    ";
    $books = $conn->query($booksSql);
}

$blockedBookIds = [];
$blockedBooksSql = "
    SELECT DISTINCT br.book_id
    FROM penalties p
    JOIN borrows br ON br.id = p.borrow_id
    LEFT JOIN (
        SELECT
            linked.penalty_id,
            pay.status,
            pay.id
        FROM payments pay
        JOIN (
            SELECT payment_id, penalty_id FROM payment_penalty_links
            UNION ALL
            SELECT id AS payment_id, penalty_id
            FROM payments
            WHERE penalty_id IS NOT NULL
        ) linked ON linked.payment_id = pay.id
    ) penalty_payments ON penalty_payments.penalty_id = p.id
    WHERE p.user_id = ?
      AND p.status = 'unpaid'
      AND (
        penalty_payments.id IS NULL
        OR penalty_payments.id = (
            SELECT latest_pay.id
            FROM payments latest_pay
            LEFT JOIN payment_penalty_links latest_link ON latest_link.payment_id = latest_pay.id
            WHERE latest_pay.user_id = p.user_id
              AND (
                latest_pay.penalty_id = p.id
                OR latest_link.penalty_id = p.id
              )
            ORDER BY latest_pay.id DESC
            LIMIT 1
        )
      )
      AND COALESCE(penalty_payments.status, '') <> 'approved'
";
$blockedBooksStmt = $conn->prepare($blockedBooksSql);
if ($blockedBooksStmt) {
    $blockedBooksStmt->bind_param('i', $userId);
    $blockedBooksStmt->execute();
    $blockedBooks = $blockedBooksStmt->get_result();
    while ($blockedBooks && ($blockedBookRow = $blockedBooks->fetch_assoc())) {
        $blockedBookIds[(int) ($blockedBookRow['book_id'] ?? 0)] = true;
    }
}

$availableBooks = [];
$unavailableBooks = [];
while ($books && ($bookRow = $books->fetch_assoc())) {
    $bookId = (int) ($bookRow['id'] ?? 0);
    $bookRow['blocked_for_penalty'] = isset($blockedBookIds[$bookId]) ? 1 : 0;
    if ((int) ($bookRow['qty_available'] ?? 0) > 0 && (int) ($bookRow['blocked_for_penalty'] ?? 0) !== 1) {
        $availableBooks[] = $bookRow;
    } else {
        $unavailableBooks[] = $bookRow;
    }
}

if ($initialBookFilter > 0) {
    $availableBooks = array_values(array_filter($availableBooks, static function (array $bookRow) use ($initialBookFilter): bool {
        return (int) ($bookRow['id'] ?? 0) === $initialBookFilter;
    }));
    $unavailableBooks = array_values(array_filter($unavailableBooks, static function (array $bookRow) use ($initialBookFilter): bool {
        return (int) ($bookRow['id'] ?? 0) === $initialBookFilter;
    }));
}

$bookSearchSuggestions = [];
$bookSearchSuggestionIndex = [];
foreach (array_merge($availableBooks, $unavailableBooks) as $bookSuggestionRow) {
    $candidates = [
        trim((string) ($bookSuggestionRow['title'] ?? '')),
        trim((string) ($bookSuggestionRow['author'] ?? '')),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        $normalizedCandidate = strtolower(preg_replace('/\s+/', ' ', $candidate));
        if (isset($bookSearchSuggestionIndex[$normalizedCandidate])) {
            continue;
        }

        $bookSearchSuggestionIndex[$normalizedCandidate] = true;
        $bookSearchSuggestions[] = $candidate;
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
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<?php $memberBorrowReturnVersion = (string) filemtime(__DIR__ . '/../assets/member_borrow_return.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="<?php echo h($role); ?>-books-borrow">
  <aside class="panel member-sidebar">
    <div class="member-sidebar-head">
      <div class="member-sidebar-toggle" aria-hidden="true">
        <span class="member-sidebar-label">Main Menu</span>
      </div>
    </div>
    <nav class="member-sidebar-nav">
      <p class="member-sidebar-group-label">Overview</p>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/dashboard.php" data-tooltip="Dashboard">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Dashboard</span>
      </a>
      <p class="member-sidebar-group-label">Library</p>
      <a class="member-sidebar-link is-active" href="/librarymanage/<?php echo h($role); ?>/books.php" data-tooltip="Books">
        <span class="dashboard-icon icon-books" aria-hidden="true"></span>
        <span class="member-sidebar-label">Books</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/catalog.php" data-tooltip="Catalog">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">Catalog</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/ebooks.php" data-tooltip="eBooks">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">eBooks</span>
      </a>
      <p class="member-sidebar-group-label">My Activity</p>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/borrow_return.php" data-tooltip="Returns">
        <span class="dashboard-icon icon-checklist" aria-hidden="true"></span>
        <span class="member-sidebar-label">Returns</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/book_incidents.php" data-tooltip="Book Incidents">
        <span class="dashboard-icon icon-notes" aria-hidden="true"></span>
        <span class="member-sidebar-label">Book Incidents</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/tracking.php" data-tooltip="Records Tracking">
        <span class="dashboard-icon icon-ledger" aria-hidden="true"></span>
        <span class="member-sidebar-label">Records Tracking</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/payment_upload.php" data-tooltip="Payments">
        <span class="dashboard-icon icon-payments" aria-hidden="true"></span>
        <span class="member-sidebar-label">Payments</span>
      </a>
    </nav>
    <p class="member-sidebar-section member-sidebar-label">Account</p>
    <div class="topbar-nav member-sidebar-utilities">
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/profile.php" data-tooltip="Profile">
        <span class="dashboard-icon icon-edit" aria-hidden="true"></span>
        <span class="member-sidebar-label">Profile</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/index.php" data-tooltip="Portal Home">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">Portal Home</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/logout.php" data-tooltip="Logout">
        <span class="dashboard-icon icon-logout" aria-hidden="true"></span>
        <span class="member-sidebar-label">Logout</span>
      </a>
    </div>
  </aside>

  <div class="member-main">
    <div class="topbar topbar-member">
      <div>
        <p class="topbar-kicker"><?php echo h(role_label($role)); ?> Portal</p>
        <h1><?php echo h(role_label($role)); ?> Books</h1>
        <p>Browse the catalog and request books</p>
      </div>
    </div>

    <div class="stack">
      <?php if ($msg !== ''): ?>
        <div class="notice <?php echo $msgType === 'error' ? 'error' : 'success'; ?>"><?php echo h($msg); ?></div>
      <?php endif; ?>

      <div class="grid cards member-workspace-grid member-workspace-grid-borrow">
        <div class="panel member-workspace-main" data-book-results-panel>
          <div class="card-head">
            <div class="dashboard-icon icon-books" aria-hidden="true"></div>
            <div>
              <span class="chip">Borrowing</span>
              <h3 class="heading-top-md">Choose a Book to Borrow</h3>
            </div>
          </div>
          <p class="muted">Tap an available book card to open the borrow request form.</p>
          <div class="chips-row meta-top-sm" data-book-results-status<?php echo $initialCategoryLabel === '' && $initialSearchFilter === '' ? ' hidden' : ''; ?>>
            <?php if ($initialCategoryLabel !== ''): ?>
              <span class="chip">Catalog: <?php echo h($initialCategoryLabel); ?></span>
            <?php endif; ?>
            <?php if ($initialSearchFilter !== ''): ?>
              <span class="chip">Search: <?php echo h($initialSearchFilter); ?></span>
            <?php endif; ?>
          </div>
          <div class="stack chips-row member-workspace-form">
            <div>
              <label for="book_ids">Books</label>
              <div class="member-book-filters">
                <div class="member-book-search-field">
                  <input id="member-book-search" type="search" class="member-book-search" placeholder="Search title or author" autocomplete="off" value="<?php echo h($initialSearchFilter); ?>" data-book-search>
                  <div class="member-book-search-suggestions" data-book-search-suggestions hidden></div>
                </div>
                <div class="ui-select-shell member-book-category-shell">
                  <select class="ui-select" data-book-category<?php echo $catalogScopeFilter !== '' ? ' data-book-fixed-category="1"' : ''; ?>>
                    <option value="">All categories</option>
                    <?php foreach ($bookCategories as $bookCategory): ?>
                      <?php $normalizedBookCategory = normalize_member_book_category($bookCategory); ?>
                      <option value="<?php echo h($normalizedBookCategory); ?>" <?php echo $effectiveCategoryFilter === $normalizedBookCategory ? 'selected' : ''; ?>><?php echo h($bookCategory); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <span class="ui-select-caret" aria-hidden="true"></span>
                </div>
              </div>
              <?php if ($catalogScopeFilter !== ''): ?>
                <p class="muted meta-top-sm">Showing books from the selected catalog first. You can still switch categories using the dropdown.</p>
              <?php endif; ?>
              <div class="member-book-picker" id="book_ids">
                <?php if ($availableBooks !== []): ?>
                  <section class="member-book-group" data-book-group>
                    <p class="member-book-group-title" data-book-group-title>Available now</p>
                    <div class="member-book-group-grid">
                      <?php foreach ($availableBooks as $book): ?>
                        <button
                          type="button"
                          class="member-book-option member-book-option-button"
                          data-book-option
                          data-book-trigger
                          data-book-id="<?php echo (int) $book['id']; ?>"
                          data-book-title="<?php echo h($book['title']); ?>"
                          data-book-author="<?php echo h($book['author']); ?>"
                          data-book-category-label="<?php echo h($book['category']); ?>"
                          data-book-description="<?php echo h((string) ($book['description'] ?? '')); ?>"
                          data-book-cover="<?php echo h(app_url((string) ($book['cover_path'] ?? ''))); ?>"
                          data-book-available="<?php echo (int) $book['qty_available']; ?>"
                          data-book-max-qty="<?php echo max(1, min($requestedBookLimit, (int) $book['qty_available'])); ?>"
                          data-book-search-text="<?php echo h(strtolower($book['title'] . ' ' . $book['author'] . ' ' . $book['category'])); ?>"
                          data-book-category-value="<?php echo h(normalize_member_book_category((string) $book['category'])); ?>"
                        >
                          <?php if (!empty($book['cover_path'])): ?>
                            <img class="member-book-option-cover" src="<?php echo h(app_url((string) $book['cover_path'])); ?>" alt="<?php echo h($book['title']); ?>">
                          <?php else: ?>
                            <div class="member-book-option-cover member-book-option-cover-placeholder">No Cover</div>
                          <?php endif; ?>
                          <span class="member-book-option-copy">
                            <strong><?php echo h($book['title']); ?></strong>
                            <span class="muted"><?php echo h($book['author']); ?> - <?php echo h($book['category']); ?></span>
                            <?php if (!empty($book['description'])): ?>
                              <span class="member-book-description"><?php echo h((string) $book['description']); ?></span>
                            <?php endif; ?>
                          </span>
                        </button>
                      <?php endforeach; ?>
                    </div>
                  </section>
                <?php endif; ?>
                <?php if ($unavailableBooks !== []): ?>
                  <section class="member-book-group" data-book-group>
                    <p class="member-book-group-title" data-book-group-title>Unavailable right now</p>
                    <div class="member-book-group-grid">
                      <?php foreach ($unavailableBooks as $book): ?>
                        <div class="member-book-option member-book-option-static is-unavailable"
                             data-book-option
                             data-book-id="<?php echo (int) $book['id']; ?>"
                             data-book-title="<?php echo h($book['title']); ?>"
                             data-book-author="<?php echo h($book['author']); ?>"
                             data-book-category-label="<?php echo h($book['category']); ?>"
                             data-book-category-value="<?php echo h(normalize_member_book_category((string) $book['category'])); ?>"
                             data-book-search-text="<?php echo h(strtolower($book['title'] . ' ' . $book['author'] . ' ' . $book['category'])); ?>">
                          <?php if (!empty($book['cover_path'])): ?>
                            <img class="member-book-option-cover" src="<?php echo h(app_url((string) $book['cover_path'])); ?>" alt="<?php echo h($book['title']); ?>">
                          <?php else: ?>
                            <div class="member-book-option-cover member-book-option-cover-placeholder">No Cover</div>
                          <?php endif; ?>
                            <span class="member-book-option-copy">
                            <strong><?php echo h($book['title']); ?></strong>
                            <span class="muted"><?php echo h($book['author']); ?> - <?php echo h($book['category']); ?></span>
                            <?php if (!empty($book['description'])): ?>
                              <span class="member-book-description"><?php echo h((string) $book['description']); ?></span>
                            <?php endif; ?>
                            <span class="member-book-option-meta">
                              <span class="badge"><?php echo (int) ($book['blocked_for_penalty'] ?? 0) === 1 ? 'Penalty hold' : 'Unavailable'; ?></span>
                              <?php if ((int) ($book['blocked_for_penalty'] ?? 0) === 1): ?>
                                <span class="muted">Settle the unpaid penalty for this title before borrowing it again.</span>
                              <?php endif; ?>
                            </span>
                          </span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </section>
                <?php endif; ?>
                <?php if ($availableBooks === [] && $unavailableBooks === []): ?>
                  <div class="empty-state">No books are available for request right now.</div>
                <?php endif; ?>
              </div>
              <div class="empty-state member-book-empty" data-book-empty hidden>No books matched your search.</div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>
<div class="desk-modal member-borrow-modal" data-book-modal hidden>
  <div class="desk-modal-backdrop member-borrow-modal-backdrop" data-book-modal-close></div>
  <div class="desk-modal-dialog panel member-borrow-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="member-borrow-modal-title">
    <div class="desk-modal-head">
      <div>
        <p class="muted eyebrow-compact">Borrow Request</p>
        <h3 id="member-borrow-modal-title" class="heading-card" data-book-modal-title>Request a Book</h3>
        <p class="muted"><?php echo $role === 'student' ? 'Submit your request for librarian approval.' : 'Set the quantity and borrowing period, then submit your request for librarian approval.'; ?></p>
      </div>
      <button type="button" class="button secondary" data-book-modal-close>Close</button>
    </div>
    <div class="member-borrow-modal-layout">
      <div class="empty-state member-borrow-modal-preview">
        <div class="member-borrow-modal-cover">
          <div class="member-book-option-cover member-book-option-cover-placeholder" data-book-modal-cover-placeholder>No Cover</div>
          <img class="member-book-option-cover" src="" alt="" data-book-modal-cover hidden>
        </div>
        <strong class="label-block meta-top-sm" data-book-modal-book-title></strong>
        <span class="muted" data-book-modal-book-meta></span>
        <p class="muted member-borrow-modal-description" data-book-modal-description hidden></p>
        <span class="badge" data-book-modal-available></span>
      </div>
      <form method="post" class="stack member-workspace-form">
        <input type="hidden" name="book_id" value="" data-book-modal-id>
        <div>
          <label for="modal_book_qty">Quantity</label>
          <div class="ui-select-shell member-book-quantity-shell">
            <select id="modal_book_qty" name="book_qty" class="ui-select" data-book-modal-qty></select>
            <span class="ui-select-caret" aria-hidden="true"></span>
          </div>
        </div>
        <div>
          <label for="modal_days">Days to borrow</label>
          <input id="modal_days" type="number" name="days" value="7" min="1" max="30">
        </div>
        <div class="inline-actions member-workspace-actions">
          <button type="submit" name="borrow" value="1">Request This Book</button>
          <span class="muted">Available stock is reduced only after librarian approval.</span>
        </div>
      </form>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/member_borrow_return.js?v=<?php echo urlencode($memberBorrowReturnVersion); ?>"></script>
<script>
window.memberBookSearchOptions = <?php echo json_encode($bookSearchSuggestions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
window.memberBookInitialFilter = <?php echo json_encode([
    'bookId' => $initialBookFilter,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
window.memberBorrowRules = <?php echo json_encode([
    'role' => $role,
    'maxCopiesPerRequest' => $requestedBookLimit,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
</script>
</body>
</html>

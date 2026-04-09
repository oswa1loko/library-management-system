<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

function manage_books_filter_query(string $search, string $catalogFilter): string
{
    $query = http_build_query(array_filter([
        'search' => $search,
        'catalog' => $catalogFilter,
    ], static fn($value) => $value !== ''));

    return $query !== '' ? '?' . $query : '';
}

function manage_books_print_title(string $printScope, string $selectedCatalogName): string
{
    if ($printScope === 'catalog' && $selectedCatalogName !== 'All') {
        return $selectedCatalogName . ' Books';
    }

    if ($printScope === 'available') {
        return 'Available Books';
    }

    if ($printScope === 'low_stock') {
        return 'Low Stock Books';
    }

    if ($printScope === 'out_of_stock') {
        return 'Out of Stock Books';
    }

    if ($selectedCatalogName !== 'All') {
        return $selectedCatalogName . ' Books';
    }

    return 'All Books';
}

$message = '';
$messageType = 'success';
$search = trim($_GET['search'] ?? '');
$catalogFilter = trim((string) ($_GET['catalog'] ?? $_GET['category'] ?? ''));
$bookFilter = max(0, (int) ($_GET['book'] ?? 0));
$printMode = isset($_GET['print']) && $_GET['print'] === '1';
$printScope = trim((string) ($_GET['print_scope'] ?? 'current'));
$formData = [
    'title' => '',
    'author' => '',
    'catalog_id' => '',
    'isbn' => '',
    'description' => '',
    'qty' => 1,
];

function upload_book_cover(array $file, string $existingPath = ''): array
{
    if (empty($file['name'])) {
        return ['path' => $existingPath, 'error' => ''];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowed, true)) {
        return ['path' => $existingPath, 'error' => 'Only JPG, JPEG, PNG, and WEBP covers are allowed.'];
    }

    $directory = __DIR__ . '/../uploads/book_covers';
    if (!ensure_upload_directory($directory)) {
        return ['path' => $existingPath, 'error' => 'Book cover folder could not be created.'];
    }

    $filename = 'cover_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $target = $directory . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['path' => $existingPath, 'error' => 'Cover upload failed.'];
    }

    return ['path' => 'uploads/book_covers/' . $filename, 'error' => ''];
}

if (isset($_POST['add'])) {
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $catalogId = max(0, (int) ($_POST['catalog_id'] ?? 0));
    $isbn = trim($_POST['isbn'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $qty = max(0, (int) ($_POST['qty'] ?? 1));
    $formData = [
        'title' => $title,
        'author' => $author,
        'catalog_id' => $catalogId > 0 ? (string) $catalogId : '',
        'isbn' => $isbn,
        'description' => $description,
        'qty' => $qty,
    ];

    $coverUpload = upload_book_cover($_FILES['cover'] ?? []);
    $selectedCatalog = null;
    if ($catalogId > 0) {
        $catalogLookup = $conn->prepare("
            SELECT id, name, description
            FROM catalogs
            WHERE id = ?
            LIMIT 1
        ");
        $catalogLookup->bind_param('i', $catalogId);
        $catalogLookup->execute();
        $selectedCatalog = $catalogLookup->get_result()->fetch_assoc();
        $catalogLookup->close();
    }

    if ($title === '') {
        $message = 'Title is required.';
        $messageType = 'error';
    } elseif ($author === '') {
        $message = 'Author is required.';
        $messageType = 'error';
    } elseif (!$selectedCatalog) {
        $message = 'Select a catalog first.';
        $messageType = 'error';
    } elseif ($isbn !== '' && !preg_match('/^\d{13}$/', $isbn)) {
        $message = 'ISBN must contain exactly 13 digits.';
        $messageType = 'error';
    } elseif ($qty <= 0) {
        $message = 'Quantity must be at least 1.';
        $messageType = 'error';
    } elseif ($coverUpload['error'] !== '') {
        $message = $coverUpload['error'];
        $messageType = 'error';
    } else {
        $coverPath = $coverUpload['path'] ?: null;
        $catalogName = trim((string) ($selectedCatalog['name'] ?? ''));
        $duplicateCheck = $conn->prepare("
            SELECT id
            FROM books
            WHERE title = ? AND author = ? AND catalog_id = ? AND (? = '' OR isbn = ?)
            LIMIT 1
        ");
        $duplicateCheck->bind_param('ssiss', $title, $author, $catalogId, $isbn, $isbn);
        $duplicateCheck->execute();
        $duplicateBook = $duplicateCheck->get_result()->fetch_assoc();
        $duplicateCheck->close();

        if ($duplicateBook) {
            $message = 'A matching book already exists under this catalog. Review the existing record before adding another one.';
            $messageType = 'error';
        } else {
            $isbnValue = $isbn !== '' ? $isbn : null;
            $descriptionValue = $description !== '' ? $description : null;
            $conn->begin_transaction();

            try {
                $stmt = $conn->prepare("
                    INSERT INTO books (title, author, category, catalog_id, isbn, description, cover_path, qty_total, qty_available)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param('sssisssii', $title, $author, $catalogName, $catalogId, $isbnValue, $descriptionValue, $coverPath, $qty, $qty);
                $stmt->execute();
                $bookId = (int) $stmt->insert_id;
                $stmt->close();

                create_missing_book_copies($conn, $bookId, $qty, 'available');
                sync_book_inventory_from_copies($conn, $bookId);

                $conn->commit();
                $message = 'Book added successfully.';
                $formData = [
                    'title' => '',
                    'author' => '',
                    'catalog_id' => '',
                    'isbn' => '',
                    'description' => '',
                    'qty' => 1,
                ];
            } catch (Throwable $exception) {
                $conn->rollback();
                $message = 'Unable to add the book right now.';
                $messageType = 'error';
            }
        }
    }
}

if (isset($_POST['delete'])) {
    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0) {
        $usageCheck = $conn->prepare("
            SELECT
                COUNT(*) AS total_borrows,
                COALESCE(SUM(CASE WHEN status IN ('pending', 'borrowed', 'return_requested') THEN 1 ELSE 0 END), 0) AS active_borrows
            FROM borrows
            WHERE book_id = ?
        ");
        $usageCheck->bind_param('i', $id);
        $usageCheck->execute();
        $usage = $usageCheck->get_result()->fetch_assoc();
        $usageCheck->close();

        $totalBorrows = (int) ($usage['total_borrows'] ?? 0);
        $activeBorrows = (int) ($usage['active_borrows'] ?? 0);

        if ($activeBorrows > 0) {
            $message = 'This book cannot be deleted while it has active or pending borrow records.';
            $messageType = 'error';
        } elseif ($totalBorrows > 0) {
            $message = 'This book cannot be deleted because it already has borrow history.';
            $messageType = 'error';
        } else {
            $stmt = $conn->prepare("DELETE FROM books WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            header('Location: manage_books.php');
            exit;
        }
    }
}

$catalogRows = $conn->query("SELECT id, name, description FROM catalogs ORDER BY name ASC");
$catalogs = [];
while ($catalogRows && ($catalogRow = $catalogRows->fetch_assoc())) {
    $catalogs[] = $catalogRow;
}
$selectedCatalogName = 'All';
if ($catalogFilter !== '') {
    $selectedCatalogName = 'Selected';
    foreach ($catalogs as $catalog) {
        if ((string) $catalog['id'] === $catalogFilter) {
            $selectedCatalogName = (string) $catalog['name'];
            break;
        }
    }
}

$booksSql = "SELECT * FROM books WHERE 1=1";
$booksParams = [];
$booksTypes = '';

if ($search !== '') {
    $booksSql .= " AND (title LIKE ? OR author LIKE ? OR COALESCE(isbn, '') LIKE ?)";
    $term = '%' . $search . '%';
    $booksParams[] = $term;
    $booksParams[] = $term;
    $booksParams[] = $term;
    $booksTypes .= 'sss';
}

if ($catalogFilter !== '') {
    $booksSql .= " AND catalog_id = ?";
    $booksParams[] = (int) $catalogFilter;
    $booksTypes .= 'i';
}

if ($bookFilter > 0) {
    $booksSql .= " AND id = ?";
    $booksParams[] = $bookFilter;
    $booksTypes .= 'i';
}

if ($printMode) {
    if ($printScope === 'available') {
        $booksSql .= " AND qty_available > 2";
    } elseif ($printScope === 'low_stock') {
        $booksSql .= " AND qty_available BETWEEN 1 AND 2";
    } elseif ($printScope === 'out_of_stock') {
        $booksSql .= " AND qty_available <= 0";
    }
}

$booksSql .= " ORDER BY id DESC";
$booksStmt = $conn->prepare($booksSql);
if ($booksTypes !== '') {
    $booksStmt->bind_param($booksTypes, ...$booksParams);
}
$booksStmt->execute();
$books = $booksStmt->get_result();

$bookStats = $conn->query("
    SELECT
        COUNT(*) AS total_titles,
        COALESCE(SUM(qty_total), 0) AS total_copies,
        COALESCE(SUM(qty_available), 0) AS available_copies
    FROM books
")->fetch_assoc();

$borrowedCopies = max(0, (int) ($bookStats['total_copies'] ?? 0) - (int) ($bookStats['available_copies'] ?? 0));
$lowStockCount = (int) ($conn->query("SELECT COUNT(*) AS low_stock_titles FROM books WHERE qty_available BETWEEN 1 AND 2")->fetch_assoc()['low_stock_titles'] ?? 0);
$outOfStockCount = (int) ($conn->query("SELECT COUNT(*) AS out_of_stock_titles FROM books WHERE qty_available <= 0")->fetch_assoc()['out_of_stock_titles'] ?? 0);
$shouldOpenAddBookModal = isset($_POST['add']) && $messageType === 'error';
$filterQueryString = manage_books_filter_query($search, $catalogFilter);
$printTitle = manage_books_print_title($printScope, $selectedCatalogName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Books')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<?php $ajaxPanelVersion = (string) filemtime(__DIR__ . '/../assets/admin_ajax_panel.js'); ?>
<?php $manageBooksVersion = (string) filemtime(__DIR__ . '/../assets/librarian_manage_books.js'); ?>
<?php $manageBooksPrintVersion = (string) filemtime(__DIR__ . '/../assets/librarian_manage_books_print.js'); ?>
<?php $manageBooksToolsVersion = (string) filemtime(__DIR__ . '/../assets/librarian_manage_books_tools.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<?php if ($printMode): ?>
<div class="site-shell">
  <?php require __DIR__ . '/partials/manage_books_print.php'; ?>
</div>
<?php else: ?>
<div class="site-shell librarian-shell member-shell js-member-sidebar" data-sidebar-key="librarian-books" data-sidebar-default="expanded">
  <?php
  $sidebarPage = 'books';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Librarian Books';
  $pageSubtitle = 'Inventory maintenance for library holdings';
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

    <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-books" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Collection Overview</p>
          <h3 class="heading-card">Catalog control and stock visibility</h3>
          <p class="muted">Track active inventory, watch low-stock titles, and keep incoming records clean before they become borrowable.</p>
        </div>
      </div>
      <div class="stat-grid">
        <div class="stat-card">
          <span class="code-pill">Titles</span>
          <strong><?php echo (int) ($bookStats['total_titles'] ?? 0); ?></strong>
          <span class="muted">Catalog entries currently available in the system.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Copies</span>
          <strong><?php echo (int) ($bookStats['total_copies'] ?? 0); ?></strong>
          <span class="muted">Total physical copies recorded across all titles.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Borrowed Out</span>
          <strong><?php echo $borrowedCopies; ?></strong>
          <span class="muted">Copies currently unavailable because they are checked out.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Low / Out</span>
          <strong><?php echo $lowStockCount + $outOfStockCount; ?></strong>
          <span class="muted"><?php echo $lowStockCount; ?> low stock and <?php echo $outOfStockCount; ?> out of stock titles need attention.</span>
        </div>
      </div>
    </div>

    <div class="grid cards">
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-add" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Add Book</p>
            <h3 class="heading-card">Create a new library entry</h3>
            <p class="muted">Open the add-book form in a modal so the stock records table stays visible on the page.</p>
          </div>
        </div>
        <div class="stack flow-top-lg">
          <div class="empty-state">Need a new catalog first? Create it in <a href="/librarymanage/librarian/manage_catalogs.php">Catalog Management</a>, then come back here to assign the book.</div>
          <div class="inline-actions">
            <button type="button" data-open-book-add-modal>Add Book</button>
            <span class="muted">Book details, assigned catalog, and starting stock are saved together in one quick modal flow.</span>
          </div>
        </div>
      </div>

    </div>

    <div class="panel" data-ajax-panel-shell="librarian-books-records-panel">
      <div class="manage-books-records-head">
        <div class="card-head card-head-tight">
          <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Catalog Records</p>
            <h3 class="heading-card">Books list and stock review</h3>
            <p class="muted">Search by title or author, narrow by assigned catalog, and jump to edit when copy counts need correction.</p>
          </div>
        </div>
        <form method="get" class="manage-books-tablefilters js-auto-submit-filters" data-ajax-filter-form>
          <input type="hidden" name="book" value="<?php echo $bookFilter > 0 ? (int) $bookFilter : ''; ?>" data-librarian-book-filter>
          <div class="grow">
            <label for="search">Search</label>
            <input id="search" name="search" value="<?php echo h($search); ?>" placeholder="Search title or author">
          </div>
          <div data-filter-panel>
            <label for="catalog_filter">Catalog</label>
            <div class="ui-select-shell">
              <select id="catalog_filter" name="catalog" class="ui-select" data-ajax-filter-control>
                <option value="">All catalogs</option>
                <?php foreach ($catalogs as $catalog): ?>
                  <option value="<?php echo (int) $catalog['id']; ?>" <?php echo $catalogFilter === (string) $catalog['id'] ? 'selected' : ''; ?>>
                    <?php echo h($catalog['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
          </div>
          <div class="manage-books-printcontrol">
            <label for="printBooksAction" class="manage-users-print-label">Print options</label>
            <div class="manage-books-printbar">
              <div class="manage-users-print-shell">
                <select id="printBooksAction" class="manage-users-print-select">
                  <option value="">Choose report to print</option>
                  <option value="current">Print Current View</option>
                  <option value="all">Print All Books</option>
                  <option value="available">Print Available Books</option>
                  <option value="low_stock">Print Low Stock</option>
                  <option value="out_of_stock">Print Out of Stock</option>
                  <?php foreach ($catalogs as $catalog): ?>
                    <option value="catalog:<?php echo (int) $catalog['id']; ?>">Print <?php echo h($catalog['name']); ?></option>
                  <?php endforeach; ?>
                </select>
                <span class="manage-users-print-caret" aria-hidden="true"></span>
              </div>
              <span class="muted manage-books-print-hint">Choosing an option opens the preview first.</span>
            </div>
          </div>
          <div class="inline-actions">
            <a class="button secondary" href="manage_books.php" data-ajax-filter-link>Reset</a>
          </div>
        </form>
      </div>
      <div class="manage-books-summary-chips">
        <span class="chip">Available copies: <?php echo (int) ($bookStats['available_copies'] ?? 0); ?></span>
        <span class="chip">Borrowed out: <?php echo $borrowedCopies; ?></span>
        <span class="chip">Low stock titles: <?php echo $lowStockCount; ?></span>
        <span class="chip">Out of stock titles: <?php echo $outOfStockCount; ?></span>
      </div>
      <div class="table-wrap table-wrap-top">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Book</th>
              <th>Author</th>
              <th>Category</th>
              <th>ISBN</th>
              <th>Total</th>
              <th>Available</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($books->num_rows === 0): ?>
              <tr><td colspan="8" class="muted">No books matched your current filters.</td></tr>
            <?php endif; ?>
              <?php while ($book = $books->fetch_assoc()): ?>
              <?php $borrowedCount = max(0, (int) $book['qty_total'] - (int) $book['qty_available']); ?>
              <tr
                data-book-row
                data-book-id="<?php echo (int) $book['id']; ?>"
                data-book-catalog-id="<?php echo (int) ($book['catalog_id'] ?? 0); ?>"
                data-title="<?php echo h(strtolower($book['title'])); ?>"
                data-author="<?php echo h(strtolower($book['author'])); ?>"
                data-category="<?php echo h(strtolower($book['category'])); ?>"
              >
                <td><?php echo (int) $book['id']; ?></td>
                <td>
                  <div class="book-media">
                    <?php if (!empty($book['cover_path'])): ?>
                      <img class="book-cover" src="/librarymanage/<?php echo h($book['cover_path']); ?>" alt="<?php echo h($book['title']); ?>">
                    <?php else: ?>
                      <div class="book-cover placeholder">No Cover</div>
                    <?php endif; ?>
                    <div>
                      <strong class="label-block"><?php echo h($book['title']); ?></strong>
                      <?php if (!empty($book['description'])): ?>
                        <span class="muted"><?php echo h($book['description']); ?></span>
                      <?php endif; ?>
                      <span class="muted"><?php echo (int) $book['qty_available'] <= 0 ? 'Unavailable now' : ((int) $book['qty_available'] <= 2 ? 'Low stock title' : 'Ready to borrow'); ?></span>
                    </div>
                  </div>
                </td>
                <td><?php echo h($book['author']); ?></td>
                <td><?php echo h((string) ($book['category'] ?: '-')); ?></td>
                <td><?php echo h((string) ($book['isbn'] ?: '-')); ?></td>
                <td><?php echo (int) $book['qty_total']; ?></td>
                <td class="manage-books-stock-cell">
                  <div class="stack stack-compact manage-books-stock-stack">
                    <span class="badge">
                      <span class="status-dot <?php echo (int) $book['qty_available'] <= 0 ? 'overdue' : ((int) $book['qty_available'] <= 2 ? 'due' : 'approved'); ?>"></span>
                      <?php echo (int) $book['qty_available']; ?> available
                    </span>
                    <?php if ($borrowedCount > 0): ?>
                      <span class="badge">
                        <span class="status-dot due"></span>
                        <?php echo $borrowedCount; ?> borrowed
                      </span>
                    <?php else: ?>
                      <span class="badge">
                        <span class="status-dot idle"></span>
                        0 borrowed
                      </span>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="manage-books-action-cell">
                  <div class="manage-books-action-stack">
                    <a class="button secondary" href="edit_book.php?id=<?php echo (int) $book['id']; ?>">Edit</a>
                    <form method="post" class="inline-form" data-confirm="Delete this book?">
                    <input type="hidden" name="id" value="<?php echo (int) $book['id']; ?>">
                    <button type="submit" class="danger" name="delete" value="1">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <p id="client-filter-empty" class="muted hidden flow-top-sm">No books match the current on-page filter.</p>
    </div>
  </div>
  </div>
</div>
<div class="desk-modal" data-book-add-modal <?php echo $shouldOpenAddBookModal ? '' : 'hidden'; ?>>
  <div class="desk-modal-backdrop" data-close-book-add-modal></div>
  <div class="desk-modal-dialog panel" role="dialog" aria-modal="true" aria-labelledby="book-add-modal-title">
    <div class="desk-modal-head">
      <div>
        <p class="muted eyebrow-compact">Add Book</p>
        <h3 id="book-add-modal-title" class="heading-card">Create a new library entry</h3>
        <p class="muted">After filling in the book details, assign the book to an existing catalog instead of typing the catalog manually.</p>
      </div>
      <button type="button" class="button secondary" data-close-book-add-modal>Close</button>
    </div>

    <form method="post" enctype="multipart/form-data" class="stack flow-top-lg">
      <div class="stack">
        <div>
          <p class="muted eyebrow-compact">Book Details</p>
          <h4 class="heading-top-md">Catalog metadata for this title</h4>
        </div>
        <div class="empty-state">Need a new catalog first? Create it in <a href="/librarymanage/librarian/manage_catalogs.php">Catalog Management</a>, then come back here to assign the book.</div>
      </div>
      <div class="grid form">
        <div>
          <label for="title">Book Title</label>
          <input id="title" name="title" value="<?php echo h($formData['title']); ?>" placeholder="Introduction to Programming" required>
        </div>
        <div>
          <label for="author">Author</label>
          <input id="author" name="author" value="<?php echo h($formData['author']); ?>" placeholder="John Doe" required>
        </div>
        <div>
          <label for="catalog_id">Catalog</label>
          <div class="ui-select-shell">
            <select id="catalog_id" name="catalog_id" class="ui-select" required>
              <option value="">Select catalog</option>
              <?php foreach ($catalogs as $catalog): ?>
                <option value="<?php echo (int) $catalog['id']; ?>" <?php echo $formData['catalog_id'] === (string) $catalog['id'] ? 'selected' : ''; ?>>
                  <?php echo h($catalog['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="ui-select-caret" aria-hidden="true"></span>
          </div>
        </div>
        <div>
          <label for="isbn">ISBN-13</label>
          <input id="isbn" name="isbn" value="<?php echo h($formData['isbn']); ?>" placeholder="9781234567890" inputmode="numeric" pattern="\d{13}" maxlength="13" aria-describedby="isbn_help">
          <div id="isbn_help" class="muted">Enter exactly 13 digits. Example: 9781234567890</div>
        </div>
        <div>
          <label for="qty">Starting Quantity</label>
          <input id="qty" type="number" name="qty" value="<?php echo (int) $formData['qty']; ?>" min="1" required>
        </div>
        <div class="form-span-2">
          <label for="description">Description</label>
          <textarea id="description" name="description" rows="4" placeholder="Short catalog note or summary"><?php echo h($formData['description']); ?></textarea>
        </div>
        <div>
          <label for="cover">Book Cover</label>
          <input id="cover" type="file" name="cover" accept=".jpg,.jpeg,.png,.webp">
          <div class="book-media book-media-top">
            <img id="add-cover-preview" class="book-cover" src="" alt="Selected cover preview" hidden>
          </div>
        </div>
      </div>

      <div class="stack">
        <div>
          <p class="muted eyebrow-compact">Inventory</p>
          <h4 class="heading-top-md">Starting stock for this new title</h4>
        </div>
        <div class="librarian-book-add-stock-grid">
          <div class="empty-state librarian-book-add-stock-card">
            <span class="code-pill">Total on create</span>
            <strong><?php echo (int) $formData['qty']; ?></strong>
            <span class="muted">A new record starts with the same total and available stock.</span>
          </div>
          <div class="empty-state librarian-book-add-stock-card">
            <span class="code-pill">Borrowed on create</span>
            <strong>0</strong>
            <span class="muted">Newly added titles begin with no borrowed copies yet.</span>
          </div>
        </div>
      </div>

      <div class="inline-actions">
        <button type="submit" name="add" value="1">Add Book</button>
        <span class="muted">Book details, assigned catalog, and starting stock are saved together on one book record.</span>
      </div>
    </form>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/shared_confirm.js"></script>
<script src="/librarymanage/assets/admin_ajax_panel.js?v=<?php echo urlencode($ajaxPanelVersion); ?>"></script>
<script src="/librarymanage/assets/librarian_manage_books.js?v=<?php echo urlencode($manageBooksVersion); ?>"></script>
<script src="/librarymanage/assets/librarian_manage_books_tools.js?v=<?php echo urlencode($manageBooksToolsVersion); ?>"></script>
<?php endif; ?>
<?php if ($printMode): ?>
<script src="/librarymanage/assets/librarian_manage_books_print.js?v=<?php echo urlencode($manageBooksPrintVersion); ?>"></script>
<?php endif; ?>
</body>
</html>


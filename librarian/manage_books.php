<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

$message = '';
$messageType = 'success';
$search = trim($_GET['search'] ?? '');
$catalogFilter = trim((string) ($_GET['catalog'] ?? $_GET['category'] ?? ''));
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
    } elseif ($isbn !== '' && !preg_match('/^[0-9Xx-]+$/', $isbn)) {
        $message = 'ISBN may only contain numbers, hyphens, and X.';
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
            $stmt = $conn->prepare("
                INSERT INTO books (title, author, category, catalog_id, isbn, description, cover_path, qty_total, qty_available)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('sssisssii', $title, $author, $catalogName, $catalogId, $isbnValue, $descriptionValue, $coverPath, $qty, $qty);
            $stmt->execute();
            $stmt->close();
            $message = 'Book added successfully.';
            $formData = [
                'title' => '',
                'author' => '',
                'catalog_id' => '',
                'isbn' => '',
                'description' => '',
                'qty' => 1,
            ];
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Books')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
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
            <p class="muted">After filling in the book details, assign the book to an existing catalog instead of typing the catalog manually.</p>
          </div>
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
              <label for="isbn">ISBN</label>
              <input id="isbn" name="isbn" value="<?php echo h($formData['isbn']); ?>" placeholder="978-1234567890">
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
              <h4 class="heading-top-md">Physical copies for this catalog record</h4>
            </div>
            <div class="empty-state">Starting quantity becomes both total copies and available copies after the book is assigned to the selected catalog.</div>
          </div>

          <div class="inline-actions">
            <button type="submit" name="add" value="1">Add Book</button>
            <span class="muted">Book details, assigned catalog, and starting stock are saved together on one book record.</span>
          </div>
        </form>
      </div>

      <div class="panel">
          <div class="card-head">
            <div class="dashboard-icon icon-guide" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Workflow Notes</p>
              <h3 class="heading-card">Daily catalog reminders</h3>
            <p class="muted">Create the catalog once, assign books to it consistently, verify stock before editing totals, and update covers only when the title record is final.</p>
          </div>
        </div>
        <div class="stack">
          <div class="empty-state">
            <strong class="label-block-gap">Catalog accuracy</strong>
            Keep catalog names clean, for example "Computer Science", "Education", or "Criminology", then assign every new book to one of those records.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Catalog management</strong>
            Rename, review, or delete unused catalogs from <a href="/librarymanage/librarian/manage_catalogs.php">Catalog Management</a> instead of changing catalog structure here.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Stock review</strong>
            Titles with very low available copies should be checked before the end of the day to avoid borrow confusion.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Edit workflow</strong>
            Use the separate edit page when changing totals or replacing covers so the list view stays focused on quick actions.
          </div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="manage-books-records-head">
        <div class="card-head card-head-tight">
          <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Catalog Records</p>
            <h3 class="heading-card">Books list and stock review</h3>
            <p class="muted">Search by title or author, narrow by assigned catalog, and jump to edit when copy counts need correction.</p>
          </div>
        </div>
        <form method="get" class="manage-books-tablefilters">
          <div class="grow">
            <label for="search">Search</label>
            <input id="search" name="search" value="<?php echo h($search); ?>" placeholder="Search title or author">
          </div>
          <div>
            <label for="catalog_filter">Catalog</label>
            <div class="ui-select-shell">
              <select id="catalog_filter" name="catalog" class="ui-select">
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
          <div class="inline-actions">
            <button type="submit">Apply</button>
            <a class="button secondary" href="manage_books.php">Reset</a>
          </div>
        </form>
      </div>
      <div class="inline-actions chips-row">
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
              <tr data-book-row data-title="<?php echo h(strtolower($book['title'])); ?>" data-author="<?php echo h(strtolower($book['author'])); ?>" data-category="<?php echo h(strtolower($book['category'])); ?>">
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
                <td>
                  <span class="badge">
                    <span class="status-dot <?php echo (int) $book['qty_available'] <= 0 ? 'overdue' : ((int) $book['qty_available'] <= 2 ? 'due' : 'approved'); ?>"></span>
                    <?php echo (int) $book['qty_available']; ?> available
                  </span>
                </td>
                <td class="inline-actions">
                  <a class="button secondary" href="edit_book.php?id=<?php echo (int) $book['id']; ?>">Edit</a>
                  <form method="post" class="inline-form" data-confirm="Delete this book?">
                    <input type="hidden" name="id" value="<?php echo (int) $book['id']; ?>">
                    <button type="submit" class="danger" name="delete" value="1">Delete</button>
                  </form>
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
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/shared_confirm.js"></script>
<script src="/librarymanage/assets/librarian_manage_books.js"></script>
</body>
</html>

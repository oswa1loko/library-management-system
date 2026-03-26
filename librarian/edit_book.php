<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

$bookId = max(0, (int) ($_GET['id'] ?? $_POST['id'] ?? 0));
$message = '';
$messageType = 'error';

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

if (isset($_POST['update'])) {
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $catalogId = max(0, (int) ($_POST['catalog_id'] ?? 0));
    $isbn = trim($_POST['isbn'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $currentBookStmt = $conn->prepare("
        SELECT qty_total, qty_available
        FROM books
        WHERE id = ?
        LIMIT 1
    ");
    $currentBookStmt->bind_param('i', $bookId);
    $currentBookStmt->execute();
    $currentBook = $currentBookStmt->get_result()->fetch_assoc();
    $currentBookStmt->close();

    $currentTotal = (int) ($currentBook['qty_total'] ?? 0);
    $currentAvailable = (int) ($currentBook['qty_available'] ?? 0);
    $additionalCopies = max(0, (int) ($_POST['qty_add'] ?? 0));
    $removedCopies = max(0, (int) ($_POST['qty_remove'] ?? 0));
    $netAvailableBeforeValidation = $currentAvailable + $additionalCopies;
    $available = $netAvailableBeforeValidation - $removedCopies;
    $total = $currentTotal + $additionalCopies - $removedCopies;
    $existingCoverPath = trim($_POST['existing_cover_path'] ?? '');
    $coverUpload = upload_book_cover($_FILES['cover'] ?? [], $existingCoverPath);
    $borrowedCopiesStmt = $conn->prepare("
        SELECT COUNT(*) AS borrowed_copies
        FROM borrows
        WHERE book_id = ?
          AND status IN ('borrowed', 'return_requested')
    ");
    $borrowedCopiesStmt->bind_param('i', $bookId);
    $borrowedCopiesStmt->execute();
    $borrowedCopiesRow = $borrowedCopiesStmt->get_result()->fetch_assoc();
    $borrowedCopiesStmt->close();
    $borrowedCopies = (int) ($borrowedCopiesRow['borrowed_copies'] ?? 0);
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

    if ($bookId <= 0 || $title === '') {
        $message = 'Book title is required.';
    } elseif ($author === '') {
        $message = 'Author is required.';
    } elseif (!$selectedCatalog) {
        $message = 'Select a catalog first.';
    } elseif ($isbn !== '' && !preg_match('/^\d{13}$/', $isbn)) {
        $message = 'ISBN must contain exactly 13 digits.';
    } elseif ($removedCopies > $netAvailableBeforeValidation) {
        $message = 'Remove copies cannot be greater than the available shelf stock (' . $netAvailableBeforeValidation . ').';
    } elseif ($total < $borrowedCopies) {
        $message = 'Total quantity cannot be lower than the number of copies currently borrowed (' . $borrowedCopies . ').';
    } elseif ($coverUpload['error'] !== '') {
        $message = $coverUpload['error'];
    } else {
        $coverPath = $coverUpload['path'] ?: null;
        $catalogName = trim((string) ($selectedCatalog['name'] ?? ''));
        $isbnValue = $isbn !== '' ? $isbn : null;
        $descriptionValue = $description !== '' ? $description : null;
        $stmt = $conn->prepare("
            UPDATE books
            SET title = ?, author = ?, category = ?, catalog_id = ?, isbn = ?, description = ?, cover_path = ?, qty_total = ?, qty_available = ?
            WHERE id = ?
        ");
        $stmt->bind_param('sssisssiii', $title, $author, $catalogName, $catalogId, $isbnValue, $descriptionValue, $coverPath, $total, $available, $bookId);
        $stmt->execute();
        $stmt->close();
        header('Location: manage_books.php');
        exit;
    }
}

$stmt = $conn->prepare("SELECT * FROM books WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $bookId);
$stmt->execute();
$book = $stmt->get_result()->fetch_assoc();
$stmt->close();

$catalogRows = $conn->query("SELECT id, name, description FROM catalogs ORDER BY name ASC");
$catalogs = [];
while ($catalogRows && ($catalogRow = $catalogRows->fetch_assoc())) {
    $catalogs[] = $catalogRow;
}

if (!$book) {
    http_response_code(404);
}

$activeBorrowedStmt = $conn->prepare("
    SELECT COUNT(*) AS borrowed_copies
    FROM borrows
    WHERE book_id = ?
      AND status IN ('borrowed', 'return_requested')
");
$activeBorrowedStmt->bind_param('i', $bookId);
$activeBorrowedStmt->execute();
$activeBorrowedRow = $activeBorrowedStmt->get_result()->fetch_assoc();
$activeBorrowedStmt->close();

$borrowedCopies = (int) ($activeBorrowedRow['borrowed_copies'] ?? 0);
$currentTotal = (int) ($book['qty_total'] ?? 0);
$currentAvailable = (int) ($book['qty_available'] ?? 0);
$pendingAddedCopies = max(0, (int) ($_POST['qty_add'] ?? 0));
$pendingRemovedCopies = max(0, (int) ($_POST['qty_remove'] ?? 0));
$displayTotal = max(0, $currentTotal + $pendingAddedCopies - $pendingRemovedCopies);
$displayAvailable = max(0, $currentAvailable + $pendingAddedCopies - $pendingRemovedCopies);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Book Editor')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
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
  $pageTitle = 'Librarian Book Editor';
  $pageSubtitle = 'Separate editor for catalog updates';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <?php if (!$book): ?>
      <?php
      $noticeItems = [['type' => 'error', 'message' => 'Book record not found.']];
      require __DIR__ . '/partials/notices.php';
      ?>
    <?php else: ?>
      <?php
      $noticeItems = [];
      if ($message !== '') {
          $noticeItems[] = ['type' => $messageType, 'message' => $message];
      }
      require __DIR__ . '/partials/notices.php';
      ?>

      <div class="panel librarian-edit-book-overview">
        <div class="card-head">
          <div class="dashboard-icon icon-tools" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Edit Workspace</p>
            <h3 class="heading-card">Adjust title details and copy counts safely</h3>
            <p class="muted">Keep available copies aligned with the real shelf count. If copies are currently borrowed, do not reduce total stock below what is already checked out.</p>
          </div>
        </div>
        <div class="stat-grid">
          <div class="stat-card">
            <span class="code-pill">Book ID</span>
            <strong>#<?php echo (int) $book['id']; ?></strong>
            <span class="muted">Current catalog record being edited.</span>
          </div>
          <div class="stat-card">
            <span class="code-pill">Total</span>
            <strong><?php echo (int) $book['qty_total']; ?></strong>
            <span class="muted">All copies tracked for this title.</span>
          </div>
          <div class="stat-card">
            <span class="code-pill">Available</span>
            <strong><?php echo (int) $book['qty_available']; ?></strong>
            <span class="muted">Copies immediately ready for borrowing.</span>
          </div>
          <div class="stat-card">
            <span class="code-pill">Borrowed Out</span>
            <strong><?php echo $borrowedCopies; ?></strong>
            <span class="muted">Copies still outside the shelf and not yet returned.</span>
          </div>
        </div>
      </div>

      <div class="grid cards librarian-edit-book-grid">
        <div class="panel librarian-edit-book-main">
            <div class="card-head">
            <div class="dashboard-icon icon-edit" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Book Editor</p>
              <h3 class="heading-card"><?php echo h($book['title']); ?></h3>
              <p class="muted">Update the catalog metadata and inventory for this one specific book record.</p>
            </div>
          </div>

          <form method="post" enctype="multipart/form-data" class="stack flow-top-md librarian-edit-book-form">
            <input type="hidden" name="id" value="<?php echo (int) $book['id']; ?>">
            <input type="hidden" name="existing_cover_path" value="<?php echo h($book['cover_path'] ?? ''); ?>">

            <div class="stack">
              <div>
                <p class="muted eyebrow-compact">Book Details</p>
                <h4 class="heading-top-md">Catalog metadata for this title</h4>
              </div>
            </div>
            <div class="grid form">
              <div>
                <label for="title">Title</label>
                <input id="title" name="title" value="<?php echo h($_POST['title'] ?? $book['title']); ?>" required>
              </div>
              <div>
                <label for="author">Author</label>
                <input id="author" name="author" value="<?php echo h($_POST['author'] ?? $book['author']); ?>" required>
              </div>
              <div>
                <label for="catalog_id">Catalog</label>
                <div class="ui-select-shell">
                  <select id="catalog_id" name="catalog_id" class="ui-select" required>
                    <option value="">Select catalog</option>
                    <?php foreach ($catalogs as $catalog): ?>
                      <option value="<?php echo (int) $catalog['id']; ?>" <?php echo (string) ($_POST['catalog_id'] ?? ($book['catalog_id'] ?? '')) === (string) $catalog['id'] ? 'selected' : ''; ?>>
                        <?php echo h($catalog['name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <span class="ui-select-caret" aria-hidden="true"></span>
                </div>
              </div>
              <div>
                <label for="isbn">ISBN-13</label>
                <input id="isbn" name="isbn" value="<?php echo h($_POST['isbn'] ?? ($book['isbn'] ?? '')); ?>" placeholder="9781234567890" inputmode="numeric" pattern="\d{13}" maxlength="13" aria-describedby="isbn_help">
                <div id="isbn_help" class="muted">Enter exactly 13 digits. Example: 9781234567890</div>
              </div>
              <div>
                <label for="qty_total">Total quantity</label>
                <input id="qty_total" type="number" name="qty_total" value="<?php echo $displayTotal; ?>" min="0" readonly aria-readonly="true" aria-describedby="qty_total_help">
                <div id="qty_total_help" class="muted">Auto-updates from the current total plus the copies you add.</div>
              </div>
              <div>
                <label for="qty_add">Add copies</label>
                <input id="qty_add" type="number" name="qty_add" value="<?php echo $pendingAddedCopies; ?>" min="0" aria-describedby="qty_add_help">
                <div id="qty_add_help" class="muted">Adding copies increases both total and available stock while keeping the <?php echo $borrowedCopies; ?> borrowed cop<?php echo $borrowedCopies === 1 ? 'y' : 'ies'; ?> counted.</div>
              </div>
              <div>
                <label for="qty_remove">Remove copies</label>
                <input id="qty_remove" type="number" name="qty_remove" value="<?php echo $pendingRemovedCopies; ?>" min="0" aria-describedby="qty_remove_help">
                <div id="qty_remove_help" class="muted">Remove only from shelf stock. You can remove up to <?php echo $currentAvailable + $pendingAddedCopies; ?> available cop<?php echo ($currentAvailable + $pendingAddedCopies) === 1 ? 'y' : 'ies'; ?> without touching borrowed books.</div>
              </div>
              <div>
                <label for="qty_available">Available quantity</label>
                <input id="qty_available" type="number" name="qty_available" value="<?php echo $displayAvailable; ?>" min="0" readonly aria-readonly="true" aria-describedby="qty_available_help">
                <div id="qty_available_help" class="muted">Auto-updates based on current available copies plus added stock. Borrowed copies stay reserved.</div>
              </div>
              <div class="form-span-2">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4"><?php echo h($_POST['description'] ?? ($book['description'] ?? '')); ?></textarea>
              </div>
              <div>
                <label for="cover">Replace cover</label>
                <input id="cover" type="file" name="cover" accept=".jpg,.jpeg,.png,.webp">
                <div class="book-media book-media-top">
                  <img id="edit-cover-preview" class="book-cover" src="" alt="Replacement cover preview" hidden>
                </div>
              </div>
            </div>

            <div class="stack">
              <div>
                <p class="muted eyebrow-compact">Inventory</p>
                <h4 class="heading-top-md">Physical copies tied to this record</h4>
              </div>
              <div class="empty-state">Use Add copies for restocking and Remove copies for safe stock reduction. Total and available quantities update automatically while borrowed copies remain reserved.</div>
            </div>

            <div class="inline-actions librarian-edit-book-actions">
              <button type="submit" name="update" value="1">Save Changes</button>
              <a class="button secondary" href="manage_books.php">Back to Books</a>
            </div>
            <div class="inline-actions librarian-edit-book-chips">
              <span class="chip">Borrowed out: <?php echo $borrowedCopies; ?></span>
              <span class="chip">Available now: <?php echo $displayAvailable; ?></span>
              <span class="chip">Catalog: <?php echo h($book['category']); ?></span>
              <?php if (!empty($book['isbn'])): ?>
                <span class="chip">ISBN: <?php echo h($book['isbn']); ?></span>
              <?php endif; ?>
            </div>
          </form>
        </div>

        <div class="panel librarian-edit-book-side">
          <div class="card-head">
            <div class="dashboard-icon icon-view" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Current Record</p>
              <h3 class="heading-card">Catalog snapshot</h3>
              <p class="muted">Review the current state before saving. This helps prevent accidental stock mismatches.</p>
            </div>
          </div>
          <div class="stack">
            <div class="empty-state">Book ID: <strong>#<?php echo (int) $book['id']; ?></strong></div>
            <div class="empty-state">Total copies: <strong><?php echo $displayTotal; ?></strong></div>
            <div class="empty-state">Available copies: <strong><?php echo $displayAvailable; ?></strong></div>
            <div class="empty-state">Borrowed out: <strong><?php echo $borrowedCopies; ?></strong></div>
            <div class="empty-state">Catalog: <strong><?php echo h((string) (($book['category'] ?? '') !== '' ? $book['category'] : '-')); ?></strong></div>
            <div class="empty-state">ISBN: <strong><?php echo h((string) (($book['isbn'] ?? '') !== '' ? $book['isbn'] : '-')); ?></strong></div>
          </div>

          <div class="book-media book-media-start">
            <?php if (!empty($book['cover_path'])): ?>
              <img class="book-cover book-cover-tall" src="/librarymanage/<?php echo h($book['cover_path']); ?>" alt="<?php echo h($book['title']); ?>">
            <?php else: ?>
              <div class="book-cover placeholder book-cover-tall">No Cover</div>
            <?php endif; ?>
            <div>
              <strong class="label-block"><?php echo h($book['title']); ?></strong>
              <span class="muted"><?php echo h($book['author']); ?></span><br>
              <span class="muted"><?php echo h($book['category']); ?></span>
              <?php if (!empty($book['description'])): ?>
                <br><span class="muted"><?php echo h($book['description']); ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="empty-state empty-state-top">
            <strong class="label-block-gap">Editing note</strong>
            If a copy is still borrowed, leave enough total stock recorded so the available count does not hide checked-out books.
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/librarian_edit_book.js"></script>
</body>
</html>


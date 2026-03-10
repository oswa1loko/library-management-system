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
    $category = trim($_POST['category'] ?? '');
    $total = max(0, (int) ($_POST['qty_total'] ?? 1));
    $requestedAvailable = (int) ($_POST['qty_available'] ?? 1);
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
    $minimumAvailable = max(0, $total - $borrowedCopies);
    $available = max($minimumAvailable, min($requestedAvailable, $total));

    if ($bookId <= 0 || $title === '') {
        $message = 'Book title is required.';
    } elseif ($author === '') {
        $message = 'Author is required.';
    } elseif ($category === '') {
        $message = 'Category is required.';
    } elseif ($total < $borrowedCopies) {
        $message = 'Total quantity cannot be lower than the number of copies currently borrowed (' . $borrowedCopies . ').';
    } elseif ($requestedAvailable < $minimumAvailable) {
        $message = 'Available quantity cannot hide borrowed copies. Minimum allowed right now is ' . $minimumAvailable . '.';
    } elseif ($coverUpload['error'] !== '') {
        $message = $coverUpload['error'];
    } else {
        $coverPath = $coverUpload['path'] ?: null;
        $stmt = $conn->prepare("UPDATE books SET title = ?, author = ?, category = ?, cover_path = ?, qty_total = ?, qty_available = ? WHERE id = ?");
        $stmt->bind_param('ssssiii', $title, $author, $category, $coverPath, $total, $available, $bookId);
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

if (!$book) {
    http_response_code(404);
}

$borrowedCopies = $book ? max(0, (int) $book['qty_total'] - (int) $book['qty_available']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Book Editor')); ?></title>
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
              <p class="muted">Update book details, stock levels, and cover image from this dedicated edit screen.</p>
            </div>
          </div>

          <form method="post" enctype="multipart/form-data" class="stack flow-top-md librarian-edit-book-form">
            <input type="hidden" name="id" value="<?php echo (int) $book['id']; ?>">
            <input type="hidden" name="existing_cover_path" value="<?php echo h($book['cover_path'] ?? ''); ?>">

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
                <label for="category">Category</label>
                <input id="category" name="category" value="<?php echo h($_POST['category'] ?? $book['category']); ?>" required>
              </div>
              <div>
                <label for="qty_total">Total quantity</label>
                <input id="qty_total" type="number" name="qty_total" value="<?php echo (int) ($_POST['qty_total'] ?? $book['qty_total']); ?>" min="0" required>
              </div>
              <div>
                <label for="qty_available">Available quantity</label>
                <input id="qty_available" type="number" name="qty_available" value="<?php echo (int) ($_POST['qty_available'] ?? $book['qty_available']); ?>" min="0" required>
              </div>
              <div>
                <label for="cover">Replace cover</label>
                <input id="cover" type="file" name="cover" accept=".jpg,.jpeg,.png,.webp">
                <div class="book-media book-media-top">
                  <img id="edit-cover-preview" class="book-cover" src="" alt="Replacement cover preview" hidden>
                </div>
              </div>
            </div>

            <div class="inline-actions librarian-edit-book-actions">
              <button type="submit" name="update" value="1">Save Changes</button>
              <a class="button secondary" href="manage_books.php">Back to Books</a>
            </div>
            <div class="inline-actions librarian-edit-book-chips">
              <span class="chip">Borrowed out: <?php echo $borrowedCopies; ?></span>
              <span class="chip">Available now: <?php echo (int) $book['qty_available']; ?></span>
              <span class="chip">Category: <?php echo h($book['category']); ?></span>
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
            <div class="empty-state">Total copies: <strong><?php echo (int) $book['qty_total']; ?></strong></div>
            <div class="empty-state">Available copies: <strong><?php echo (int) $book['qty_available']; ?></strong></div>
            <div class="empty-state">Borrowed out: <strong><?php echo $borrowedCopies; ?></strong></div>
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

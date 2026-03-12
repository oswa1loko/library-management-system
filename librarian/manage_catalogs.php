<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

$message = '';
$messageType = 'success';
$search = trim((string) ($_GET['search'] ?? ''));
$selectedCatalogId = max(0, (int) ($_GET['catalog'] ?? $_POST['catalog_id'] ?? 0));
$formData = [
    'name' => '',
    'description' => '',
];

function upload_catalog_cover(array $file, string $existingPath = ''): array
{
    if (empty($file['name'])) {
        return ['path' => $existingPath, 'error' => '', 'replaced' => false];
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowed, true)) {
        return ['path' => $existingPath, 'error' => 'Only JPG, JPEG, PNG, and WEBP images are allowed for catalogs.', 'replaced' => false];
    }

    $directory = __DIR__ . '/../uploads/catalog_covers';
    if (!ensure_upload_directory($directory)) {
        return ['path' => $existingPath, 'error' => 'Catalog cover folder could not be created.', 'replaced' => false];
    }

    $filename = 'catalog_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $target = $directory . '/' . $filename;

    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $target)) {
        return ['path' => $existingPath, 'error' => 'Catalog image upload failed.', 'replaced' => false];
    }

    return ['path' => 'uploads/catalog_covers/' . $filename, 'error' => '', 'replaced' => true];
}

if (isset($_POST['create_catalog'])) {
    $catalogName = trim((string) ($_POST['catalog_name'] ?? ''));
    $catalogDescription = trim((string) ($_POST['catalog_description'] ?? ''));
    $coverUpload = upload_catalog_cover($_FILES['catalog_cover'] ?? []);
    $formData = [
        'name' => $catalogName,
        'description' => $catalogDescription,
    ];

    if ($catalogName === '') {
        $message = 'Catalog name is required.';
        $messageType = 'error';
    } elseif ($coverUpload['error'] !== '') {
        $message = (string) $coverUpload['error'];
        $messageType = 'error';
    } else {
        $catalogDescriptionValue = $catalogDescription !== '' ? $catalogDescription : null;
        $catalogCoverPath = (string) ($coverUpload['path'] ?? '');
        $catalogCoverValue = $catalogCoverPath !== '' ? $catalogCoverPath : null;
        $stmt = $conn->prepare("
            INSERT INTO catalogs (name, description, cover_path)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param('sss', $catalogName, $catalogDescriptionValue, $catalogCoverValue);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            if (!empty($catalogCoverPath)) {
                remove_relative_file($catalogCoverPath);
            }
            $message = 'This catalog already exists.';
            $messageType = 'error';
        } else {
            audit_log($conn, 'librarian.catalog.create', [
                'name' => $catalogName,
            ]);
            $message = 'Catalog created successfully.';
            $formData = [
                'name' => '',
                'description' => '',
            ];
        }
    }
}

if (isset($_POST['rename_catalog'])) {
    $catalogId = max(0, (int) ($_POST['catalog_id'] ?? 0));
    $catalogName = trim((string) ($_POST['catalog_name'] ?? ''));
    $catalogDescription = trim((string) ($_POST['catalog_description'] ?? ''));
    $existingCoverPath = trim((string) ($_POST['existing_cover_path'] ?? ''));
    $coverUpload = upload_catalog_cover($_FILES['catalog_cover'] ?? [], $existingCoverPath);

    if ($catalogId <= 0 || $catalogName === '') {
        $message = 'Catalog name is required.';
        $messageType = 'error';
    } elseif ($coverUpload['error'] !== '') {
        $message = (string) $coverUpload['error'];
        $messageType = 'error';
    } else {
        $catalogDescriptionValue = $catalogDescription !== '' ? $catalogDescription : null;
        $catalogCoverPath = (string) ($coverUpload['path'] ?? '');
        $catalogCoverValue = $catalogCoverPath !== '' ? $catalogCoverPath : null;
        $stmt = $conn->prepare("
            UPDATE catalogs
            SET name = ?, description = ?, cover_path = ?
            WHERE id = ?
        ");
        $stmt->bind_param('sssi', $catalogName, $catalogDescriptionValue, $catalogCoverValue, $catalogId);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            if (!empty($catalogCoverPath) && $catalogCoverPath !== $existingCoverPath) {
                remove_relative_file($catalogCoverPath);
            }
            $message = 'Unable to rename this catalog. The name may already be in use.';
            $messageType = 'error';
        } else {
            if (!empty($existingCoverPath) && !empty($catalogCoverPath) && $catalogCoverPath !== $existingCoverPath) {
                remove_relative_file($existingCoverPath);
            }
            $sync = $conn->prepare("
                UPDATE books
                SET category = ?
                WHERE catalog_id = ?
            ");
            $sync->bind_param('si', $catalogName, $catalogId);
            $sync->execute();
            $sync->close();

            audit_log($conn, 'librarian.catalog.rename', [
                'catalog_id' => $catalogId,
                'name' => $catalogName,
            ]);
            $message = 'Catalog updated successfully.';
        }
    }
}

if (isset($_POST['delete_catalog'])) {
    $catalogId = max(0, (int) ($_POST['catalog_id'] ?? 0));

    if ($catalogId <= 0) {
        $message = 'Catalog record not found.';
        $messageType = 'error';
    } else {
        $usageStmt = $conn->prepare("
            SELECT COUNT(*) AS assigned_books
            FROM books
            WHERE catalog_id = ?
        ");
        $usageStmt->bind_param('i', $catalogId);
        $usageStmt->execute();
        $usage = $usageStmt->get_result()->fetch_assoc();
        $usageStmt->close();

        $assignedBooks = (int) ($usage['assigned_books'] ?? 0);
        if ($assignedBooks > 0) {
            $message = 'This catalog cannot be deleted while books are still assigned to it.';
            $messageType = 'error';
        } else {
            $catalogLookup = $conn->prepare("
                SELECT name, cover_path
                FROM catalogs
                WHERE id = ?
                LIMIT 1
            ");
            $catalogLookup->bind_param('i', $catalogId);
            $catalogLookup->execute();
            $catalogRow = $catalogLookup->get_result()->fetch_assoc();
            $catalogLookup->close();

            $deleteStmt = $conn->prepare("DELETE FROM catalogs WHERE id = ?");
            $deleteStmt->bind_param('i', $catalogId);
            $deleteStmt->execute();
            $deleteStmt->close();

            $catalogCoverPath = trim((string) ($catalogRow['cover_path'] ?? ''));
            if ($catalogCoverPath !== '') {
                remove_relative_file($catalogCoverPath);
            }

            audit_log($conn, 'librarian.catalog.delete', [
                'catalog_id' => $catalogId,
                'name' => (string) ($catalogRow['name'] ?? ''),
            ]);
            $message = 'Catalog deleted successfully.';
        }
    }
}

$stats = $conn->query("
    SELECT
      COUNT(*) AS total_catalogs,
      COALESCE(SUM(book_count), 0) AS assigned_books
    FROM (
        SELECT c.id, COUNT(b.id) AS book_count
        FROM catalogs c
        LEFT JOIN books b ON b.catalog_id = c.id
        GROUP BY c.id
    ) catalog_stats
")->fetch_assoc();

$unusedCatalogs = (int) ($conn->query("
    SELECT COUNT(*) AS total_unused
    FROM catalogs c
    LEFT JOIN books b ON b.catalog_id = c.id
    WHERE b.id IS NULL
")->fetch_assoc()['total_unused'] ?? 0);

$catalogSql = "
    SELECT
      c.id,
      c.name,
      c.description,
      c.cover_path,
      c.created_at,
      COUNT(b.id) AS assigned_books,
      COALESCE(SUM(b.qty_total), 0) AS total_copies,
      COALESCE(SUM(b.qty_available), 0) AS available_copies
    FROM catalogs c
    LEFT JOIN books b ON b.catalog_id = c.id
    WHERE 1 = 1
";
$catalogParams = [];
$catalogTypes = '';

if ($search !== '') {
    $catalogSql .= " AND (c.name LIKE ? OR COALESCE(c.description, '') LIKE ?)";
    $term = '%' . $search . '%';
    $catalogParams[] = $term;
    $catalogParams[] = $term;
    $catalogTypes .= 'ss';
}

$catalogSql .= "
    GROUP BY c.id, c.name, c.description, c.cover_path, c.created_at
    ORDER BY c.name ASC
";

$catalogStmt = $conn->prepare($catalogSql);
if ($catalogTypes !== '') {
    $catalogStmt->bind_param($catalogTypes, ...$catalogParams);
}
$catalogStmt->execute();
$catalogRows = $catalogStmt->get_result();
$catalogCards = [];
while ($catalogRows && ($catalogRow = $catalogRows->fetch_assoc())) {
    $catalogCards[] = $catalogRow;
}
$catalogStmt->close();

$selectedCatalog = null;
$selectedCatalogBooks = null;
if ($selectedCatalogId > 0) {
    $selectedCatalogStmt = $conn->prepare("
        SELECT
          c.id,
          c.name,
          c.description,
          c.cover_path,
          c.created_at,
          COUNT(b.id) AS assigned_books,
          COALESCE(SUM(b.qty_total), 0) AS total_copies,
          COALESCE(SUM(b.qty_available), 0) AS available_copies
        FROM catalogs c
        LEFT JOIN books b ON b.catalog_id = c.id
        WHERE c.id = ?
        GROUP BY c.id, c.name, c.description, c.cover_path, c.created_at
        LIMIT 1
    ");
    $selectedCatalogStmt->bind_param('i', $selectedCatalogId);
    $selectedCatalogStmt->execute();
    $selectedCatalog = $selectedCatalogStmt->get_result()->fetch_assoc();
    $selectedCatalogStmt->close();

    if ($selectedCatalog) {
        $selectedBooksStmt = $conn->prepare("
            SELECT id, title, author, isbn, cover_path, qty_total, qty_available
            FROM books
            WHERE catalog_id = ?
            ORDER BY title ASC
        ");
        $selectedBooksStmt->bind_param('i', $selectedCatalogId);
        $selectedBooksStmt->execute();
        $selectedCatalogBooks = $selectedBooksStmt->get_result();
        $selectedBooksStmt->close();
    } else {
        $selectedCatalogId = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Catalog Management')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell librarian-shell member-shell js-member-sidebar" data-sidebar-key="librarian-catalogs" data-sidebar-default="expanded">
  <?php
  $sidebarPage = 'catalogs';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Librarian Catalog Management';
  $pageSubtitle = 'Create, rename, and safeguard library catalogs';
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
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Catalog structure control</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo (int) ($stats['total_catalogs'] ?? 0); ?></strong>
          <span class="muted">Catalogs defined</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($stats['assigned_books'] ?? 0); ?></strong>
          <span class="muted">Books assigned to catalogs</span>
        </div>
        <div class="stat-card">
          <strong><?php echo $unusedCatalogs; ?></strong>
          <span class="muted">Catalogs not yet used by books</span>
        </div>
      </div>
    </div>

    <div class="grid cards">
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Create Catalog</p>
            <h3 class="heading-card">Add a controlled catalog record</h3>
            <p class="muted">Define the catalog once here, then assign books to it from the books page.</p>
          </div>
        </div>

        <form method="post" enctype="multipart/form-data" class="stack flow-top-md">
          <div class="grid form">
            <div>
              <label for="catalog_name">Catalog Name</label>
              <input id="catalog_name" name="catalog_name" value="<?php echo h($formData['name']); ?>" placeholder="Computer Science" required>
            </div>
            <div>
              <label for="catalog_cover">Catalog Image</label>
              <input id="catalog_cover" type="file" name="catalog_cover" accept=".jpg,.jpeg,.png,.webp">
            </div>
            <div class="form-span-2">
              <label for="catalog_description">Catalog Description</label>
              <textarea id="catalog_description" name="catalog_description" rows="4" placeholder="Short note about this catalog section"><?php echo h($formData['description']); ?></textarea>
            </div>
          </div>

          <div class="inline-actions">
            <button type="submit" name="create_catalog" value="1">Create Catalog</button>
            <a class="button secondary" href="/librarymanage/librarian/manage_books.php">Go to Books</a>
          </div>
        </form>
      </div>

      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-guide" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Safeguards</p>
            <h3 class="heading-card">Catalog handling rules</h3>
            <p class="muted">Keep catalog names stable and manage them here instead of typing them during book creation.</p>
          </div>
        </div>
        <div class="stack">
          <div class="empty-state">Rename catalogs here so assigned books stay synchronized with the updated catalog name.</div>
          <div class="empty-state">Deletion is blocked when books are still assigned to a catalog.</div>
          <div class="empty-state">Use the books page only for assigning titles and stock after the catalog exists.</div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="toolbar toolbar-top">
        <div class="grow">
          <div class="card-head card-head-tight">
            <div class="dashboard-icon icon-books" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Catalog Records</p>
              <h3 class="heading-card">Browse catalog cards</h3>
              <p class="muted">Click a catalog card to open its assigned books, total copies, and maintenance actions.</p>
            </div>
          </div>
        </div>
        <form method="get" class="toolbar grow">
          <div class="grow">
            <label for="search">Search</label>
            <input id="search" name="search" value="<?php echo h($search); ?>" placeholder="Search catalog name or description">
          </div>
          <div class="inline-actions">
            <button type="submit">Apply</button>
            <a class="button secondary" href="manage_catalogs.php">Reset</a>
          </div>
        </form>
      </div>

      <div class="grid cards flow-top-md librarian-catalog-grid">
        <?php if ($catalogCards === []): ?>
          <div class="empty-state">No catalogs matched your current filters.</div>
        <?php endif; ?>

        <?php foreach ($catalogCards as $catalog): ?>
          <div class="panel librarian-catalog-card<?php echo $selectedCatalogId === (int) $catalog['id'] ? ' is-active' : ''; ?>">
            <a class="librarian-catalog-card-link" href="manage_catalogs.php?catalog=<?php echo (int) $catalog['id']; ?><?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">
              <div class="librarian-catalog-card-media">
                <?php if (!empty($catalog['cover_path'])): ?>
                  <img class="book-cover book-cover-tall" src="/librarymanage/<?php echo h($catalog['cover_path']); ?>" alt="<?php echo h($catalog['name']); ?>">
                <?php else: ?>
                  <div class="book-cover placeholder book-cover-tall">No Image</div>
                <?php endif; ?>
              </div>
              <div class="stack">
                <div>
                  <strong class="label-block"><?php echo h($catalog['name']); ?></strong>
                  <span class="muted"><?php echo h((string) ($catalog['description'] !== '' ? $catalog['description'] : 'No description yet.')); ?></span>
                </div>
                <div class="inline-actions chips-row">
                  <span class="chip"><?php echo (int) $catalog['assigned_books']; ?> assigned</span>
                  <span class="chip"><?php echo (int) $catalog['total_copies']; ?> copies</span>
                  <span class="chip"><?php echo (int) $catalog['available_copies']; ?> available</span>
                </div>
                <span class="muted">Created <?php echo h(format_display_date((string) $catalog['created_at'], '-')); ?></span>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($catalogCards !== [] && !$selectedCatalog): ?>
      <div class="panel">
        <div class="empty-state">Click any catalog card above to open its assigned books and copy totals.</div>
      </div>
    <?php endif; ?>
  </div>
  </div>
</div>
<?php if ($selectedCatalog): ?>
  <div class="catalog-modal" data-catalog-modal>
    <a class="catalog-modal-backdrop" href="manage_catalogs.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>" aria-label="Close catalog details"></a>
    <div class="catalog-modal-dialog panel" role="dialog" aria-modal="true" aria-labelledby="catalog-modal-title">
      <div class="catalog-modal-head">
        <div>
          <p class="muted eyebrow-compact">Selected Catalog</p>
          <h3 id="catalog-modal-title" class="heading-card"><?php echo h($selectedCatalog['name']); ?></h3>
          <p class="muted">Review assigned books and total copies, then rename or delete the catalog from this detail view.</p>
        </div>
        <a class="button secondary" href="manage_catalogs.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>">Close</a>
      </div>

      <div class="grid cards">
        <div class="panel">
          <div class="librarian-catalog-card-media">
            <?php if (!empty($selectedCatalog['cover_path'])): ?>
              <img class="book-cover book-cover-tall" src="/librarymanage/<?php echo h($selectedCatalog['cover_path']); ?>" alt="<?php echo h($selectedCatalog['name']); ?>">
            <?php else: ?>
              <div class="book-cover placeholder book-cover-tall">No Image</div>
            <?php endif; ?>
          </div>
          <div class="inline-actions chips-row">
            <span class="chip"><?php echo (int) $selectedCatalog['assigned_books']; ?> assigned books</span>
            <span class="chip"><?php echo (int) $selectedCatalog['total_copies']; ?> total copies</span>
            <span class="chip"><?php echo (int) $selectedCatalog['available_copies']; ?> available copies</span>
            <span class="chip">Created <?php echo h(format_display_date((string) $selectedCatalog['created_at'], '-')); ?></span>
          </div>
          <div class="empty-state flow-top-md">
            <?php echo h((string) (($selectedCatalog['description'] ?? '') !== '' ? $selectedCatalog['description'] : 'No description yet.')); ?>
          </div>
          <form method="post" enctype="multipart/form-data" class="stack flow-top-md">
            <input type="hidden" name="catalog_id" value="<?php echo (int) $selectedCatalog['id']; ?>">
            <input type="hidden" name="existing_cover_path" value="<?php echo h((string) ($selectedCatalog['cover_path'] ?? '')); ?>">
            <div class="grid form">
              <div>
                <label for="catalog_name_selected">Catalog Name</label>
                <input id="catalog_name_selected" name="catalog_name" value="<?php echo h($selectedCatalog['name']); ?>" required>
              </div>
              <div>
                <label for="catalog_cover_selected">Catalog Image</label>
                <input id="catalog_cover_selected" type="file" name="catalog_cover" accept=".jpg,.jpeg,.png,.webp">
              </div>
              <div class="form-span-2">
                <label for="catalog_description_selected">Description</label>
                <textarea id="catalog_description_selected" name="catalog_description" rows="3"><?php echo h((string) $selectedCatalog['description']); ?></textarea>
              </div>
            </div>
            <div class="inline-actions">
              <button type="submit" name="rename_catalog" value="1">Save Catalog</button>
            </div>
          </form>
          <div class="inline-actions">
            <?php if ((int) $selectedCatalog['assigned_books'] === 0): ?>
              <form method="post" class="inline-form" data-confirm="Delete this catalog?">
                <input type="hidden" name="catalog_id" value="<?php echo (int) $selectedCatalog['id']; ?>">
                <button type="submit" class="danger" name="delete_catalog" value="1">Delete Catalog</button>
              </form>
            <?php else: ?>
              <span class="muted">Delete unavailable while books are assigned.</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="panel">
          <div class="card-head">
            <div class="dashboard-icon icon-books" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Assigned Books</p>
              <h3 class="heading-card">Books under this catalog</h3>
            </div>
          </div>

          <div class="stack">
            <?php if (!$selectedCatalogBooks || $selectedCatalogBooks->num_rows === 0): ?>
              <div class="empty-state">No books are assigned to this catalog yet.</div>
            <?php endif; ?>

            <?php while ($book = $selectedCatalogBooks->fetch_assoc()): ?>
              <div class="empty-state librarian-catalog-book-item">
                <div class="book-media">
                  <?php if (!empty($book['cover_path'])): ?>
                    <img class="book-cover" src="/librarymanage/<?php echo h($book['cover_path']); ?>" alt="<?php echo h($book['title']); ?>">
                  <?php else: ?>
                    <div class="book-cover placeholder">No Cover</div>
                  <?php endif; ?>
                  <div>
                    <strong class="label-block"><?php echo h($book['title']); ?></strong>
                    <span class="muted"><?php echo h($book['author']); ?></span>
                    <?php if (!empty($book['isbn'])): ?>
                      <span class="muted">ISBN: <?php echo h($book['isbn']); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="inline-actions chips-row">
                  <span class="chip"><?php echo (int) $book['qty_total']; ?> total</span>
                  <span class="chip"><?php echo (int) $book['qty_available']; ?> available</span>
                  <a class="button secondary" href="/librarymanage/librarian/edit_book.php?id=<?php echo (int) $book['id']; ?>">Edit Book</a>
                </div>
              </div>
            <?php endwhile; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/shared_confirm.js"></script>
<script src="/librarymanage/assets/librarian_catalog_modal.js"></script>
</body>
</html>

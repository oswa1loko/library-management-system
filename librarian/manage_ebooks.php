<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

$message = '';
$messageType = 'success';

function upload_ebook_pdf(array $file, string $existingPath = ''): array
{
    if (empty($file['name'])) {
        return ['path' => $existingPath, 'error' => ''];
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        return ['path' => $existingPath, 'error' => 'Only PDF eBooks are allowed.'];
    }

    if ((int) ($file['size'] ?? 0) > 25 * 1024 * 1024) {
        return ['path' => $existingPath, 'error' => 'eBook file must be 25MB or smaller.'];
    }

    $directory = __DIR__ . '/../uploads/ebooks';
    if (!ensure_upload_directory($directory)) {
        return ['path' => $existingPath, 'error' => 'eBook upload folder could not be created.'];
    }

    $filename = 'ebook_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
    $target = $directory . '/' . $filename;
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $target)) {
        return ['path' => $existingPath, 'error' => 'eBook upload failed.'];
    }

    return ['path' => 'uploads/ebooks/' . $filename, 'error' => ''];
}

function upload_ebook_cover(array $file, string $existingPath = ''): array
{
    if (empty($file['name'])) {
        return ['path' => $existingPath, 'error' => ''];
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowed, true)) {
        return ['path' => $existingPath, 'error' => 'Only JPG, JPEG, PNG, and WEBP cover images are allowed.'];
    }

    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['path' => $existingPath, 'error' => 'Cover image must be 5MB or smaller.'];
    }

    $directory = __DIR__ . '/../uploads/ebook_covers';
    if (!ensure_upload_directory($directory)) {
        return ['path' => $existingPath, 'error' => 'eBook cover upload folder could not be created.'];
    }

    $filename = 'ebook_cover_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $target = $directory . '/' . $filename;
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $target)) {
        return ['path' => $existingPath, 'error' => 'eBook cover upload failed.'];
    }

    return ['path' => 'uploads/ebook_covers/' . $filename, 'error' => ''];
}

$formData = [
    'title' => '',
    'author' => '',
    'description' => '',
];

if (isset($_POST['add_ebook'])) {
    $title = trim((string) ($_POST['title'] ?? ''));
    $author = trim((string) ($_POST['author'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $formData = [
        'title' => $title,
        'author' => $author,
        'description' => $description,
    ];

    $upload = upload_ebook_pdf($_FILES['ebook_file'] ?? []);
    $coverUpload = upload_ebook_cover($_FILES['cover_file'] ?? []);

    if ($title === '') {
        $message = 'Title is required.';
        $messageType = 'error';
    } elseif ($author === '') {
        $message = 'Author is required.';
        $messageType = 'error';
    } elseif (($coverUpload['error'] ?? '') !== '') {
        $message = (string) $coverUpload['error'];
        $messageType = 'error';
    } elseif (($upload['path'] ?? '') === '') {
        $message = $upload['error'] !== '' ? $upload['error'] : 'Upload the PDF first.';
        $messageType = 'error';
    } elseif (($upload['error'] ?? '') !== '') {
        $message = (string) $upload['error'];
        $messageType = 'error';
    } else {
        $filePath = (string) $upload['path'];
        $coverPath = (string) ($coverUpload['path'] ?? '');
        $descriptionValue = $description !== '' ? $description : null;
        $coverValue = $coverPath !== '' ? $coverPath : null;
        $uploadedBy = (int) ($_SESSION['user_id'] ?? 0);

        $stmt = $conn->prepare("
            INSERT INTO ebooks (title, author, description, cover_path, file_path, uploaded_by, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->bind_param('sssssi', $title, $author, $descriptionValue, $coverValue, $filePath, $uploadedBy);
        $stmt->execute();
        $stmt->close();

        $message = 'eBook uploaded successfully.';
        $formData = ['title' => '', 'author' => '', 'description' => ''];
    }
}

if (isset($_POST['delete_ebook'])) {
    $ebookId = (int) ($_POST['ebook_id'] ?? 0);
    $fetch = $conn->prepare("SELECT file_path, cover_path FROM ebooks WHERE id = ? LIMIT 1");
    $fetch->bind_param('i', $ebookId);
    $fetch->execute();
    $ebook = $fetch->get_result()->fetch_assoc();
    $fetch->close();

    if ($ebook) {
        $delete = $conn->prepare("DELETE FROM ebooks WHERE id = ? LIMIT 1");
        $delete->bind_param('i', $ebookId);
        $delete->execute();
        $delete->close();
        remove_relative_file((string) ($ebook['file_path'] ?? ''));
        remove_relative_file((string) ($ebook['cover_path'] ?? ''));
        header('Location: manage_ebooks.php');
        exit;
    }
}

$ebooks = $conn->query("
    SELECT e.id, e.title, e.author, e.description, e.cover_path, e.file_path, e.is_active, e.created_at, u.username AS uploaded_by_name
    FROM ebooks e
    LEFT JOIN users u ON u.id = e.uploaded_by
    ORDER BY e.id DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'eBooks')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell librarian-shell member-shell js-member-sidebar" data-sidebar-key="librarian-ebooks" data-sidebar-default="expanded">
  <?php
  $sidebarPage = 'ebooks';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
    <?php
    $pageTitle = 'Librarian eBooks';
    $pageSubtitle = 'Upload and manage view-only PDF eBooks';
    require __DIR__ . '/partials/topbar.php';
    ?>

    <div class="stack">
      <?php if ($message !== ''): ?>
        <div class="notice <?php echo $messageType === 'error' ? 'error' : 'success'; ?>"><?php echo h($message); ?></div>
      <?php endif; ?>

      <div class="grid cards">
        <div class="panel">
          <div class="card-head">
            <div class="dashboard-icon icon-books" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Upload</p>
              <h3 class="heading-card">Add a new eBook</h3>
              <p class="muted">Fill up the eBook details first, then upload a PDF file for view-only access.</p>
            </div>
          </div>

          <form method="post" enctype="multipart/form-data" class="stack flow-top-lg">
            <div class="grid form">
              <div>
                <label for="title">Title</label>
                <input id="title" name="title" value="<?php echo h($formData['title']); ?>" required>
              </div>
              <div>
                <label for="author">Author</label>
                <input id="author" name="author" value="<?php echo h($formData['author']); ?>" required>
              </div>
              <div class="form-span-2">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4"><?php echo h($formData['description']); ?></textarea>
              </div>
              <div class="form-span-2">
                <label for="ebook_file">PDF File</label>
                <input id="ebook_file" type="file" name="ebook_file" accept="application/pdf,.pdf" required>
              </div>
              <div class="form-span-2">
                <label for="cover_file">Cover Image</label>
                <input id="cover_file" type="file" name="cover_file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
              </div>
            </div>
            <div class="inline-actions">
              <button type="submit" name="add_ebook" value="1">Upload eBook</button>
              <span class="muted">View-only restrictions are best-effort in the browser and cannot be made permanently tamper-proof.</span>
            </div>
          </form>
        </div>

        <div class="panel">
          <div class="card-head">
            <div class="dashboard-icon icon-guide" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Rules</p>
              <h3 class="heading-card">eBook publishing notes</h3>
            </div>
          </div>
          <div class="stack">
            <div class="empty-state">Only PDF files are accepted for online eBook viewing.</div>
            <div class="empty-state">Students and faculty will be able to open the eBook in a view-only page.</div>
            <div class="empty-state">Direct browser downloads are discouraged, but web browsers cannot guarantee permanent anti-download enforcement.</div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Library eBooks</p>
            <h3 class="heading-card">Manage uploaded eBooks</h3>
          </div>
        </div>
        <div class="table-wrap table-wrap-top">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Author</th>
                <th>Status</th>
                <th>Uploaded</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$ebooks || $ebooks->num_rows === 0): ?>
                <tr><td colspan="6" class="muted">No eBooks uploaded yet.</td></tr>
              <?php endif; ?>
              <?php while ($ebook = $ebooks->fetch_assoc()): ?>
                <tr>
                  <td><?php echo (int) $ebook['id']; ?></td>
                  <td>
                    <div class="ebook-table-book">
                      <?php if (trim((string) ($ebook['cover_path'] ?? '')) !== ''): ?>
                        <img class="ebook-table-cover" src="/librarymanage/<?php echo h((string) $ebook['cover_path']); ?>" alt="<?php echo h($ebook['title']); ?>">
                      <?php else: ?>
                        <div class="ebook-table-cover ebook-table-cover-fallback" aria-hidden="true">eBook</div>
                      <?php endif; ?>
                      <div>
                        <strong class="label-block"><?php echo h($ebook['title']); ?></strong>
                        <span class="muted"><?php echo h((string) ($ebook['description'] ?: 'No description')); ?></span>
                      </div>
                    </div>
                  </td>
                  <td><?php echo h($ebook['author']); ?></td>
                  <td><span class="badge"><?php echo (int) ($ebook['is_active'] ?? 0) === 1 ? 'Active' : 'Hidden'; ?></span></td>
                  <td><?php echo h(format_display_datetime((string) ($ebook['created_at'] ?? ''))); ?></td>
                  <td class="inline-actions">
                    <a class="button secondary" href="edit_ebook.php?id=<?php echo (int) $ebook['id']; ?>">Edit</a>
                    <form method="post" class="inline-form" data-confirm="Delete this eBook?">
                      <input type="hidden" name="ebook_id" value="<?php echo (int) $ebook['id']; ?>">
                      <button type="submit" class="danger" name="delete_ebook" value="1">Delete</button>
                    </form>
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
<script src="/librarymanage/assets/shared_confirm.js"></script>
</body>
</html>

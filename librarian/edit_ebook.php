<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

function upload_ebook_pdf_edit(array $file, string $existingPath = ''): array
{
    if (empty($file['name'])) {
        return ['path' => $existingPath, 'error' => ''];
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        return ['path' => $existingPath, 'error' => 'Only PDF eBooks are allowed.'];
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

    if ($existingPath !== '' && $existingPath !== 'uploads/ebooks/' . $filename) {
        remove_relative_file($existingPath);
    }

    return ['path' => 'uploads/ebooks/' . $filename, 'error' => ''];
}

function upload_ebook_cover_edit(array $file, string $existingPath = ''): array
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

    if ($existingPath !== '' && $existingPath !== 'uploads/ebook_covers/' . $filename) {
        remove_relative_file($existingPath);
    }

    return ['path' => 'uploads/ebook_covers/' . $filename, 'error' => ''];
}

$ebookId = (int) ($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT * FROM ebooks WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $ebookId);
$stmt->execute();
$ebook = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ebook) {
    http_response_code(404);
    exit('eBook not found.');
}

$message = '';
$messageType = 'success';

if (isset($_POST['save_ebook'])) {
    $title = trim((string) ($_POST['title'] ?? ''));
    $author = trim((string) ($_POST['author'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $upload = upload_ebook_pdf_edit($_FILES['ebook_file'] ?? [], (string) ($ebook['file_path'] ?? ''));
    $coverUpload = upload_ebook_cover_edit($_FILES['cover_file'] ?? [], (string) ($ebook['cover_path'] ?? ''));

    if ($title === '') {
        $message = 'Title is required.';
        $messageType = 'error';
    } elseif ($author === '') {
        $message = 'Author is required.';
        $messageType = 'error';
    } elseif (($coverUpload['error'] ?? '') !== '') {
        $message = (string) $coverUpload['error'];
        $messageType = 'error';
    } elseif (($upload['error'] ?? '') !== '') {
        $message = (string) $upload['error'];
        $messageType = 'error';
    } else {
        $filePath = (string) ($upload['path'] ?? $ebook['file_path']);
        $coverPath = (string) ($coverUpload['path'] ?? $ebook['cover_path']);
        $descriptionValue = $description !== '' ? $description : null;
        $coverValue = $coverPath !== '' ? $coverPath : null;
        $update = $conn->prepare("
            UPDATE ebooks
            SET title = ?, author = ?, description = ?, cover_path = ?, file_path = ?, is_active = ?
            WHERE id = ?
            LIMIT 1
        ");
        $update->bind_param('sssssii', $title, $author, $descriptionValue, $coverValue, $filePath, $isActive, $ebookId);
        $update->execute();
        $update->close();
        header('Location: manage_ebooks.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Edit eBook')); ?></title>
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
    $pageTitle = 'Edit eBook';
    $pageSubtitle = 'Update metadata, PDF file, or visibility';
    require __DIR__ . '/partials/topbar.php';
    ?>
    <div class="stack">
      <?php if ($message !== ''): ?>
        <div class="notice <?php echo $messageType === 'error' ? 'error' : 'success'; ?>"><?php echo h($message); ?></div>
      <?php endif; ?>
      <div class="panel">
        <form method="post" enctype="multipart/form-data" class="stack">
          <div class="grid form">
            <div>
              <label for="title">Title</label>
              <input id="title" name="title" value="<?php echo h((string) $ebook['title']); ?>" required>
            </div>
            <div>
              <label for="author">Author</label>
              <input id="author" name="author" value="<?php echo h((string) $ebook['author']); ?>" required>
            </div>
            <div class="form-span-2">
              <label for="description">Description</label>
              <textarea id="description" name="description" rows="4"><?php echo h((string) ($ebook['description'] ?? '')); ?></textarea>
            </div>
            <div class="form-span-2">
              <label for="ebook_file">Replace PDF</label>
              <input id="ebook_file" type="file" name="ebook_file" accept="application/pdf,.pdf">
            </div>
            <div class="form-span-2">
              <label for="cover_file">Replace Cover Image</label>
              <input id="cover_file" type="file" name="cover_file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
            </div>
            <div>
              <label><input type="checkbox" name="is_active" value="1" <?php echo (int) ($ebook['is_active'] ?? 0) === 1 ? 'checked' : ''; ?>> Active for student/faculty viewing</label>
            </div>
            <?php if (trim((string) ($ebook['cover_path'] ?? '')) !== ''): ?>
              <div class="form-span-2">
                <img class="ebook-edit-cover" src="/librarymanage/<?php echo h((string) $ebook['cover_path']); ?>" alt="<?php echo h((string) $ebook['title']); ?>">
              </div>
            <?php endif; ?>
          </div>
          <div class="inline-actions">
            <button type="submit" name="save_ebook" value="1">Save Changes</button>
            <a class="button secondary" href="manage_ebooks.php">Back</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
</body>
</html>

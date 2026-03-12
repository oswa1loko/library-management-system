<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_roles(['student', 'faculty']);

$role = (string) $_SESSION['role'];
$ebookId = (int) ($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT id, title, author, description FROM ebooks WHERE id = ? AND is_active = 1 LIMIT 1");
$stmt->bind_param('i', $ebookId);
$stmt->execute();
$ebook = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ebook) {
    http_response_code(404);
    exit('eBook not found.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title($role, 'View eBook')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<?php $ebookReaderVersion = (string) filemtime(__DIR__ . '/../assets/ebook_reader.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="<?php echo h($role); ?>-ebooks">
  <aside class="panel member-sidebar">
    <div class="member-sidebar-head">
      <button type="button" class="member-sidebar-toggle js-sidebar-toggle" aria-expanded="true" aria-label="Collapse sidebar">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Main Menu</span>
      </button>
    </div>
    <p class="member-sidebar-section member-sidebar-label">Main</p>
    <nav class="member-sidebar-nav">
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/dashboard.php" data-tooltip="Dashboard"><span class="dashboard-icon icon-view" aria-hidden="true"></span><span class="member-sidebar-label">Dashboard</span></a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/books.php" data-tooltip="Books and Borrow"><span class="dashboard-icon icon-books" aria-hidden="true"></span><span class="member-sidebar-label">Books / Borrow</span></a>
      <a class="member-sidebar-link is-active" href="/librarymanage/<?php echo h($role); ?>/ebooks.php" data-tooltip="eBooks"><span class="dashboard-icon icon-guide" aria-hidden="true"></span><span class="member-sidebar-label">eBooks</span></a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/borrow_return.php" data-tooltip="My Borrows and Returns"><span class="dashboard-icon icon-checklist" aria-hidden="true"></span><span class="member-sidebar-label">My Borrows / Returns</span></a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/payment_upload.php" data-tooltip="Payments"><span class="dashboard-icon icon-payments" aria-hidden="true"></span><span class="member-sidebar-label">Payments</span></a>
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
        <h1><?php echo h($ebook['title']); ?></h1>
        <p><?php echo h($ebook['author']); ?></p>
      </div>
    </div>

    <div class="stack">
      <div class="panel">
        <div class="inline-actions inline-actions-spread">
          <div>
            <strong class="label-block"><?php echo h($ebook['title']); ?></strong>
            <span class="muted"><?php echo h((string) ($ebook['description'] ?: 'View-only eBook')); ?></span>
          </div>
          <a class="button secondary" href="/librarymanage/<?php echo h($role); ?>/ebooks.php">Back to eBooks</a>
        </div>
        <div class="notice warning flow-top-md">This eBook opens in a custom reader page as best-effort view-only access. Some mobile browsers may still show their own share or download controls.</div>
        <div
          class="ebook-reader-shell flow-top-md"
          data-ebook-reader
          data-pdf-url="/librarymanage/ebook_stream.php?id=<?php echo (int) $ebook['id']; ?>"
          data-pdf-title="<?php echo h($ebook['title']); ?>"
        >
          <div class="ebook-reader-toolbar">
            <div class="ebook-reader-toolbar-group">
              <span class="chip">Viewer</span>
              <span class="muted" data-ebook-page-label>Preparing pages...</span>
            </div>
            <div class="ebook-reader-toolbar-group ebook-reader-toolbar-actions">
              <button type="button" class="button secondary" data-ebook-zoom-out>Zoom Out</button>
              <button type="button" class="button secondary" data-ebook-zoom-in>Zoom In</button>
            </div>
          </div>
          <div class="ebook-reader-stage" data-ebook-stage>
            <div class="ebook-reader-loading muted" data-ebook-loading>Loading eBook page...</div>
          </div>
          <div class="ebook-reader-page-controls">
            <button type="button" class="button secondary" data-ebook-prev>Previous</button>
            <button type="button" class="button secondary" data-ebook-next>Next</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script type="module" src="/librarymanage/assets/ebook_reader.js?v=<?php echo urlencode($ebookReaderVersion); ?>"></script>
</body>
</html>

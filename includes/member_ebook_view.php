<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_roles(['student', 'faculty']);

$role = (string) $_SESSION['role'];
$ebookId = (int) ($_GET['id'] ?? 0);
$ebook = null;

try {
    $stmt = library_safe_prepare($conn, "SELECT id, title, author, description FROM ebooks WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('i', $ebookId);
    $stmt->execute();
    $ebook = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $exception) {
    http_response_code(503);
    exit('eBook service is temporarily unavailable.');
}

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
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<?php $ebookReaderVersion = (string) filemtime(__DIR__ . '/../assets/ebook_reader.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="<?php echo h($role); ?>-ebooks">
  <aside class="panel member-sidebar">
    <div class="member-sidebar-head">
      <div class="member-sidebar-toggle" aria-hidden="true">
        <span class="member-sidebar-label">Main Menu</span>
      </div>
    </div>
    <nav class="member-sidebar-nav">
      <p class="member-sidebar-group-label">Overview</p>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/dashboard.php" data-tooltip="Dashboard"><span class="dashboard-icon icon-view" aria-hidden="true"></span><span class="member-sidebar-label">Dashboard</span></a>
      <p class="member-sidebar-group-label">Library</p>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/books.php" data-tooltip="Books"><span class="dashboard-icon icon-books" aria-hidden="true"></span><span class="member-sidebar-label">Books</span></a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/catalog.php" data-tooltip="Catalog"><span class="dashboard-icon icon-guide" aria-hidden="true"></span><span class="member-sidebar-label">Catalog</span></a>
      <a class="member-sidebar-link is-active" href="/librarymanage/<?php echo h($role); ?>/ebooks.php" data-tooltip="eBooks"><span class="dashboard-icon icon-guide" aria-hidden="true"></span><span class="member-sidebar-label">eBooks</span></a>
      <p class="member-sidebar-group-label">My Activity</p>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/borrow_return.php" data-tooltip="Returns"><span class="dashboard-icon icon-checklist" aria-hidden="true"></span><span class="member-sidebar-label">Returns</span></a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/book_incidents.php" data-tooltip="Book Incidents"><span class="dashboard-icon icon-notes" aria-hidden="true"></span><span class="member-sidebar-label">Book Incidents</span></a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/tracking.php" data-tooltip="Records Tracking"><span class="dashboard-icon icon-ledger" aria-hidden="true"></span><span class="member-sidebar-label">Records Tracking</span></a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/payment_upload.php" data-tooltip="Payments"><span class="dashboard-icon icon-payments" aria-hidden="true"></span><span class="member-sidebar-label">Payments</span></a>
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
    <div class="stack ebook-reader-layout">
      <div class="panel ebook-reader-panel">
        <div class="ebook-reader-topbar">
          <div class="ebook-reader-header-copy">
            <span class="chip ebook-reader-kicker">Desktop Reader</span>
            <strong class="label-block ebook-reader-title"><?php echo h($ebook['title']); ?></strong>
            <div class="ebook-reader-meta">
              <span class="chip">PDF Viewer</span>
              <span class="chip"><?php echo h((string) ($ebook['author'] ?: 'Author unavailable')); ?></span>
            </div>
            <span class="muted ebook-reader-description"><?php echo h((string) ($ebook['description'] ?: 'View-only eBook')); ?></span>
          </div>
          <div class="ebook-reader-topbar-actions">
            <a class="button secondary" href="/librarymanage/<?php echo h($role); ?>/ebooks.php">Back to eBooks</a>
          </div>
        </div>
        <div class="notice warning flow-top-md ebook-reader-notice">This eBook opens in a custom reader page as best-effort view-only access. Some mobile browsers may still show their own share or download controls.</div>
        <div
          class="ebook-reader-shell flow-top-md"
          data-ebook-reader
          data-pdf-url="/librarymanage/ebook_stream.php?id=<?php echo (int) $ebook['id']; ?>"
          data-pdf-title="<?php echo h($ebook['title']); ?>"
        >
          <div class="ebook-reader-toolbar">
            <div class="ebook-reader-toolbar-group ebook-reader-toolbar-nav">
              <button type="button" class="button secondary" data-ebook-prev>Previous</button>
              <button type="button" class="button secondary" data-ebook-next>Next</button>
            </div>
            <div class="ebook-reader-toolbar-group ebook-reader-toolbar-status">
              <span class="chip ebook-reader-status-chip">Viewer</span>
              <span class="ebook-reader-page-pill" data-ebook-page-label>Preparing pages...</span>
            </div>
            <div class="ebook-reader-toolbar-group ebook-reader-toolbar-actions">
              <button type="button" class="button secondary" data-ebook-zoom-out>Zoom Out</button>
              <button type="button" class="button secondary" data-ebook-zoom-in>Zoom In</button>
              <div class="ebook-reader-jump">
                <label class="sr-only" for="ebook-page-jump-<?php echo (int) $ebook['id']; ?>">Jump to page</label>
                <input
                  id="ebook-page-jump-<?php echo (int) $ebook['id']; ?>"
                  type="number"
                  min="1"
                  step="1"
                  inputmode="numeric"
                  placeholder="Page #"
                  data-ebook-page-input
                >
                <button type="button" class="button secondary" data-ebook-page-jump>Go</button>
              </div>
            </div>
          </div>
          <div class="ebook-reader-stage-wrap">
            <div class="ebook-reader-stage" data-ebook-stage>
              <div class="ebook-reader-loading muted" data-ebook-loading>Loading eBook page...</div>
            </div>
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

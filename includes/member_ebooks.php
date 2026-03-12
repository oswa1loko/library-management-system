<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_roles(['student', 'faculty']);

$role = (string) $_SESSION['role'];
$ebooks = $conn->query("
    SELECT id, title, author, description, cover_path, created_at
    FROM ebooks
    WHERE is_active = 1
    ORDER BY title ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title($role, 'eBooks')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
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
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/dashboard.php" data-tooltip="Dashboard">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Dashboard</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/books.php" data-tooltip="Books and Borrow">
        <span class="dashboard-icon icon-books" aria-hidden="true"></span>
        <span class="member-sidebar-label">Books / Borrow</span>
      </a>
      <a class="member-sidebar-link is-active" href="/librarymanage/<?php echo h($role); ?>/ebooks.php" data-tooltip="eBooks">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">eBooks</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/borrow_return.php" data-tooltip="My Borrows and Returns">
        <span class="dashboard-icon icon-checklist" aria-hidden="true"></span>
        <span class="member-sidebar-label">My Borrows / Returns</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/payment_upload.php" data-tooltip="Payments">
        <span class="dashboard-icon icon-payments" aria-hidden="true"></span>
        <span class="member-sidebar-label">Payments</span>
      </a>
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
        <h1><?php echo h(role_label($role)); ?> eBooks</h1>
        <p>View-only online eBooks for reading inside the system</p>
      </div>
    </div>

    <div class="stack">
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
          <div>
            <span class="chip">Online Library</span>
            <h3 class="heading-top-md">Available eBooks</h3>
            <p class="muted">These PDFs are available for online viewing only inside the library system.</p>
          </div>
        </div>
        <div class="ebook-grid flow-top-md">
          <?php if (!$ebooks || $ebooks->num_rows === 0): ?>
            <div class="empty-state">No eBooks are available right now.</div>
          <?php endif; ?>
          <?php while ($ebook = $ebooks->fetch_assoc()): ?>
            <div class="panel ebook-card">
              <?php if (trim((string) ($ebook['cover_path'] ?? '')) !== ''): ?>
                <img class="ebook-card-cover" src="/librarymanage/<?php echo h((string) $ebook['cover_path']); ?>" alt="<?php echo h($ebook['title']); ?>">
              <?php else: ?>
                <div class="ebook-card-cover ebook-card-cover-fallback" aria-hidden="true">eBook</div>
              <?php endif; ?>
              <div class="stack ebook-card-copy">
                <div>
                  <strong class="label-block"><?php echo h($ebook['title']); ?></strong>
                  <span class="muted"><?php echo h($ebook['author']); ?></span>
                </div>
                <div class="muted ebook-card-description"><?php echo h((string) ($ebook['description'] ?: 'No description available.')); ?></div>
                <div class="inline-actions ebook-card-actions">
                  <a class="button secondary" href="/librarymanage/<?php echo h($role); ?>/ebook_view.php?id=<?php echo (int) $ebook['id']; ?>" target="_blank" rel="noopener noreferrer">View eBook</a>
                  <span class="chip">View only</span>
                </div>
              </div>
            </div>
          <?php endwhile; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
</body>
</html>

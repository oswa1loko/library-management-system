<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_roles(['student', 'faculty']);

$role = (string) $_SESSION['role'];
$catalogSummary = $conn->query("
    SELECT
      COUNT(DISTINCT category) AS total_catalogs,
      COUNT(*) AS total_titles,
      COALESCE(SUM(qty_available), 0) AS available_copies
    FROM books
    WHERE category IS NOT NULL AND TRIM(category) <> ''
")->fetch_assoc() ?: [
    'total_catalogs' => 0,
    'total_titles' => 0,
    'available_copies' => 0,
];

$catalogRows = $conn->query("
    SELECT
      b.category,
      MAX(c.cover_path) AS cover_path,
      MAX(c.description) AS description,
      COUNT(*) AS title_count,
      COALESCE(SUM(b.qty_total), 0) AS total_copies,
      COALESCE(SUM(b.qty_available), 0) AS available_copies,
      COALESCE(SUM(CASE WHEN b.qty_available > 0 THEN 1 ELSE 0 END), 0) AS ready_titles
    FROM books b
    LEFT JOIN catalogs c ON c.name = b.category
    WHERE b.category IS NOT NULL AND TRIM(b.category) <> ''
    GROUP BY b.category
    ORDER BY ready_titles DESC, available_copies DESC, title_count DESC, b.category ASC
");

$catalogs = [];
while ($catalogRows && ($catalogRow = $catalogRows->fetch_assoc())) {
    $catalogs[] = $catalogRow;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title($role, 'Catalog')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="<?php echo h($role); ?>-catalog">
  <aside class="panel member-sidebar">
    <div class="member-sidebar-head">
      <div class="member-sidebar-toggle" aria-hidden="true">
        <span class="member-sidebar-label">Main Menu</span>
      </div>
    </div>
    <nav class="member-sidebar-nav">
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/dashboard.php" data-tooltip="Dashboard">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Dashboard</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/books.php" data-tooltip="Books">
        <span class="dashboard-icon icon-books" aria-hidden="true"></span>
        <span class="member-sidebar-label">Books</span>
      </a>
      <a class="member-sidebar-link is-active" href="/librarymanage/<?php echo h($role); ?>/catalog.php" data-tooltip="Catalog">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">Catalog</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/ebooks.php" data-tooltip="eBooks">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">eBooks</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/borrow_return.php" data-tooltip="Returns">
        <span class="dashboard-icon icon-checklist" aria-hidden="true"></span>
        <span class="member-sidebar-label">Returns</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/tracking.php" data-tooltip="Records Tracking">
        <span class="dashboard-icon icon-ledger" aria-hidden="true"></span>
        <span class="member-sidebar-label">Records Tracking</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/payment_upload.php" data-tooltip="Payments">
        <span class="dashboard-icon icon-payments" aria-hidden="true"></span>
        <span class="member-sidebar-label">Payments</span>
      </a>
    </nav>
    <p class="member-sidebar-section member-sidebar-label">Account</p>
    <div class="topbar-nav member-sidebar-utilities">
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/profile.php" data-tooltip="Profile">
        <span class="dashboard-icon icon-edit" aria-hidden="true"></span>
        <span class="member-sidebar-label">Profile</span>
      </a>
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
        <h1><?php echo h(role_label($role)); ?> Catalog</h1>
        <p>Browse library sections before opening the full books list</p>
      </div>
    </div>

    <div class="stack">
      <div class="grid cards flow-top-md librarian-catalog-grid member-catalog-grid">
        <?php if ($catalogs === []): ?>
          <div class="panel">
            <div class="empty-state">No catalog sections are available right now.</div>
          </div>
        <?php endif; ?>
        <?php foreach ($catalogs as $catalog): ?>
          <?php $categoryValue = strtolower((string) ($catalog['category'] ?? '')); ?>
          <div class="panel librarian-catalog-card member-catalog-card">
            <a class="librarian-catalog-card-link" href="/librarymanage/<?php echo h($role); ?>/books.php?category=<?php echo urlencode($categoryValue); ?>">
              <div class="librarian-catalog-card-media member-catalog-card-media">
                <?php if (!empty($catalog['cover_path'])): ?>
                  <img class="book-cover" src="/librarymanage/<?php echo h((string) $catalog['cover_path']); ?>" alt="<?php echo h((string) ($catalog['category'] ?? 'Catalog')); ?>">
                <?php else: ?>
                  <div class="book-cover placeholder">No Cover</div>
                <?php endif; ?>
                <div class="member-catalog-card-copy">
                  <h3 class="heading-top-md member-catalog-card-title"><?php echo h((string) ($catalog['category'] ?? 'Uncategorized')); ?></h3>
                  <p class="muted member-catalog-card-description">
                    <?php
                      $description = trim((string) ($catalog['description'] ?? ''));
                      echo h($description !== '' ? $description : 'Browse matching books.');
                    ?>
                  </p>
                  <div class="inline-actions chips-row">
                    <span class="chip"><?php echo (int) ($catalog['title_count'] ?? 0); ?> titles</span>
                    <span class="chip"><?php echo (int) ($catalog['available_copies'] ?? 0); ?> available</span>
                  </div>
                </div>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
</body>
</html>

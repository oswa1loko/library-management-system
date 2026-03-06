<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_roles(['student', 'faculty']);

$role = (string) $_SESSION['role'];

$catalogStats = $conn->query("
    SELECT
      COUNT(*) AS total_titles,
      COALESCE(SUM(qty_total), 0) AS total_copies,
      COALESCE(SUM(qty_available), 0) AS available_copies,
      COALESCE(SUM(CASE WHEN qty_available > 0 THEN 1 ELSE 0 END), 0) AS available_titles
    FROM books
")->fetch_assoc();

$books = $conn->query("
    SELECT b.id, b.title, b.author, b.category, b.cover_path, b.qty_total, b.qty_available,
           COUNT(br.id) AS times_borrowed
    FROM books b
    LEFT JOIN borrows br ON br.book_id = b.id
    GROUP BY b.id, b.title, b.author, b.category, b.cover_path, b.qty_total, b.qty_available
    ORDER BY b.title ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title($role, 'Books')); ?></title>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css">
</head>
<body>
<div class="site-shell">
  <div class="topbar">
    <div>
      <h1><?php echo h(ucfirst($role)); ?> Portal</h1>
      <p>Available library books</p>
    </div>
    <div class="topbar-nav">
      <a href="/librarymanage/<?php echo h($role); ?>/dashboard.php">Dashboard</a>
      <a href="/librarymanage/logout.php">Logout</a>
    </div>
  </div>

  <div class="stack">
    <div class="panel member-workspace-overview">
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Catalog snapshot</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo (int) ($catalogStats['total_titles'] ?? 0); ?></strong>
          <span class="muted">Titles in catalog</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($catalogStats['total_copies'] ?? 0); ?></strong>
          <span class="muted">Total copies</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($catalogStats['available_titles'] ?? 0); ?></strong>
          <span class="muted">Titles available now</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($catalogStats['available_copies'] ?? 0); ?></strong>
          <span class="muted">Available copies</span>
        </div>
      </div>
    </div>

    <div class="panel member-workspace-history">
      <div class="card-head">
        <div class="dashboard-icon icon-books" aria-hidden="true"></div>
        <div>
          <span class="chip">Catalog</span>
          <h3 class="heading-top-md">Books Catalog</h3>
        </div>
      </div>
      <p class="muted">Availability and borrowing activity across the current inventory.</p>
      <div class="table-wrap table-wrap-top">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Book</th>
              <th>Author</th>
              <th>Category</th>
              <th>Total</th>
              <th>Available</th>
              <th>Borrowed Count</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($books->num_rows === 0): ?>
              <tr><td colspan="7" class="muted">No books available yet.</td></tr>
            <?php endif; ?>
            <?php while ($book = $books->fetch_assoc()): ?>
              <tr>
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
                      <span class="muted">Book ID #<?php echo (int) $book['id']; ?></span>
                    </div>
                  </div>
                </td>
                <td><?php echo h($book['author']); ?></td>
                <td><span class="badge"><?php echo h($book['category']); ?></span></td>
                <td><?php echo (int) $book['qty_total']; ?></td>
                <td>
                  <span class="badge">
                    <span class="status-dot <?php echo (int) $book['qty_available'] > 0 ? 'approved' : 'unpaid'; ?>"></span>
                    <?php echo (int) $book['qty_available']; ?>
                  </span>
                </td>
                <td><?php echo (int) $book['times_borrowed']; ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body>
</html>

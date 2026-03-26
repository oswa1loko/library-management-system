<div class="panel print-users-sheet">
  <div class="print-users-head">
    <div class="print-brand">
      <img src="/librarymanage/assets/images/regismarielogo.png" alt="Library logo" class="print-brand-logo">
      <div>
        <p class="muted eyebrow-compact">Library Management System</p>
        <h1><?php echo h($printTitle); ?></h1>
        <p class="muted">
          Generated on <span id="printGeneratedAt"><?php echo h(date('F j, Y g:i A')); ?></span>
          | Search: <?php echo h($search !== '' ? $search : 'None'); ?>
          | Catalog: <?php echo h($selectedCatalogName); ?>
          | Scope: <?php echo h($printScope === 'catalog' ? 'Catalog' : str_replace('_', ' ', ucfirst($printScope))); ?>
          | Records: <?php echo (int) $books->num_rows; ?>
        </p>
      </div>
    </div>
    <div class="inline-actions print-users-screen-actions no-print">
      <button type="button" id="printNowButton">Print Now</button>
      <a class="button secondary" href="manage_books.php<?php echo h($filterQueryString); ?>">Back</a>
    </div>
  </div>

  <div class="table-wrap table-wrap-top print-users-table">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Title</th>
          <th>Author</th>
          <th>Catalog</th>
          <th>ISBN</th>
          <th>Total Copies</th>
          <th>Available</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($books->num_rows === 0): ?>
          <tr><td colspan="7" class="muted">No books matched the selected print filter.</td></tr>
        <?php endif; ?>
        <?php while ($book = $books->fetch_assoc()): ?>
          <tr>
            <td><?php echo (int) $book['id']; ?></td>
            <td><?php echo h($book['title']); ?></td>
            <td><?php echo h($book['author']); ?></td>
            <td><?php echo h((string) ($book['category'] ?: '-')); ?></td>
            <td><?php echo h((string) ($book['isbn'] ?: '-')); ?></td>
            <td><?php echo (int) $book['qty_total']; ?></td>
            <td><?php echo (int) $book['qty_available']; ?></td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel print-users-sheet">
  <div class="print-users-head">
    <div class="print-brand">
      <img src="/librarymanage/assets/images/RMLOGO.jfif" alt="Library logo" class="print-brand-logo">
      <div>
        <p class="muted eyebrow-compact">Library Management System</p>
        <h1><?php echo h($printTitle); ?></h1>
        <p class="muted">
          Generated on <span id="printGeneratedAt"><?php echo h(date('F j, Y g:i A')); ?></span>
          | Search: <?php echo h($search !== '' ? $search : 'None'); ?>
          | Records: <?php echo (int) ($printUsers ? $printUsers->num_rows : 0); ?>
        </p>
      </div>
    </div>
    <div class="inline-actions print-users-screen-actions no-print">
      <button type="button" id="printNowButton">Print Now</button>
      <a class="button secondary" href="manage_accounts.php<?php echo h($filterQueryString); ?>">Back</a>
    </div>
  </div>
  <?php if ($printUserId > 0 && $printUsers && $printUsers->num_rows === 1): ?>
    <?php $singleUser = $printUsers->fetch_assoc(); ?>
    <div class="print-user-card">
      <div class="print-user-card-head">
        <div>
          <p class="muted eyebrow-compact">Account Profile</p>
          <h2><?php echo h($singleUser['fullname']); ?></h2>
        </div>
        <span class="badge"><?php echo h(ucfirst($singleUser['role'])); ?></span>
      </div>
      <div class="print-user-grid">
        <div class="print-user-field">
          <span class="muted">User ID</span>
          <strong>#<?php echo (int) $singleUser['id']; ?></strong>
        </div>
        <div class="print-user-field">
          <span class="muted">Username</span>
          <strong><?php echo h($singleUser['username']); ?></strong>
        </div>
        <div class="print-user-field">
          <span class="muted">Email</span>
          <strong><?php echo h($singleUser['email']); ?></strong>
        </div>
        <div class="print-user-field">
          <span class="muted">Created At</span>
          <strong><?php echo h($singleUser['created_at']); ?></strong>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap table-wrap-top print-users-table">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Username</th>
            <th>Role</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$printUsers || $printUsers->num_rows === 0): ?>
            <tr><td colspan="6" class="muted">No users matched the selected print filter.</td></tr>
          <?php endif; ?>
          <?php while ($printUsers && $user = $printUsers->fetch_assoc()): ?>
            <tr>
              <td><?php echo (int) $user['id']; ?></td>
              <td><?php echo h($user['fullname']); ?></td>
              <td><?php echo h($user['email']); ?></td>
              <td><?php echo h($user['username']); ?></td>
              <td><?php echo h(ucfirst($user['role'])); ?></td>
              <td><?php echo h($user['created_at']); ?></td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<script src="/librarymanage/assets/admin_manage_accounts_print.js"></script>

<?php
$pageTitle = isset($pageTitle) ? (string) $pageTitle : 'Admin';
$pageSubtitle = isset($pageSubtitle) ? (string) $pageSubtitle : '';
?>
<div class="topbar">
  <div>
    <h1><?php echo h($pageTitle); ?></h1>
    <?php if ($pageSubtitle !== ''): ?>
      <p><?php echo h($pageSubtitle); ?></p>
    <?php endif; ?>
  </div>
  <div class="topbar-nav">
    <a href="dashboard.php">Dashboard</a>
    <a href="/librarymanage/logout.php">Logout</a>
  </div>
</div>


<?php
$pageTitle = isset($pageTitle) ? (string) $pageTitle : 'Librarian';
$pageSubtitle = isset($pageSubtitle) ? (string) $pageSubtitle : '';
?>
<div class="topbar topbar-librarian">
  <div>
    <p class="topbar-kicker">Librarian Desk</p>
    <h1><?php echo h($pageTitle); ?></h1>
    <?php if ($pageSubtitle !== ''): ?>
      <p><?php echo h($pageSubtitle); ?></p>
    <?php endif; ?>
  </div>
</div>

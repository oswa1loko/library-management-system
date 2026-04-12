<?php
$pageTitle = isset($pageTitle) ? (string) $pageTitle : 'Admin';
$pageSubtitle = isset($pageSubtitle) ? (string) $pageSubtitle : '';
$sidebarPage = isset($sidebarPage) ? (string) $sidebarPage : '';
?>
<div class="topbar topbar-admin">
  <div>
    <p class="topbar-kicker">Admin Portal</p>
    <h1><?php echo h($pageTitle); ?></h1>
    <?php if ($pageSubtitle !== ''): ?>
      <p><?php echo h($pageSubtitle); ?></p>
    <?php endif; ?>
  </div>
</div>

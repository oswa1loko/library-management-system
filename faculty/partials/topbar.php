<?php
$pageTitle = isset($pageTitle) ? (string) $pageTitle : 'Faculty';
$pageSubtitle = isset($pageSubtitle) ? (string) $pageSubtitle : '';
$topbarPrimaryHref = isset($topbarPrimaryHref) ? (string) $topbarPrimaryHref : '/librarymanage/index.php';
$topbarPrimaryLabel = isset($topbarPrimaryLabel) ? (string) $topbarPrimaryLabel : 'Home';
?>
<div class="topbar">
  <div>
    <h1><?php echo h($pageTitle); ?></h1>
    <?php if ($pageSubtitle !== ''): ?>
      <p><?php echo h($pageSubtitle); ?></p>
    <?php endif; ?>
  </div>
  <div class="topbar-nav">
    <a href="<?php echo h($topbarPrimaryHref); ?>"><?php echo h($topbarPrimaryLabel); ?></a>
    <a href="/librarymanage/logout.php">Logout</a>
  </div>
</div>

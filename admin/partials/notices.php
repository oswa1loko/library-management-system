<?php
$noticeItems = isset($noticeItems) && is_array($noticeItems) ? $noticeItems : [];
foreach ($noticeItems as $noticeItem):
    $type = (string) ($noticeItem['type'] ?? 'success');
    $message = trim((string) ($noticeItem['message'] ?? ''));
    if ($message === '') {
        continue;
    }
?>
  <div class="notice <?php echo $type === 'error' ? 'error' : 'success'; ?>"><?php echo h($message); ?></div>
<?php endforeach; ?>


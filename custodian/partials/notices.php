<?php
$noticeItems = isset($noticeItems) && is_array($noticeItems) ? $noticeItems : [];
foreach ($noticeItems as $noticeItem):
    $type = (string) ($noticeItem['type'] ?? 'success');
    $message = trim((string) ($noticeItem['message'] ?? ''));
    if ($message === '') {
        continue;
    }
    $allowedTypes = ['success', 'error', 'warning'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'success';
    }
?>
  <div class="notice <?php echo h($type); ?>"><?php echo h($message); ?></div>
<?php endforeach; ?>

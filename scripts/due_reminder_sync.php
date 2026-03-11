<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$result = [
    'checked' => 0,
    'sent' => 0,
    'failed' => 0,
    'skipped' => 0,
];

try {
    $result = send_due_soon_reminders($conn);
    audit_log($conn, 'system.due_reminder_sync.cron', $result, null, 'system');
    echo '[OK] Due reminder sync complete. '
        . 'Checked: ' . (int) $result['checked']
        . ', sent: ' . (int) $result['sent']
        . ', failed: ' . (int) $result['failed']
        . ', skipped: ' . (int) $result['skipped']
        . PHP_EOL;
    if (!empty($result['errors']) && is_array($result['errors'])) {
        foreach ($result['errors'] as $error) {
            echo '[MAIL-ERROR] Borrow #' . (int) ($error['borrow_id'] ?? 0)
                . ' to ' . (string) ($error['email'] ?? '-')
                . ': ' . (string) ($error['message'] ?? 'Unknown error')
                . PHP_EOL;
        }
    }
    exit(0);
} catch (Throwable $e) {
    audit_log($conn, 'system.due_reminder_sync.cron_failed', [
        'error' => $e->getMessage(),
    ], null, 'system');
    fwrite(STDERR, '[ERROR] Due reminder sync failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

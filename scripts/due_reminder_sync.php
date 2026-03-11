<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$result = [
    'due_soon' => [
        'checked' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => [],
    ],
    'overdue' => [
        'checked' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => [],
    ],
];

try {
    $result['due_soon'] = send_due_soon_reminders($conn);
    $result['overdue'] = send_overdue_notices($conn);
    audit_log($conn, 'system.due_reminder_sync.cron', $result, null, 'system');
    echo '[OK] Due reminder sync complete.' . PHP_EOL;
    foreach (['due_soon' => 'Due Soon', 'overdue' => 'Overdue'] as $key => $label) {
        $bucket = $result[$key] ?? [];
        echo '[' . $label . '] '
            . 'Checked: ' . (int) ($bucket['checked'] ?? 0)
            . ', sent: ' . (int) ($bucket['sent'] ?? 0)
            . ', failed: ' . (int) ($bucket['failed'] ?? 0)
            . ', skipped: ' . (int) ($bucket['skipped'] ?? 0)
            . PHP_EOL;
        if (!empty($bucket['errors']) && is_array($bucket['errors'])) {
            foreach ($bucket['errors'] as $error) {
                echo '[MAIL-ERROR][' . $label . '] Borrow #' . (int) ($error['borrow_id'] ?? 0)
                    . ' to ' . (string) ($error['email'] ?? '-')
                    . ': ' . (string) ($error['message'] ?? 'Unknown error')
                    . PHP_EOL;
            }
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

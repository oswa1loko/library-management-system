<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$result = ['inserted' => 0, 'updated' => 0];

try {
    $result = sync_overdue_penalties($conn);
    audit_log($conn, 'system.penalty_sync.cron', $result, null, 'system');
    echo '[OK] Penalty sync complete. Inserted: ' . (int) $result['inserted'] . ', updated: ' . (int) $result['updated'] . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    audit_log($conn, 'system.penalty_sync.cron_failed', [
        'error' => $e->getMessage(),
    ], null, 'system');
    fwrite(STDERR, '[ERROR] Penalty sync failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}


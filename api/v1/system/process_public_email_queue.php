<?php
require_once __DIR__ . '/../_bootstrap.php';

api_require_method('POST');

$result = process_pending_email_jobs($conn, 2);
api_json([
    'ok' => true,
    'result' => $result,
]);

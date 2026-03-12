<?php
require_once __DIR__ . '/../_bootstrap.php';

api_require_method('POST');
$user = api_require_session_login();

if (!roles_match((string) ($user['role'] ?? ''), 'librarian') && !roles_match((string) ($user['role'] ?? ''), 'admin')) {
    api_error('Forbidden.', 403);
}

$result = process_pending_email_jobs($conn, 5);
api_json([
    'ok' => true,
    'result' => $result,
]);

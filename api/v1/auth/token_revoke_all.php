<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('POST');
$user = api_require_session_login();

global $conn;

$delete = $conn->prepare("DELETE FROM api_tokens WHERE user_id = ?");
$delete->bind_param('i', $user['id']);
$delete->execute();
$removedCount = (int) $delete->affected_rows;
$delete->close();

audit_log($conn, 'api.token.revoke_all', [
    'removed_count' => $removedCount,
], $user['id'], $user['role']);

api_json([
    'ok' => true,
    'message' => 'All API tokens revoked.',
    'removed_count' => $removedCount,
]);


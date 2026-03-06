<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('POST');
$user = api_require_session_login();

global $conn;

$tokenId = (int) ($_POST['token_id'] ?? 0);
if ($tokenId <= 0) {
    api_error('Invalid token_id.', 422);
}

$delete = $conn->prepare("DELETE FROM api_tokens WHERE id = ? AND user_id = ?");
$delete->bind_param('ii', $tokenId, $user['id']);
$delete->execute();
$removed = $delete->affected_rows === 1;
$delete->close();

if (!$removed) {
    api_error('Token not found.', 404);
}

audit_log($conn, 'api.token.revoke', [
    'token_id' => $tokenId,
], $user['id'], $user['role']);

api_json([
    'ok' => true,
    'message' => 'API token revoked.',
]);

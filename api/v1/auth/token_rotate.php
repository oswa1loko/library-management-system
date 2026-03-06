<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('POST');
$user = api_require_session_login();

global $conn;

$tokenId = (int) ($_POST['token_id'] ?? 0);
if ($tokenId <= 0) {
    api_error('Invalid token_id.', 422);
}

$plainToken = 'lm_' . bin2hex(random_bytes(24));
$tokenHash = hash('sha256', $plainToken);

$update = $conn->prepare("
    UPDATE api_tokens
    SET token_hash = ?, last_used_at = NULL
    WHERE id = ? AND user_id = ?
");
$update->bind_param('sii', $tokenHash, $tokenId, $user['id']);
$update->execute();
$rotated = $update->affected_rows === 1;
$update->close();

if (!$rotated) {
    api_error('Token not found.', 404);
}

audit_log($conn, 'api.token.rotate', [
    'token_id' => $tokenId,
], $user['id'], $user['role']);

api_json([
    'ok' => true,
    'message' => 'API token rotated. Store this token securely; it is shown only once.',
    'token' => $plainToken,
    'meta' => [
        'id' => $tokenId,
    ],
]);


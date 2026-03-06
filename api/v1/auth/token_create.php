<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('POST');
$user = api_require_session_login();

global $conn;

$label = trim((string) ($_POST['label'] ?? 'default'));
if ($label === '') {
    $label = 'default';
}
if (strlen($label) > 100) {
    $label = substr($label, 0, 100);
}

$expiresInDays = (int) ($_POST['expires_in_days'] ?? 30);
$expiresInDays = max(1, min($expiresInDays, 365));
$expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days"));

$scopeInput = trim((string) ($_POST['scopes'] ?? 'read,write'));
$allowedScopes = ['read', 'write'];
$scopeParts = array_values(array_unique(array_filter(array_map('trim', explode(',', $scopeInput)), static function ($scope) use ($allowedScopes) {
    return in_array($scope, $allowedScopes, true);
})));
if (empty($scopeParts)) {
    $scopeParts = ['read', 'write'];
}
$scopes = implode(',', $scopeParts);

$plainToken = 'lm_' . bin2hex(random_bytes(24));
$tokenHash = hash('sha256', $plainToken);

$insert = $conn->prepare("
    INSERT INTO api_tokens (user_id, token_hash, label, scopes, expires_at)
    VALUES (?, ?, ?, ?, ?)
");
$insert->bind_param('issss', $user['id'], $tokenHash, $label, $scopes, $expiresAt);
$ok = $insert->execute();
$tokenId = (int) $insert->insert_id;
$insert->close();

if (!$ok) {
    api_error('Unable to create API token right now.', 500);
}

audit_log($conn, 'api.token.create', [
    'token_id' => $tokenId,
    'label' => $label,
    'scopes' => $scopes,
    'expires_at' => $expiresAt,
], $user['id'], $user['role']);

api_json([
    'ok' => true,
    'message' => 'API token created. Store this token securely; it is shown only once.',
    'token' => $plainToken,
    'meta' => [
        'id' => $tokenId,
        'label' => $label,
        'scopes' => $scopes,
        'expires_at' => $expiresAt,
    ],
], 201);

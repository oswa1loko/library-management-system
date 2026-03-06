<?php
require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function api_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $status = 400, array $extra = []): void
{
    api_json(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

function api_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        api_error('Method not allowed.', 405);
    }
}

function api_user(): array
{
    return [
        'id' => (int) ($_SESSION['user_id'] ?? 0),
        'username' => (string) ($_SESSION['username'] ?? ''),
        'email' => (string) ($_SESSION['email'] ?? ''),
        'role' => (string) ($_SESSION['role'] ?? ''),
    ];
}

function api_get_request_header(string $name): string
{
    $target = strtolower($name);

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strtolower((string) $key) === $target) {
                return trim((string) $value);
            }
        }
    }

    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$serverKey] ?? ''));
}

function api_get_token_from_request(): string
{
    $authHeader = api_get_request_header('Authorization');
    if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $authHeader, $matches) === 1) {
        return trim((string) $matches[1]);
    }

    $headerToken = api_get_request_header('X-API-Token');
    if ($headerToken !== '') {
        return $headerToken;
    }

    return '';
}

function api_user_from_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    global $conn;
    $tokenHash = hash('sha256', $token);

    $stmt = $conn->prepare("
        SELECT t.id AS token_id, t.scopes, u.id, u.username, u.email, u.role
        FROM api_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ?
          AND (t.expires_at IS NULL OR t.expires_at > NOW())
        LIMIT 1
    ");
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $touch = $conn->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?");
    $touch->bind_param('i', $row['token_id']);
    $touch->execute();
    $touch->close();

    return [
        'token_id' => (int) $row['token_id'],
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'email' => (string) $row['email'],
        'role' => (string) $row['role'],
        'scopes' => (string) ($row['scopes'] ?? 'read,write'),
    ];
}

function api_require_login(): array
{
    $user = api_user();
    if ($user['id'] > 0 && $user['username'] !== '' && $user['role'] !== '') {
        return $user;
    }

    $token = api_get_token_from_request();
    if ($token !== '') {
        $tokenUser = api_user_from_token($token);
        if ($tokenUser !== null) {
            if (!api_token_has_scope($tokenUser, 'read')) {
                api_error('Forbidden: missing token scope "read".', 403);
            }
            return $tokenUser;
        }
    }

    api_error('Unauthorized.', 401);
}

function api_require_token_auth(): array
{
    $token = api_get_token_from_request();
    if ($token === '') {
        api_error('API token required.', 401);
    }

    $user = api_user_from_token($token);
    if ($user === null) {
        api_error('Invalid or expired API token.', 401);
    }

    return $user;
}

function api_token_has_scope(array $user, string $scope): bool
{
    $scope = trim($scope);
    if ($scope === '') {
        return true;
    }

    $rawScopes = (string) ($user['scopes'] ?? '');
    if ($rawScopes === '') {
        return false;
    }

    $parts = array_map('trim', explode(',', $rawScopes));
    return in_array($scope, $parts, true);
}

function api_require_token_scope(array $user, string $scope): void
{
    if (!api_token_has_scope($user, $scope)) {
        api_error('Forbidden: missing token scope "' . $scope . '".', 403);
    }
}

function api_require_session_login(): array
{
    $user = api_user();
    if ($user['id'] <= 0 || $user['username'] === '' || $user['role'] === '') {
        api_error('Session login required.', 401);
    }

    return $user;
}

function api_require_role(string $role): array
{
    $user = api_require_login();
    if ($user['role'] !== $role) {
        api_error('Forbidden.', 403);
    }
    return $user;
}

function api_query_limit(int $default = 20, int $max = 100): int
{
    $limit = (int) ($_GET['limit'] ?? $default);
    if ($limit < 1) {
        $limit = $default;
    }
    return min($limit, $max);
}

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect_to_dashboard(?string $role = null): void
{
    $role = $role ?? ($_SESSION['role'] ?? '');

    $map = [
        'admin' => '/librarymanage/admin/dashboard.php',
        'student' => '/librarymanage/student/dashboard.php',
        'faculty' => '/librarymanage/faculty/dashboard.php',
        'custodian' => '/librarymanage/custodian/dashboard.php',
    ];

    header('Location: ' . ($map[$role] ?? '/librarymanage/loginpage.php'));
    exit;
}

function page_title(string $role, string $title): string
{
    return ucfirst($role) . ' | ' . $title;
}

function system_roles(): array
{
    return ['student', 'faculty', 'custodian', 'admin'];
}

function complaint_statuses(): array
{
    return ['new', 'reviewed', 'resolved'];
}

function payment_statuses(): array
{
    return ['pending', 'approved', 'rejected'];
}

function penalty_statuses(): array
{
    return ['unpaid', 'paid'];
}

function ensure_upload_directory(string $path): bool
{
    return is_dir($path) || mkdir($path, 0777, true);
}

function format_currency($amount): string
{
    return 'PHP ' . number_format((float) $amount, 2);
}

function remove_relative_file(string $relativePath): void
{
    $relativePath = trim($relativePath);
    if ($relativePath === '') {
        return;
    }

    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function sync_overdue_penalties(mysqli $conn): array
{
    $inserted = 0;
    $updated = 0;

    $conn->query("
        INSERT INTO penalties (borrow_id, user_id, amount, reason, status)
        SELECT
            br.id,
            br.user_id,
            CAST(DATEDIFF(CURDATE(), br.due_date) * 2 AS DECIMAL(10,2)) AS amount,
            CONCAT('Overdue (', DATEDIFF(CURDATE(), br.due_date), ' day/s)') AS reason,
            'unpaid'
        FROM borrows br
        LEFT JOIN penalties p ON p.borrow_id = br.id
        WHERE br.status IN ('borrowed', 'return_requested')
          AND br.due_date < CURDATE()
          AND p.id IS NULL
    ");
    $inserted = max(0, (int) $conn->affected_rows);

    $conn->query("
        UPDATE penalties p
        JOIN borrows br ON br.id = p.borrow_id
        SET
            p.amount = CAST(DATEDIFF(CURDATE(), br.due_date) * 2 AS DECIMAL(10,2)),
            p.reason = CONCAT('Overdue (', DATEDIFF(CURDATE(), br.due_date), ' day/s)')
        WHERE br.status IN ('borrowed', 'return_requested')
          AND br.due_date < CURDATE()
          AND p.status = 'unpaid'
    ");
    $updated = max(0, (int) $conn->affected_rows);

    if ($inserted > 0) {
        create_notification(
            $conn,
            'admin',
            'Overdue Penalties Updated',
            $inserted . ' new overdue penalty record(s) were created by auto-sync.',
            'warning'
        );
    }

    return ['inserted' => $inserted, 'updated' => $updated];
}

function sync_overdue_penalties_if_needed(mysqli $conn, int $seconds = 60): void
{
    $lastRun = (int) ($_SESSION['overdue_penalty_sync_at'] ?? 0);
    $now = time();

    if ($now - $lastRun < $seconds) {
        return;
    }

    $_SESSION['overdue_penalty_sync_at'] = $now;

    try {
        sync_overdue_penalties($conn);
    } catch (Throwable $e) {
        // Keep the request flow resilient even if sync fails.
    }
}

function audit_log(mysqli $conn, string $eventName, array $context = [], ?int $actorUserId = null, ?string $actorRole = null): void
{
    $eventName = trim($eventName);
    if ($eventName === '') {
        return;
    }

    $actorUserId = $actorUserId ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($actorUserId <= 0) {
        $actorUserId = null;
    }

    $actorRole = $actorRole ?? (string) ($_SESSION['role'] ?? 'system');
    $actorRole = trim($actorRole) !== '' ? trim($actorRole) : 'system';

    $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    if ($contextJson !== null && $contextJson === false) {
        $contextJson = null;
    }

    $stmt = $conn->prepare("
        INSERT INTO audit_logs (actor_user_id, actor_role, event_name, context_json)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('isss', $actorUserId, $actorRole, $eventName, $contextJson);
    $stmt->execute();
    $stmt->close();
}

function create_notification(mysqli $conn, string $role, string $title, string $body, string $severity = 'info'): void
{
    $role = trim($role);
    $title = trim($title);
    $body = trim($body);
    if ($role === '' || $title === '' || $body === '') {
        return;
    }

    $allowedSeverity = ['info', 'warning', 'critical'];
    if (!in_array($severity, $allowedSeverity, true)) {
        $severity = 'info';
    }

    $stmt = $conn->prepare("
        INSERT INTO notifications (role, title, body, severity, is_read)
        VALUES (?, ?, ?, ?, 0)
    ");
    $stmt->bind_param('ssss', $role, $title, $body, $severity);
    $stmt->execute();
    $stmt->close();
}

function ensure_member_api_token(mysqli $conn, int $userId, string $label = 'member-ui', int $expiresDays = 30): string
{
    $key = 'member_api_token_' . $userId;
    $sessionToken = (string) ($_SESSION[$key] ?? '');
    if ($sessionToken !== '') {
        return $sessionToken;
    }

    $plainToken = 'lm_' . bin2hex(random_bytes(24));
    $tokenHash = hash('sha256', $plainToken);
    $expiresDays = max(1, min($expiresDays, 365));
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresDays} days"));

    $insert = $conn->prepare("
        INSERT INTO api_tokens (user_id, token_hash, label, scopes, expires_at)
        VALUES (?, ?, ?, 'read,write', ?)
    ");
    $insert->bind_param('isss', $userId, $tokenHash, $label, $expiresAt);
    $ok = $insert->execute();
    $insert->close();

    if (!$ok) {
        return '';
    }

    $_SESSION[$key] = $plainToken;
    return $plainToken;
}

function member_api_post_request(string $endpoint, array $fields, string $token): array
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $url = $scheme . '://' . $host . '/librarymanage/api/v1/' . ltrim($endpoint, '/');
    $payload = http_build_query($fields);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $json = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'status' => $status,
            'json' => is_array($json) ? $json : null,
            'transport_error' => $error,
        ];
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/x-www-form-urlencoded',
    ];
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }

    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'status' => $status,
        'json' => is_array($json) ? $json : null,
        'transport_error' => '',
    ];
}
?>

<?php
require_once __DIR__ . '/session.php';

app_start_session();

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_base_path(): string
{
    static $basePath = null;

    if ($basePath !== null) {
        return $basePath;
    }

    $configured = '';
    if (isset($GLOBALS['library_runtime_config']['app_base_path'])) {
        $configured = trim((string) $GLOBALS['library_runtime_config']['app_base_path']);
    }

    if ($configured === '') {
        $requestPathCandidates = [
            (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
            trim((string) ($_SERVER['SCRIPT_NAME'] ?? '')),
        ];

        foreach ($requestPathCandidates as $requestPath) {
            if ($requestPath === '') {
                continue;
            }

            $scriptDir = str_replace('\\', '/', dirname($requestPath));
            $scriptDir = $scriptDir === '/' || $scriptDir === '.' ? '' : rtrim($scriptDir, '/');

            if ($scriptDir !== '' && preg_match('#/(admin|student|faculty|librarian|api/v1|includes|frontend/dist)$#', $scriptDir) === 1) {
                $scriptDir = preg_replace('#/(admin|student|faculty|librarian|api/v1|includes|frontend/dist)$#', '', $scriptDir) ?? $scriptDir;
            }

            $configured = trim($scriptDir, '/');
            break;
        }
    }

    if ($configured === '' || $configured === '/') {
        $basePath = '';
        return $basePath;
    }

    $basePath = '/' . trim($configured, '/');
    return $basePath;
}

function app_url(string $path = ''): string
{
    $path = trim($path);
    if ($path === '' || $path === '/') {
        return app_base_path() !== '' ? app_base_path() . '/' : '/';
    }

    if (preg_match('#^(?:https?:)?//#i', $path) === 1) {
        return $path;
    }

    return (app_base_path() !== '' ? app_base_path() : '') . '/' . ltrim($path, '/');
}

function library_csrf_token(): string
{
    $token = (string) ($_SESSION['library_csrf_token'] ?? '');
    if ($token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['library_csrf_token'] = $token;
    }

    return $token;
}

function library_verify_csrf_token(?string $token): bool
{
    $sessionToken = (string) ($_SESSION['library_csrf_token'] ?? '');
    $token = trim((string) $token);

    if ($sessionToken === '' || $token === '') {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

function library_get_request_header(string $name): string
{
    $target = strtolower(trim($name));
    if ($target === '') {
        return '';
    }

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

function library_rate_limit_storage_path(): string
{
    return dirname(__DIR__) . '/storage/runtime/rate_limits.json';
}

function library_rate_limit_client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return $ip !== '' ? $ip : 'unknown';
}

function library_rate_limit_normalize_key(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return 'anonymous';
    }

    $value = preg_replace('/[^a-z0-9@._:-]+/', '-', $value) ?? $value;
    return trim($value, '-') !== '' ? trim($value, '-') : 'anonymous';
}

function library_rate_limit_attempt(string $bucket, int $maxAttempts, int $windowSeconds): array
{
    $maxAttempts = max(1, $maxAttempts);
    $windowSeconds = max(1, $windowSeconds);

    $path = library_rate_limit_storage_path();
    $directory = dirname($path);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        return [
            'allowed' => true,
            'retry_after' => 0,
            'remaining' => $maxAttempts - 1,
        ];
    }

    $now = time();
    $windowStart = $now - $windowSeconds;
    $state = [];

    try {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return [
                'allowed' => true,
                'retry_after' => 0,
                'remaining' => $maxAttempts - 1,
            ];
        }

        $raw = stream_get_contents($handle);
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }

        foreach ($state as $key => $timestamps) {
            if (!is_array($timestamps)) {
                unset($state[$key]);
                continue;
            }

            $filtered = array_values(array_filter($timestamps, static function ($timestamp) use ($windowStart) {
                return is_int($timestamp) && $timestamp >= $windowStart;
            }));

            if ($filtered === []) {
                unset($state[$key]);
            } else {
                $state[$key] = $filtered;
            }
        }

        $bucketKey = library_rate_limit_normalize_key($bucket);
        $bucketTimestamps = array_values(array_filter($state[$bucketKey] ?? [], static function ($timestamp) use ($windowStart) {
            return is_int($timestamp) && $timestamp >= $windowStart;
        }));

        if (count($bucketTimestamps) >= $maxAttempts) {
            $oldest = min($bucketTimestamps);
            $retryAfter = max(1, ($oldest + $windowSeconds) - $now);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);

            return [
                'allowed' => false,
                'retry_after' => $retryAfter,
                'remaining' => 0,
            ];
        }

        $bucketTimestamps[] = $now;
        $state[$bucketKey] = $bucketTimestamps;

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return [
            'allowed' => true,
            'retry_after' => 0,
            'remaining' => max(0, $maxAttempts - count($bucketTimestamps)),
        ];
    } catch (Throwable $exception) {
        @flock($handle, LOCK_UN);
        fclose($handle);
        return [
            'allowed' => true,
            'retry_after' => 0,
            'remaining' => $maxAttempts - 1,
        ];
    }
}

function library_rate_limit_clear(string $bucket): void
{
    $path = library_rate_limit_storage_path();
    if (!is_file($path)) {
        return;
    }

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }

        $raw = stream_get_contents($handle);
        $state = json_decode((string) $raw, true);
        if (!is_array($state)) {
            $state = [];
        }

        unset($state[library_rate_limit_normalize_key($bucket)]);

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    } catch (Throwable $exception) {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function library_should_enforce_csrf(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return false;
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('#^/(?:api/v1)(?:/|$)#i', $requestUri) === 1 || preg_match('#/(?:api/v1)(?:/|$)#i', $scriptName) === 1) {
        return false;
    }

    return true;
}

function library_reject_csrf_request(): void
{
    http_response_code(419);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Expired | Library</title>
    <link rel="stylesheet" href="<?php echo h(app_url('assets/app.css')); ?>">
    </head>
    <body>
    <div class="auth-shell">
      <div class="card surface-shell-xl">
        <div class="panel-pad-lg stack">
          <p class="muted eyebrow">Security Check</p>
          <h2 class="heading-section">Request expired or invalid</h2>
          <div class="notice error">This action was blocked because the security token was missing or invalid.</div>
          <p class="muted text-measure text-measure-wide">Refresh the page and try again. If this keeps happening, sign in again first.</p>
          <div class="inline-actions">
            <a class="button" href="<?php echo h(app_url('loginpage.php')); ?>">Open Login</a>
            <a class="button secondary" href="<?php echo h(app_url('index.php')); ?>">Back Home</a>
          </div>
        </div>
      </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

function library_enforce_csrf_for_post(): void
{
    if (!library_should_enforce_csrf()) {
        return;
    }

    $token = (string) ($_POST['csrf_token'] ?? library_get_request_header('X-CSRF-Token') ?? '');
    if (!library_verify_csrf_token($token)) {
        library_reject_csrf_request();
    }
}

function library_inject_csrf_hidden_fields(string $buffer): string
{
    if (PHP_SAPI === 'cli' || stripos($buffer, '<form') === false) {
        return $buffer;
    }

    $token = library_csrf_token();

    return preg_replace_callback(
        '#<form\b([^>]*)>#i',
        static function (array $matches) use ($token): string {
            $formTag = (string) $matches[0];
            $attributes = (string) ($matches[1] ?? '');

            if (preg_match('/\bmethod\s*=\s*([\'"]?)post\1/i', $attributes) !== 1) {
                return $formTag;
            }

            if (stripos($formTag, 'name="csrf_token"') !== false || stripos($formTag, "name='csrf_token'") !== false) {
                return $formTag;
            }

            return $formTag . "\n" . '  <input type="hidden" name="csrf_token" value="' . h($token) . '">';
        },
        $buffer
    ) ?? $buffer;
}

function app_public_url(string $path = ''): string
{
    $configured = trim(library_runtime_value('app_public_url'));
    if ($configured !== '') {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return rtrim($configured, '/');
        }
        return rtrim($configured, '/') . '/' . ltrim($path, '/');
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . app_url($path);
}

function app_is_local_environment(): bool
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function redirect_to_dashboard(?string $role = null): void
{
    $role = canonical_role($role ?? ($_SESSION['role'] ?? ''));

    $map = [
        'admin' => app_url('admin/dashboard.php'),
        'student' => app_url('student/dashboard.php'),
        'faculty' => app_url('faculty/dashboard.php'),
        'librarian' => app_url('librarian/dashboard.php'),
    ];

    header('Location: ' . ($map[$role] ?? app_url('loginpage.php')));
    exit;
}

function page_title(string $role, string $title): string
{
    return role_label($role) . ' | ' . $title;
}

function system_roles(): array
{
    return ['student', 'faculty', 'librarian', 'admin'];
}

function student_course_options(): array
{
    return [
        'BSIT' => 'BSIT',
        'BSCS' => 'BSCS',
        'BSIS' => 'BSIS',
        'BSBA' => 'BSBA',
        'BSA' => 'BSA',
        'BSHM' => 'BSHM',
        'BSTM' => 'BSTM',
        'BSED' => 'BSED',
        'BEED' => 'BEED',
        'BSCRIM' => 'BSCRIM',
        'BSN' => 'BSN',
        'BSOA' => 'BSOA',
    ];
}

function role_label(string $role): string
{
    $map = [
        'admin' => 'Admin',
        'student' => 'Student',
        'faculty' => 'Faculty',
        'librarian' => 'Librarian',
        'system' => 'System',
    ];

    $role = canonical_role(trim($role));
    return $map[$role] ?? ucfirst($role);
}

function canonical_role(string $role): string
{
    $role = trim($role);
    // Accept the legacy DB/session value during migration and normalize it.
    return $role === 'custodian' ? 'librarian' : $role;
}

function roles_match(string $actualRole, string $expectedRole): bool
{
    return canonical_role($actualRole) === canonical_role($expectedRole);
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

function get_member_dashboard_summary(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("
        SELECT
          (SELECT COALESCE(SUM(CASE WHEN status IN ('borrowed', 'return_requested') THEN 1 ELSE 0 END), 0)
             FROM borrows
            WHERE user_id = ?) AS active_borrows,
          (SELECT COALESCE(SUM(CASE WHEN status IN ('borrowed', 'return_requested') AND due_date < CURDATE() THEN 1 ELSE 0 END), 0)
             FROM borrows
            WHERE user_id = ?) AS overdue_borrows,
          (SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0)
             FROM payments
            WHERE user_id = ?) AS pending_payments,
          (SELECT COALESCE(SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END), 0)
             FROM penalties
            WHERE user_id = ?) AS unpaid_penalties,
          (SELECT COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END), 0)
             FROM penalties
            WHERE user_id = ?) AS unpaid_total
    ");
    $stmt->bind_param('iiiii', $userId, $userId, $userId, $userId, $userId);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return $summary;
}

function member_due_notification_delay_minutes(): int
{
    return 5;
}

function get_member_due_soon_books(mysqli $conn, int $userId, int $limit = 5): array
{
    $limit = max(1, $limit);
    $delayMinutes = member_due_notification_delay_minutes();
    $stmt = $conn->prepare("
        SELECT br.id, b.title, br.due_date, br.status
        FROM borrows br
        JOIN books b ON b.id = br.book_id
        WHERE br.user_id = ?
          AND br.status IN ('borrowed', 'return_requested')
          AND br.due_date >= CURDATE()
          AND br.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
          AND TIMESTAMPDIFF(
                MINUTE,
                COALESCE(br.approved_at, CONCAT(br.borrow_date, ' 00:00:00')),
                NOW()
              ) >= ?
        ORDER BY br.due_date ASC
        LIMIT ?
    ");
    $stmt->bind_param('iii', $userId, $delayMinutes, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function get_member_overdue_books(mysqli $conn, int $userId, int $limit = 5): array
{
    $limit = max(1, $limit);
    $stmt = $conn->prepare("
        SELECT br.id, b.title, br.due_date, br.status
        FROM borrows br
        JOIN books b ON b.id = br.book_id
        WHERE br.user_id = ?
          AND br.status IN ('borrowed', 'return_requested')
          AND br.due_date < CURDATE()
        ORDER BY br.due_date ASC, br.id DESC
        LIMIT ?
    ");
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function get_member_recent_return_confirmations(mysqli $conn, int $userId, int $limit = 5, int $days = 7): array
{
    $limit = max(1, $limit);
    $days = max(1, $days);

    $stmt = $conn->prepare("
        SELECT b.title, br.returned_at, br.return_date
        FROM borrows br
        JOIN books b ON b.id = br.book_id
        WHERE br.user_id = ?
          AND br.status = 'returned'
          AND (
            (br.returned_at IS NOT NULL AND br.returned_at >= DATE_SUB(NOW(), INTERVAL ? DAY))
            OR
            (br.returned_at IS NULL AND br.return_date IS NOT NULL AND br.return_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY))
          )
        ORDER BY COALESCE(br.returned_at, CONCAT(br.return_date, ' 00:00:00')) DESC, br.id DESC
        LIMIT ?
    ");
    $stmt->bind_param('iiii', $userId, $days, $days, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function format_display_date(?string $dateValue, string $fallback = '-'): string
{
    $dateValue = trim((string) $dateValue);
    if ($dateValue === '' || $dateValue === '0000-00-00') {
        return $fallback;
    }

    $timestamp = strtotime($dateValue);
    if ($timestamp === false) {
        return $dateValue;
    }

    return date('M j, Y', $timestamp);
}

function format_display_datetime(?string $dateValue, string $fallback = '-'): string
{
    $dateValue = trim((string) $dateValue);
    if ($dateValue === '' || $dateValue === '0000-00-00' || $dateValue === '0000-00-00 00:00:00') {
        return $fallback;
    }

    $timestamp = strtotime($dateValue);
    if ($timestamp === false) {
        return $dateValue;
    }

    return date('M j, Y g:i A', $timestamp);
}

function format_batch_reference(?string $batchRef, string $label = 'Request Ref'): string
{
    $batchRef = trim((string) $batchRef);
    $label = trim($label) !== '' ? trim($label) : 'Request Ref';

    if ($batchRef === '') {
        return $label;
    }

    if (preg_match('/^legacy-(\d+)$/i', $batchRef, $matches) === 1) {
        return $label . ' ' . str_pad($matches[1], 3, '0', STR_PAD_LEFT);
    }

    $suffix = $batchRef;
    if (str_contains($batchRef, '-')) {
        $parts = explode('-', $batchRef, 2);
        $suffix = $parts[1] ?? $batchRef;
    }

    $suffix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $suffix), 0, 6));
    if ($suffix === '') {
        return $label;
    }

    return $label . ' ' . $suffix;
}

function library_runtime_value(string $key, string $fallback = ''): string
{
    $key = trim($key);
    if ($key === '') {
        return $fallback;
    }

    $value = trim((string) getenv($key));
    if ($value !== '') {
        return $value;
    }

    $config = $GLOBALS['library_runtime_config'] ?? [];
    if (is_array($config)) {
        $configured = trim((string) ($config[$key] ?? ''));
        if ($configured !== '') {
            return $configured;
        }
    }

    return $fallback;
}

function library_mail_from_address(): string
{
    return library_runtime_value('LIBRARY_MAIL_FROM_ADDRESS', 'no-reply@localhost');
}

function library_mail_from_name(): string
{
    return library_runtime_value('LIBRARY_MAIL_FROM_NAME', 'Library Management System');
}

function library_email_signature(): string
{
    return library_runtime_value('LIBRARY_EMAIL_SIGNATURE', 'Library Services Team');
}

function mask_email_address(string $email): string
{
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) {
        return $email;
    }

    [$localPart, $domain] = explode('@', $email, 2);
    if ($localPart === '') {
        return '*@' . $domain;
    }

    if (strlen($localPart) <= 2) {
        return substr($localPart, 0, 1) . str_repeat('*', max(0, strlen($localPart) - 1)) . '@' . $domain;
    }

    return substr($localPart, 0, 2) . str_repeat('*', max(0, strlen($localPart) - 2)) . '@' . $domain;
}

function is_valid_email_address(string $email): bool
{
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

function role_requires_login_otp(string $role): bool
{
    $role = canonical_role($role);
    return in_array($role, ['student', 'faculty'], true);
}

function generate_login_otp_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function login_otp_resend_cooldown_seconds(): int
{
    $value = (int) library_runtime_value('LIBRARY_LOGIN_OTP_RESEND_COOLDOWN');
    return $value >= 30 ? $value : 60;
}

function login_otp_max_attempts(): int
{
    $value = (int) library_runtime_value('LIBRARY_LOGIN_OTP_MAX_ATTEMPTS');
    return $value > 0 ? $value : 5;
}

function get_login_otp_resend_wait_seconds(mysqli $conn, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT login_otp_sent_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($sentAt);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found || trim((string) $sentAt) === '') {
        return 0;
    }

    $sentAtTimestamp = strtotime((string) $sentAt);
    if ($sentAtTimestamp === false) {
        return 0;
    }

    $remaining = ($sentAtTimestamp + login_otp_resend_cooldown_seconds()) - time();
    return max(0, $remaining);
}

function clear_login_otp(mysqli $conn, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $stmt = $conn->prepare("
        UPDATE users
        SET login_otp_hash = NULL,
            login_otp_expires_at = NULL,
            login_otp_sent_at = NULL
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function issue_login_otp(mysqli $conn, int $userId): array
{
    $code = generate_login_otp_code();
    $hash = hash('sha256', $code);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $sentAt = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("
        UPDATE users
        SET login_otp_hash = ?,
            login_otp_expires_at = ?,
            login_otp_sent_at = ?
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('sssi', $hash, $expiresAt, $sentAt, $userId);
    $stmt->execute();
    $stmt->close();

    return [
        'code' => $code,
        'expires_at' => $expiresAt,
        'sent_at' => $sentAt,
    ];
}

function send_login_otp_email(string $email, string $fullName, string $role, string $otpCode): bool
{
    $payload = build_login_otp_email_payload($email, $fullName, $role, $otpCode);
    if (!$payload) {
        return false;
    }

    return send_library_email(
        (string) $payload['to'],
        (string) $payload['subject'],
        (string) $payload['text'],
        (string) $payload['html']
    );
}

function build_login_otp_email_payload(string $email, string $fullName, string $role, string $otpCode): ?array
{
    $email = trim($email);
    $fullName = trim($fullName);
    $otpCode = trim($otpCode);

    if (!is_valid_email_address($email) || $otpCode === '') {
        set_library_mail_last_error('Missing or invalid login OTP recipient.');
        return null;
    }

    $roleLabel = role_label($role);
    $subject = 'Your Library Login Verification Code';
    $message = "Hello {$fullName},\n\n"
        . "Use this verification code to finish logging in to the library portal:\n\n"
        . "{$otpCode}\n\n"
        . "This code is valid for 10 minutes.\n\n"
        . "Role: {$roleLabel}\n\n"
        . "Do not share this code with anyone.\n\n"
        . "If you did not try to log in, you may ignore this email.\n\n"
        . library_email_signature();

    $htmlMessage = '<div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.6;color:#10233a;">'
        . '<p>Hello <strong>' . h($fullName) . '</strong>,</p>'
        . '<p>Use this verification code to finish logging in to the library portal:</p>'
        . '<div style="margin:16px 0;padding:14px 18px;border-radius:14px;background:#f7fbff;border:1px solid #d7e6f5;font-size:28px;font-weight:800;letter-spacing:0.22em;text-align:center;">'
        . h($otpCode)
        . '</div>'
        . '<p>This code is valid for <strong>10 minutes</strong>.</p>'
        . '<p><strong>Role:</strong> ' . h($roleLabel) . '</p>'
        . '<p><strong>Do not share this code with anyone.</strong></p>'
        . '<p style="color:#5c7188;">If you did not try to log in, you may ignore this email.</p>'
        . '<p style="margin-top:22px;">' . h(library_email_signature()) . '</p>'
        . '</div>';

    return [
        'to' => $email,
        'subject' => $subject,
        'text' => $message,
        'html' => $htmlMessage,
    ];
}

function enqueue_login_otp_email_job(mysqli $conn, string $email, string $fullName, string $role, string $otpCode): bool
{
    $payload = build_login_otp_email_payload($email, $fullName, $role, $otpCode);
    if (!$payload) {
        return false;
    }

    $clearPendingStmt = $conn->prepare("
        DELETE FROM email_jobs
        WHERE job_type = 'login_otp'
          AND recipient_email = ?
          AND status = 'pending'
    ");
    $clearPendingStmt->bind_param('s', $payload['to']);
    $clearPendingStmt->execute();
    $clearPendingStmt->close();

    return enqueue_email_job(
        $conn,
        'login_otp',
        (string) $payload['to'],
        (string) $payload['subject'],
        (string) $payload['text'],
        (string) $payload['html']
    );
}

function password_setup_expiry_hours(): int
{
    $hours = (int) library_runtime_value('LIBRARY_PASSWORD_SETUP_EXPIRY_HOURS');
    return $hours >= 1 ? min($hours, 168) : 72;
}

function generate_placeholder_password(): string
{
    return password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
}

function password_token_url(string $token, string $purpose = 'account_setup'): string
{
    $token = trim($token);
    $purpose = trim($purpose) !== '' ? trim($purpose) : 'account_setup';

    $path = $purpose === 'password_reset' ? 'reset_password.php' : 'setup_password.php';
    return app_public_url($path . '?token=' . urlencode($token));
}

function password_setup_url(string $token): string
{
    return password_token_url($token, 'account_setup');
}

function invalidate_password_setup_tokens(mysqli $conn, int $userId, ?string $purpose = null): void
{
    if ($userId <= 0) {
        return;
    }

    if ($purpose !== null && $purpose !== '') {
        $stmt = $conn->prepare("
            UPDATE password_setup_tokens
            SET used_at = NOW()
            WHERE user_id = ?
              AND purpose = ?
              AND used_at IS NULL
        ");
        $stmt->bind_param('is', $userId, $purpose);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare("
        UPDATE password_setup_tokens
        SET used_at = NOW()
        WHERE user_id = ?
          AND used_at IS NULL
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function issue_password_setup_token(mysqli $conn, int $userId, string $purpose = 'account_setup'): ?array
{
    $userId = max(0, $userId);
    $purpose = trim($purpose) !== '' ? trim($purpose) : 'account_setup';
    if ($userId <= 0) {
        return null;
    }

    invalidate_password_setup_tokens($conn, $userId, $purpose);

    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . password_setup_expiry_hours() . ' hours'));

    $stmt = $conn->prepare("
        INSERT INTO password_setup_tokens (user_id, token_hash, purpose, expires_at)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param('isss', $userId, $tokenHash, $purpose, $expiresAt);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        return null;
    }

    if ($purpose === 'account_setup') {
        $update = $conn->prepare("
            UPDATE users
            SET password_setup_required = 1
            WHERE id = ?
            LIMIT 1
        ");
        $update->bind_param('i', $userId);
        $update->execute();
        $update->close();
    }

    return [
        'token' => $plainToken,
        'expires_at' => $expiresAt,
        'url' => password_token_url($plainToken, $purpose),
    ];
}

function find_password_setup_token(mysqli $conn, string $plainToken, string $purpose = 'account_setup'): ?array
{
    $plainToken = trim($plainToken);
    if ($plainToken === '') {
        return null;
    }

    $tokenHash = hash('sha256', $plainToken);
    $stmt = $conn->prepare("
        SELECT
            pst.id,
            pst.user_id,
            pst.purpose,
            pst.expires_at,
            pst.used_at,
            u.fullname,
            u.email,
            u.username,
            u.role,
            u.password_setup_required
        FROM password_setup_tokens pst
        JOIN users u ON u.id = pst.user_id
        WHERE pst.token_hash = ?
          AND pst.purpose = ?
          AND pst.used_at IS NULL
          AND pst.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->bind_param('ss', $tokenHash, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function complete_password_setup(mysqli $conn, int $userId, int $tokenId, string $password, string $purpose = 'account_setup'): bool
{
    $userId = max(0, $userId);
    $tokenId = max(0, $tokenId);
    $password = trim($password);
    $purpose = trim($purpose) !== '' ? trim($purpose) : 'account_setup';
    if ($userId <= 0 || $tokenId <= 0 || $password === '') {
        return false;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($purpose === 'account_setup') {
        $updateUser = $conn->prepare("
            UPDATE users
            SET password = ?,
                password_setup_required = 0,
                password_setup_completed_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");
        $updateUser->bind_param('si', $passwordHash, $userId);
    } else {
        $updateUser = $conn->prepare("
            UPDATE users
            SET password = ?
            WHERE id = ?
            LIMIT 1
        ");
        $updateUser->bind_param('si', $passwordHash, $userId);
    }
    $userOk = $updateUser->execute();
    $updateUser->close();

    if (!$userOk) {
        return false;
    }

    $consume = $conn->prepare("
        UPDATE password_setup_tokens
        SET used_at = NOW()
        WHERE id = ?
          AND user_id = ?
          AND used_at IS NULL
        LIMIT 1
    ");
    $consume->bind_param('ii', $tokenId, $userId);
    $consume->execute();
    $consume->close();

    invalidate_password_setup_tokens($conn, $userId, $purpose);
    clear_login_otp($conn, $userId);

    return true;
}

function build_password_reset_email_payload(
    string $email,
    string $fullName,
    string $role,
    string $username,
    string $resetUrl
): ?array {
    $email = trim($email);
    $fullName = trim($fullName);
    $username = trim($username);
    $resetUrl = trim($resetUrl);

    if (!is_valid_email_address($email) || $resetUrl === '') {
        set_library_mail_last_error('Missing or invalid password reset recipient.');
        return null;
    }

    $roleLabel = role_label($role);
    $subject = 'Library Account Password Reset Request';
    $message = "Dear {$fullName},\n\n"
        . "A request has been received to reset the password for your library account.\n\n"
        . "Account details:\n"
        . "Role: {$roleLabel}\n"
        . "Username: {$username}\n\n"
        . "To continue, please use the secure link below to create a new password:\n"
        . "{$resetUrl}\n\n"
        . "For your security, this reset link will expire in " . password_setup_expiry_hours() . " hours.\n\n"
        . "If you did not request a password reset, no further action is required and you may disregard this message.\n\n"
        . library_email_signature();

    $htmlMessage = '<div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.6;color:#10233a;">'
        . '<p>Dear <strong>' . h($fullName) . '</strong>,</p>'
        . '<p>A request has been received to reset the password for your library account.</p>'
        . '<div style="margin:16px 0;padding:16px 18px;border-radius:14px;background:#f7fbff;border:1px solid #d7e6f5;">'
        . '<p style="margin:0 0 10px;font-weight:700;color:#27496b;">Account details</p>'
        . '<p style="margin:0 0 8px;"><strong>Role:</strong> ' . h($roleLabel) . '</p>'
        . '<p style="margin:0;"><strong>Username:</strong> ' . h($username) . '</p>'
        . '</div>'
        . '<p>To continue, please use the secure link below to create a new password:</p>'
        . '<p style="margin:18px 0;">'
        . '<a href="' . h($resetUrl) . '" style="display:inline-block;padding:12px 18px;border-radius:999px;background:#124170;color:#ffffff;text-decoration:none;font-weight:700;">Reset Password</a>'
        . '</p>'
        . '<p>If the button above does not open, copy and paste this link into your browser:</p>'
        . '<p><a href="' . h($resetUrl) . '">' . h($resetUrl) . '</a></p>'
        . '<p>For your security, this reset link will expire in <strong>' . password_setup_expiry_hours() . ' hours</strong>.</p>'
        . '<p style="color:#5c7188;">If you did not request a password reset, no further action is required and you may disregard this message.</p>'
        . '<p style="margin-top:22px;">' . h(library_email_signature()) . '</p>'
        . '</div>';

    return [
        'to' => $email,
        'subject' => $subject,
        'text' => $message,
        'html' => $htmlMessage,
    ];
}

function enqueue_password_reset_email_job(
    mysqli $conn,
    string $email,
    string $fullName,
    string $role,
    string $username,
    string $resetUrl
): bool {
    $payload = build_password_reset_email_payload($email, $fullName, $role, $username, $resetUrl);
    if (!$payload) {
        return false;
    }

    return enqueue_email_job(
        $conn,
        'password_reset',
        (string) $payload['to'],
        (string) $payload['subject'],
        (string) $payload['text'],
        (string) $payload['html']
    );
}

function build_account_setup_email_payload(
    string $email,
    string $fullName,
    string $role,
    string $username,
    string $setupUrl
): ?array {
    $email = trim($email);
    $fullName = trim($fullName);
    $username = trim($username);
    $setupUrl = trim($setupUrl);

    if (!is_valid_email_address($email) || $setupUrl === '') {
        set_library_mail_last_error('Missing or invalid account setup recipient.');
        return null;
    }

    $roleLabel = role_label($role);
    $subject = 'Library Account Activation';
    $message = "Dear {$fullName},\n\n"
        . "A library account has been created for you.\n\n"
        . "Account details:\n"
        . "Role: {$roleLabel}\n"
        . "Username: {$username}\n\n"
        . "To activate your account, please use the secure link below to create your password:\n"
        . "{$setupUrl}\n\n"
        . "For your security, this activation link will expire in " . password_setup_expiry_hours() . " hours.\n\n"
        . "After completing password setup, you may sign in to the library system using your username or registered email address.\n\n"
        . "If you were not expecting this account, no further action is required and you may disregard this message.\n\n"
        . library_email_signature();

    $htmlMessage = '<div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.6;color:#10233a;">'
        . '<p>Dear <strong>' . h($fullName) . '</strong>,</p>'
        . '<p>A library account has been created for you.</p>'
        . '<div style="margin:16px 0;padding:16px 18px;border-radius:14px;background:#f7fbff;border:1px solid #d7e6f5;">'
        . '<p style="margin:0 0 10px;font-weight:700;color:#27496b;">Account details</p>'
        . '<p style="margin:0 0 8px;"><strong>Role:</strong> ' . h($roleLabel) . '</p>'
        . '<p style="margin:0;"><strong>Username:</strong> ' . h($username) . '</p>'
        . '</div>'
        . '<p>To activate your account, please use the secure link below to create your password:</p>'
        . '<p style="margin:18px 0;">'
        . '<a href="' . h($setupUrl) . '" style="display:inline-block;padding:12px 18px;border-radius:999px;background:#124170;color:#ffffff;text-decoration:none;font-weight:700;">Set Your Password</a>'
        . '</p>'
        . '<p>If the button above does not open, copy and paste this link into your browser:</p>'
        . '<p><a href="' . h($setupUrl) . '">' . h($setupUrl) . '</a></p>'
        . '<p>For your security, this activation link will expire in <strong>' . password_setup_expiry_hours() . ' hours</strong>.</p>'
        . '<p>After completing password setup, you may sign in using your username or registered email address.</p>'
        . '<p style="color:#5c7188;">If you were not expecting this account, no further action is required and you may disregard this message.</p>'
        . '<p style="margin-top:22px;">' . h(library_email_signature()) . '</p>'
        . '</div>';

    return [
        'to' => $email,
        'subject' => $subject,
        'text' => $message,
        'html' => $htmlMessage,
    ];
}

function enqueue_account_setup_email_job(
    mysqli $conn,
    string $email,
    string $fullName,
    string $role,
    string $username,
    string $setupUrl
): bool {
    $payload = build_account_setup_email_payload($email, $fullName, $role, $username, $setupUrl);
    if (!$payload) {
        return false;
    }

    return enqueue_email_job(
        $conn,
        'account_setup',
        (string) $payload['to'],
        (string) $payload['subject'],
        (string) $payload['text'],
        (string) $payload['html']
    );
}

function build_borrow_approval_email_payload(mysqli $conn, int $borrowId): ?array
{
    $borrowId = max(0, $borrowId);
    if ($borrowId <= 0) {
        set_library_mail_last_error('Missing borrow record.');
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            br.id,
            br.borrow_date,
            br.due_date,
            u.fullname,
            u.email,
            u.role,
            b.title,
            b.author
        FROM borrows br
        JOIN users u ON u.id = br.user_id
        JOIN books b ON b.id = br.book_id
        WHERE br.id = ? AND br.status = 'borrowed'
        LIMIT 1
    ");
    $stmt->bind_param('i', $borrowId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        set_library_mail_last_error('Borrow record is not available for approval email.');
        return null;
    }

    $email = trim((string) ($row['email'] ?? ''));
    if ($email === '' || !is_valid_email_address($email)) {
        set_library_mail_last_error('Borrower email address is missing or invalid.');
        return null;
    }

    $fullName = trim((string) ($row['fullname'] ?? 'Library Member'));
    $roleLabel = role_label((string) ($row['role'] ?? ''));
    $title = trim((string) ($row['title'] ?? ''));
    $author = trim((string) ($row['author'] ?? ''));
    $borrowDate = format_display_date((string) ($row['borrow_date'] ?? ''));
    $dueDate = format_display_date((string) ($row['due_date'] ?? ''));

    $subject = 'Borrow Request Approved - ' . ($title !== '' ? $title : 'Library Book');
    $message = "Good day, {$fullName}.\n\n"
        . "This is to inform you that your borrowing request has been approved by the Regis Marie College Library.\n\n"
        . "Book: {$title}\n"
        . "Author: {$author}\n"
        . "Borrow date: {$borrowDate}\n"
        . "Due date: {$dueDate}\n"
        . "Account type: {$roleLabel}\n\n"
        . "Please claim and return the book on or before the due date. Kindly keep it in good condition while it is under your care.\n\n"
        . "Thank you.\n\n"
        . library_email_signature();

    $htmlMessage = '<div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.6;color:#10233a;">'
        . '<p>Good day, <strong>' . h($fullName) . '</strong>.</p>'
        . '<p>This is to inform you that your borrowing request has been <strong>approved</strong> by the <strong>Regis Marie College Library</strong>.</p>'
        . '<div style="margin:16px 0;padding:16px 18px;border-radius:14px;background:#f7fbff;border:1px solid #d7e6f5;">'
        . '<p style="margin:0 0 8px;"><strong>Book:</strong> ' . h($title) . '</p>'
        . '<p style="margin:0 0 8px;"><strong>Author:</strong> ' . h($author) . '</p>'
        . '<p style="margin:0 0 8px;"><strong>Borrow date:</strong> ' . h($borrowDate) . '</p>'
        . '<p style="margin:0;"><strong>Due date:</strong> ' . h($dueDate) . '</p>'
        . '</div>'
        . '<p><strong>Account type:</strong> ' . h($roleLabel) . '</p>'
        . '<p>Please claim and return the book on or before the due date. Kindly keep it in good condition while it is under your care.</p>'
        . '<p>Thank you.</p>'
        . '<p style="margin-top:22px;">' . h(library_email_signature()) . '</p>'
        . '</div>';

    return [
        'borrow_id' => $borrowId,
        'to' => $email,
        'subject' => $subject,
        'text' => $message,
        'html' => $htmlMessage,
    ];
}

function build_grouped_borrow_approval_email_payload(mysqli $conn, int $borrowId): ?array
{
    $borrowId = max(0, $borrowId);
    if ($borrowId <= 0) {
        set_library_mail_last_error('Missing borrow record.');
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            br.id,
            br.request_batch,
            br.user_id,
            br.book_id,
            u.fullname,
            u.email,
            u.role,
            b.title,
            b.author
        FROM borrows br
        JOIN users u ON u.id = br.user_id
        JOIN books b ON b.id = br.book_id
        WHERE br.id = ? AND br.status = 'borrowed'
        LIMIT 1
    ");
    $stmt->bind_param('i', $borrowId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        set_library_mail_last_error('Borrow record is not available for approval email.');
        return null;
    }

    $requestBatch = trim((string) ($row['request_batch'] ?? ''));
    $userId = (int) ($row['user_id'] ?? 0);
    $bookId = (int) ($row['book_id'] ?? 0);
    if ($requestBatch === '' || $userId <= 0 || $bookId <= 0) {
        set_library_mail_last_error('Borrow record is missing batch details.');
        return null;
    }

    $pendingStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM borrows
        WHERE request_batch = ?
          AND user_id = ?
          AND book_id = ?
          AND status = 'pending'
    ");
    $pendingStmt->bind_param('sii', $requestBatch, $userId, $bookId);
    $pendingStmt->execute();
    $pendingTotal = (int) (($pendingStmt->get_result()->fetch_assoc()['total'] ?? 0));
    $pendingStmt->close();

    if ($pendingTotal > 0) {
        set_library_mail_last_error('Approval email deferred until all pending copies of this title are processed.');
        return null;
    }

    $groupStmt = $conn->prepare("
        SELECT id, borrow_date, due_date
        FROM borrows
        WHERE request_batch = ?
          AND user_id = ?
          AND book_id = ?
          AND status = 'borrowed'
          AND approval_notice_sent_at IS NULL
        ORDER BY id ASC
    ");
    $groupStmt->bind_param('sii', $requestBatch, $userId, $bookId);
    $groupStmt->execute();
    $groupRows = $groupStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $groupStmt->close();

    if ($groupRows === []) {
        set_library_mail_last_error('Borrow approval email already queued for this title.');
        return null;
    }

    $email = trim((string) ($row['email'] ?? ''));
    if ($email === '' || !is_valid_email_address($email)) {
        set_library_mail_last_error('Borrower email address is missing or invalid.');
        return null;
    }

    $borrowIds = array_map(static fn(array $groupRow): int => (int) ($groupRow['id'] ?? 0), $groupRows);
    $copyCount = count($borrowIds);
    $fullName = trim((string) ($row['fullname'] ?? 'Library Member'));
    $roleLabel = role_label((string) ($row['role'] ?? ''));
    $title = trim((string) ($row['title'] ?? ''));
    $author = trim((string) ($row['author'] ?? ''));
    $borrowDate = format_display_date((string) ($groupRows[0]['borrow_date'] ?? ''));
    $dueDate = format_display_date((string) ($groupRows[0]['due_date'] ?? ''));
    $copyLabel = $copyCount === 1 ? '1 copy' : $copyCount . ' copies';

    $subject = 'Borrow Request Approved - ' . ($title !== '' ? $title : 'Library Book');
    $message = "Good day, {$fullName}.\n\n"
        . "This is to inform you that your borrowing request has been approved by the Regis Marie College Library.\n\n"
        . "Book: {$title}\n"
        . "Author: {$author}\n"
        . "Approved copies: {$copyLabel}\n"
        . "Borrow date: {$borrowDate}\n"
        . "Due date: {$dueDate}\n"
        . "Account type: {$roleLabel}\n\n"
        . "Please claim and return the book(s) on or before the due date. Kindly keep them in good condition while they are under your care.\n\n"
        . "Thank you.\n\n"
        . library_email_signature();

    $htmlMessage = '<div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.6;color:#10233a;">'
        . '<p>Good day, <strong>' . h($fullName) . '</strong>.</p>'
        . '<p>This is to inform you that your borrowing request has been <strong>approved</strong> by the <strong>Regis Marie College Library</strong>.</p>'
        . '<div style="margin:16px 0;padding:16px 18px;border-radius:14px;background:#f7fbff;border:1px solid #d7e6f5;">'
        . '<p style="margin:0 0 8px;"><strong>Book:</strong> ' . h($title) . '</p>'
        . '<p style="margin:0 0 8px;"><strong>Author:</strong> ' . h($author) . '</p>'
        . '<p style="margin:0 0 8px;"><strong>Approved copies:</strong> ' . h($copyLabel) . '</p>'
        . '<p style="margin:0 0 8px;"><strong>Borrow date:</strong> ' . h($borrowDate) . '</p>'
        . '<p style="margin:0;"><strong>Due date:</strong> ' . h($dueDate) . '</p>'
        . '</div>'
        . '<p><strong>Account type:</strong> ' . h($roleLabel) . '</p>'
        . '<p>Please claim and return the book(s) on or before the due date. Kindly keep them in good condition while they are under your care.</p>'
        . '<p>Thank you.</p>'
        . '<p style="margin-top:22px;">' . h(library_email_signature()) . '</p>'
        . '</div>';

    return [
        'borrow_ids' => $borrowIds,
        'to' => $email,
        'subject' => $subject,
        'text' => $message,
        'html' => $htmlMessage,
    ];
}

function send_borrow_approval_email(mysqli $conn, int $borrowId): bool
{
    $payload = build_borrow_approval_email_payload($conn, $borrowId);
    if (!$payload) {
        return false;
    }

    return send_library_email(
        (string) $payload['to'],
        (string) $payload['subject'],
        (string) $payload['text'],
        (string) $payload['html']
    );
}

function build_incident_payment_approval_email_payload(mysqli $conn, int $paymentId): ?array
{
    if ($paymentId <= 0) {
        set_library_mail_last_error('Invalid incident payment reference.');
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            pay.id AS payment_id,
            pay.amount,
            pay.status,
            pay.created_at,
            pay.incident_id,
            u.fullname,
            u.email,
            u.role,
            bi.incident_type,
            bi.assessed_fee,
            bi.settlement_status,
            bi.workflow_status,
            bi.resolved_at,
            b.title
        FROM payments pay
        JOIN users u ON u.id = pay.user_id
        JOIN book_incidents bi ON bi.id = pay.incident_id
        LEFT JOIN books b ON b.id = bi.book_id
        WHERE pay.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        set_library_mail_last_error('Incident payment email details were not found.');
        return null;
    }

    $email = trim((string) ($row['email'] ?? ''));
    if ($email === '' || !is_valid_email_address($email)) {
        set_library_mail_last_error('Member email address is missing or invalid.');
        return null;
    }

    if ((string) ($row['status'] ?? '') !== 'approved') {
        set_library_mail_last_error('Incident payment has not been approved yet.');
        return null;
    }

    $fullName = trim((string) ($row['fullname'] ?? 'Library Member'));
    $roleLabel = role_label((string) ($row['role'] ?? ''));
    $bookTitle = trim((string) ($row['title'] ?? 'Library Book'));
    $incidentType = book_incident_type_label((string) ($row['incident_type'] ?? ''));
    $incidentId = (int) ($row['incident_id'] ?? 0);
    $paidAmount = format_currency((float) ($row['amount'] ?? 0));
    $assessedFee = format_currency((float) ($row['assessed_fee'] ?? 0));
    $approvedAt = format_display_datetime((string) ($row['resolved_at'] ?? ''), format_display_datetime((string) ($row['created_at'] ?? '')));
    $finalStatus = 'Paid and Closed';

    $subject = 'Incident Payment Confirmed - ' . ($bookTitle !== '' ? $bookTitle : 'Library Incident');
    $message = "Good day, {$fullName}.\n\n"
        . "This is to confirm that your incident payment has been successfully received and approved by the Regis Marie College Library.\n\n"
        . "Incident reference: #{$incidentId}\n"
        . "Book title: {$bookTitle}\n"
        . "Incident type: {$incidentType}\n"
        . "Paid amount: {$paidAmount}\n"
        . "Assessed fee: {$assessedFee}\n"
        . "Approval date: {$approvedAt}\n"
        . "Final status: {$finalStatus}\n"
        . "Account type: {$roleLabel}\n\n"
        . "Your incident record has been marked as settled in the library system. Please keep this email for your reference.\n\n"
        . "Thank you.\n\n"
        . library_email_signature();

    $htmlMessage = '<div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.6;color:#10233a;">'
        . '<p>Good day, <strong>' . h($fullName) . '</strong>.</p>'
        . '<p>This is to confirm that your incident payment has been <strong>successfully received and approved</strong> by the <strong>Regis Marie College Library</strong>.</p>'
        . '<div style="margin:16px 0;padding:16px 18px;border-radius:14px;background:#f7fbff;border:1px solid #d7e6f5;">'
        . '<p style="margin:0 0 8px;"><strong>Incident reference:</strong> #' . (int) $incidentId . '</p>'
        . '<p style="margin:0 0 8px;"><strong>Book title:</strong> ' . h($bookTitle) . '</p>'
        . '<p style="margin:0 0 8px;"><strong>Incident type:</strong> ' . h($incidentType) . '</p>'
        . '<p style="margin:0 0 8px;"><strong>Paid amount:</strong> ' . h($paidAmount) . '</p>'
        . '<p style="margin:0 0 8px;"><strong>Assessed fee:</strong> ' . h($assessedFee) . '</p>'
        . '<p style="margin:0 0 8px;"><strong>Approval date:</strong> ' . h($approvedAt) . '</p>'
        . '<p style="margin:0;"><strong>Final status:</strong> ' . h($finalStatus) . '</p>'
        . '</div>'
        . '<p><strong>Account type:</strong> ' . h($roleLabel) . '</p>'
        . '<p>Your incident record has been marked as <strong>settled</strong> in the library system. Please keep this email for your reference.</p>'
        . '<p>Thank you.</p>'
        . '<p style="margin-top:22px;">' . h(library_email_signature()) . '</p>'
        . '</div>';

    return [
        'to' => $email,
        'subject' => $subject,
        'text' => $message,
        'html' => $htmlMessage,
    ];
}

function send_incident_payment_approval_email(mysqli $conn, int $paymentId): bool
{
    $payload = build_incident_payment_approval_email_payload($conn, $paymentId);
    if (!$payload) {
        return false;
    }

    return send_library_email(
        (string) $payload['to'],
        (string) $payload['subject'],
        (string) $payload['text'],
        (string) $payload['html']
    );
}

function enqueue_email_job(
    mysqli $conn,
    string $jobType,
    string $recipientEmail,
    string $subject,
    string $textBody,
    ?string $htmlBody = null
): bool {
    $recipientEmail = trim($recipientEmail);
    $subject = trim($subject);
    $textBody = trim($textBody);
    $htmlBody = $htmlBody !== null ? trim($htmlBody) : null;

    if ($jobType === '' || $recipientEmail === '' || $subject === '' || $textBody === '') {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO email_jobs (job_type, recipient_email, subject, text_body, html_body, status, available_at)
        VALUES (?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->bind_param('sssss', $jobType, $recipientEmail, $subject, $textBody, $htmlBody);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function enqueue_borrow_approval_email_job(mysqli $conn, int $borrowId): bool
{
    $payload = build_grouped_borrow_approval_email_payload($conn, $borrowId);
    if (!$payload) {
        $lastError = get_library_mail_last_error();
        return str_contains($lastError, 'deferred') || str_contains($lastError, 'already queued');
    }

    $queued = enqueue_email_job(
        $conn,
        'borrow_approval',
        (string) $payload['to'],
        (string) $payload['subject'],
        (string) $payload['text'],
        (string) $payload['html']
    );

    if (!$queued) {
        return false;
    }

    $borrowIds = array_values(array_filter(array_map('intval', (array) ($payload['borrow_ids'] ?? []))));
    if ($borrowIds !== []) {
        $approvedNoticeSentAt = date('Y-m-d H:i:s');
        $idList = implode(',', $borrowIds);
        $conn->query("UPDATE borrows SET approval_notice_sent_at = '" . $conn->real_escape_string($approvedNoticeSentAt) . "' WHERE id IN ({$idList})");
    }

    return true;
}

function create_library_smtp_mailer(): ?\PHPMailer\PHPMailer\PHPMailer
{
    if (library_mailer_mode() !== 'smtp' || !class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return null;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = library_runtime_value('LIBRARY_SMTP_HOST');
    $mail->Port = library_smtp_port();
    $mail->SMTPAuth = true;
    $mail->Username = library_runtime_value('LIBRARY_SMTP_USERNAME');
    $mail->Password = library_runtime_value('LIBRARY_SMTP_PASSWORD');
    $mail->SMTPSecure = library_smtp_secure();
    $mail->SMTPAutoTLS = true;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 10;
    $mail->SMTPKeepAlive = true;
    $fromAddress = trim(library_mail_from_address());
    $smtpUsername = trim(library_runtime_value('LIBRARY_SMTP_USERNAME'));
    if (!is_valid_email_address($fromAddress) && is_valid_email_address($smtpUsername)) {
        $fromAddress = $smtpUsername;
    }
    $mail->setFrom($fromAddress, library_mail_from_name());
    if (is_valid_email_address($smtpUsername)) {
        $mail->Sender = $smtpUsername;
    }

    return $mail;
}

function send_library_email_with_mailer(\PHPMailer\PHPMailer\PHPMailer $mail, string $to, string $subject, string $textBody, ?string $htmlBody = null): bool
{
    set_library_mail_last_error('');

    $to = trim($to);
    $subject = trim($subject);
    $textBody = trim($textBody);

    if ($to === '' || $subject === '' || $textBody === '') {
        set_library_mail_last_error('Missing recipient, subject, or message body.');
        return false;
    }

    if (!is_valid_email_address($to)) {
        set_library_mail_last_error('Invalid recipient email address.');
        return false;
    }

    try {
        $mail->clearAllRecipients();
        $mail->Subject = $subject;
        $mail->Body = $htmlBody !== null && trim($htmlBody) !== '' ? $htmlBody : nl2br(h($textBody));
        $mail->AltBody = $textBody;
        $mail->isHTML(true);
        $mail->addAddress($to);
        return $mail->send();
    } catch (Throwable $e) {
        set_library_mail_last_error($e->getMessage());
        return false;
    }
}

function send_borrow_approval_emails_bulk(mysqli $conn, array $borrowIds): array
{
    $result = [
        'sent' => [],
        'failed' => [],
    ];

    $payloads = [];
    foreach ($borrowIds as $borrowId) {
        $payload = build_borrow_approval_email_payload($conn, (int) $borrowId);
        if ($payload) {
            $payloads[] = $payload;
            continue;
        }

        $result['failed'][(int) $borrowId] = get_library_mail_last_error();
    }

    if ($payloads === []) {
        return $result;
    }

    $mailer = create_library_smtp_mailer();
    if ($mailer) {
        foreach ($payloads as $payload) {
            $sent = send_library_email_with_mailer(
                $mailer,
                (string) $payload['to'],
                (string) $payload['subject'],
                (string) $payload['text'],
                (string) $payload['html']
            );

            if ($sent) {
                $result['sent'][] = (int) $payload['borrow_id'];
            } else {
                $result['failed'][(int) $payload['borrow_id']] = get_library_mail_last_error();
            }
        }

        $mailer->smtpClose();
        return $result;
    }

    foreach ($payloads as $payload) {
        $sent = send_library_email(
            (string) $payload['to'],
            (string) $payload['subject'],
            (string) $payload['text'],
            (string) $payload['html']
        );

        if ($sent) {
            $result['sent'][] = (int) $payload['borrow_id'];
        } else {
            $result['failed'][(int) $payload['borrow_id']] = get_library_mail_last_error();
        }
    }

    return $result;
}

function process_pending_email_jobs(mysqli $conn, int $limit = 5): array
{
    $result = [
        'processed' => 0,
        'sent' => 0,
        'failed' => 0,
    ];

    $limit = max(1, min($limit, 20));
    $jobsStmt = $conn->prepare("
        SELECT id, recipient_email, subject, text_body, html_body
        FROM email_jobs
        WHERE status = 'pending' AND available_at <= NOW()
        ORDER BY
            CASE WHEN job_type = 'login_otp' THEN 0 ELSE 1 END ASC,
            CASE WHEN job_type = 'login_otp' THEN id ELSE NULL END DESC,
            CASE WHEN job_type <> 'login_otp' THEN id ELSE NULL END ASC
        LIMIT ?
    ");
    $jobsStmt->bind_param('i', $limit);
    $jobsStmt->execute();
    $jobs = $jobsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $jobsStmt->close();

    if ($jobs === []) {
        return $result;
    }

    foreach ($jobs as $job) {
        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId <= 0) {
            continue;
        }

        $claimStmt = $conn->prepare("
            UPDATE email_jobs
            SET status = 'sending', attempts = attempts + 1, last_error = NULL
            WHERE id = ? AND status = 'pending'
        ");
        $claimStmt->bind_param('i', $jobId);
        $claimStmt->execute();
        $claimed = $claimStmt->affected_rows === 1;
        $claimStmt->close();

        if (!$claimed) {
            continue;
        }

        $result['processed']++;
        $sent = send_library_email(
            (string) ($job['recipient_email'] ?? ''),
            (string) ($job['subject'] ?? ''),
            (string) ($job['text_body'] ?? ''),
            isset($job['html_body']) ? (string) $job['html_body'] : null
        );

        if ($sent) {
            $result['sent']++;
            $doneStmt = $conn->prepare("
                UPDATE email_jobs
                SET status = 'sent', sent_at = NOW(), last_error = NULL
                WHERE id = ?
            ");
            $doneStmt->bind_param('i', $jobId);
            $doneStmt->execute();
            $doneStmt->close();
            continue;
        }

        $result['failed']++;
        $error = get_library_mail_last_error();
        $failStmt = $conn->prepare("
            UPDATE email_jobs
            SET status = 'failed', last_error = ?
            WHERE id = ?
        ");
        $failStmt->bind_param('si', $error, $jobId);
        $failStmt->execute();
        $failStmt->close();
    }

    return $result;
}

function verify_login_otp(mysqli $conn, int $userId, string $otpCode): bool
{
    $otpCode = trim($otpCode);
    if ($userId <= 0 || $otpCode === '') {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT login_otp_hash, login_otp_expires_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($otpHash, $expiresAt);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found || trim((string) $otpHash) === '' || trim((string) $expiresAt) === '') {
        return false;
    }

    if (strtotime((string) $expiresAt) < time()) {
        return false;
    }

    return hash_equals((string) $otpHash, hash('sha256', $otpCode));
}

function set_library_mail_last_error(string $message): void
{
    $GLOBALS['library_mail_last_error'] = trim($message);
}

function get_library_mail_last_error(): string
{
    return trim((string) ($GLOBALS['library_mail_last_error'] ?? ''));
}

function can_send_library_email(): bool
{
    return library_mailer_mode() !== 'disabled';
}

function library_mailer_mode(): string
{
    $smtpHost = library_runtime_value('LIBRARY_SMTP_HOST');
    $smtpUser = library_runtime_value('LIBRARY_SMTP_USERNAME');
    $smtpPass = library_runtime_value('LIBRARY_SMTP_PASSWORD');

    if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '' && class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return 'smtp';
    }

    return function_exists('mail') ? 'mail' : 'disabled';
}

function library_smtp_port(): int
{
    $value = (int) library_runtime_value('LIBRARY_SMTP_PORT');
    return $value > 0 ? $value : 587;
}

function library_smtp_secure(): string
{
    $value = strtolower(library_runtime_value('LIBRARY_SMTP_SECURE'));
    return in_array($value, ['tls', 'ssl'], true) ? $value : 'tls';
}

function library_mail_health_snapshot(bool $sendProbe = false): array
{
    $mailerMode = library_mailer_mode();
    $smtpHost = trim(library_runtime_value('LIBRARY_SMTP_HOST'));
    $smtpUser = trim(library_runtime_value('LIBRARY_SMTP_USERNAME'));
    $smtpPass = trim(library_runtime_value('LIBRARY_SMTP_PASSWORD'));
    $fromAddress = library_mail_from_address();
    $fromName = library_mail_from_name();
    $issues = [];
    $probe = null;

    if ($mailerMode === 'disabled') {
        $issues[] = 'Mail sending is disabled because no working transport is configured.';
    }

    if ($mailerMode === 'smtp' && !class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        $issues[] = 'SMTP is configured but PHPMailer is not available.';
    }

    if ($fromAddress === '' || !is_valid_email_address($fromAddress)) {
        $issues[] = 'The From address is missing or invalid.';
    }

    if ($smtpHost === '' && $mailerMode === 'smtp') {
        $issues[] = 'SMTP host is missing.';
    }

    if ($sendProbe) {
        $probeRecipient = is_valid_email_address($fromAddress) ? $fromAddress : $smtpUser;
        if ($probeRecipient !== '' && is_valid_email_address($probeRecipient)) {
            $timestamp = date('Y-m-d H:i:s');
            $subject = 'Library Mail Health Check';
            $textBody = "This is a mail health check from the Library Management System.\n\n"
                . 'Mode: ' . strtoupper($mailerMode) . "\n"
                . 'Timestamp: ' . $timestamp . "\n";
            $htmlBody = '<div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.6;color:#10233a;">'
                . '<p>This is a mail health check from the <strong>Library Management System</strong>.</p>'
                . '<p><strong>Mode:</strong> ' . h(strtoupper($mailerMode)) . '<br>'
                . '<strong>Timestamp:</strong> ' . h($timestamp) . '</p>'
                . '</div>';

            $probeSent = send_library_email($probeRecipient, $subject, $textBody, $htmlBody);
            $probe = [
                'recipient' => $probeRecipient,
                'success' => $probeSent,
                'error' => $probeSent ? '' : get_library_mail_last_error(),
                'checked_at' => $timestamp,
            ];

            if (!$probeSent && $probe['error'] !== '') {
                $issues[] = $probe['error'];
            }
        } else {
            $probe = [
                'recipient' => '',
                'success' => false,
                'error' => 'No valid recipient is available for the mail health check.',
                'checked_at' => date('Y-m-d H:i:s'),
            ];
            $issues[] = $probe['error'];
        }
    }

    return [
        'mode' => $mailerMode,
        'smtp_host' => $smtpHost,
        'smtp_port' => library_smtp_port(),
        'smtp_secure' => library_smtp_secure(),
        'smtp_username_masked' => mask_email_address($smtpUser),
        'smtp_configured' => $smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '',
        'phpmailer_available' => class_exists(\PHPMailer\PHPMailer\PHPMailer::class),
        'from_address' => $fromAddress,
        'from_name' => $fromName,
        'signature' => library_email_signature(),
        'issues' => array_values(array_unique(array_filter($issues))),
        'probe' => $probe,
        'captured_at' => date('Y-m-d H:i:s'),
    ];
}

function send_library_email(string $to, string $subject, string $textBody, ?string $htmlBody = null): bool
{
    set_library_mail_last_error('');

    $to = trim($to);
    $subject = trim($subject);
    $textBody = trim($textBody);

    if ($to === '' || $subject === '' || $textBody === '') {
        set_library_mail_last_error('Missing recipient, subject, or message body.');
        return false;
    }

    if (!is_valid_email_address($to) || !can_send_library_email()) {
        set_library_mail_last_error('Invalid recipient email or mail transport is not configured.');
        return false;
    }

    $fromAddress = library_mail_from_address();
    $fromName = library_mail_from_name();
    $mailerMode = library_mailer_mode();

    if ($mailerMode === 'smtp' && class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        try {
            $mail = create_library_smtp_mailer();
            if (!$mail) {
                set_library_mail_last_error('SMTP mailer could not be initialized.');
                return false;
            }
            $mail->SMTPKeepAlive = false;
            return send_library_email_with_mailer($mail, $to, $subject, $textBody, $htmlBody);
        } catch (Throwable $e) {
            set_library_mail_last_error($e->getMessage());
            return false;
        }
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromAddress . '>',
        'Reply-To: ' . $fromAddress,
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    $sent = @mail($to, $encodedSubject, $textBody, implode("\r\n", $headers));
    if (!$sent) {
        set_library_mail_last_error('PHP mail() failed to hand off the message.');
    }
    return $sent;
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

    return ['inserted' => $inserted, 'updated' => $updated];
}

function overdue_penalty_sync_runtime_file(): string
{
    return dirname(__DIR__) . '/storage/runtime/overdue_penalty_sync.json';
}

function ensure_overdue_penalty_runtime_directory(): void
{
    $directory = dirname(overdue_penalty_sync_runtime_file());
    if (!is_dir($directory)) {
        @mkdir($directory, 0777, true);
    }
}

function sync_overdue_penalties_if_needed(mysqli $conn, int $seconds = 3600): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $seconds = max(300, $seconds);
    ensure_overdue_penalty_runtime_directory();
    $runtimeFile = overdue_penalty_sync_runtime_file();
    $handle = @fopen($runtimeFile, 'c+');
    if (!$handle) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return;
        }

        $existing = stream_get_contents($handle);
        $state = json_decode($existing ?: '{}', true);
        if (!is_array($state)) {
            $state = [];
        }

        $now = time();
        $lastRun = (int) ($state['last_run_at'] ?? 0);
        if ($lastRun > 0 && ($now - $lastRun) < $seconds) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return;
        }

        $state['last_run_at'] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES));
        fflush($handle);

        try {
            $result = sync_overdue_penalties($conn);
            $state['last_success_at'] = $now;
            $state['last_result'] = [
                'inserted' => (int) ($result['inserted'] ?? 0),
                'updated' => (int) ($result['updated'] ?? 0),
            ];
            unset($state['last_error']);
        } catch (Throwable $e) {
            $state['last_error'] = $e->getMessage();
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES));
        fflush($handle);

        flock($handle, LOCK_UN);
        fclose($handle);
    } catch (Throwable $e) {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function update_email_reminder_debug_snapshot(string $bucket, array $result): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $bucket = trim($bucket);
    if ($bucket === '') {
        return;
    }

    $snapshot = is_array($_SESSION['email_reminder_debug'] ?? null) ? $_SESSION['email_reminder_debug'] : [];
    $snapshot[$bucket] = [
        'checked' => (int) ($result['checked'] ?? 0),
        'sent' => (int) ($result['sent'] ?? 0),
        'failed' => (int) ($result['failed'] ?? 0),
        'skipped' => (int) ($result['skipped'] ?? 0),
        'errors' => array_slice(is_array($result['errors'] ?? null) ? $result['errors'] : [], 0, 3),
        'captured_at' => date('Y-m-d H:i:s'),
    ];
    $_SESSION['email_reminder_debug'] = $snapshot;
}

function due_reminder_sync_runtime_file(): string
{
    return dirname(__DIR__) . '/storage/runtime/due_reminder_sync.json';
}

function ensure_due_reminder_runtime_directory(): void
{
    $directory = dirname(due_reminder_sync_runtime_file());
    if (!is_dir($directory)) {
        @mkdir($directory, 0777, true);
    }
}

function run_due_reminder_sync_if_needed(mysqli $conn, int $seconds = 3600): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $seconds = max(300, $seconds);
    ensure_due_reminder_runtime_directory();
    $runtimeFile = due_reminder_sync_runtime_file();
    $handle = @fopen($runtimeFile, 'c+');
    if (!$handle) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return;
        }

        $existing = stream_get_contents($handle);
        $state = json_decode($existing ?: '{}', true);
        if (!is_array($state)) {
            $state = [];
        }

        $now = time();
        $lastRun = (int) ($state['last_run_at'] ?? 0);
        if ($lastRun > 0 && ($now - $lastRun) < $seconds) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return;
        }

        $state['last_run_at'] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES));
        fflush($handle);

        try {
            $dueSoon = send_due_soon_reminders($conn);
            $overdue = send_overdue_notices($conn);

            $state['last_success_at'] = $now;
            $state['last_result'] = [
                'due_soon' => [
                    'checked' => (int) ($dueSoon['checked'] ?? 0),
                    'sent' => (int) ($dueSoon['sent'] ?? 0),
                    'failed' => (int) ($dueSoon['failed'] ?? 0),
                    'skipped' => (int) ($dueSoon['skipped'] ?? 0),
                ],
                'overdue' => [
                    'checked' => (int) ($overdue['checked'] ?? 0),
                    'sent' => (int) ($overdue['sent'] ?? 0),
                    'failed' => (int) ($overdue['failed'] ?? 0),
                    'skipped' => (int) ($overdue['skipped'] ?? 0),
                ],
            ];
            unset($state['last_error']);
        } catch (Throwable $e) {
            $state['last_error'] = $e->getMessage();
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES));
        fflush($handle);

        flock($handle, LOCK_UN);
        fclose($handle);
    } catch (Throwable $e) {
        @flock($handle, LOCK_UN);
        fclose($handle);
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

function create_notification(mysqli $conn, string $role, string $title, string $body, string $severity = 'info', ?int $userId = null): void
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
        INSERT INTO notifications (role, user_id, title, body, severity, is_read)
        VALUES (?, ?, ?, ?, ?, 0)
    ");
    $stmt->bind_param('sisss', $role, $userId, $title, $body, $severity);
    $stmt->execute();
    $stmt->close();
}

function admin_notification_inbox_excluded_titles(): array
{
    return [
        'Overdue Penalties Updated',
        'Due Soon Email Reminders Sent',
        'Overdue Email Notices Sent',
    ];
}

function admin_notification_inbox_where_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? trim($alias) . '.' : '';
    $excluded = array_map(
        static fn(string $title): string => "'" . str_replace("'", "''", $title) . "'",
        admin_notification_inbox_excluded_titles()
    );

    return $prefix . "role = 'admin' AND " . $prefix . 'title NOT IN (' . implode(', ', $excluded) . ')';
}

function notification_incident_id(array $notification): int
{
    $title = trim((string) ($notification['title'] ?? ''));
    $body = trim((string) ($notification['body'] ?? ''));
    $combinedText = $title . ' ' . $body;

    if ($combinedText !== '' && preg_match('/\bincident\s*#\s*(\d+)\b/i', $combinedText, $matches) === 1) {
        return (int) ($matches[1] ?? 0);
    }

    return 0;
}

function notification_copy_id(array $notification): string
{
    $title = trim((string) ($notification['title'] ?? ''));
    $body = trim((string) ($notification['body'] ?? ''));
    $combinedText = $title . ' ' . $body;

    if ($combinedText !== '' && preg_match('/\(([A-Za-z0-9-]+)\)/', $combinedText, $matches) === 1) {
        return trim((string) ($matches[1] ?? ''));
    }

    return '';
}

function notification_lookup_incident_id(array $notification): int
{
    $incidentId = notification_incident_id($notification);
    if ($incidentId > 0) {
        return $incidentId;
    }

    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return 0;
    }

    $copyId = notification_copy_id($notification);
    if ($copyId === '') {
        return 0;
    }

    $createdAt = trim((string) ($notification['created_at'] ?? ''));
    if ($createdAt !== '') {
        $stmt = $conn->prepare("
            SELECT bi.id
            FROM book_incidents bi
            LEFT JOIN borrows br ON br.id = bi.borrow_id
            LEFT JOIN book_copies bc ON bc.id = COALESCE(bi.book_copy_id, br.book_copy_id)
            WHERE bc.copy_id = ?
              AND bi.reported_at <= ?
            ORDER BY bi.reported_at DESC, bi.id DESC
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('ss', $copyId, $createdAt);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $incidentId = (int) ($row['id'] ?? 0);
            if ($incidentId > 0) {
                return $incidentId;
            }
        }
    }

    $stmt = $conn->prepare("
        SELECT bi.id
        FROM book_incidents bi
        LEFT JOIN borrows br ON br.id = bi.borrow_id
        LEFT JOIN book_copies bc ON bc.id = COALESCE(bi.book_copy_id, br.book_copy_id)
        WHERE bc.copy_id = ?
        ORDER BY bi.reported_at DESC, bi.id DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('s', $copyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['id'] ?? 0);
}

function notification_destination_for_viewer(string $viewerRole, array $notification): array
{
    $viewerRole = trim(strtolower($viewerRole));
    $title = trim((string) ($notification['title'] ?? ''));
    $body = trim((string) ($notification['body'] ?? ''));
    $kind = trim((string) ($notification['kind'] ?? 'notification'));
    $titleLower = strtolower($title);
    $bodyLower = strtolower($body);
    $url = '';
    $label = '';

    if ($viewerRole === 'student') {
        if (strpos($titleLower, 'incident') !== false || strpos($bodyLower, 'incident') !== false) {
            $url = '/librarymanage/student/book_incidents.php';
            $label = 'Open book incidents';
        } elseif ($kind === 'due_soon' || $kind === 'overdue' || strpos($titleLower, 'return request approved') !== false) {
            $url = '/librarymanage/student/borrow_return.php';
            $label = 'Open borrow status';
        } elseif (strpos($titleLower, 'payment') !== false) {
            $url = '/librarymanage/student/payment_upload.php';
            $label = 'Open payments';
        } else {
            $url = '/librarymanage/student/notifications.php';
            $label = 'Open notifications';
        }
    } elseif ($viewerRole === 'faculty') {
        if (strpos($titleLower, 'incident') !== false || strpos($bodyLower, 'incident') !== false) {
            $url = '/librarymanage/faculty/book_incidents.php';
            $label = 'Open book incidents';
        } elseif ($kind === 'due_soon' || $kind === 'overdue' || strpos($titleLower, 'return request approved') !== false) {
            $url = '/librarymanage/faculty/borrow_return.php';
            $label = 'Open borrow status';
        } elseif (strpos($titleLower, 'payment') !== false) {
            $url = '/librarymanage/faculty/payment_upload.php';
            $label = 'Open payments';
        } else {
            $url = '/librarymanage/faculty/notifications.php';
            $label = 'Open notifications';
        }
    } elseif ($viewerRole === 'admin') {
        if (strpos($titleLower, 'incident') !== false || strpos($bodyLower, 'incident') !== false) {
            $incidentId = notification_lookup_incident_id($notification);
            $url = '/librarymanage/admin/book_incidents_records.php';
            if ($incidentId > 0) {
                $url .= '?incident=' . $incidentId;
            }
            $label = 'Open book incidents';
        } elseif (strpos($titleLower, 'payment') !== false) {
            $url = '/librarymanage/admin/payments_records.php';
            $label = 'Open payments';
        } elseif (strpos($titleLower, 'complaint') !== false) {
            $url = '/librarymanage/admin/complaints_records.php';
            $label = 'Open complaints';
        } elseif (strpos($titleLower, 'announcement') !== false) {
            $url = '/librarymanage/admin/announcements.php';
            $label = 'Open announcements';
        } elseif (strpos($titleLower, 'account') !== false || strpos($bodyLower, 'account') !== false) {
            $url = '/librarymanage/admin/manage_accounts.php';
            $label = 'Open accounts';
        } else {
            $url = '/librarymanage/admin/notifications.php';
            $label = 'Open notifications';
        }
    } elseif ($viewerRole === 'librarian') {
        if (strpos($titleLower, 'incident') !== false || strpos($bodyLower, 'incident') !== false) {
            $incidentId = notification_lookup_incident_id($notification);
            $url = '/librarymanage/librarian/manage_book_incidents.php';
            if ($incidentId > 0) {
                $url .= '?incident=' . $incidentId;
            }
            $label = 'Open book incidents';
        } elseif (
            strpos($titleLower, 'borrow') !== false
            || strpos($titleLower, 'return') !== false
            || strpos($bodyLower, 'borrow') !== false
            || strpos($bodyLower, 'return') !== false
        ) {
            $url = '/librarymanage/librarian/manage_borrows.php';
            $label = 'Open borrow workflow';
        } else {
            $url = '/librarymanage/librarian/notifications.php';
            $label = 'Open notifications';
        }
    }

    return [
        'url' => $url,
        'label' => $label,
    ];
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
    $url = $scheme . '://' . $host . app_url('api/v1/' . ltrim($endpoint, '/'));
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

function send_due_soon_reminders(mysqli $conn): array
{
    $result = [
        'checked' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    $rows = $conn->query("
        SELECT
            br.id,
            br.user_id,
            br.book_id,
            br.due_date,
            br.due_reminder_sent_at,
            u.fullname,
            u.email,
            u.role,
            b.title,
            b.author
        FROM borrows br
        JOIN users u ON u.id = br.user_id
        JOIN books b ON b.id = br.book_id
        WHERE br.status = 'borrowed'
          AND u.role IN ('student', 'faculty')
          AND br.due_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    ");

    if (!$rows instanceof mysqli_result) {
        return $result;
    }

    $updateStmt = $conn->prepare("
        UPDATE borrows
        SET due_reminder_sent_at = ?
        WHERE id = ?
    ");

    $groupedRows = [];
    while ($row = $rows->fetch_assoc()) {
        $result['checked']++;

        $borrowId = (int) ($row['id'] ?? 0);
        $email = trim((string) ($row['email'] ?? ''));
        $dueDate = (string) ($row['due_date'] ?? '');
        $sentAt = trim((string) ($row['due_reminder_sent_at'] ?? ''));

        if ($borrowId <= 0 || $email === '' || !is_valid_email_address($email)) {
            $result['skipped']++;
            continue;
        }

        if ($sentAt !== '') {
            $result['skipped']++;
            continue;
        }

        $groupKey = strtolower($email);
        if (!isset($groupedRows[$groupKey])) {
            $groupedRows[$groupKey] = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'fullname' => trim((string) ($row['fullname'] ?? 'Member')),
                'email' => $email,
                'role' => (string) ($row['role'] ?? 'member'),
                'items' => [],
            ];
        }

        $groupedRows[$groupKey]['items'][] = [
            'borrow_id' => $borrowId,
            'title' => trim((string) ($row['title'] ?? 'your borrowed book')),
            'author' => trim((string) ($row['author'] ?? '')),
            'due_date' => $dueDate,
        ];
    }

    foreach ($groupedRows as $group) {
        $fullName = $group['fullname'];
        $email = $group['email'];
        $roleLabel = role_label((string) ($group['role'] ?? 'member'));
        $items = is_array($group['items'] ?? null) ? $group['items'] : [];
        if (empty($items)) {
            continue;
        }

        $subject = count($items) === 1
            ? 'Reminder: "' . $items[0]['title'] . '" is due tomorrow'
            : 'Reminder: ' . count($items) . ' borrowed book(s) are due tomorrow';

        $groupedItems = [];
        foreach ($items as $item) {
            $summaryKey = strtolower(trim((string) ($item['title'] ?? ''))) . '|' . strtolower(trim((string) ($item['author'] ?? ''))) . '|' . trim((string) ($item['due_date'] ?? ''));
            if (!isset($groupedItems[$summaryKey])) {
                $groupedItems[$summaryKey] = [
                    'title' => trim((string) ($item['title'] ?? 'your borrowed book')),
                    'author' => trim((string) ($item['author'] ?? '')),
                    'due_date' => trim((string) ($item['due_date'] ?? '')),
                    'copies' => 0,
                ];
            }
            $groupedItems[$summaryKey]['copies']++;
        }

        $message = "Good day, {$fullName}.\n\n"
            . "This is a reminder from the Regis Marie College Library that the following borrowed book(s) are due tomorrow:\n\n";
        $htmlList = '';
        foreach ($groupedItems as $groupedItem) {
            $formattedDueDate = format_display_date((string) $groupedItem['due_date'], (string) $groupedItem['due_date']);
            $copyCount = (int) ($groupedItem['copies'] ?? 0);
            $copyLabel = $copyCount === 1 ? '1 copy' : $copyCount . ' copies';
            $authorLabel = trim((string) ($groupedItem['author'] ?? ''));
            $message .= '- "' . $groupedItem['title'] . '" by ' . ($authorLabel !== '' ? $authorLabel : 'Unknown author') . ' (' . $copyLabel . ') - due on ' . $formattedDueDate . "\n";
            $htmlList .= '<li><strong>' . h((string) $groupedItem['title']) . '</strong>'
                . ' by ' . h($authorLabel !== '' ? $authorLabel : 'Unknown author')
                . ' (' . h($copyLabel) . ')'
                . ' - due on ' . h($formattedDueDate)
                . '</li>';
        }
        $message .= "\nPlease return the book(s) on or before the due date to avoid overdue penalties.\n\n"
            . 'Account type: ' . $roleLabel . "\n\n"
            . "If you have already returned any of these items, kindly disregard this message.\n\n"
            . "Thank you.\n\n"
            . library_email_signature();

        $htmlMessage = '<div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.6;color:#10233a;">'
            . '<p>Good day, <strong>' . h($fullName) . '</strong>.</p>'
            . '<p>This is a reminder from the <strong>Regis Marie College Library</strong> that the following borrowed book(s) are due <strong>tomorrow</strong>:</p>'
            . '<div style="margin:18px 0;padding:14px 16px;border:1px solid #d7e6f5;border-radius:14px;background:#f7fbff;">'
            . '<ul style="margin:0;padding-left:18px;">' . $htmlList . '</ul>'
            . '</div>'
            . '<p><strong>Account type:</strong> ' . h($roleLabel) . '</p>'
            . '<p>Please return the book(s) on or before the due date to avoid overdue penalties.</p>'
            . '<p style="color:#5c7188;">If you have already returned any of these items, kindly disregard this message.</p>'
            . '<p>Thank you.</p>'
            . '<p style="margin-top:22px;">' . h(library_email_signature()) . '</p>'
            . '</div>';

        $sent = send_library_email($email, $subject, $message, $htmlMessage);

        if ($sent) {
            $timestamp = date('Y-m-d H:i:s');
            foreach ($items as $item) {
                $borrowId = (int) ($item['borrow_id'] ?? 0);
                $updateStmt->bind_param('si', $timestamp, $borrowId);
                $updateStmt->execute();
                $result['sent']++;
            }
            continue;
        }

        $result['failed'] += count($items);
        foreach ($items as $item) {
            $result['errors'][] = [
                'borrow_id' => (int) ($item['borrow_id'] ?? 0),
                'email' => $email,
                'message' => get_library_mail_last_error(),
            ];
        }
    }

    $updateStmt->close();

    update_email_reminder_debug_snapshot('due_soon', $result);

    return $result;
}

function get_member_catalog_highlights(mysqli $conn, int $limit = 4): array
{
    $limit = max(1, min(8, $limit));
    $sql = "
        SELECT
            category,
            COUNT(*) AS title_count,
            COALESCE(SUM(qty_available), 0) AS available_copies,
            COALESCE(SUM(CASE WHEN qty_available > 0 THEN 1 ELSE 0 END), 0) AS ready_titles
        FROM books
        WHERE category IS NOT NULL AND TRIM(category) <> ''
        GROUP BY category
        ORDER BY ready_titles DESC, available_copies DESC, title_count DESC, category ASC
        LIMIT {$limit}
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'category' => (string) ($row['category'] ?? ''),
            'title_count' => (int) ($row['title_count'] ?? 0),
            'available_copies' => (int) ($row['available_copies'] ?? 0),
            'ready_titles' => (int) ($row['ready_titles'] ?? 0),
        ];
    }

    return $items;
}

function send_overdue_notices(mysqli $conn): array
{
    $result = [
        'checked' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => [],
    ];

    $rows = $conn->query("
        SELECT
            br.id,
            br.user_id,
            br.book_id,
            br.due_date,
            br.overdue_notice_sent_at,
            u.fullname,
            u.email,
            u.role,
            b.title,
            b.author
        FROM borrows br
        JOIN users u ON u.id = br.user_id
        JOIN books b ON b.id = br.book_id
        WHERE br.status = 'borrowed'
          AND u.role IN ('student', 'faculty')
          AND br.due_date < CURDATE()
    ");

    if (!$rows instanceof mysqli_result) {
        return $result;
    }

    $updateStmt = $conn->prepare("
        UPDATE borrows
        SET overdue_notice_sent_at = ?
        WHERE id = ?
    ");
    $penaltyStmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_unpaid
        FROM penalties
        WHERE user_id = ?
          AND status = 'unpaid'
    ");

    $groupedRows = [];
    while ($row = $rows->fetch_assoc()) {
        $result['checked']++;

        $borrowId = (int) ($row['id'] ?? 0);
        $email = trim((string) ($row['email'] ?? ''));
        $sentAt = trim((string) ($row['overdue_notice_sent_at'] ?? ''));

        if ($borrowId <= 0 || $email === '' || !is_valid_email_address($email)) {
            $result['skipped']++;
            continue;
        }

        if ($sentAt !== '') {
            $result['skipped']++;
            continue;
        }

        $groupKey = strtolower($email);
        if (!isset($groupedRows[$groupKey])) {
            $groupedRows[$groupKey] = [
                'fullname' => trim((string) ($row['fullname'] ?? 'Member')),
                'email' => $email,
                'role' => (string) ($row['role'] ?? 'member'),
                'items' => [],
            ];
        }

        $groupedRows[$groupKey]['items'][] = [
            'borrow_id' => $borrowId,
            'title' => trim((string) ($row['title'] ?? 'your borrowed book')),
            'author' => trim((string) ($row['author'] ?? '')),
            'due_date' => (string) ($row['due_date'] ?? ''),
        ];
    }

    foreach ($groupedRows as $group) {
        $userId = (int) ($group['user_id'] ?? 0);
        $fullName = $group['fullname'];
        $email = $group['email'];
        $roleLabel = role_label((string) ($group['role'] ?? 'member'));
        $items = is_array($group['items'] ?? null) ? $group['items'] : [];
        if (empty($items)) {
            continue;
        }

        $totalPenalty = 0.0;
        if ($penaltyStmt && $userId > 0) {
            $penaltyStmt->bind_param('i', $userId);
            $penaltyStmt->execute();
            $penaltyRow = $penaltyStmt->get_result()->fetch_assoc();
            $totalPenalty = (float) ($penaltyRow['total_unpaid'] ?? 0);
        }
        $totalPenaltyLabel = format_currency($totalPenalty);

        $subject = count($items) === 1
            ? 'Overdue Notice: "' . $items[0]['title'] . '"'
            : 'Overdue Notice: ' . count($items) . ' borrowed book(s)';

        $groupedItems = [];
        foreach ($items as $item) {
            $summaryKey = strtolower(trim((string) ($item['title'] ?? ''))) . '|' . strtolower(trim((string) ($item['author'] ?? ''))) . '|' . trim((string) ($item['due_date'] ?? ''));
            if (!isset($groupedItems[$summaryKey])) {
                $groupedItems[$summaryKey] = [
                    'title' => trim((string) ($item['title'] ?? 'your borrowed book')),
                    'author' => trim((string) ($item['author'] ?? '')),
                    'due_date' => trim((string) ($item['due_date'] ?? '')),
                    'copies' => 0,
                ];
            }
            $groupedItems[$summaryKey]['copies']++;
        }

        $message = "Good day, {$fullName}.\n\n"
            . "This is an overdue notice from the Regis Marie College Library for the following borrowed book(s):\n\n";
        $htmlList = '';
        foreach ($groupedItems as $groupedItem) {
            $formattedDueDate = format_display_date((string) $groupedItem['due_date'], (string) $groupedItem['due_date']);
            $daysOverdue = max(1, (int) floor((strtotime(date('Y-m-d')) - strtotime((string) $groupedItem['due_date'])) / 86400));
            $copyCount = (int) ($groupedItem['copies'] ?? 0);
            $copyLabel = $copyCount === 1 ? '1 copy' : $copyCount . ' copies';
            $authorLabel = trim((string) ($groupedItem['author'] ?? ''));
            $message .= '- "' . $groupedItem['title'] . '" by ' . ($authorLabel !== '' ? $authorLabel : 'Unknown author') . ' (' . $copyLabel . ') - due on ' . $formattedDueDate . ' and now overdue by ' . $daysOverdue . ' day' . ($daysOverdue === 1 ? '' : 's') . "\n";
            $htmlList .= '<li><strong>' . h((string) $groupedItem['title']) . '</strong>'
                . ' by ' . h($authorLabel !== '' ? $authorLabel : 'Unknown author')
                . ' (' . h($copyLabel) . ')'
                . ' - due on ' . h($formattedDueDate)
                . ' and now overdue by ' . (int) $daysOverdue . ' day' . ($daysOverdue === 1 ? '' : 's')
                . '</li>';
        }
        $message .= "\nPlease return the book(s) as soon as possible to avoid additional penalties.\n\n"
            . 'Total unpaid penalties: ' . $totalPenaltyLabel . "\n"
            . 'Account type: ' . $roleLabel . "\n\n"
            . "If you have already returned any of these items, kindly disregard this message.\n\n"
            . "Thank you.\n\n"
            . library_email_signature();

        $htmlMessage = '<div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.6;color:#10233a;">'
            . '<p>Good day, <strong>' . h($fullName) . '</strong>.</p>'
            . '<p>This is an <strong style="color:#b42318;">overdue notice</strong> from the <strong>Regis Marie College Library</strong> for the following borrowed book(s):</p>'
            . '<div style="margin:18px 0;padding:14px 16px;border:1px solid #f3c6c2;border-radius:14px;background:#fff7f6;">'
            . '<ul style="margin:0;padding-left:18px;">' . $htmlList . '</ul>'
            . '</div>'
            . '<p><strong>Total unpaid penalties:</strong> ' . h($totalPenaltyLabel) . '</p>'
            . '<p><strong>Account type:</strong> ' . h($roleLabel) . '</p>'
            . '<p>Please return the book(s) as soon as possible to avoid additional penalties.</p>'
            . '<p style="color:#5c7188;">If you have already returned any of these items, kindly disregard this message.</p>'
            . '<p>Thank you.</p>'
            . '<p style="margin-top:22px;">' . h(library_email_signature()) . '</p>'
            . '</div>';

        $sent = send_library_email($email, $subject, $message, $htmlMessage);

        if ($sent) {
            $timestamp = date('Y-m-d H:i:s');
            foreach ($items as $item) {
                $borrowId = (int) ($item['borrow_id'] ?? 0);
                $updateStmt->bind_param('si', $timestamp, $borrowId);
                $updateStmt->execute();
                $result['sent']++;
            }
            continue;
        }

        $result['failed'] += count($items);
        foreach ($items as $item) {
            $result['errors'][] = [
                'borrow_id' => (int) ($item['borrow_id'] ?? 0),
                'email' => $email,
                'message' => get_library_mail_last_error(),
            ];
        }
    }

    $updateStmt->close();
    if ($penaltyStmt) {
        $penaltyStmt->close();
    }

    update_email_reminder_debug_snapshot('overdue', $result);

    return $result;
}

if (isset($conn) && $conn instanceof mysqli) {
    library_enforce_csrf_for_post();
    sync_overdue_penalties_if_needed($conn);
    run_due_reminder_sync_if_needed($conn);
}

?>

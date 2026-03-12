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

function redirect_to_dashboard(?string $role = null): void
{
    $role = canonical_role($role ?? ($_SESSION['role'] ?? ''));

    $map = [
        'admin' => '/librarymanage/admin/dashboard.php',
        'student' => '/librarymanage/student/dashboard.php',
        'faculty' => '/librarymanage/faculty/dashboard.php',
        'librarian' => '/librarymanage/librarian/dashboard.php',
    ];

    header('Location: ' . ($map[$role] ?? '/librarymanage/loginpage.php'));
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

    return send_library_email($email, $subject, $message, $htmlMessage);
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
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = library_runtime_value('LIBRARY_SMTP_HOST');
            $mail->Port = library_smtp_port();
            $mail->SMTPAuth = true;
            $mail->Username = library_runtime_value('LIBRARY_SMTP_USERNAME');
            $mail->Password = library_runtime_value('LIBRARY_SMTP_PASSWORD');
            $mail->SMTPSecure = library_smtp_secure();
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($fromAddress, $fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody !== null && trim($htmlBody) !== '' ? $htmlBody : nl2br(h($textBody));
            $mail->AltBody = $textBody;
            $mail->isHTML(true);
            return $mail->send();
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
            b.title
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

        if ($sentAt !== '' && strpos($sentAt, $dueDate) === 0) {
            $result['skipped']++;
            continue;
        }

        $fullName = trim((string) ($row['fullname'] ?? 'Member'));
        $bookTitle = trim((string) ($row['title'] ?? 'your borrowed book'));
        $roleLabel = role_label((string) ($row['role'] ?? 'member'));
        $formattedDueDate = format_display_date($dueDate, $dueDate);
        $subject = 'Reminder: "' . $bookTitle . '" is due tomorrow';
        $message = "Hello {$fullName},\n\n"
            . "This is a friendly reminder that your borrowed book \"{$bookTitle}\" is due tomorrow ({$formattedDueDate}).\n\n"
            . "Please return it on or before the due date to avoid overdue penalties.\n\n"
            . "Borrow details:\n"
            . "- Book: {$bookTitle}\n"
            . "- Due date: {$formattedDueDate}\n"
            . "- Role: {$roleLabel}\n"
            . "- Borrow ID: #{$borrowId}\n\n"
            . "If you have already returned this book, you may ignore this email.\n\n"
            . library_email_signature();

        $htmlMessage = '<div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.6;color:#10233a;">'
            . '<p>Hello <strong>' . h($fullName) . '</strong>,</p>'
            . '<p>This is a friendly reminder that your borrowed book <strong>"' . h($bookTitle) . '"</strong> is due <strong>tomorrow</strong> (' . h($formattedDueDate) . ').</p>'
            . '<div style="margin:18px 0;padding:14px 16px;border:1px solid #d7e6f5;border-radius:14px;background:#f7fbff;">'
            . '<div><strong>Book:</strong> ' . h($bookTitle) . '</div>'
            . '<div><strong>Due date:</strong> ' . h($formattedDueDate) . '</div>'
            . '<div><strong>Role:</strong> ' . h($roleLabel) . '</div>'
            . '<div><strong>Borrow ID:</strong> #' . (int) $borrowId . '</div>'
            . '</div>'
            . '<p>Please return it on or before the due date to avoid overdue penalties.</p>'
            . '<p style="color:#5c7188;">If you have already returned this book, you may ignore this email.</p>'
            . '<p style="margin-top:22px;">' . h(library_email_signature()) . '</p>'
            . '</div>';

        $sent = send_library_email($email, $subject, $message, $htmlMessage);

        if ($sent) {
            $timestamp = date('Y-m-d H:i:s');
            $updateStmt->bind_param('si', $timestamp, $borrowId);
            $updateStmt->execute();
            $result['sent']++;
            continue;
        }

        $result['failed']++;
        $result['errors'][] = [
            'borrow_id' => $borrowId,
            'email' => $email,
            'message' => get_library_mail_last_error(),
        ];
    }

    $updateStmt->close();

    if ($result['sent'] > 0) {
        create_notification(
            $conn,
            'admin',
            'Due Soon Email Reminders Sent',
            $result['sent'] . ' due-soon reminder email(s) were sent for books due tomorrow.',
            'info'
        );
    }

    return $result;
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
            b.title
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

    while ($row = $rows->fetch_assoc()) {
        $result['checked']++;

        $borrowId = (int) ($row['id'] ?? 0);
        $email = trim((string) ($row['email'] ?? ''));
        $dueDate = (string) ($row['due_date'] ?? '');
        $sentAt = trim((string) ($row['overdue_notice_sent_at'] ?? ''));

        if ($borrowId <= 0 || $email === '' || !is_valid_email_address($email)) {
            $result['skipped']++;
            continue;
        }

        if ($sentAt !== '') {
            $result['skipped']++;
            continue;
        }

        $fullName = trim((string) ($row['fullname'] ?? 'Member'));
        $bookTitle = trim((string) ($row['title'] ?? 'your borrowed book'));
        $roleLabel = role_label((string) ($row['role'] ?? 'member'));
        $formattedDueDate = format_display_date($dueDate, $dueDate);
        $daysOverdue = max(1, (int) floor((strtotime(date('Y-m-d')) - strtotime($dueDate)) / 86400));
        $subject = 'Overdue Notice: "' . $bookTitle . '"';
        $message = "Hello {$fullName},\n\n"
            . "This is an overdue notice for your borrowed book \"{$bookTitle}\".\n\n"
            . "The due date was {$formattedDueDate}, and the item is now {$daysOverdue} day" . ($daysOverdue === 1 ? '' : 's') . " overdue.\n\n"
            . "Please return it as soon as possible to avoid additional penalties.\n\n"
            . "Borrow details:\n"
            . "- Book: {$bookTitle}\n"
            . "- Due date: {$formattedDueDate}\n"
            . "- Role: {$roleLabel}\n"
            . "- Borrow ID: #{$borrowId}\n\n"
            . "If you have already returned this book, you may ignore this email.\n\n"
            . library_email_signature();

        $htmlMessage = '<div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.6;color:#10233a;">'
            . '<p>Hello <strong>' . h($fullName) . '</strong>,</p>'
            . '<p>This is an <strong style="color:#b42318;">overdue notice</strong> for your borrowed book <strong>"' . h($bookTitle) . '"</strong>.</p>'
            . '<div style="margin:18px 0;padding:14px 16px;border:1px solid #f3c6c2;border-radius:14px;background:#fff7f6;">'
            . '<div><strong>Book:</strong> ' . h($bookTitle) . '</div>'
            . '<div><strong>Due date:</strong> ' . h($formattedDueDate) . '</div>'
            . '<div><strong>Current status:</strong> ' . (int) $daysOverdue . ' day' . ($daysOverdue === 1 ? '' : 's') . ' overdue</div>'
            . '<div><strong>Role:</strong> ' . h($roleLabel) . '</div>'
            . '<div><strong>Borrow ID:</strong> #' . (int) $borrowId . '</div>'
            . '</div>'
            . '<p>Please return it as soon as possible to avoid additional penalties.</p>'
            . '<p style="color:#5c7188;">If you have already returned this book, you may ignore this email.</p>'
            . '<p style="margin-top:22px;">' . h(library_email_signature()) . '</p>'
            . '</div>';

        $sent = send_library_email($email, $subject, $message, $htmlMessage);

        if ($sent) {
            $timestamp = date('Y-m-d H:i:s');
            $updateStmt->bind_param('si', $timestamp, $borrowId);
            $updateStmt->execute();
            $result['sent']++;
            continue;
        }

        $result['failed']++;
        $result['errors'][] = [
            'borrow_id' => $borrowId,
            'email' => $email,
            'message' => get_library_mail_last_error(),
        ];
    }

    $updateStmt->close();

    if ($result['sent'] > 0) {
        create_notification(
            $conn,
            'admin',
            'Overdue Email Notices Sent',
            $result['sent'] . ' one-time overdue email notice(s) were sent.',
            'warning'
        );
    }

    return $result;
}
?>

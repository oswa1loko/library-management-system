<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

function apply_manage_accounts_filters(string &$sql, string &$types, array &$params, string $search, string $roleFilter, array $rolesAllowed): void
{
    if ($search !== '') {
        $sql .= " AND (fullname LIKE ? OR email LIKE ? OR username LIKE ?)";
        $term = '%' . $search . '%';
        $types .= 'sss';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    if ($roleFilter !== '' && in_array($roleFilter, $rolesAllowed, true)) {
        $sql .= " AND role = ?";
        $types .= 's';
        $params[] = $roleFilter;
    }
}

function run_manage_accounts_query(mysqli $conn, string $sql, string $types, array $params): mysqli_result
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
}

function manage_accounts_print_title(string $roleFilter, array $rolesAllowed, int $printUserId, array $printUserIds): string
{
    if ($printUserId > 0) {
        return 'User Record';
    }

    if (count($printUserIds) > 0) {
        return 'Selected Users';
    }

    if ($roleFilter !== '' && in_array($roleFilter, $rolesAllowed, true)) {
        return role_label($roleFilter) . ' Users';
    }

    return 'All Users';
}

function manage_accounts_filter_query(string $search, string $roleFilter): string
{
    $query = http_build_query(array_filter([
        'search' => $search,
        'role' => $roleFilter,
    ], static fn($value) => $value !== ''));

    return $query !== '' ? '?' . $query : '';
}

function manage_accounts_push_notice(array &$noticeItems, string $type, string $message): void
{
    $message = trim($message);
    if ($message === '') {
        return;
    }

    $noticeItems[] = [
        'type' => $type,
        'message' => $message,
    ];
}

function manage_accounts_create_payload(array $source): array
{
    return [
        'fullname' => trim((string) ($source['fullname'] ?? '')),
        'email' => trim((string) ($source['email'] ?? '')),
        'username' => trim((string) ($source['username'] ?? '')),
        'role' => trim((string) ($source['role'] ?? 'student')),
        'course' => trim((string) ($source['course'] ?? '')),
    ];
}

function manage_accounts_create_invited_user(mysqli $conn, array $payload, array $rolesAllowed, array $courseOptions): array
{
    $fullname = trim((string) ($payload['fullname'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $username = trim((string) ($payload['username'] ?? ''));
    $role = trim((string) ($payload['role'] ?? ''));
    $course = trim((string) ($payload['course'] ?? ''));

    if ($fullname === '' || $email === '' || $username === '' || $role === '') {
        return ['ok' => false, 'message' => 'Complete all required fields.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter a valid email address.'];
    }

    if (!in_array($role, $rolesAllowed, true)) {
        return ['ok' => false, 'message' => 'Invalid role selected.'];
    }

    if ($role === 'student' && ($course === '' || !array_key_exists($course, $courseOptions))) {
        return ['ok' => false, 'message' => 'Select a valid program for the student account.'];
    }

    $check = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
    $check->bind_param('ss', $email, $username);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();
        return ['ok' => false, 'message' => 'Email or username already exists.'];
    }
    $check->close();

    $passwordHash = generate_placeholder_password();
    $courseValue = $role === 'student' ? $course : null;
    $passwordSetupRequired = 1;

    $insert = $conn->prepare("
        INSERT INTO users (fullname, email, username, password, role, course, password_setup_required)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insert->bind_param('ssssssi', $fullname, $email, $username, $passwordHash, $role, $courseValue, $passwordSetupRequired);
    $ok = $insert->execute();
    $newUserId = $ok ? (int) $insert->insert_id : 0;
    $insert->close();

    if (!$ok || $newUserId <= 0) {
        return ['ok' => false, 'message' => 'Unable to create user.'];
    }

    $tokenData = issue_password_setup_token($conn, $newUserId, 'account_setup');
    if (!$tokenData) {
        $delete = $conn->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
        $delete->bind_param('i', $newUserId);
        $delete->execute();
        $delete->close();
        return ['ok' => false, 'message' => 'Unable to create the password setup link right now.'];
    }

    $emailQueued = false;
    if (can_send_library_email()) {
        $emailQueued = enqueue_account_setup_email_job(
            $conn,
            $email,
            $fullname,
            $role,
            $username,
            (string) ($tokenData['url'] ?? '')
        );
    }

    audit_log($conn, 'admin.user.create', [
        'user_id' => $newUserId,
        'role' => $role,
        'username' => $username,
        'course' => $courseValue,
        'password_setup_required' => true,
        'email_queued' => $emailQueued,
    ]);

    return [
        'ok' => true,
        'user_id' => $newUserId,
        'fullname' => $fullname,
        'email' => $email,
        'username' => $username,
        'role' => $role,
        'course' => $courseValue,
        'setup_url' => (string) ($tokenData['url'] ?? ''),
        'expires_at' => (string) ($tokenData['expires_at'] ?? ''),
        'email_queued' => $emailQueued,
    ];
}

function manage_accounts_send_invite(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'message' => 'Invalid user selected.'];
    }

    $lookup = $conn->prepare("
        SELECT id, fullname, email, username, role
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $lookup->bind_param('i', $userId);
    $lookup->execute();
    $user = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$user) {
        return ['ok' => false, 'message' => 'User not found.'];
    }

    $email = trim((string) ($user['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'User email is missing or invalid.'];
    }

    $tokenData = issue_password_setup_token($conn, (int) $user['id'], 'account_setup');
    if (!$tokenData) {
        return ['ok' => false, 'message' => 'Unable to generate a fresh setup link right now.'];
    }

    $emailQueued = false;
    if (can_send_library_email()) {
        $emailQueued = enqueue_account_setup_email_job(
            $conn,
            $email,
            (string) ($user['fullname'] ?? ''),
            (string) ($user['role'] ?? ''),
            (string) ($user['username'] ?? ''),
            (string) ($tokenData['url'] ?? '')
        );
    }

    audit_log($conn, 'admin.user.invite', [
        'user_id' => (int) $user['id'],
        'username' => (string) ($user['username'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'email_queued' => $emailQueued,
    ]);

    return [
        'ok' => true,
        'user_id' => (int) $user['id'],
        'fullname' => (string) ($user['fullname'] ?? ''),
        'username' => (string) ($user['username'] ?? ''),
        'setup_url' => (string) ($tokenData['url'] ?? ''),
        'expires_at' => (string) ($tokenData['expires_at'] ?? ''),
        'email_queued' => $emailQueued,
    ];
}

function manage_accounts_parse_bulk_rows(string $rawInput): array
{
    $lines = preg_split('/\R/u', $rawInput) ?: [];
    $rows = [];
    $errors = [];

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        if (trim($line) === '') {
            continue;
        }

        $columns = array_map('trim', str_getcsv($line));
        if ($index === 0) {
            $normalizedHeader = array_map(static fn($value) => strtolower((string) $value), $columns);
            if ($normalizedHeader !== [] && in_array('fullname', $normalizedHeader, true) && in_array('email', $normalizedHeader, true)) {
                continue;
            }
        }

        if (count($columns) < 4 || count($columns) > 5) {
            $errors[] = 'Line ' . $lineNumber . ': use 4 or 5 comma-separated values -> fullname,email,username,role,program.';
            continue;
        }

        $rows[] = [
            'line' => $lineNumber,
            'fullname' => (string) ($columns[0] ?? ''),
            'email' => (string) ($columns[1] ?? ''),
            'username' => (string) ($columns[2] ?? ''),
            'role' => (string) ($columns[3] ?? ''),
            'course' => (string) ($columns[4] ?? ''),
        ];
    }

    return [
        'rows' => $rows,
        'errors' => $errors,
    ];
}

function manage_accounts_delete_blockers(mysqli $conn, int $userId): array
{
    $blockers = [];
    if ($userId <= 0) {
        return $blockers;
    }

    $borrowStmt = $conn->prepare("
        SELECT status, COUNT(*) AS total
        FROM borrows
        WHERE user_id = ?
          AND status IN ('pending', 'borrowed', 'return_requested')
        GROUP BY status
    ");
    $borrowStmt->bind_param('i', $userId);
    $borrowStmt->execute();
    $borrowRows = $borrowStmt->get_result();
    while ($borrowRows && ($row = $borrowRows->fetch_assoc())) {
        $status = (string) ($row['status'] ?? '');
        $total = (int) ($row['total'] ?? 0);
        if ($total <= 0) {
            continue;
        }
        if ($status === 'pending') {
            $blockers[] = $total . ' pending borrow request(s)';
        } elseif ($status === 'borrowed') {
            $blockers[] = $total . ' borrowed book(s)';
        } elseif ($status === 'return_requested') {
            $blockers[] = $total . ' return-requested book(s)';
        }
    }
    $borrowStmt->close();

    $penaltyStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM penalties
        WHERE user_id = ?
          AND status = 'unpaid'
    ");
    $penaltyStmt->bind_param('i', $userId);
    $penaltyStmt->execute();
    $penaltyRow = $penaltyStmt->get_result()->fetch_assoc();
    $penaltyStmt->close();
    $unpaidPenalties = (int) ($penaltyRow['total'] ?? 0);
    if ($unpaidPenalties > 0) {
        $blockers[] = $unpaidPenalties . ' unpaid penalt' . ($unpaidPenalties === 1 ? 'y' : 'ies');
    }

    $paymentStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM payments
        WHERE user_id = ?
          AND status = 'pending'
    ");
    $paymentStmt->bind_param('i', $userId);
    $paymentStmt->execute();
    $paymentRow = $paymentStmt->get_result()->fetch_assoc();
    $paymentStmt->close();
    $pendingPayments = (int) ($paymentRow['total'] ?? 0);
    if ($pendingPayments > 0) {
        $blockers[] = $pendingPayments . ' pending payment submission(s)';
    }

    return $blockers;
}

function manage_accounts_admin_safety_counts(mysqli $conn): array
{
    $result = $conn->query("
        SELECT
            COUNT(*) AS total_admins,
            SUM(CASE WHEN account_status <> 'inactive' THEN 1 ELSE 0 END) AS active_admins
        FROM users
        WHERE role = 'admin'
    ");

    $row = $result ? $result->fetch_assoc() : [];

    return [
        'total_admins' => (int) ($row['total_admins'] ?? 0),
        'active_admins' => (int) ($row['active_admins'] ?? 0),
    ];
}

function manage_accounts_delete_guard_message(array $user, array $adminSafety): string
{
    if ((string) ($user['role'] ?? '') !== 'admin') {
        return '';
    }

    $isActiveAdmin = (string) ($user['account_status'] ?? 'active') !== 'inactive';
    $totalAdmins = (int) ($adminSafety['total_admins'] ?? 0);
    $activeAdmins = (int) ($adminSafety['active_admins'] ?? 0);

    if ($totalAdmins <= 1) {
        return 'You cannot delete the last admin account.';
    }

    if ($isActiveAdmin && $activeAdmins <= 1) {
        return 'You cannot delete the last active admin account. Create or reactivate another admin first.';
    }

    return '';
}

function manage_accounts_deactivate_guard_message(array $user, string $nextStatus, int $actorUserId, array $adminSafety): string
{
    if ($nextStatus !== 'inactive') {
        return '';
    }

    $userId = (int) ($user['id'] ?? 0);
    if ($actorUserId > 0 && $userId === $actorUserId) {
        return 'You cannot deactivate the account you are currently using.';
    }

    if ((string) ($user['role'] ?? '') !== 'admin') {
        return '';
    }

    $isActiveAdmin = (string) ($user['account_status'] ?? 'active') !== 'inactive';
    $activeAdmins = (int) ($adminSafety['active_admins'] ?? 0);

    if ($isActiveAdmin && $activeAdmins <= 1) {
        return 'You cannot deactivate the last active admin account. Create or reactivate another admin first.';
    }

    return '';
}

function manage_accounts_set_account_status(mysqli $conn, int $userId, string $status, int $actorUserId): array
{
    $status = $status === 'inactive' ? 'inactive' : 'active';
    if ($userId <= 0) {
        return ['ok' => false, 'message' => 'Invalid user selected.'];
    }

    $lookup = $conn->prepare("SELECT id, fullname, username, role, account_status FROM users WHERE id = ? LIMIT 1");
    $lookup->bind_param('i', $userId);
    $lookup->execute();
    $user = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$user) {
        return ['ok' => false, 'message' => 'User not found.'];
    }

    $deactivateGuardMessage = manage_accounts_deactivate_guard_message(
        $user,
        $status,
        $actorUserId,
        manage_accounts_admin_safety_counts($conn)
    );
    if ($deactivateGuardMessage !== '') {
        return ['ok' => false, 'message' => $deactivateGuardMessage];
    }

    if ((string) ($user['account_status'] ?? 'active') === $status) {
        return ['ok' => false, 'message' => 'Account is already marked as ' . $status . '.'];
    }

    $update = $conn->prepare("UPDATE users SET account_status = ? WHERE id = ? LIMIT 1");
    $update->bind_param('si', $status, $userId);
    $ok = $update->execute();
    $update->close();

    if (!$ok) {
        return ['ok' => false, 'message' => 'Unable to update the account status right now.'];
    }

    audit_log($conn, $status === 'inactive' ? 'admin.user.deactivate' : 'admin.user.reactivate', [
        'user_id' => $userId,
        'username' => (string) ($user['username'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'account_status' => $status,
    ]);

    return [
        'ok' => true,
        'message' => $status === 'inactive'
            ? ((string) ($user['fullname'] ?? 'User') . ' was deactivated. The record is preserved, but the account can no longer log in.')
            : ((string) ($user['fullname'] ?? 'User') . ' was reactivated and can log in again.'),
    ];
}

$noticeItems = [];
$rolesAllowed = system_roles();
$courseOptions = student_course_options();
$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$printMode = isset($_GET['print']) && $_GET['print'] === '1';
$printUserId = (int) ($_GET['user_id'] ?? 0);
$printUserIds = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['user_ids'] ?? '')))));
$createData = ['fullname' => '', 'email' => '', 'username' => '', 'role' => 'student', 'course' => ''];
$bulkData = trim((string) ($_POST['bulk_rows'] ?? ''));
$queuedEmailTarget = 0;
$activeProvisioningTab = 'single';

if (isset($_GET['updated'])) {
    manage_accounts_push_notice($noticeItems, 'success', 'User updated successfully.');
}

if (isset($_GET['deleted'])) {
    manage_accounts_push_notice($noticeItems, 'success', 'User removed successfully.');
}

if (isset($_POST['delete'])) {
    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0 && (int) ($_SESSION['user_id'] ?? 0) === $id) {
        manage_accounts_push_notice($noticeItems, 'error', 'You cannot delete the account you are currently using.');
    } elseif ($id > 0) {
        $lookup = $conn->prepare("SELECT id, username, role, account_status FROM users WHERE id = ? LIMIT 1");
        $lookup->bind_param('i', $id);
        $lookup->execute();
        $deletedUser = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        $blockers = manage_accounts_delete_blockers($conn, $id);
        $deleteGuardMessage = manage_accounts_delete_guard_message($deletedUser ?: [], manage_accounts_admin_safety_counts($conn));

        if ($blockers !== []) {
            manage_accounts_push_notice(
                $noticeItems,
                'error',
                'User cannot be deleted yet because this account still has ' . implode(', ', $blockers) . '.'
            );
        } elseif ($deleteGuardMessage !== '') {
            manage_accounts_push_notice($noticeItems, 'error', $deleteGuardMessage);
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $deleted = $stmt->affected_rows === 1;
            $stmt->close();
            if ($deleted) {
                audit_log($conn, 'admin.user.delete', [
                    'deleted_user_id' => $id,
                    'deleted_username' => (string) ($deletedUser['username'] ?? ''),
                    'deleted_role' => (string) ($deletedUser['role'] ?? ''),
                ]);
            }
            header('Location: manage_accounts.php?deleted=1');
            exit;
        }
    }
}

if (isset($_POST['set_status'])) {
    $statusResult = manage_accounts_set_account_status(
        $conn,
        (int) ($_POST['id'] ?? 0),
        (string) ($_POST['status'] ?? 'active'),
        (int) ($_SESSION['user_id'] ?? 0)
    );

    manage_accounts_push_notice(
        $noticeItems,
        ($statusResult['ok'] ?? false) ? 'success' : 'error',
        (string) ($statusResult['message'] ?? 'Unable to update account status.')
    );
}

if (isset($_POST['send_invite'])) {
    $inviteResult = manage_accounts_send_invite($conn, (int) ($_POST['id'] ?? 0));

    if ($inviteResult['ok'] ?? false) {
        if (!empty($inviteResult['email_queued'])) {
            $queuedEmailTarget++;
            manage_accounts_push_notice(
                $noticeItems,
                'success',
                'A fresh account setup email was queued for ' . (string) ($inviteResult['fullname'] ?? 'the user') . '.'
            );
        } else {
            manage_accounts_push_notice(
                $noticeItems,
                'warning',
                'Invite link refreshed for ' . (string) ($inviteResult['fullname'] ?? 'the user') . ', but email transport is not configured. Setup link: ' . (string) ($inviteResult['setup_url'] ?? '')
            );
        }
    } else {
        manage_accounts_push_notice($noticeItems, 'error', (string) ($inviteResult['message'] ?? 'Unable to send the invite.'));
    }
}

if (isset($_POST['create'])) {
    $activeProvisioningTab = 'single';
    $createData = manage_accounts_create_payload($_POST);
    $createResult = manage_accounts_create_invited_user($conn, $createData, $rolesAllowed, $courseOptions);

    if ($createResult['ok'] ?? false) {
        $createData = ['fullname' => '', 'email' => '', 'username' => '', 'role' => 'student', 'course' => ''];
        if (!empty($createResult['email_queued'])) {
            $queuedEmailTarget++;
            manage_accounts_push_notice(
                $noticeItems,
                'success',
                'User created successfully. A password setup email was queued for ' . (string) ($createResult['fullname'] ?? 'the user') . '.'
            );
        } else {
            manage_accounts_push_notice(
                $noticeItems,
                'warning',
                'User created successfully, but email transport is not configured. Share this setup link manually: ' . (string) ($createResult['setup_url'] ?? '')
            );
        }
    } else {
        manage_accounts_push_notice($noticeItems, 'error', (string) ($createResult['message'] ?? 'Unable to create user.'));
    }
}

if (isset($_POST['bulk_create'])) {
    $activeProvisioningTab = 'bulk';
    $parsedBulk = manage_accounts_parse_bulk_rows($bulkData);
    foreach ((array) ($parsedBulk['errors'] ?? []) as $bulkError) {
        manage_accounts_push_notice($noticeItems, 'error', (string) $bulkError);
    }

    $bulkCreated = 0;
    $bulkFailed = 0;
    $bulkLinks = [];

    foreach ((array) ($parsedBulk['rows'] ?? []) as $bulkRow) {
        $bulkResult = manage_accounts_create_invited_user($conn, $bulkRow, $rolesAllowed, $courseOptions);
        if ($bulkResult['ok'] ?? false) {
            $bulkCreated++;
            if (!empty($bulkResult['email_queued'])) {
                $queuedEmailTarget++;
            } else {
                $bulkLinks[] = (string) ($bulkResult['fullname'] ?? 'User') . ': ' . (string) ($bulkResult['setup_url'] ?? '');
            }
            continue;
        }

        $bulkFailed++;
        manage_accounts_push_notice(
            $noticeItems,
            'error',
            'Line ' . (int) ($bulkRow['line'] ?? 0) . ': ' . (string) ($bulkResult['message'] ?? 'Unable to create user.')
        );
    }

    if ($bulkCreated > 0) {
        manage_accounts_push_notice(
            $noticeItems,
            'success',
            $bulkCreated . ' account(s) created from the bulk list.'
        );
    }

    if ($bulkFailed > 0) {
        manage_accounts_push_notice(
            $noticeItems,
            'warning',
            $bulkFailed . ' bulk row(s) were skipped because of validation or duplicate issues.'
        );
    }

    if ($bulkLinks !== []) {
        manage_accounts_push_notice(
            $noticeItems,
            'warning',
            'Email transport is not configured for some created accounts. Manual setup links: ' . implode(' | ', $bulkLinks)
        );
    }

    if ($bulkCreated > 0 && $bulkFailed === 0) {
        $bulkData = '';
    }
}

if ($queuedEmailTarget > 0) {
    $emailDispatch = process_pending_email_jobs($conn, max(5, min(20, $queuedEmailTarget + 2)));
    if (($emailDispatch['sent'] ?? 0) > 0) {
        manage_accounts_push_notice(
            $noticeItems,
            'info',
            (int) $emailDispatch['sent'] . ' account email job(s) were sent immediately.'
        );
    }

    if (($emailDispatch['failed'] ?? 0) > 0) {
        manage_accounts_push_notice(
            $noticeItems,
            'warning',
            (int) $emailDispatch['failed'] . ' email job(s) could not be sent right now. They can be retried once mail transport is ready.'
        );
    }
}

if (isset($_GET['edit'])) {
    $editId = (int) ($_GET['edit'] ?? 0);
    if ($editId > 0) {
        header('Location: edit_user.php?id=' . $editId);
        exit;
    }
}

$stats = $conn->query("
    SELECT
      COUNT(*) AS total_users,
      SUM(role = 'student') AS students,
      SUM(role = 'faculty') AS faculty,
      SUM(role = 'librarian') AS librarians,
      SUM(account_status = 'inactive') AS inactive_users
    FROM users
")->fetch_assoc();
$adminSafety = manage_accounts_admin_safety_counts($conn);

$sql = "
    SELECT
        u.id,
        u.fullname,
        u.email,
        u.username,
        u.role,
        u.account_status,
        u.course,
        u.password_setup_required,
        u.password_setup_completed_at,
        u.created_at,
        COALESCE(active_borrows.total, 0) AS active_borrow_count,
        COALESCE(unpaid_penalties.total, 0) AS unpaid_penalty_count,
        COALESCE(pending_payments.total, 0) AS pending_payment_count,
        admin_safety.total_admins,
        admin_safety.active_admins
    FROM users u
    CROSS JOIN (
        SELECT
            COUNT(*) AS total_admins,
            SUM(CASE WHEN account_status <> 'inactive' THEN 1 ELSE 0 END) AS active_admins
        FROM users
        WHERE role = 'admin'
    ) admin_safety
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS total
        FROM borrows
        WHERE status IN ('pending', 'borrowed', 'return_requested')
        GROUP BY user_id
    ) active_borrows ON active_borrows.user_id = u.id
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS total
        FROM penalties
        WHERE status = 'unpaid'
        GROUP BY user_id
    ) unpaid_penalties ON unpaid_penalties.user_id = u.id
    LEFT JOIN (
        SELECT user_id, COUNT(*) AS total
        FROM payments
        WHERE status = 'pending'
        GROUP BY user_id
    ) pending_payments ON pending_payments.user_id = u.id
    WHERE 1=1
";
$types = '';
$params = [];
apply_manage_accounts_filters($sql, $types, $params, $search, $roleFilter, $rolesAllowed);

$sql .= " ORDER BY id DESC";
$users = run_manage_accounts_query($conn, $sql, $types, $params);
$printUsers = null;

if ($printMode) {
    $printSql = "SELECT id, fullname, email, username, role, account_status, course, password_setup_required, password_setup_completed_at, created_at FROM users WHERE 1=1";
    $printTypes = '';
    $printParams = [];
    apply_manage_accounts_filters($printSql, $printTypes, $printParams, $search, $roleFilter, $rolesAllowed);

    if ($printUserId > 0) {
        $printSql .= " AND id = ?";
        $printTypes .= 'i';
        $printParams[] = $printUserId;
    }

    if ($printUserId === 0 && count($printUserIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($printUserIds), '?'));
        $printSql .= " AND id IN ($placeholders)";
        $printTypes .= str_repeat('i', count($printUserIds));
        foreach ($printUserIds as $selectedId) {
            $printParams[] = $selectedId;
        }
    }

    $printSql .= " ORDER BY role ASC, fullname ASC, id ASC";
    $printUsers = run_manage_accounts_query($conn, $printSql, $printTypes, $printParams);
}

$printTitle = manage_accounts_print_title($roleFilter, $rolesAllowed, $printUserId, $printUserIds);
$filterQueryString = manage_accounts_filter_query($search, $roleFilter);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Accounts</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<?php if ($printMode): ?>
<div class="site-shell">
    <?php require __DIR__ . '/partials/manage_accounts_print.php'; ?>
</div>
<?php else: ?>
<div class="site-shell admin-shell member-shell js-member-sidebar" data-sidebar-key="admin-accounts" data-sidebar-default="expanded" data-sidebar-lock="expanded">
  <?php
  $sidebarPage = 'accounts';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Manage Accounts';
  $pageSubtitle = 'Admin account provisioning and maintenance';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <div class="notice warning">
      Keep at least one active admin account in the system at all times. Deactivate accounts before deleting them whenever possible. Active admins available: <?php echo (int) ($adminSafety['active_admins'] ?? 0); ?>.
    </div>
    <?php require __DIR__ . '/partials/notices.php'; ?>
    <?php require __DIR__ . '/partials/manage_accounts_stats.php'; ?>

    <?php require __DIR__ . '/partials/manage_accounts_create_notes.php'; ?>

    <?php require __DIR__ . '/partials/manage_accounts_directory.php'; ?>
  </div>
  </div>
</div>
<?php endif; ?>
<?php if (!$printMode): ?>
  <script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
  <?php $manageAccountsScriptVersion = (string) filemtime(__DIR__ . '/../assets/admin_manage_accounts.js'); ?>
  <script src="/librarymanage/assets/admin_manage_accounts.js?v=<?php echo urlencode($manageAccountsScriptVersion); ?>"></script>
<?php endif; ?>
</body>
</html>


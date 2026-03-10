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

$message = '';
$messageType = 'success';
$rolesAllowed = system_roles();
$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$printMode = isset($_GET['print']) && $_GET['print'] === '1';
$printUserId = (int) ($_GET['user_id'] ?? 0);
$printUserIds = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['user_ids'] ?? '')))));
$createData = ['fullname' => '', 'email' => '', 'username' => '', 'role' => 'student'];

if (isset($_GET['updated'])) {
    $message = 'User updated successfully.';
}

if (isset($_GET['deleted'])) {
    $message = 'User removed successfully.';
}

if (isset($_POST['delete'])) {
    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0 && (int) ($_SESSION['user_id'] ?? 0) === $id) {
        $message = 'You cannot delete the account you are currently using.';
        $messageType = 'error';
    } elseif ($id > 0) {
        $lookup = $conn->prepare("SELECT username, role FROM users WHERE id = ? LIMIT 1");
        $lookup->bind_param('i', $id);
        $lookup->execute();
        $deletedUser = $lookup->get_result()->fetch_assoc();
        $lookup->close();

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

if (isset($_POST['create'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $createData = ['fullname' => $fullname, 'email' => $email, 'username' => $username, 'role' => $role];

    if ($fullname === '' || $email === '' || $username === '' || $role === '' || $password === '') {
        $message = 'Complete all required fields.';
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
        $messageType = 'error';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters.';
        $messageType = 'error';
    } elseif (!in_array($role, $rolesAllowed, true)) {
        $message = 'Invalid role selected.';
        $messageType = 'error';
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
        $check->bind_param('ss', $email, $username);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $message = 'Email or username already exists.';
            $messageType = 'error';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $conn->prepare("INSERT INTO users (fullname, email, username, password, role) VALUES (?, ?, ?, ?, ?)");
            $insert->bind_param('sssss', $fullname, $email, $username, $hash, $role);

            if ($insert->execute()) {
                $newUserId = (int) $insert->insert_id;
                $message = 'User created successfully.';
                $createData = ['fullname' => '', 'email' => '', 'username' => '', 'role' => 'student'];
                audit_log($conn, 'admin.user.create', [
                    'user_id' => $newUserId,
                    'role' => $role,
                    'username' => $username,
                ]);
            } else {
                $message = 'Unable to create user.';
                $messageType = 'error';
            }

            $insert->close();
        }

        $check->close();
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
      SUM(role = 'librarian') AS librarians
    FROM users
")->fetch_assoc();

$sql = "SELECT id, fullname, email, username, role, created_at FROM users WHERE 1=1";
$types = '';
$params = [];
apply_manage_accounts_filters($sql, $types, $params, $search, $roleFilter, $rolesAllowed);

$sql .= " ORDER BY id DESC";
$users = run_manage_accounts_query($conn, $sql, $types, $params);
$printUsers = null;

if ($printMode) {
    $printSql = "SELECT id, fullname, email, username, role, created_at FROM users WHERE 1=1";
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
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
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
    <?php
    $noticeItems = [];
    if ($message !== '') {
        $noticeItems[] = ['type' => $messageType, 'message' => $message];
    }
    require __DIR__ . '/partials/notices.php';
    ?>
    <?php require __DIR__ . '/partials/manage_accounts_stats.php'; ?>

    <?php require __DIR__ . '/partials/manage_accounts_create_notes.php'; ?>

    <?php require __DIR__ . '/partials/manage_accounts_directory.php'; ?>
  </div>
  </div>
</div>
<?php endif; ?>
<?php if (!$printMode): ?>
  <script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
  <script src="/librarymanage/assets/admin_manage_accounts.js"></script>
<?php endif; ?>
</body>
</html>

<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$rolesAllowed = system_roles();
$message = '';
$messageType = 'success';
$editId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$editUser = null;

if ($editId <= 0) {
    header('Location: manage_accounts.php');
    exit;
}

$loadUser = $conn->prepare("SELECT id, fullname, email, username, role, created_at FROM users WHERE id = ? LIMIT 1");
$loadUser->bind_param('i', $editId);
$loadUser->execute();
$editUser = $loadUser->get_result()->fetch_assoc();
$loadUser->close();

if (!$editUser) {
    header('Location: manage_accounts.php');
    exit;
}

if (isset($_POST['update'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');

    if ($fullname === '' || $email === '' || $username === '' || $role === '') {
        $message = 'Complete all required fields.';
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
        $messageType = 'error';
    } elseif ($newPassword !== '' && strlen($newPassword) < 6) {
        $message = 'New password must be at least 6 characters.';
        $messageType = 'error';
    } elseif (!in_array($role, $rolesAllowed, true)) {
        $message = 'Invalid role selected.';
        $messageType = 'error';
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id <> ? LIMIT 1");
        $check->bind_param('ssi', $email, $username, $editId);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $message = 'Email or username is already used by another account.';
            $messageType = 'error';
        } else {
            if ($newPassword !== '') {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET fullname = ?, email = ?, username = ?, role = ?, password = ? WHERE id = ?");
                $update->bind_param('sssssi', $fullname, $email, $username, $role, $hash, $editId);
            } else {
                $update = $conn->prepare("UPDATE users SET fullname = ?, email = ?, username = ?, role = ? WHERE id = ?");
                $update->bind_param('ssssi', $fullname, $email, $username, $role, $editId);
            }

            if ($update->execute()) {
                $update->close();
                audit_log($conn, 'admin.user.update', [
                    'user_id' => $editId,
                    'username' => $username,
                    'role' => $role,
                    'password_changed' => $newPassword !== '',
                ]);
                header('Location: manage_accounts.php?updated=1');
                exit;
            }

            $update->close();
            $message = 'Unable to update user.';
            $messageType = 'error';
        }

        $check->close();
    }

    $editUser['fullname'] = $fullname;
    $editUser['email'] = $email;
    $editUser['username'] = $username;
    $editUser['role'] = $role;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit User</title>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css">
</head>
<body>
<div class="auth-shell">
  <div class="card surface-shell-wide edit-user-shell">
    <div class="split split-stretch">
      <div class="panel-pad-lg edit-user-main">
        <div class="card-head">
          <div class="dashboard-icon icon-edit" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow">Edit Account</p>
            <h2 class="heading-tight"><?php echo h($editUser['fullname']); ?></h2>
            <p class="muted text-measure">Update role access, profile details, and login credentials from this dedicated admin workspace.</p>
          </div>
        </div>

        <div class="inline-actions flow-top-md edit-user-summary">
          <span class="chip">User ID #<?php echo (int) $editUser['id']; ?></span>
          <span class="chip">Role: <?php echo h(role_label((string) $editUser['role'])); ?></span>
          <span class="chip">Created: <?php echo h(format_display_date((string) $editUser['created_at'])); ?></span>
        </div>

        <?php if ($message !== ''): ?>
          <div class="notice <?php echo $messageType === 'error' ? 'error' : 'success'; ?>"><?php echo h($message); ?></div>
        <?php endif; ?>

        <form method="post" class="stack flow-top-md edit-user-form">
          <input type="hidden" name="id" value="<?php echo (int) $editUser['id']; ?>">
          <div class="grid form">
            <div>
              <label for="fullname">Full name</label>
              <input id="fullname" name="fullname" value="<?php echo h($editUser['fullname']); ?>" required>
            </div>
            <div>
              <label for="email">Email</label>
              <input id="email" type="email" name="email" value="<?php echo h($editUser['email']); ?>" required>
            </div>
            <div>
              <label for="username">Username</label>
              <input id="username" name="username" value="<?php echo h($editUser['username']); ?>" required>
            </div>
            <div>
              <label for="role">Role</label>
              <div class="ui-select-shell">
                <select id="role" name="role" class="ui-select" required>
                  <?php foreach ($rolesAllowed as $roleOption): ?>
                    <option value="<?php echo h($roleOption); ?>" <?php echo $editUser['role'] === $roleOption ? 'selected' : ''; ?>><?php echo h(role_label($roleOption)); ?></option>
                  <?php endforeach; ?>
                </select>
                <span class="ui-select-caret" aria-hidden="true"></span>
              </div>
            </div>
            <div>
              <label for="new_password">New password</label>
              <input id="new_password" type="password" name="new_password" placeholder="Leave blank to keep current password">
            </div>
          </div>
          <div class="empty-state">Leave the password field blank if the current login password should stay unchanged.</div>
          <div class="inline-actions">
            <button type="submit" name="update" value="1">Save Changes</button>
            <a class="button secondary" href="manage_accounts.php">Back to Accounts</a>
          </div>
        </form>
      </div>

      <div class="panel-pad-lg hero-panel-dark-soft edit-user-side">
        <div class="card-head">
          <div class="dashboard-icon icon-guide" aria-hidden="true"></div>
          <div>
            <span class="chip">Admin Checklist</span>
            <h3 class="heading-top heading-tight">Before saving</h3>
            <p class="muted">Make sure the role matches the account's real access needs and that the email or username is still unique across the system.</p>
          </div>
        </div>

        <div class="stack flow-top-md">
          <div class="empty-state">
            <strong class="label-block-gap">Role changes</strong>
            Adjust roles carefully because the next login will route the account to a different dashboard.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Credential updates</strong>
            Only set a new password when the account really needs credential replacement or recovery.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Directory cleanup</strong>
            Return to the accounts directory after saving if you still need to review or remove other users.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>

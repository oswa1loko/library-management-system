<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';

if (!empty($_SESSION['role']) && !empty($_SESSION['username'])) {
    redirect_to_dashboard();
}

$showSetupNote = false;
$check = $conn->query("SHOW TABLES LIKE 'users'");
if (!$check || $check->num_rows === 0) {
    $showSetupNote = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        $error = 'Please complete all fields.';
    } else {
        $stmt = $conn->prepare("
            SELECT id, username, email, password, role
            FROM users
            WHERE (username = ? OR email = ?)
            LIMIT 1
        ");
        $stmt->bind_param('ss', $login, $login);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($id, $dbUsername, $dbEmail, $dbPassword, $dbRole);
            $stmt->fetch();

            $ok = password_verify($password, $dbPassword);

            if (!$ok && md5($password) === $dbPassword) {
                $ok = true;
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $upgrade = $conn->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
                $upgrade->bind_param('si', $newHash, $id);
                $upgrade->execute();
                $upgrade->close();
            }

            if ($ok) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $id;
                $_SESSION['username'] = $dbUsername;
                $_SESSION['email'] = $dbEmail;
                $_SESSION['role'] = $dbRole;
                $stmt->close();
                redirect_to_dashboard($dbRole);
            }

            $error = 'Invalid credentials.';
        } else {
            $error = 'Invalid credentials.';
        }

        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | Library</title>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="auth-shell">
  <div class="card auth-card-shell">
    <div class="split auth-split">
      <div class="auth-panel auth-panel-main">
        <p class="muted auth-kicker">Secure Access</p>
        <h2 class="auth-title">Library Login</h2>
        <p class="muted auth-intro">Use your email or username and password. The system will route you to the correct dashboard after login.</p>

        <?php if (!empty($error)): ?>
          <div class="notice error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" class="stack auth-form">
          <div>
            <label for="username">Email or Username</label>
            <input id="username" type="text" name="username" placeholder="Enter email or username" required>
          </div>

          <div>
            <label for="password">Password</label>
            <input id="password" type="password" name="password" placeholder="Enter password" required>
          </div>

          <div class="inline-actions">
            <button type="submit">Login</button>
            <a class="button secondary" href="/librarymanage/index.php">Back Home</a>
          </div>
        </form>

        <?php if ($showSetupNote): ?>
          <div class="footer-note">First time setup is missing. Run <a href="setup.php">setup.php</a> once before logging in.</div>
        <?php endif; ?>
      </div>

      <div class="auth-panel auth-panel-side">
        <span class="chip">Role-Based Portal</span>
        <h3 class="auth-side-title">What you can access after login</h3>
        <div class="stack auth-role-list">
          <div class="empty-state auth-role-item"><strong>Admin</strong>Manage accounts, review payment submissions, and monitor penalty records.</div>
          <div class="empty-state auth-role-item"><strong>Student and Faculty</strong>Borrow books, check return status, and upload payment proof.</div>
          <div class="empty-state auth-role-item"><strong>Librarian</strong>Manage books, track active borrows, mark returns, and maintain penalties.</div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>

<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$password = trim((string) ($_POST['password'] ?? ''));
$confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));
$error = '';
$info = '';
$tokenRecord = $token !== '' ? find_password_setup_token($conn, $token, 'password_reset') : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tokenRecord) {
        $error = 'This password reset link is invalid or has already expired. Request a new reset link from the login page.';
    } elseif ($password === '' || $confirmPassword === '') {
        $error = 'Enter and confirm the new password.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $completed = complete_password_setup(
            $conn,
            (int) ($tokenRecord['user_id'] ?? 0),
            (int) ($tokenRecord['id'] ?? 0),
            $password,
            'password_reset'
        );

        if ($completed) {
            audit_log($conn, 'auth.password_reset.complete', [
                'user_id' => (int) ($tokenRecord['user_id'] ?? 0),
                'username' => (string) ($tokenRecord['username'] ?? ''),
                'role' => (string) ($tokenRecord['role'] ?? ''),
            ]);

            $_SESSION['loginpage_flash'] = [
                'type' => 'info',
                'message' => 'Password reset successfully. You can now log in with your new password.',
            ];

            header('Location: ' . app_url('loginpage.php'));
            exit;
        }

        $error = 'Unable to save the new password right now. Please try again.';
    }
}

if ($tokenRecord && $info === '') {
    $info = 'Resetting password for ' . (string) ($tokenRecord['fullname'] ?? 'your account') . ' (' . role_label((string) ($tokenRecord['role'] ?? '')) . ').';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password | Library</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/assets/app.css'); ?>
<link rel="stylesheet" href="<?php echo h(app_url('assets/app.css')); ?>?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body class="auth-page">
<div class="auth-shell">
  <div class="auth-card-shell">
    <div class="split auth-split">
      <div class="auth-panel auth-panel-main">
        <p class="muted auth-kicker">Password Recovery</p>
        <h2 class="auth-title">Set a New Password</h2>
        <p class="muted auth-intro">Choose a new password for your library account. After saving, use it the next time you log in.</p>

        <?php if ($info !== ''): ?>
          <div class="notice info"><?php echo h($info); ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="notice error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if ($tokenRecord): ?>
          <form method="post" class="stack auth-form" autocomplete="off">
            <input type="hidden" name="token" value="<?php echo h($token); ?>">
            <div>
              <label for="password">New Password</label>
              <input id="password" type="password" name="password" placeholder="At least 8 characters" required>
            </div>
            <div>
              <label for="confirm_password">Confirm Password</label>
              <input id="confirm_password" type="password" name="confirm_password" placeholder="Repeat the password" required>
            </div>
            <div class="inline-actions">
              <button type="submit">Save New Password</button>
              <a class="button secondary" href="<?php echo h(app_url('loginpage.php')); ?>">Back to Login</a>
            </div>
          </form>
          <div class="footer-note">Choose at least 8 characters. After saving, use the new password on your next login.</div>
        <?php else: ?>
          <div class="notice warning">This reset link is no longer active.</div>
          <div class="inline-actions">
            <a class="button secondary" href="<?php echo h(app_url('forgot_password.php')); ?>">Request New Reset Link</a>
          </div>
          <div class="footer-note">If you still need access, request a fresh reset link from the login page.</div>
        <?php endif; ?>
      </div>

      <div class="auth-panel auth-panel-side">
        <span class="chip">Secure Reset</span>
        <h3 class="auth-side-title">Recovery notes</h3>
        <div class="stack auth-role-list">
          <div class="auth-role-item auth-role-item-compact">
            <span class="auth-role-marker" aria-hidden="true"></span>
            <div>
              <strong>Link-based recovery</strong>
              <span>The reset link only works once and expires automatically.</span>
            </div>
          </div>
          <div class="auth-role-item auth-role-item-compact">
            <span class="auth-role-marker" aria-hidden="true"></span>
            <div>
              <strong>Private password setup</strong>
              <span>No plain password is ever sent through email during recovery.</span>
            </div>
          </div>
          <div class="auth-role-item auth-role-item-compact">
            <span class="auth-role-marker" aria-hidden="true"></span>
            <div>
              <strong>Works with your current login</strong>
              <span>After saving, the new password is used for the same username or email account.</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>

<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$password = trim((string) ($_POST['password'] ?? ''));
$confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));
$error = '';
$info = '';
$tokenRecord = $token !== '' ? find_password_setup_token($conn, $token, 'account_setup') : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tokenRecord) {
        $error = 'This password setup link is invalid or has already expired. Ask the administrator to send a new invitation.';
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
            $password
        );

        if ($completed) {
            audit_log($conn, 'auth.account_setup.complete', [
                'user_id' => (int) ($tokenRecord['user_id'] ?? 0),
                'username' => (string) ($tokenRecord['username'] ?? ''),
                'role' => (string) ($tokenRecord['role'] ?? ''),
            ]);

            $_SESSION['loginpage_flash'] = [
                'type' => 'info',
                'message' => 'Password set successfully. You can now log in with your email or username.',
            ];

            header('Location: ' . app_url('loginpage.php'));
            exit;
        }

        $error = 'Unable to save the new password right now. Please try again.';
    }
}

if ($tokenRecord && $info === '') {
    $info = 'Setting up access for ' . (string) ($tokenRecord['fullname'] ?? 'your account') . ' (' . role_label((string) ($tokenRecord['role'] ?? '')) . ').';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set Password | Library</title>
<link rel="stylesheet" href="<?php echo h(app_url('assets/app.css')); ?>">
</head>
<body class="auth-page">
<div class="auth-shell">
  <div class="auth-card-shell">
    <div class="split auth-split">
      <div class="auth-panel auth-panel-main">
        <p class="muted auth-kicker">Account Activation</p>
        <h2 class="auth-title">Set Your Password</h2>
        <p class="muted auth-intro">Finish setting up your library account by choosing a password only you know.</p>

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
              <button type="submit">Save Password</button>
              <a class="button secondary" href="<?php echo h(app_url('loginpage.php')); ?>">Back to Login</a>
            </div>
          </form>
          <div class="footer-note">Use at least 8 characters. After saving, you can log in with your email or username.</div>
        <?php else: ?>
          <div class="notice warning">This setup link is no longer active.</div>
          <div class="inline-actions">
            <a class="button secondary" href="<?php echo h(app_url('loginpage.php')); ?>">Back to Login</a>
          </div>
          <div class="footer-note">If you still need access, ask the administrator to resend your invitation email.</div>
        <?php endif; ?>
      </div>

      <div class="auth-panel auth-panel-side">
        <span class="chip">Secure Onboarding</span>
        <h3 class="auth-side-title">Why this step matters</h3>
        <div class="stack auth-role-list">
          <div class="auth-role-item auth-role-item-compact">
            <span class="auth-role-marker" aria-hidden="true"></span>
            <div>
              <strong>No shared default password</strong>
              <span>Each user sets a private password instead of receiving a reusable one.</span>
            </div>
          </div>
          <div class="auth-role-item auth-role-item-compact">
            <span class="auth-role-marker" aria-hidden="true"></span>
            <div>
              <strong>Email-based activation</strong>
              <span>Account invitations can be sent automatically after creation.</span>
            </div>
          </div>
          <div class="auth-role-item auth-role-item-compact">
            <span class="auth-role-marker" aria-hidden="true"></span>
            <div>
              <strong>Thesis-ready flow</strong>
              <span>Shows a more realistic institutional onboarding workflow for demos and defense.</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>

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
              <div class="password-field" data-password-field>
                <input
                  id="password"
                  class="password-field-input"
                  type="password"
                  name="password"
                  placeholder="At least 8 characters"
                  required
                  data-password-input
                >
                <button
                  type="button"
                  class="password-toggle"
                  aria-label="Show password"
                  title="Show password"
                  data-password-toggle
                  data-visible="false"
                >
                  <span class="password-toggle-icon" aria-hidden="true">
                    <svg class="password-toggle-icon-eye" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                      <path d="M12 5C7.28 5 3.23 7.86 1.47 11.95a.75.75 0 0 0 0 .6C3.23 16.14 7.28 19 12 19s8.77-2.86 10.53-6.45a.75.75 0 0 0 0-.6C20.77 7.86 16.72 5 12 5Zm0 12.5c-3.97 0-7.43-2.29-9-5.25 1.57-2.96 5.03-5.25 9-5.25s7.43 2.29 9 5.25c-1.57 2.96-5.03 5.25-9 5.25Zm0-8.5a3.25 3.25 0 1 0 3.25 3.25A3.25 3.25 0 0 0 12 9Zm0 5a1.75 1.75 0 1 1 1.75-1.75A1.75 1.75 0 0 1 12 14Z"></path>
                    </svg>
                    <svg class="password-toggle-icon-eye-off" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                      <path d="M2.78 2.72a.75.75 0 1 0-1.06 1.06l2.11 2.11a12.78 12.78 0 0 0-2.36 3.56.75.75 0 0 0 0 .6C3.23 16.14 7.28 19 12 19a10.6 10.6 0 0 0 5.16-1.3l3.06 3.06a.75.75 0 1 0 1.06-1.06Zm9.22 14.78c-3.97 0-7.43-2.29-9-5.25a11.24 11.24 0 0 1 1.91-2.8l2.2 2.2A3.22 3.22 0 0 0 7 12.25 5 5 0 0 0 12 17.5Zm0-3.5a1.75 1.75 0 0 1-1.74-1.95l2.19 2.19A1.76 1.76 0 0 1 12 14Zm10.53-1.75C20.77 7.86 16.72 5 12 5a10.57 10.57 0 0 0-4.03.79l1.24 1.24A9.15 9.15 0 0 1 12 6.5c3.97 0 7.43 2.29 9 5.25a11.32 11.32 0 0 1-2.58 3.48l1.08 1.08a12.75 12.75 0 0 0 3.03-4.06.75.75 0 0 0 0-.6Zm-7.17.52a3.26 3.26 0 0 0-4.13-4.13l1.23 1.23a1.75 1.75 0 0 1 1.67 1.67Z"></path>
                    </svg>
                  </span>
                </button>
              </div>
            </div>
            <div>
              <label for="confirm_password">Confirm Password</label>
              <div class="password-field" data-password-field>
                <input
                  id="confirm_password"
                  class="password-field-input"
                  type="password"
                  name="confirm_password"
                  placeholder="Repeat the password"
                  required
                  data-password-input
                >
                <button
                  type="button"
                  class="password-toggle"
                  aria-label="Show password"
                  title="Show password"
                  data-password-toggle
                  data-visible="false"
                >
                  <span class="password-toggle-icon" aria-hidden="true">
                    <svg class="password-toggle-icon-eye" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                      <path d="M12 5C7.28 5 3.23 7.86 1.47 11.95a.75.75 0 0 0 0 .6C3.23 16.14 7.28 19 12 19s8.77-2.86 10.53-6.45a.75.75 0 0 0 0-.6C20.77 7.86 16.72 5 12 5Zm0 12.5c-3.97 0-7.43-2.29-9-5.25 1.57-2.96 5.03-5.25 9-5.25s7.43 2.29 9 5.25c-1.57 2.96-5.03 5.25-9 5.25Zm0-8.5a3.25 3.25 0 1 0 3.25 3.25A3.25 3.25 0 0 0 12 9Zm0 5a1.75 1.75 0 1 1 1.75-1.75A1.75 1.75 0 0 1 12 14Z"></path>
                    </svg>
                    <svg class="password-toggle-icon-eye-off" viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                      <path d="M2.78 2.72a.75.75 0 1 0-1.06 1.06l2.11 2.11a12.78 12.78 0 0 0-2.36 3.56.75.75 0 0 0 0 .6C3.23 16.14 7.28 19 12 19a10.6 10.6 0 0 0 5.16-1.3l3.06 3.06a.75.75 0 1 0 1.06-1.06Zm9.22 14.78c-3.97 0-7.43-2.29-9-5.25a11.24 11.24 0 0 1 1.91-2.8l2.2 2.2A3.22 3.22 0 0 0 7 12.25 5 5 0 0 0 12 17.5Zm0-3.5a1.75 1.75 0 0 1-1.74-1.95l2.19 2.19A1.76 1.76 0 0 1 12 14Zm10.53-1.75C20.77 7.86 16.72 5 12 5a10.57 10.57 0 0 0-4.03.79l1.24 1.24A9.15 9.15 0 0 1 12 6.5c3.97 0 7.43 2.29 9 5.25a11.32 11.32 0 0 1-2.58 3.48l1.08 1.08a12.75 12.75 0 0 0 3.03-4.06.75.75 0 0 0 0-.6Zm-7.17.52a3.26 3.26 0 0 0-4.13-4.13l1.23 1.23a1.75 1.75 0 0 1 1.67 1.67Z"></path>
                    </svg>
                  </span>
                </button>
              </div>
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
<script>
(() => {
  const passwordToggles = Array.from(document.querySelectorAll('[data-password-toggle]'));
  passwordToggles.forEach((toggle) => {
    const field = toggle.closest('[data-password-field]');
    const input = field ? field.querySelector('[data-password-input]') : null;
    if (!input) {
      return;
    }

    const syncPasswordState = (isVisible) => {
      input.type = isVisible ? 'text' : 'password';
      toggle.dataset.visible = isVisible ? 'true' : 'false';
      toggle.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
      const label = isVisible ? 'Hide password' : 'Show password';
      toggle.setAttribute('aria-label', label);
      toggle.setAttribute('title', label);
    };

    syncPasswordState(false);
    toggle.addEventListener('click', () => {
      syncPasswordState(input.type === 'password');
      input.focus({ preventScroll: true });
      const valueLength = input.value.length;
      if (typeof input.setSelectionRange === 'function') {
        input.setSelectionRange(valueLength, valueLength);
      }
    });
  });
})();
</script>
</body>
</html>

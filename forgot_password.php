<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';

if (!empty($_SESSION['role']) && !empty($_SESSION['username'])) {
    redirect_to_dashboard();
}

$login = trim((string) ($_POST['login'] ?? ''));
$error = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($login === '') {
        $error = 'Enter your email or username first.';
    } else {
        $stmt = $conn->prepare("
            SELECT id, fullname, email, username, role, account_status, password_setup_required
            FROM users
            WHERE (email = ? OR username = ?)
            LIMIT 1
        ");
        $stmt->bind_param('ss', $login, $login);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $role = strtolower(trim((string) ($user['role'] ?? '')));
        $canSelfServeReset = $user
            && in_array($role, ['student', 'faculty', 'librarian', 'admin'], true)
            && (string) ($user['account_status'] ?? '') !== 'inactive'
            && (int) ($user['password_setup_required'] ?? 0) === 0
            && is_valid_email_address((string) ($user['email'] ?? ''));

        if ($canSelfServeReset) {
            $tokenData = issue_password_setup_token($conn, (int) ($user['id'] ?? 0), 'password_reset');
            if ($tokenData) {
                $queued = enqueue_password_reset_email_job(
                    $conn,
                    (string) ($user['email'] ?? ''),
                    (string) ($user['fullname'] ?? ''),
                    $role,
                    (string) ($user['username'] ?? ''),
                    (string) ($tokenData['url'] ?? '')
                );
                if ($queued) {
                    process_pending_email_jobs($conn, 2);
                    audit_log($conn, 'auth.password_reset.request', [
                        'user_id' => (int) ($user['id'] ?? 0),
                        'username' => (string) ($user['username'] ?? ''),
                        'role' => $role,
                    ]);
                }
            }
        }

        $info = 'If the account exists and has a valid email address, a password reset link is being sent.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | Library</title>
<script>
(() => {
  const storageKey = 'librarymanage-theme';
  const root = document.documentElement;

  try {
    const storedTheme = window.localStorage.getItem(storageKey);
    const theme = storedTheme === 'light' ? 'light' : 'dark';
    root.setAttribute('data-theme', theme);
    root.style.colorScheme = theme;
  } catch (error) {
    root.setAttribute('data-theme', 'dark');
    root.style.colorScheme = 'dark';
  }
})();
</script>
<?php $assetVersion = (string) filemtime(__DIR__ . '/assets/app.css'); ?>
<link rel="stylesheet" href="<?php echo h(app_url('assets/app.css')); ?>?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body class="auth-page">
<div class="auth-shell">
  <div class="auth-card-shell">
    <div class="split auth-split">
      <div class="auth-panel auth-panel-main">
        <p class="muted auth-kicker">Password Recovery</p>
        <h2 class="auth-title">Forgot Your Password?</h2>
        <p class="muted auth-intro">Enter your email or username and we will send a secure password reset link to your registered email address.</p>

        <?php if ($info !== ''): ?>
          <div class="notice success"><?php echo h($info); ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="notice error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <form method="post" class="stack auth-form" autocomplete="off">
          <div>
            <label for="login">Email or Username</label>
            <input id="login" type="text" name="login" value="<?php echo h($login); ?>" placeholder="Enter your email or username" required>
          </div>
          <div class="inline-actions">
            <button type="submit">Send Reset Link</button>
            <a class="button secondary" href="<?php echo h(app_url('loginpage.php')); ?>">Back to Login</a>
          </div>
        </form>
        <div class="footer-note">Use the same email or username already registered on your library account.</div>
      </div>

      <div class="auth-panel auth-panel-side">
        <span class="chip">Self-Service Recovery</span>
        <h3 class="auth-side-title">How it works</h3>
        <div class="stack auth-role-list">
          <div class="auth-role-item auth-role-item-compact">
            <span class="auth-role-marker" aria-hidden="true"></span>
            <div>
              <strong>Email-based reset</strong>
              <span>A reset link is sent to the email address already registered on the account.</span>
            </div>
          </div>
          <div class="auth-role-item auth-role-item-compact">
            <span class="auth-role-marker" aria-hidden="true"></span>
            <div>
              <strong>Time-limited access</strong>
              <span>Reset links expire automatically for better account security.</span>
            </div>
          </div>
          <div class="auth-role-item auth-role-item-compact">
            <span class="auth-role-marker" aria-hidden="true"></span>
            <div>
              <strong>No manual password sharing</strong>
              <span>Authorized users can recover access without asking another staff member to create a new password for them.</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>

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

$error = '';
$info = '';
$pendingOtp = is_array($_SESSION['pending_login_otp'] ?? null) ? $_SESSION['pending_login_otp'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['back_to_login'])) {
        $pendingOtp = is_array($_SESSION['pending_login_otp'] ?? null) ? $_SESSION['pending_login_otp'] : null;
        if ($pendingOtp) {
            clear_login_otp($conn, (int) ($pendingOtp['user_id'] ?? 0));
        }
        unset($_SESSION['pending_login_otp']);
        header('Location: /librarymanage/loginpage.php');
        exit;
    } elseif (isset($_POST['verify_otp']) || isset($_POST['resend_otp'])) {
        $pendingOtp = is_array($_SESSION['pending_login_otp'] ?? null) ? $_SESSION['pending_login_otp'] : null;

        if (!$pendingOtp) {
            $error = 'Your verification session has expired. Please log in again.';
        } else {
            $pendingUserId = (int) ($pendingOtp['user_id'] ?? 0);
            $pendingUsername = (string) ($pendingOtp['username'] ?? '');
            $pendingEmail = (string) ($pendingOtp['email'] ?? '');
            $pendingRole = (string) ($pendingOtp['role'] ?? '');
            $pendingFullName = (string) ($pendingOtp['fullname'] ?? '');

            if (!is_valid_email_address($pendingEmail)) {
                clear_login_otp($conn, $pendingUserId);
                unset($_SESSION['pending_login_otp']);
                $pendingOtp = null;
                $error = 'This account does not have a valid email address for verification. Please contact the librarian.';
            } elseif (isset($_POST['resend_otp'])) {
                $issued = issue_login_otp($conn, $pendingUserId);
                $sent = send_login_otp_email($pendingEmail, $pendingFullName, $pendingRole, $issued['code']);

                if ($sent) {
                    $info = 'A new verification code has been sent to ' . $pendingEmail . '.';
                } else {
                    $error = 'Unable to resend the verification code right now.';
                }
            } else {
                $otpCode = trim((string) ($_POST['otp_code'] ?? ''));
                if ($otpCode === '') {
                    $error = 'Enter the verification code sent to your email.';
                } elseif (!verify_login_otp($conn, $pendingUserId, $otpCode)) {
                    $error = 'Invalid or expired verification code.';
                } else {
                    clear_login_otp($conn, $pendingUserId);
                    unset($_SESSION['pending_login_otp']);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $pendingUserId;
                    $_SESSION['username'] = $pendingUsername;
                    $_SESSION['email'] = $pendingEmail;
                    $_SESSION['role'] = $pendingRole;
                    redirect_to_dashboard($pendingRole);
                }
            }
        }
    } else {
        $login = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($login === '' || $password === '') {
            $error = 'Please complete all fields.';
        } else {
            $stmt = $conn->prepare("
                SELECT id, fullname, username, email, password, role
                FROM users
                WHERE (username = ? OR email = ?)
                LIMIT 1
            ");
            $stmt->bind_param('ss', $login, $login);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 1) {
                $stmt->bind_result($id, $dbFullName, $dbUsername, $dbEmail, $dbPassword, $dbRole);
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
                    if (role_requires_login_otp($dbRole)) {
                        if (!is_valid_email_address($dbEmail)) {
                            clear_login_otp($conn, (int) $id);
                            $error = 'This account does not have a valid email address for verification. Please contact the librarian.';
                        } else {
                            $issued = issue_login_otp($conn, (int) $id);
                            $sent = send_login_otp_email($dbEmail, $dbFullName, $dbRole, $issued['code']);

                            if ($sent) {
                                $_SESSION['pending_login_otp'] = [
                                    'user_id' => (int) $id,
                                    'fullname' => $dbFullName,
                                    'username' => $dbUsername,
                                    'email' => $dbEmail,
                                    'role' => $dbRole,
                                ];
                                $pendingOtp = $_SESSION['pending_login_otp'];
                                $info = 'A verification code has been sent to ' . $dbEmail . '.';
                            } else {
                                clear_login_otp($conn, (int) $id);
                                $error = 'Unable to send the verification code right now.';
                            }
                        }
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = (int) $id;
                        $_SESSION['username'] = $dbUsername;
                        $_SESSION['email'] = $dbEmail;
                        $_SESSION['role'] = $dbRole;
                        $stmt->close();
                        redirect_to_dashboard($dbRole);
                    }
                } else {
                    $error = 'Invalid credentials.';
                }
            } else {
                $error = 'Invalid credentials.';
            }

            $stmt->close();
        }
    }
}

$pendingOtp = is_array($_SESSION['pending_login_otp'] ?? null) ? $_SESSION['pending_login_otp'] : null;
$isOtpStep = $pendingOtp !== null;
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
        <p class="muted auth-kicker"><?php echo $isOtpStep ? 'Account Verification' : 'Secure Access'; ?></p>
        <h2 class="auth-title"><?php echo $isOtpStep ? 'Verify Login Code' : 'Library Login'; ?></h2>
        <p class="muted auth-intro">
          <?php if ($isOtpStep): ?>
            Enter the 6-digit code sent to <?php echo h((string) ($pendingOtp['email'] ?? 'your email')); ?> to complete your login.
          <?php else: ?>
            Use your email or username and password. Student and faculty accounts will receive a one-time verification code after password login.
          <?php endif; ?>
        </p>

        <?php if ($info !== ''): ?>
          <div class="notice success"><?php echo h($info); ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="notice error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if ($isOtpStep): ?>
          <form method="POST" autocomplete="off" class="stack auth-form">
            <div>
              <label for="otp_code">Verification Code</label>
              <input id="otp_code" type="text" name="otp_code" inputmode="numeric" maxlength="6" placeholder="Enter 6-digit code" required>
            </div>

            <div class="inline-actions">
              <button type="submit" name="verify_otp" value="1">Verify and Login</button>
              <button type="submit" name="resend_otp" value="1" class="button secondary" formnovalidate>Resend Code</button>
              <button type="submit" name="back_to_login" value="1" class="button secondary" formnovalidate>Back to Login</button>
            </div>
          </form>
          <div class="footer-note">The verification code is valid for 10 minutes. If you did not receive it, use `Resend Code`.</div>
        <?php else: ?>
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
        <?php endif; ?>

        <?php if ($showSetupNote): ?>
          <div class="footer-note">First time setup is missing. Run <a href="setup.php">setup.php</a> once before logging in.</div>
        <?php endif; ?>
      </div>

      <div class="auth-panel auth-panel-side">
        <span class="chip"><?php echo $isOtpStep ? 'Email Verification' : 'Role-Based Portal'; ?></span>
        <h3 class="auth-side-title"><?php echo $isOtpStep ? 'Who uses login verification' : 'What you can access after login'; ?></h3>
        <div class="stack auth-role-list">
          <?php if ($isOtpStep): ?>
            <div class="empty-state auth-role-item"><strong>Student and Faculty</strong>Email OTP is required after entering the correct password.</div>
            <div class="empty-state auth-role-item"><strong>Admin and Librarian</strong>Direct password login stays available for staff access.</div>
            <div class="empty-state auth-role-item"><strong>Security</strong>Verification codes expire after 10 minutes and can be resent when needed.</div>
          <?php else: ?>
            <div class="empty-state auth-role-item"><strong>Admin</strong>Manage accounts, review payment submissions, and monitor penalty records.</div>
            <div class="empty-state auth-role-item"><strong>Student and Faculty</strong>Borrow books, check return status, and upload payment proof.</div>
            <div class="empty-state auth-role-item"><strong>Librarian</strong>Manage books, track active borrows, mark returns, and maintain penalties.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>

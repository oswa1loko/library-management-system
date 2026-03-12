<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';

function loginpage_set_flash(string $type, string $message): void
{
    $_SESSION['loginpage_flash'] = [
        'type' => $type,
        'message' => trim($message),
    ];
}

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
$flash = is_array($_SESSION['loginpage_flash'] ?? null) ? $_SESSION['loginpage_flash'] : null;
unset($_SESSION['loginpage_flash']);
if ($flash) {
    if (($flash['type'] ?? '') === 'error') {
        $error = trim((string) ($flash['message'] ?? ''));
    } elseif (($flash['type'] ?? '') === 'info') {
        $info = trim((string) ($flash['message'] ?? ''));
    }
}
$pendingOtp = is_array($_SESSION['pending_login_otp'] ?? null) ? $_SESSION['pending_login_otp'] : null;
$otpResendCooldown = login_otp_resend_cooldown_seconds();
$otpMaxAttempts = login_otp_max_attempts();
$otpResendWaitSeconds = $pendingOtp ? get_login_otp_resend_wait_seconds($conn, (int) ($pendingOtp['user_id'] ?? 0)) : 0;
$enteredOtpCode = preg_replace('/\D+/', '', (string) ($_POST['otp_code'] ?? ''));

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
            $otpAttempts = max(0, (int) ($pendingOtp['otp_attempts'] ?? 0));

            if (!is_valid_email_address($pendingEmail)) {
                clear_login_otp($conn, $pendingUserId);
                unset($_SESSION['pending_login_otp']);
                $pendingOtp = null;
                $error = 'This account does not have a valid email address for verification. Please contact the librarian.';
            } elseif (isset($_POST['resend_otp'])) {
                $resendWaitSeconds = get_login_otp_resend_wait_seconds($conn, $pendingUserId);
                if ($resendWaitSeconds > 0) {
                    $error = 'Please wait ' . $resendWaitSeconds . ' seconds before requesting a new verification code.';
                } else {
                    $issued = issue_login_otp($conn, $pendingUserId);
                    $sent = send_login_otp_email($pendingEmail, $pendingFullName, $pendingRole, $issued['code']);

                    if ($sent) {
                        $_SESSION['pending_login_otp']['otp_attempts'] = 0;
                        loginpage_set_flash('info', 'New code sent to ' . $pendingEmail . '.');
                        header('Location: /librarymanage/loginpage.php');
                        exit;
                    } else {
                        $error = 'Unable to resend the verification code right now.';
                    }
                }
            } else {
                $otpCode = trim((string) ($_POST['otp_code'] ?? ''));
                if ($otpCode === '') {
                    $error = 'Enter the verification code sent to your email.';
                } elseif (!verify_login_otp($conn, $pendingUserId, $otpCode)) {
                    $otpAttempts++;
                    $_SESSION['pending_login_otp']['otp_attempts'] = $otpAttempts;
                    $pendingOtp = $_SESSION['pending_login_otp'];

                    if ($otpAttempts >= $otpMaxAttempts) {
                        clear_login_otp($conn, $pendingUserId);
                        unset($_SESSION['pending_login_otp']);
                        $pendingOtp = null;
                        $error = 'Too many invalid verification attempts. Please log in again.';
                    } else {
                        $remainingAttempts = $otpMaxAttempts - $otpAttempts;
                        $error = 'Invalid or expired verification code. ' . $remainingAttempts . ' attempt' . ($remainingAttempts === 1 ? '' : 's') . ' remaining.';
                    }
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
                                    'otp_attempts' => 0,
                                ];
                                loginpage_set_flash('info', 'Code sent to ' . $dbEmail . '.');
                                $stmt->close();
                                header('Location: /librarymanage/loginpage.php');
                                exit;
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
if ($isOtpStep) {
    $otpResendWaitSeconds = get_login_otp_resend_wait_seconds($conn, (int) ($pendingOtp['user_id'] ?? 0));
} else {
    $otpResendWaitSeconds = 0;
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
<div class="auth-shell<?php echo $isOtpStep ? ' auth-shell-otp' : ''; ?>">
  <div class="card auth-card-shell<?php echo $isOtpStep ? ' auth-card-shell-otp' : ''; ?>">
    <div class="split auth-split">
      <div class="auth-panel auth-panel-main<?php echo $isOtpStep ? ' auth-panel-main-otp' : ''; ?>">
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
          <form method="POST" autocomplete="off" class="stack auth-form auth-form-otp">
            <div>
              <label for="otp_code">Verification Code</label>
              <input id="otp_code" type="hidden" name="otp_code" value="<?php echo h(substr($enteredOtpCode, 0, 6)); ?>" data-otp-hidden>
              <div class="otp-code-group" data-otp-group>
                <?php for ($otpIndex = 0; $otpIndex < 6; $otpIndex++): ?>
                  <input
                    type="text"
                    class="otp-code-slot"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="1"
                    autocomplete="one-time-code"
                    aria-label="Verification code digit <?php echo $otpIndex + 1; ?>"
                    value="<?php echo h(substr($enteredOtpCode, $otpIndex, 1)); ?>"
                    data-otp-slot
                  >
                <?php endfor; ?>
              </div>
            </div>

            <div class="inline-actions auth-inline-actions">
              <button type="submit" name="verify_otp" value="1">Verify Code</button>
              <button
                type="submit"
                name="resend_otp"
                value="1"
                class="button secondary"
                formnovalidate
                <?php echo $otpResendWaitSeconds > 0 ? 'disabled aria-disabled="true"' : ''; ?>
                data-resend-button
              >
                Resend Code
              </button>
            </div>
            <div
              class="otp-resend-status<?php echo $otpResendWaitSeconds > 0 ? ' is-active' : ''; ?>"
              data-resend-status
              data-wait-seconds="<?php echo $otpResendWaitSeconds; ?>"
            >
              <?php if ($otpResendWaitSeconds > 0): ?>
                Resend available in <strong data-resend-countdown><?php echo $otpResendWaitSeconds; ?></strong> seconds.
              <?php endif; ?>
            </div>
            <button type="submit" name="back_to_login" value="1" class="auth-back-link" formnovalidate>
              <span aria-hidden="true">←</span>
              <span>Return to Login</span>
            </button>
          </form>
          <div class="footer-note">Verification codes are valid for 10 minutes. For security, a new code can be requested once every <?php echo $otpResendCooldown; ?> seconds.</div>
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

      <?php if (!$isOtpStep): ?>
      <div class="auth-panel auth-panel-side">
        <span class="chip">Quick Access</span>
        <h3 class="auth-side-title">Quick access after sign in</h3>
        <div class="stack auth-role-list">
            <div class="auth-role-item auth-role-item-compact">
              <span class="auth-role-marker" aria-hidden="true"></span>
              <div>
                <strong>Admin</strong>
                <span>Manage users, payments, and reports.</span>
              </div>
            </div>
            <div class="auth-role-item auth-role-item-compact">
              <span class="auth-role-marker" aria-hidden="true"></span>
              <div>
                <strong>Student and Faculty</strong>
                <span>Borrow books and track requests.</span>
              </div>
            </div>
            <div class="auth-role-item auth-role-item-compact">
              <span class="auth-role-marker" aria-hidden="true"></span>
              <div>
                <strong>Librarian</strong>
                <span>Manage circulation, returns, and penalties.</span>
              </div>
            </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if ($isOtpStep): ?>
<script>
(() => {
  const hiddenOtpInput = document.querySelector('[data-otp-hidden]');
  const otpSlots = Array.from(document.querySelectorAll('[data-otp-slot]'));
  const otpForm = hiddenOtpInput ? hiddenOtpInput.closest('form') : null;
  const status = document.querySelector('[data-resend-status]');
  const button = document.querySelector('[data-resend-button]');
  const syncOtpValue = () => {
    if (!hiddenOtpInput || otpSlots.length === 0) {
      return;
    }
    hiddenOtpInput.value = otpSlots.map((slot) => slot.value.replace(/\D/g, '').slice(0, 1)).join('');
  };

  const submitOtpFormIfComplete = () => {
    if (!otpForm || !hiddenOtpInput) {
      return;
    }

    if (hiddenOtpInput.value.length === otpSlots.length) {
      const verifyButton = otpForm.querySelector('button[name="verify_otp"]');
      if (verifyButton) {
        verifyButton.click();
      } else {
        otpForm.submit();
      }
    }
  };

  if (hiddenOtpInput && otpSlots.length > 0) {
    otpSlots.forEach((slot, index) => {
      slot.addEventListener('input', () => {
        slot.value = slot.value.replace(/\D/g, '').slice(0, 1);
        syncOtpValue();
        if (slot.value !== '' && otpSlots[index + 1]) {
          otpSlots[index + 1].focus();
          otpSlots[index + 1].select();
        }
        submitOtpFormIfComplete();
      });

      slot.addEventListener('keydown', (event) => {
        if (event.key === 'Backspace' && slot.value === '' && otpSlots[index - 1]) {
          otpSlots[index - 1].focus();
          otpSlots[index - 1].select();
        }
        if (event.key === 'ArrowLeft' && otpSlots[index - 1]) {
          event.preventDefault();
          otpSlots[index - 1].focus();
          otpSlots[index - 1].select();
        }
        if (event.key === 'ArrowRight' && otpSlots[index + 1]) {
          event.preventDefault();
          otpSlots[index + 1].focus();
          otpSlots[index + 1].select();
        }
      });

      slot.addEventListener('focus', () => {
        slot.select();
      });

      slot.addEventListener('paste', (event) => {
        event.preventDefault();
        const pastedValue = (event.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, otpSlots.length);
        if (pastedValue === '') {
          return;
        }
        otpSlots.forEach((otpSlot, otpIndex) => {
          otpSlot.value = pastedValue[otpIndex] ?? '';
        });
        syncOtpValue();
        const targetIndex = Math.min(pastedValue.length, otpSlots.length) - 1;
        if (targetIndex >= 0) {
          otpSlots[targetIndex].focus();
          otpSlots[targetIndex].select();
        }
        submitOtpFormIfComplete();
      });
    });

    syncOtpValue();
    const firstEmptySlot = otpSlots.find((slot) => slot.value === '');
    (firstEmptySlot || otpSlots[0]).focus();
    (firstEmptySlot || otpSlots[0]).select();
  }

  if (!status || !button) {
    return;
  }

  let remaining = Number(status.dataset.waitSeconds || '0');
  if (!Number.isFinite(remaining) || remaining <= 0) {
    return;
  }

  const render = () => {
    const countdown = status.querySelector('[data-resend-countdown]');
    if (remaining > 0) {
      status.classList.add('is-active');
      status.innerHTML = 'Resend available in <strong data-resend-countdown>' + remaining + '</strong> seconds.';
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');
      return;
    }

    status.classList.remove('is-active');
    status.textContent = 'You can request a new verification code now.';
    button.disabled = false;
    button.removeAttribute('aria-disabled');
  };

  render();
  const timer = window.setInterval(() => {
    remaining -= 1;
    render();
    if (remaining <= 0) {
      window.clearInterval(timer);
    }
  }, 1000);
})();
</script>
<?php endif; ?>
</body>
</html>

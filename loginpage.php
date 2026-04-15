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
        header('Location: ' . app_url('loginpage.php'));
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
                $otpResendLimit = library_rate_limit_attempt(
                    'login_otp_resend:' . library_rate_limit_client_ip() . ':' . $pendingUserId,
                    5,
                    900
                );
                if (!$otpResendLimit['allowed']) {
                    $error = 'Too many verification code resend requests. Please wait ' . $otpResendLimit['retry_after'] . ' seconds.';
                } else {
                $resendWaitSeconds = get_login_otp_resend_wait_seconds($conn, $pendingUserId);
                if ($resendWaitSeconds > 0) {
                    $error = 'Please wait ' . $resendWaitSeconds . ' seconds before requesting a new verification code.';
                } else {
                    $issued = issue_login_otp($conn, $pendingUserId);
                    $queued = enqueue_login_otp_email_job($conn, $pendingEmail, $pendingFullName, $pendingRole, $issued['code']);

                    if ($queued) {
                        process_pending_email_jobs($conn, 1);
                        $_SESSION['pending_login_otp']['otp_attempts'] = 0;
                        loginpage_set_flash('info', 'New code is being sent to ' . $pendingEmail . '.');
                        header('Location: ' . app_url('loginpage.php'));
                        exit;
                    } else {
                        $error = 'Unable to resend the verification code right now.';
                    }
                }
                }
            } else {
                $otpVerifyLimit = library_rate_limit_attempt(
                    'login_otp_verify:' . library_rate_limit_client_ip() . ':' . $pendingUserId,
                    8,
                    600
                );
                if (!$otpVerifyLimit['allowed']) {
                    $error = 'Too many verification attempts. Please wait ' . $otpVerifyLimit['retry_after'] . ' seconds before trying again.';
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
                    library_rate_limit_clear('login_otp_verify:' . library_rate_limit_client_ip() . ':' . $pendingUserId);
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
        }
    } else {
        $login = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($login === '' || $password === '') {
            $error = 'Please complete all fields.';
        } else {
            $loginRateLimitKey = 'login_password:' . library_rate_limit_client_ip() . ':' . library_rate_limit_normalize_key($login);
            $loginRateLimit = library_rate_limit_attempt($loginRateLimitKey, 10, 900);
            if (!$loginRateLimit['allowed']) {
                $error = 'Too many login attempts. Please wait ' . $loginRateLimit['retry_after'] . ' seconds before trying again.';
            } else {
            $stmt = $conn->prepare("
                SELECT id, fullname, username, email, password, role, account_status, password_setup_required
                FROM users
                WHERE (username = ? OR email = ?)
                LIMIT 1
            ");
            $stmt->bind_param('ss', $login, $login);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows === 1) {
                $stmt->bind_result($id, $dbFullName, $dbUsername, $dbEmail, $dbPassword, $dbRole, $dbAccountStatus, $dbPasswordSetupRequired);
                $stmt->fetch();

                if ((string) $dbAccountStatus === 'inactive') {
                    $error = 'This account has been deactivated. Please contact the administrator or librarian for assistance.';
                } elseif ((int) $dbPasswordSetupRequired === 1) {
                    $error = 'This account still needs password setup. Use the invitation email link or ask the administrator to resend it.';
                } else {
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
                                library_rate_limit_clear($loginRateLimitKey);
                                $issued = issue_login_otp($conn, (int) $id);
                                $queued = enqueue_login_otp_email_job($conn, $dbEmail, $dbFullName, $dbRole, $issued['code']);

                                if ($queued) {
                                    process_pending_email_jobs($conn, 1);
                                    $_SESSION['pending_login_otp'] = [
                                        'user_id' => (int) $id,
                                        'fullname' => $dbFullName,
                                        'username' => $dbUsername,
                                        'email' => $dbEmail,
                                        'role' => $dbRole,
                                        'otp_attempts' => 0,
                                    ];
                                    loginpage_set_flash('info', 'Verification code is being sent to ' . $dbEmail . '.');
                                    $stmt->close();
                                    header('Location: ' . app_url('loginpage.php'));
                                    exit;
                                } else {
                                    clear_login_otp($conn, (int) $id);
                                    $error = 'Unable to send the verification code right now.';
                                }
                            }
                        } else {
                            library_rate_limit_clear($loginRateLimitKey);
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
                }
            } else {
                $error = 'Invalid credentials.';
            }

            $stmt->close();
            }
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
<link rel="stylesheet" href="assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body class="auth-page">
<div class="auth-shell<?php echo $isOtpStep ? ' auth-shell-otp' : ''; ?>">
  <div class="auth-card-shell<?php echo $isOtpStep ? ' auth-card-shell-otp' : ''; ?>">
    <div class="split auth-split">
      <div class="auth-panel auth-panel-main<?php echo $isOtpStep ? ' auth-panel-main-otp' : ''; ?>">
        <p class="muted auth-kicker"><?php echo $isOtpStep ? 'Account Verification' : 'Secure Access'; ?></p>
        <h2 class="auth-title"><?php echo $isOtpStep ? 'Verify Login Code' : 'Library Login'; ?></h2>
        <p class="muted auth-intro">
          <?php if ($isOtpStep): ?>
            Enter the 6-digit code sent to <?php echo h((string) ($pendingOtp['email'] ?? 'your email')); ?> to complete your login.
          <?php else: ?>
            Sign in with your email or username and password. New accounts must set a password from the invitation email first. Student and faculty accounts receive a one-time verification code after login.
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
              <div class="password-field" data-password-field>
                <input
                  id="password"
                  class="password-field-input"
                  type="password"
                  name="password"
                  placeholder="Enter password"
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

            <div class="auth-inline-note">
              <a href="<?php echo h(app_url('forgot_password.php')); ?>">Forgot Password?</a>
            </div>

            <div class="inline-actions">
              <button type="submit">Login</button>
              <a class="button secondary" href="<?php echo h(app_url('index.php')); ?>">Back Home</a>
            </div>
          </form>
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
<?php if ($isOtpStep): ?>
<script src="<?php echo h(app_url('assets/login_email_queue_worker.js')); ?>"></script>
<?php endif; ?>
</body>
</html>

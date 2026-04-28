<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$password = trim((string) ($_POST['password'] ?? ''));
$confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));
$error = '';
$info = '';
$enteredOtpCode = preg_replace('/\D+/', '', (string) ($_POST['otp_code'] ?? ''));
$tokenRecord = $token !== '' ? find_password_setup_token($conn, $token, 'password_reset') : null;
$pendingResetOtp = is_array($_SESSION['pending_password_reset_otp'] ?? null) ? $_SESSION['pending_password_reset_otp'] : null;
$otpResendCooldown = login_otp_resend_cooldown_seconds();
$otpMaxAttempts = login_otp_max_attempts();

if ($pendingResetOtp && (string) ($pendingResetOtp['token'] ?? '') !== $token) {
    unset($_SESSION['pending_password_reset_otp']);
    $pendingResetOtp = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['back_to_reset_form'])) {
        if ($pendingResetOtp) {
            clear_login_otp($conn, (int) ($pendingResetOtp['user_id'] ?? 0));
        }
        unset($_SESSION['pending_password_reset_otp']);
        header('Location: ' . app_url('reset_password.php?token=' . urlencode($token)));
        exit;
    } elseif (isset($_POST['verify_otp']) || isset($_POST['resend_otp'])) {
        if (!$pendingResetOtp) {
            $error = 'Your verification session has expired. Please request the password reset step again.';
        } else {
            $pendingUserId = (int) ($pendingResetOtp['user_id'] ?? 0);
            $pendingEmail = (string) ($pendingResetOtp['email'] ?? '');
            $pendingFullName = (string) ($pendingResetOtp['fullname'] ?? '');
            $pendingRole = (string) ($pendingResetOtp['role'] ?? '');
            $pendingToken = (string) ($pendingResetOtp['token'] ?? '');
            $pendingPassword = (string) ($pendingResetOtp['password'] ?? '');
            $otpAttempts = max(0, (int) ($pendingResetOtp['otp_attempts'] ?? 0));

            if (!is_valid_email_address($pendingEmail)) {
                clear_login_otp($conn, $pendingUserId);
                unset($_SESSION['pending_password_reset_otp']);
                $pendingResetOtp = null;
                $error = 'This account does not have a valid email address for verification.';
            } elseif (isset($_POST['resend_otp'])) {
                $resendWaitSeconds = get_login_otp_resend_wait_seconds($conn, $pendingUserId);
                if ($resendWaitSeconds > 0) {
                    $error = 'Please wait ' . $resendWaitSeconds . ' seconds before requesting a new verification code.';
                } else {
                    $issued = issue_login_otp($conn, $pendingUserId);
                    $queued = enqueue_security_otp_email_job($conn, $pendingEmail, $pendingFullName, $pendingRole, $issued['code'], 'password_reset');
                    if ($queued) {
                        process_pending_email_jobs($conn, 1);
                        $_SESSION['pending_password_reset_otp']['otp_attempts'] = 0;
                        $pendingResetOtp = $_SESSION['pending_password_reset_otp'];
                        $info = 'A new verification code is being sent to ' . $pendingEmail . '.';
                    } else {
                        $error = 'Unable to resend the verification code right now.';
                    }
                }
            } else {
                $freshTokenRecord = $pendingToken !== '' ? find_password_setup_token($conn, $pendingToken, 'password_reset') : null;
                if (!$freshTokenRecord) {
                    clear_login_otp($conn, $pendingUserId);
                    unset($_SESSION['pending_password_reset_otp']);
                    $pendingResetOtp = null;
                    $error = 'This password reset link is invalid or has already expired. Request a new reset link from the login page.';
                } elseif ($enteredOtpCode === '') {
                    $error = 'Enter the verification code sent to your email.';
                } elseif (!verify_login_otp($conn, $pendingUserId, $enteredOtpCode)) {
                    $otpAttempts++;
                    $_SESSION['pending_password_reset_otp']['otp_attempts'] = $otpAttempts;
                    $pendingResetOtp = $_SESSION['pending_password_reset_otp'];
                    if ($otpAttempts >= $otpMaxAttempts) {
                        clear_login_otp($conn, $pendingUserId);
                        unset($_SESSION['pending_password_reset_otp']);
                        $pendingResetOtp = null;
                        $error = 'Too many invalid verification attempts. Request the password reset step again.';
                    } else {
                        $remainingAttempts = $otpMaxAttempts - $otpAttempts;
                        $error = 'Invalid or expired verification code. ' . $remainingAttempts . ' attempt' . ($remainingAttempts === 1 ? '' : 's') . ' remaining.';
                    }
                } else {
                    $completed = complete_password_setup(
                        $conn,
                        (int) ($freshTokenRecord['user_id'] ?? 0),
                        (int) ($freshTokenRecord['id'] ?? 0),
                        $pendingPassword,
                        'password_reset'
                    );

                    clear_login_otp($conn, $pendingUserId);
                    unset($_SESSION['pending_password_reset_otp']);
                    $pendingResetOtp = null;

                    if ($completed) {
                        revoke_all_trusted_devices($conn, $pendingUserId);
                        clear_current_trusted_device_cookie();
                        audit_log($conn, 'auth.password_reset.complete', [
                            'user_id' => (int) ($freshTokenRecord['user_id'] ?? 0),
                            'username' => (string) ($freshTokenRecord['username'] ?? ''),
                            'role' => (string) ($freshTokenRecord['role'] ?? ''),
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
        }
    } elseif (!$tokenRecord) {
        $error = 'This password reset link is invalid or has already expired. Request a new reset link from the login page.';
    } elseif ($password === '' || $confirmPassword === '') {
        $error = 'Enter and confirm the new password.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $userEmail = trim((string) ($tokenRecord['email'] ?? ''));
        if (!is_valid_email_address($userEmail)) {
            $error = 'This account does not have a valid email address for verification.';
        } else {
            $issued = issue_login_otp($conn, (int) ($tokenRecord['user_id'] ?? 0));
            $queued = enqueue_security_otp_email_job(
                $conn,
                $userEmail,
                (string) ($tokenRecord['fullname'] ?? ''),
                (string) ($tokenRecord['role'] ?? ''),
                (string) ($issued['code'] ?? ''),
                'password_reset'
            );

            if ($queued) {
                process_pending_email_jobs($conn, 1);
                $_SESSION['pending_password_reset_otp'] = [
                    'user_id' => (int) ($tokenRecord['user_id'] ?? 0),
                    'fullname' => (string) ($tokenRecord['fullname'] ?? ''),
                    'email' => $userEmail,
                    'role' => (string) ($tokenRecord['role'] ?? ''),
                    'username' => (string) ($tokenRecord['username'] ?? ''),
                    'token' => $token,
                    'password' => $password,
                    'otp_attempts' => 0,
                ];
                header('Location: ' . app_url('reset_password.php?token=' . urlencode($token)));
                exit;
            }

            clear_login_otp($conn, (int) ($tokenRecord['user_id'] ?? 0));
            $error = 'Unable to send the verification code right now. Please try again.';
        }
    }
}

if ($tokenRecord && $info === '') {
    $info = 'Resetting password for ' . (string) ($tokenRecord['fullname'] ?? 'your account') . ' (' . role_label((string) ($tokenRecord['role'] ?? '')) . ').';
}

$isOtpStep = $pendingResetOtp !== null;
$otpResendWaitSeconds = $isOtpStep ? get_login_otp_resend_wait_seconds($conn, (int) ($pendingResetOtp['user_id'] ?? 0)) : 0;
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
        <h2 class="auth-title"><?php echo $isOtpStep ? 'Verify Password Reset' : 'Set a New Password'; ?></h2>
        <p class="muted auth-intro">
          <?php if ($isOtpStep): ?>
            Enter the 6-digit code sent to <?php echo h((string) ($pendingResetOtp['email'] ?? 'your email')); ?> to finish resetting your password.
          <?php else: ?>
            Choose a new password for your library account. After saving, use it the next time you log in.
          <?php endif; ?>
        </p>

        <?php if ($info !== ''): ?>
          <div class="notice info"><?php echo h($info); ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="notice error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if ($isOtpStep): ?>
          <form method="post" class="stack auth-form auth-form-otp" autocomplete="off">
            <input type="hidden" name="token" value="<?php echo h($token); ?>">
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
            <button type="submit" name="back_to_reset_form" value="1" class="auth-back-link" formnovalidate>
              <span aria-hidden="true">&larr;</span>
              <span>Return to Reset Form</span>
            </button>
          </form>
          <div class="footer-note">Verification codes are valid for 10 minutes. For security, a new code can be requested once every <?php echo $otpResendCooldown; ?> seconds.</div>
        <?php elseif ($tokenRecord): ?>
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
              <strong>Email verification</strong>
              <span>Sensitive password recovery now asks for a one-time code before the new password is saved.</span>
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

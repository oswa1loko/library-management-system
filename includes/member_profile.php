<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_roles(['student', 'faculty']);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) $_SESSION['role'];
$msg = '';
$msgType = 'success';
$pendingPasswordOtp = is_array($_SESSION['member_profile_password_otp'] ?? null) ? $_SESSION['member_profile_password_otp'] : null;
$otpResendCooldown = login_otp_resend_cooldown_seconds();
$otpMaxAttempts = login_otp_max_attempts();
$enteredOtpCode = preg_replace('/\D+/', '', (string) ($_POST['otp_code'] ?? ''));

function upload_member_profile_photo(array $file, string $existingPath = ''): array
{
    if (empty($file['name'])) {
        return ['path' => $existingPath, 'error' => ''];
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowed, true)) {
        return ['path' => $existingPath, 'error' => 'Only JPG, JPEG, PNG, and WEBP files are allowed.'];
    }

    if ((int) ($file['size'] ?? 0) > 3 * 1024 * 1024) {
        return ['path' => $existingPath, 'error' => 'Profile photo must be 3MB or smaller.'];
    }

    $directory = __DIR__ . '/../uploads/profile_photos';
    if (!ensure_upload_directory($directory)) {
        return ['path' => $existingPath, 'error' => 'Profile photo folder could not be created.'];
    }

    $filename = 'profile_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $fullPath = $directory . '/' . $filename;
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $fullPath)) {
        return ['path' => $existingPath, 'error' => 'Profile photo upload failed.'];
    }

    if ($existingPath !== '') {
        remove_relative_file($existingPath);
    }

    return ['path' => 'uploads/profile_photos/' . $filename, 'error' => ''];
}

$stmt = $conn->prepare("SELECT id, fullname, email, username, password, role, course, created_at, profile_photo_path FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$profile) {
    http_response_code(404);
    exit('Profile not found.');
}

$fullName = trim((string) ($profile['fullname'] ?? ''));
$email = trim((string) ($profile['email'] ?? ''));
$course = trim((string) ($profile['course'] ?? ''));
$profilePhotoPath = trim((string) ($profile['profile_photo_path'] ?? ''));
$initialsSource = preg_split('/\s+/', trim($fullName)) ?: [];
$initials = '';
foreach ($initialsSource as $part) {
    if ($part === '') {
        continue;
    }
    $initials .= strtoupper(substr($part, 0, 1));
    if (strlen($initials) >= 2) {
        break;
    }
}
if ($initials === '') {
    $initials = strtoupper(substr((string) ($profile['username'] ?? 'U'), 0, 2));
}

$isOtpStep = $pendingPasswordOtp && (int) ($pendingPasswordOtp['user_id'] ?? 0) === $userId;
if (!$isOtpStep && $pendingPasswordOtp) {
    unset($_SESSION['member_profile_password_otp']);
    $pendingPasswordOtp = null;
}

if (isset($_POST['back_to_password_form'])) {
    if ($pendingPasswordOtp) {
        clear_login_otp($conn, (int) ($pendingPasswordOtp['user_id'] ?? 0));
    }
    unset($_SESSION['member_profile_password_otp']);
    header('Location: ' . (string) ($_SERVER['REQUEST_URI'] ?? app_url($role . '/profile.php')));
    exit;
} elseif (isset($_POST['verify_otp']) || isset($_POST['resend_otp'])) {
    if (!$isOtpStep || !$pendingPasswordOtp) {
        $msg = 'Your password update verification session has expired.';
        $msgType = 'error';
    } else {
        $pendingEmail = (string) ($pendingPasswordOtp['email'] ?? '');
        $pendingFullName = (string) ($pendingPasswordOtp['fullname'] ?? '');
        $pendingRole = (string) ($pendingPasswordOtp['role'] ?? '');
        $pendingNewPassword = (string) ($pendingPasswordOtp['new_password'] ?? '');
        $otpAttempts = max(0, (int) ($pendingPasswordOtp['otp_attempts'] ?? 0));

        if (!is_valid_email_address($pendingEmail)) {
            clear_login_otp($conn, $userId);
            unset($_SESSION['member_profile_password_otp']);
            $pendingPasswordOtp = null;
            $isOtpStep = false;
            $msg = 'This account does not have a valid email address for verification.';
            $msgType = 'error';
        } elseif (isset($_POST['resend_otp'])) {
            $resendWaitSeconds = get_login_otp_resend_wait_seconds($conn, $userId);
            if ($resendWaitSeconds > 0) {
                $msg = 'Please wait ' . $resendWaitSeconds . ' seconds before requesting a new verification code.';
                $msgType = 'error';
            } else {
                $issued = issue_login_otp($conn, $userId);
                $queued = enqueue_security_otp_email_job($conn, $pendingEmail, $pendingFullName, $pendingRole, $issued['code'], 'password_change');
                if ($queued) {
                    process_pending_email_jobs($conn, 1);
                    $_SESSION['member_profile_password_otp']['otp_attempts'] = 0;
                    $pendingPasswordOtp = $_SESSION['member_profile_password_otp'];
                    $msg = 'A new verification code is being sent to ' . $pendingEmail . '.';
                    $msgType = 'success';
                } else {
                    $msg = 'Unable to resend the verification code right now.';
                    $msgType = 'error';
                }
            }
        } elseif ($enteredOtpCode === '') {
            $msg = 'Enter the verification code sent to your email.';
            $msgType = 'error';
        } elseif (!verify_login_otp($conn, $userId, $enteredOtpCode)) {
            $otpAttempts++;
            $_SESSION['member_profile_password_otp']['otp_attempts'] = $otpAttempts;
            $pendingPasswordOtp = $_SESSION['member_profile_password_otp'];
            if ($otpAttempts >= $otpMaxAttempts) {
                clear_login_otp($conn, $userId);
                unset($_SESSION['member_profile_password_otp']);
                $pendingPasswordOtp = null;
                $isOtpStep = false;
                $msg = 'Too many invalid verification attempts. Start the password update again.';
            } else {
                $remainingAttempts = $otpMaxAttempts - $otpAttempts;
                $msg = 'Invalid or expired verification code. ' . $remainingAttempts . ' attempt' . ($remainingAttempts === 1 ? '' : 's') . ' remaining.';
            }
            $msgType = 'error';
        } else {
            $newHash = password_hash($pendingNewPassword, PASSWORD_DEFAULT);
            $updatePasswordStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
            $updatePasswordStmt->bind_param('si', $newHash, $userId);
            $ok = $updatePasswordStmt->execute();
            $updatePasswordStmt->close();

            clear_login_otp($conn, $userId);
            unset($_SESSION['member_profile_password_otp']);
            $pendingPasswordOtp = null;
            $isOtpStep = false;

            if ($ok) {
                revoke_all_trusted_devices($conn, $userId);
                clear_current_trusted_device_cookie();
                $profile['password'] = $newHash;
                $msg = 'Password updated successfully.';
                $msgType = 'success';
            } else {
                $msg = 'Unable to update password right now.';
                $msgType = 'error';
            }
        }
    }
} elseif (isset($_POST['change_password'])) {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $storedPassword = (string) ($profile['password'] ?? '');

    $currentMatches = $currentPassword !== ''
        && (password_verify($currentPassword, $storedPassword) || md5($currentPassword) === $storedPassword);

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $msg = 'Complete all password fields.';
        $msgType = 'error';
    } elseif (!$currentMatches) {
        $msg = 'Current password is incorrect.';
        $msgType = 'error';
    } elseif (strlen($newPassword) < 8) {
        $msg = 'New password must be at least 8 characters.';
        $msgType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $msg = 'New password and confirmation do not match.';
        $msgType = 'error';
    } elseif (!is_valid_email_address($email)) {
        $msg = 'This account does not have a valid email address for verification.';
        $msgType = 'error';
    } else {
        $issued = issue_login_otp($conn, $userId);
        $queued = enqueue_security_otp_email_job($conn, $email, $fullName, $role, $issued['code'], 'password_change');

        if ($queued) {
            process_pending_email_jobs($conn, 1);
            $_SESSION['member_profile_password_otp'] = [
                'user_id' => $userId,
                'fullname' => $fullName,
                'email' => $email,
                'role' => $role,
                'new_password' => $newPassword,
                'otp_attempts' => 0,
            ];
            $pendingPasswordOtp = $_SESSION['member_profile_password_otp'];
            $isOtpStep = true;
            $msg = 'A verification code is being sent to ' . $email . ' before we update your password.';
            $msgType = 'success';
        } else {
            clear_login_otp($conn, $userId);
            $msg = 'Unable to send the verification code right now.';
            $msgType = 'error';
        }
    }
}

if ($role === 'student' && isset($_POST['save_profile_photo'])) {
    $photoUpload = upload_member_profile_photo($_FILES['profile_photo'] ?? [], $profilePhotoPath);
    if (($photoUpload['error'] ?? '') !== '') {
        $msg = (string) $photoUpload['error'];
        $msgType = 'error';
    } else {
        $newPhotoPath = (string) ($photoUpload['path'] ?? $profilePhotoPath);
        $updateStmt = $conn->prepare("UPDATE users SET profile_photo_path = ? WHERE id = ? LIMIT 1");
        $updateStmt->bind_param('si', $newPhotoPath, $userId);
        $ok = $updateStmt->execute();
        $updateStmt->close();

        if ($ok) {
            $profile['profile_photo_path'] = $newPhotoPath;
            $profilePhotoPath = $newPhotoPath;
            $msg = 'Profile photo updated successfully.';
        } else {
            $msg = 'Unable to update profile photo right now.';
            $msgType = 'error';
        }
    }
}

if ($role === 'student' && isset($_POST['remove_profile_photo'])) {
    if ($profilePhotoPath === '') {
        $msg = 'No profile photo to remove.';
        $msgType = 'error';
    } else {
        $updateStmt = $conn->prepare("UPDATE users SET profile_photo_path = NULL WHERE id = ? LIMIT 1");
        $updateStmt->bind_param('i', $userId);
        $ok = $updateStmt->execute();
        $updateStmt->close();

        if ($ok) {
            remove_relative_file($profilePhotoPath);
            $profilePhotoPath = '';
            $profile['profile_photo_path'] = null;
            $msg = 'Profile photo removed.';
        } else {
            $msg = 'Unable to remove profile photo right now.';
            $msgType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title($role, 'Profile')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="<?php echo h($role); ?>-profile">
  <aside class="panel member-sidebar">
    <div class="member-sidebar-head">
      <div class="member-sidebar-toggle" aria-hidden="true">
        <span class="member-sidebar-label">Main Menu</span>
      </div>
    </div>
    <nav class="member-sidebar-nav">
      <p class="member-sidebar-group-label">Overview</p>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/dashboard.php" data-tooltip="Dashboard">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Dashboard</span>
      </a>
      <p class="member-sidebar-group-label">Library</p>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/books.php" data-tooltip="Books">
        <span class="dashboard-icon icon-books" aria-hidden="true"></span>
        <span class="member-sidebar-label">Books</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/catalog.php" data-tooltip="Catalog">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">Catalog</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/ebooks.php" data-tooltip="eBooks">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">eBooks</span>
      </a>
      <p class="member-sidebar-group-label">My Activity</p>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/borrow_return.php" data-tooltip="Returns">
        <span class="dashboard-icon icon-checklist" aria-hidden="true"></span>
        <span class="member-sidebar-label">Returns</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/book_incidents.php" data-tooltip="Book Incidents">
        <span class="dashboard-icon icon-notes" aria-hidden="true"></span>
        <span class="member-sidebar-label">Book Incidents</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/tracking.php" data-tooltip="Records Tracking">
        <span class="dashboard-icon icon-ledger" aria-hidden="true"></span>
        <span class="member-sidebar-label">Records Tracking</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/payment_upload.php" data-tooltip="Payments">
        <span class="dashboard-icon icon-payments" aria-hidden="true"></span>
        <span class="member-sidebar-label">Payments</span>
      </a>
    </nav>
    <p class="member-sidebar-section member-sidebar-label">Account</p>
    <div class="topbar-nav member-sidebar-utilities">
      <a class="member-sidebar-link is-active" href="/librarymanage/<?php echo h($role); ?>/profile.php" data-tooltip="Profile">
        <span class="dashboard-icon icon-edit" aria-hidden="true"></span>
        <span class="member-sidebar-label">Profile</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/index.php" data-tooltip="Portal Home">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">Portal Home</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/logout.php" data-tooltip="Logout">
        <span class="dashboard-icon icon-logout" aria-hidden="true"></span>
        <span class="member-sidebar-label">Logout</span>
      </a>
    </div>
  </aside>

  <div class="member-main">
    <div class="topbar topbar-member">
      <div>
        <p class="topbar-kicker"><?php echo h(role_label($role)); ?> Portal</p>
        <h1><?php echo h(role_label($role)); ?> Profile</h1>
        <p>Review account details and manage login credentials</p>
      </div>
    </div>

    <div class="stack">
      <?php if ($msg !== ''): ?>
        <div class="notice <?php echo $msgType === 'error' ? 'error' : 'success'; ?>"><?php echo h($msg); ?></div>
      <?php endif; ?>

      <section class="panel member-profile-hero">
        <div class="member-profile-hero-main">
          <?php if ($profilePhotoPath !== ''): ?>
            <img class="member-profile-avatar member-profile-avatar-image" src="/librarymanage/<?php echo h($profilePhotoPath); ?>" alt="<?php echo h((string) ($profile['fullname'] ?? 'Profile')); ?>">
          <?php else: ?>
            <div class="member-profile-avatar" aria-hidden="true"><?php echo h($initials); ?></div>
          <?php endif; ?>
          <div class="member-profile-copy">
            <p class="muted eyebrow-compact stack-copy">Account Profile</p>
            <h2 class="member-profile-name"><?php echo h((string) ($profile['fullname'] ?? 'Member')); ?></h2>
            <div class="inline-actions chips-row member-profile-chips">
              <span class="chip"><?php echo h(role_label((string) ($profile['role'] ?? ''))); ?></span>
              <?php if ($role === 'student' && $course !== ''): ?>
                <span class="chip"><?php echo h($course); ?></span>
              <?php endif; ?>
              <span class="chip">@<?php echo h((string) ($profile['username'] ?? '-')); ?></span>
            </div>
            <p class="muted member-profile-copyline"><?php echo h((string) ($profile['email'] ?? '-')); ?></p>
          </div>
        </div>
        <div class="member-profile-meta">
          <div class="member-profile-meta-card">
            <span class="muted">Member since</span>
            <strong><?php echo h(format_display_datetime((string) ($profile['created_at'] ?? ''), '-')); ?></strong>
          </div>
          <div class="member-profile-meta-card">
            <span class="muted">Access</span>
            <strong><?php echo h(role_label((string) ($profile['role'] ?? ''))); ?> Account</strong>
          </div>
          <?php if ($role === 'student'): ?>
            <div class="member-profile-meta-card">
              <span class="muted">Program</span>
              <strong><?php echo h($course !== '' ? $course : 'Not set'); ?></strong>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <div class="grid cards member-profile-grid">
        <div class="panel member-profile-panel member-profile-panel-main">
          <div class="card-head">
            <div class="dashboard-icon icon-edit" aria-hidden="true"></div>
            <div>
              <span class="chip">Details</span>
              <h3 class="heading-top-md">Personal Information</h3>
            </div>
          </div>
          <p class="muted copy-bottom">Profile details are view-only on this page. <?php echo $role === 'student' ? 'Students can still update their profile photo below.' : 'Faculty accounts are fully read-only here.'; ?></p>
          <div class="grid form member-profile-form-grid">
            <div>
              <label for="profile_fullname">Full name</label>
              <input id="profile_fullname" value="<?php echo h($fullName); ?>" disabled>
            </div>
            <div>
              <label for="profile_email">Email</label>
              <input id="profile_email" type="email" value="<?php echo h($email); ?>" disabled>
            </div>
            <?php if ($role === 'student'): ?>
              <div>
                <label for="profile_course">Program</label>
                <input id="profile_course" value="<?php echo h($course); ?>" disabled>
              </div>
            <?php endif; ?>
            <div>
              <label for="profile_username">Username</label>
              <input id="profile_username" value="<?php echo h((string) ($profile['username'] ?? '')); ?>" disabled>
            </div>
            <div>
              <label for="profile_role">Role</label>
              <input id="profile_role" value="<?php echo h(role_label((string) ($profile['role'] ?? ''))); ?>" disabled>
            </div>
            <div class="member-profile-form-span">
              <label>Profile photo</label>
              <input value="<?php echo h($profilePhotoPath !== '' ? 'Profile photo uploaded' : 'No profile photo uploaded'); ?>" disabled>
            </div>
          </div>
          <?php if ($role === 'student'): ?>
            <form method="post" enctype="multipart/form-data" class="stack member-profile-form flow-top-md">
              <div class="member-profile-form-span">
                <label for="profile_photo">Profile photo</label>
                <input id="profile_photo" type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp" required>
                <span class="muted meta-top-sm">Accepted: JPG, JPEG, PNG, WEBP up to 3MB.</span>
              </div>
              <div class="inline-actions member-workspace-actions">
                <button type="submit" name="save_profile_photo" value="1">Update Photo</button>
                <?php if ($profilePhotoPath !== ''): ?>
                  <button type="submit" name="remove_profile_photo" value="1" class="button secondary">Remove Photo</button>
                <?php endif; ?>
              </div>
            </form>
          <?php endif; ?>
        </div>

        <div class="panel member-profile-panel member-profile-panel-side">
          <div class="card-head">
            <div class="dashboard-icon icon-edit" aria-hidden="true"></div>
            <div>
              <span class="chip">Security</span>
              <h3 class="heading-top-md">Password & Access</h3>
            </div>
          </div>
          <p class="muted copy-bottom">Use a strong password with at least 8 characters. Update it any time you think your account may be at risk.</p>
          <div class="empty-state member-profile-note">
            <?php echo $isOtpStep ? 'Enter the code from your email to finish this password update.' : 'Password changes require email verification before saving.'; ?>
          </div>
          <?php if ($isOtpStep): ?>
            <?php $otpResendWaitSeconds = get_login_otp_resend_wait_seconds($conn, $userId); ?>
            <form method="post" class="stack member-profile-form auth-form-otp" autocomplete="off">
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
              <div class="inline-actions member-workspace-actions">
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
              <div class="inline-actions member-workspace-actions">
                <button type="submit" name="back_to_password_form" value="1" class="button secondary" formnovalidate>Back</button>
                <span class="muted">A new code can be requested every <?php echo $otpResendCooldown; ?> seconds.</span>
              </div>
            </form>
          <?php else: ?>
            <form method="post" class="stack member-profile-form">
              <div class="grid form member-profile-form-grid">
                <div>
                  <label for="current_password">Current password</label>
                  <div class="password-field" data-password-field>
                    <input
                      id="current_password"
                      class="password-field-input"
                      type="password"
                      name="current_password"
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
                  <label for="new_password">New password</label>
                  <div class="password-field" data-password-field>
                    <input
                      id="new_password"
                      class="password-field-input"
                      type="password"
                      name="new_password"
                      minlength="8"
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
                <div class="member-profile-form-span">
                  <label for="confirm_password">Confirm new password</label>
                  <div class="password-field" data-password-field>
                    <input
                      id="confirm_password"
                      class="password-field-input"
                      type="password"
                      name="confirm_password"
                      minlength="8"
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
              </div>
              <div class="inline-actions member-workspace-actions">
                <button type="submit" name="change_password" value="1">Update Password</button>
                <span class="muted">Make sure the new password is easy for you to remember but hard to guess.</span>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
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
<script src="/librarymanage/assets/login_email_queue_worker.js"></script>
<?php endif; ?>
</body>
</html>

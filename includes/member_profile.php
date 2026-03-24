<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_roles(['student', 'faculty']);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) $_SESSION['role'];
$msg = '';
$msgType = 'success';

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

if (isset($_POST['change_password'])) {
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
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updatePasswordStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ? LIMIT 1");
        $updatePasswordStmt->bind_param('si', $newHash, $userId);
        $ok = $updatePasswordStmt->execute();
        $updatePasswordStmt->close();

        if ($ok) {
            $profile['password'] = $newHash;
            $msg = 'Password updated successfully.';
        } else {
            $msg = 'Unable to update password right now.';
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
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/dashboard.php" data-tooltip="Dashboard">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Dashboard</span>
      </a>
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
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/borrow_return.php" data-tooltip="Returns">
        <span class="dashboard-icon icon-checklist" aria-hidden="true"></span>
        <span class="member-sidebar-label">Returns</span>
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
      <a class="member-sidebar-link" href="/librarymanage/index.php" data-tooltip="Home">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">Home</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/logout.php" data-tooltip="Logout">
        <span class="dashboard-icon icon-logout" aria-hidden="true"></span>
        <span class="member-sidebar-label">Logout</span>
      </a>
    </div>
  </aside>

  <div class="member-main">
    <div class="topbar">
      <div>
        <h1><?php echo h(role_label($role)); ?> Portal</h1>
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
              <span class="muted">Course</span>
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
                <label for="profile_course">Course</label>
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
            Password changes take effect immediately after saving.
          </div>
          <form method="post" class="stack member-profile-form">
            <div class="grid form member-profile-form-grid">
              <div>
                <label for="current_password">Current password</label>
                <input id="current_password" type="password" name="current_password" required>
              </div>
              <div>
                <label for="new_password">New password</label>
                <input id="new_password" type="password" name="new_password" minlength="8" required>
              </div>
              <div class="member-profile-form-span">
                <label for="confirm_password">Confirm new password</label>
                <input id="confirm_password" type="password" name="confirm_password" minlength="8" required>
              </div>
            </div>
            <div class="inline-actions member-workspace-actions">
              <button type="submit" name="change_password" value="1">Update Password</button>
              <span class="muted">Make sure the new password is easy for you to remember but hard to guess.</span>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
</body>
</html>

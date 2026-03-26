<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$viewId = (int) ($_GET['id'] ?? 0);
if ($viewId <= 0) {
    header('Location: manage_accounts.php');
    exit;
}

$stmt = $conn->prepare("SELECT id, fullname, email, username, role, account_status, course, created_at, profile_photo_path FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $viewId);
$stmt->execute();
$viewUser = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$viewUser) {
    header('Location: manage_accounts.php');
    exit;
}

$fullName = trim((string) ($viewUser['fullname'] ?? ''));
$profilePhotoPath = trim((string) ($viewUser['profile_photo_path'] ?? ''));
$initialsSource = preg_split('/\s+/', $fullName) ?: [];
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
    $initials = strtoupper(substr((string) ($viewUser['username'] ?? 'U'), 0, 2));
}

$summary = null;
$recentBorrows = [];
$role = (string) ($viewUser['role'] ?? '');
if (in_array($role, ['student', 'faculty'], true)) {
    $summary = get_member_dashboard_summary($conn, (int) $viewUser['id']);

    $recentStmt = $conn->prepare("
        SELECT b.title, br.status, br.borrow_date, br.due_date, br.return_date, br.requested_at
        FROM borrows br
        JOIN books b ON b.id = br.book_id
        WHERE br.user_id = ?
        ORDER BY br.requested_at DESC, br.id DESC
        LIMIT 5
    ");
    $recentStmt->bind_param('i', $viewId);
    $recentStmt->execute();
    $recentResult = $recentStmt->get_result();
    while ($recentResult && ($row = $recentResult->fetch_assoc())) {
        $recentBorrows[] = $row;
    }
    $recentStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View User Profile</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell admin-shell member-shell js-member-sidebar" data-sidebar-key="admin-view-user" data-sidebar-default="expanded" data-sidebar-lock="expanded">
  <?php
  $sidebarPage = 'accounts';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'View ' . role_label((string) ($viewUser['role'] ?? '')) . ' Profile';
  $pageSubtitle = 'Read-only account details for admin review';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <section class="panel member-profile-hero">
      <div class="member-profile-hero-main">
        <?php if ($profilePhotoPath !== ''): ?>
          <img class="member-profile-avatar member-profile-avatar-image" src="/librarymanage/<?php echo h($profilePhotoPath); ?>" alt="<?php echo h($fullName !== '' ? $fullName : 'Profile'); ?>">
        <?php else: ?>
          <div class="member-profile-avatar" aria-hidden="true"><?php echo h($initials); ?></div>
        <?php endif; ?>
        <div class="member-profile-copy">
          <p class="muted eyebrow-compact stack-copy">Admin Profile Review</p>
          <h2 class="member-profile-name"><?php echo h($fullName !== '' ? $fullName : 'User'); ?></h2>
          <div class="inline-actions chips-row member-profile-chips">
            <span class="chip"><?php echo h(role_label((string) ($viewUser['role'] ?? ''))); ?></span>
            <span class="chip"><?php echo h(ucfirst((string) ($viewUser['account_status'] ?? 'active'))); ?></span>
            <?php if (($viewUser['role'] ?? '') === 'student' && trim((string) ($viewUser['course'] ?? '')) !== ''): ?>
              <span class="chip"><?php echo h((string) $viewUser['course']); ?></span>
            <?php endif; ?>
            <span class="chip">@<?php echo h((string) ($viewUser['username'] ?? '-')); ?></span>
          </div>
          <p class="muted member-profile-copyline"><?php echo h((string) ($viewUser['email'] ?? '-')); ?></p>
        </div>
      </div>
      <div class="member-profile-meta">
        <div class="member-profile-meta-card">
          <span class="muted">Member since</span>
          <strong><?php echo h(format_display_datetime((string) ($viewUser['created_at'] ?? ''), '-')); ?></strong>
        </div>
        <div class="member-profile-meta-card">
          <span class="muted">Access</span>
          <strong><?php echo h(role_label((string) ($viewUser['role'] ?? ''))); ?> Account</strong>
        </div>
        <div class="member-profile-meta-card">
          <span class="muted">Account status</span>
          <strong><?php echo h(ucfirst((string) ($viewUser['account_status'] ?? 'active'))); ?></strong>
        </div>
        <?php if (($viewUser['role'] ?? '') === 'student'): ?>
          <div class="member-profile-meta-card">
            <span class="muted">Course</span>
            <strong><?php echo h(trim((string) ($viewUser['course'] ?? '')) !== '' ? (string) $viewUser['course'] : 'Not set'); ?></strong>
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
            <h3 class="heading-top-md">Account Information</h3>
          </div>
        </div>
        <div class="grid form member-profile-form-grid">
          <div>
            <label>Full name</label>
            <input value="<?php echo h((string) ($viewUser['fullname'] ?? '')); ?>" disabled>
          </div>
          <div>
            <label>Email</label>
            <input value="<?php echo h((string) ($viewUser['email'] ?? '')); ?>" disabled>
          </div>
          <div>
            <label>Username</label>
            <input value="<?php echo h((string) ($viewUser['username'] ?? '')); ?>" disabled>
          </div>
          <div>
            <label>Role</label>
            <input value="<?php echo h(role_label((string) ($viewUser['role'] ?? ''))); ?>" disabled>
          </div>
          <?php if (($viewUser['role'] ?? '') === 'student'): ?>
            <div class="member-profile-form-span">
              <label>Course</label>
              <input value="<?php echo h((string) ($viewUser['course'] ?? '')); ?>" disabled>
            </div>
          <?php endif; ?>
        </div>
        <div class="inline-actions member-workspace-actions admin-view-user-actions">
          <a class="button secondary" href="manage_accounts.php">Back to Accounts</a>
        </div>
      </div>

      <div class="panel member-profile-panel member-profile-panel-side">
        <div class="card-head">
          <div class="dashboard-icon icon-checklist" aria-hidden="true"></div>
          <div>
            <span class="chip">Snapshot</span>
            <h3 class="heading-top-md">Library Status</h3>
          </div>
        </div>
        <?php if ($summary !== null): ?>
          <div class="stack">
            <div class="empty-state">Active borrows: <strong><?php echo (int) ($summary['active_borrows'] ?? 0); ?></strong></div>
            <div class="empty-state">Overdue borrows: <strong><?php echo (int) ($summary['overdue_borrows'] ?? 0); ?></strong></div>
            <div class="empty-state">Pending payments: <strong><?php echo (int) ($summary['pending_payments'] ?? 0); ?></strong></div>
            <div class="empty-state">Unpaid penalties: <strong><?php echo h(format_currency($summary['unpaid_total'] ?? 0)); ?></strong></div>
          </div>
        <?php else: ?>
          <div class="empty-state">No borrowing summary is shown for this account role.</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($recentBorrows !== []): ?>
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
          <div>
            <span class="chip">Recent Activity</span>
            <h3 class="heading-card">Latest Borrow Records</h3>
          </div>
        </div>
        <div class="table-wrap table-wrap-top">
          <table>
            <thead>
              <tr>
                <th>Book</th>
                <th>Status</th>
                <th>Requested</th>
                <th>Borrowed</th>
                <th>Due</th>
                <th>Returned</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentBorrows as $borrow): ?>
                <tr>
                  <td><strong><?php echo h((string) ($borrow['title'] ?? '-')); ?></strong></td>
                  <td><span class="badge"><?php echo h(ucwords(str_replace('_', ' ', (string) ($borrow['status'] ?? '-')))); ?></span></td>
                  <td><?php echo h(format_display_datetime((string) ($borrow['requested_at'] ?? ''), '-')); ?></td>
                  <td><?php echo h(format_display_date((string) ($borrow['borrow_date'] ?? ''), '-')); ?></td>
                  <td><?php echo h(format_display_date((string) ($borrow['due_date'] ?? ''), '-')); ?></td>
                  <td><?php echo h(format_display_date((string) ($borrow['return_date'] ?? ''), '-')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
</body>
</html>

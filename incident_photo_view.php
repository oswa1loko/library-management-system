<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();

$incidentId = max(0, (int) ($_GET['incident_id'] ?? 0));
$viewerRole = canonical_role((string) ($_SESSION['role'] ?? ''));
$viewerUserId = (int) ($_SESSION['user_id'] ?? 0);
$notice = '';
$photoPath = '';
$photoTitle = 'Incident Photo Viewer';
$incidentTitle = '';
$viewerBackUrl = app_url('index.php');
$viewerBackLabel = 'Back Home';

if (roles_match($viewerRole, 'admin')) {
    $viewerBackUrl = app_url('admin/book_incidents_records.php');
    $viewerBackLabel = 'Back to Incidents';
} elseif (roles_match($viewerRole, 'librarian')) {
    $viewerBackUrl = app_url('librarian/manage_book_incidents.php');
    $viewerBackLabel = 'Back to Incidents';
} elseif (roles_match($viewerRole, 'student')) {
    $viewerBackUrl = app_url('student/book_incidents.php');
    $viewerBackLabel = 'Back to Book Incidents';
} elseif (roles_match($viewerRole, 'faculty')) {
    $viewerBackUrl = app_url('faculty/book_incidents.php');
    $viewerBackLabel = 'Back to Book Incidents';
}

if ($incidentId <= 0) {
    $notice = 'Incident photo reference is missing.';
} else {
    $stmt = $conn->prepare("
        SELECT bi.id, bi.user_id, bi.incident_photo_path, b.title
        FROM book_incidents bi
        JOIN books b ON b.id = bi.book_id
        WHERE bi.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $incidentId);
    $stmt->execute();
    $incident = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$incident) {
        $notice = 'Incident record was not found.';
    } else {
        $ownerUserId = (int) ($incident['user_id'] ?? 0);
        $canView = roles_match($viewerRole, 'admin')
            || roles_match($viewerRole, 'librarian')
            || $ownerUserId === $viewerUserId;

        if (!$canView) {
            $notice = 'You do not have permission to view this incident photo.';
        } else {
            $photoPath = trim((string) ($incident['incident_photo_path'] ?? ''));
            $incidentTitle = trim((string) ($incident['title'] ?? 'Incident photo'));
            $photoTitle = 'Incident Photo #' . (int) ($incident['id'] ?? 0);

            if ($photoPath === '') {
                $notice = 'No damage photo is attached to this incident.';
            } else {
                $resolvedPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $photoPath));
                $photoRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'incident_photos');

                if (
                    $resolvedPath === false
                    || $photoRoot === false
                    || !str_starts_with(strtolower($resolvedPath), strtolower($photoRoot))
                    || !is_file($resolvedPath)
                ) {
                    $notice = 'The incident photo could not be found.';
                    $photoPath = '';
                }
            }
        }
    }
}

$extension = strtolower(pathinfo($photoPath, PATHINFO_EXTENSION));
$isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
$assetVersion = (string) filemtime(__DIR__ . '/assets/app.css');
$themeVersion = (string) filemtime(__DIR__ . '/assets/theme.js');
$photoUrl = $photoPath !== '' ? app_url($photoPath) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($photoTitle); ?></title>
<link rel="icon" type="image/png" href="<?php echo h(app_url('assets/images/regismarielogo.png')); ?>">
<script src="<?php echo h(app_url('assets/theme.js?v=' . urlencode($themeVersion))); ?>"></script>
<link rel="stylesheet" href="<?php echo h(app_url('assets/app.css?v=' . urlencode($assetVersion))); ?>">
</head>
<body class="payment-proof-screen">
<div class="site-shell member-shell">
  <div class="member-main">
    <div class="stack payment-proof-shell">
      <div class="panel payment-proof-header">
        <div>
          <p class="muted eyebrow-compact">Incident Review</p>
          <h1 class="payment-proof-title">Incident Photo Viewer</h1>
          <p class="muted payment-proof-subtitle">
            <?php echo h($photoTitle); ?>
            <?php if ($incidentTitle !== ''): ?>
              | <?php echo h($incidentTitle); ?>
            <?php endif; ?>
          </p>
        </div>
        <div class="inline-actions">
          <a class="button secondary" href="<?php echo h($viewerBackUrl); ?>"><?php echo h($viewerBackLabel); ?></a>
          <a class="button secondary" href="javascript:window.close()">Close</a>
        </div>
      </div>

      <?php if ($notice !== ''): ?>
        <div class="notice error"><?php echo h($notice); ?></div>
      <?php elseif ($isImage): ?>
        <div class="panel payment-proof-viewer-shell">
          <div class="payment-proof-viewer-actions">
            <span class="muted">Image preview</span>
            <div class="inline-actions">
              <a class="button secondary" href="<?php echo h($photoUrl); ?>" target="_blank" rel="noopener">Open Full Image</a>
            </div>
          </div>
          <div class="payment-proof-image-stage">
            <img class="payment-proof-image" src="<?php echo h($photoUrl); ?>" alt="<?php echo h($photoTitle); ?>">
          </div>
        </div>
      <?php else: ?>
        <div class="panel">
          <p class="muted">This incident photo cannot be previewed here.</p>
          <a class="button" href="<?php echo h($photoUrl); ?>" target="_blank" rel="noopener">Open File</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>

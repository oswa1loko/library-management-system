<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();

$paymentId = max(0, (int) ($_GET['payment_id'] ?? 0));
$viewerRole = (string) ($_SESSION['role'] ?? '');
$viewerUserId = (int) ($_SESSION['user_id'] ?? 0);
$notice = '';
$proofPath = '';
$proofTitle = 'Payment Proof Viewer';
$paymentOwnerLabel = '';

if ($paymentId <= 0) {
    $notice = 'Payment proof reference is missing.';
} else {
    $stmt = $conn->prepare("
        SELECT p.id, p.user_id, p.proof_path, u.fullname, u.username
        FROM payments p
        JOIN users u ON u.id = p.user_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payment) {
        $notice = 'Payment record was not found.';
    } else {
        $ownerUserId = (int) ($payment['user_id'] ?? 0);
        $canView = roles_match($viewerRole, 'admin')
            || roles_match($viewerRole, 'librarian')
            || $ownerUserId === $viewerUserId;

        if (!$canView) {
            $notice = 'You do not have permission to view this payment proof.';
        } else {
            $proofPath = trim((string) ($payment['proof_path'] ?? ''));
            $proofTitle = 'Payment Proof #' . (int) ($payment['id'] ?? 0);
            $paymentOwnerLabel = trim((string) ($payment['fullname'] ?? '')) !== ''
                ? (string) $payment['fullname']
                : (string) ($payment['username'] ?? '');

            if ($proofPath === '') {
                $notice = 'No proof file is attached to this payment record.';
            } else {
                $resolvedPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $proofPath));
                $proofRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'proofs');

                if (
                    $resolvedPath === false
                    || $proofRoot === false
                    || !str_starts_with(strtolower($resolvedPath), strtolower($proofRoot))
                    || !is_file($resolvedPath)
                ) {
                    $notice = 'The proof file could not be found.';
                    $proofPath = '';
                }
            }
        }
    }
}

$extension = strtolower(pathinfo($proofPath, PATHINFO_EXTENSION));
$isPdf = $extension === 'pdf';
$isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
$assetVersion = (string) filemtime(__DIR__ . '/assets/app.css');
$themeVersion = (string) filemtime(__DIR__ . '/assets/theme.js');
$proofUrl = $proofPath !== '' ? app_url($proofPath) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($proofTitle); ?></title>
<link rel="icon" type="image/png" href="<?php echo h(app_url('assets/images/regismarielogo.png')); ?>">
<script src="<?php echo h(app_url('assets/theme.js?v=' . urlencode($themeVersion))); ?>"></script>
<link rel="stylesheet" href="<?php echo h(app_url('assets/app.css?v=' . urlencode($assetVersion))); ?>">
</head>
<body>
<div class="site-shell member-shell">
  <div class="member-main">
    <div class="topbar">
      <div>
        <h1>Payment Proof Viewer</h1>
        <p>
          <?php echo h($proofTitle); ?>
          <?php if ($paymentOwnerLabel !== ''): ?>
            | Submitted by <?php echo h($paymentOwnerLabel); ?>
          <?php endif; ?>
        </p>
      </div>
      <div class="inline-actions">
        <a class="button secondary" href="javascript:window.close()">Close</a>
      </div>
    </div>

    <div class="stack">
      <?php if ($notice !== ''): ?>
        <div class="notice error"><?php echo h($notice); ?></div>
      <?php elseif ($isPdf): ?>
        <div class="panel">
          <object data="<?php echo h($proofUrl); ?>" type="application/pdf" style="width:100%;min-height:80vh;border:0;">
            <p class="muted">This browser could not preview the PDF. <a href="<?php echo h($proofUrl); ?>" target="_blank" rel="noopener">Open the proof in a new tab</a>.</p>
          </object>
        </div>
      <?php elseif ($isImage): ?>
        <div class="panel" style="text-align:center;">
          <img src="<?php echo h($proofUrl); ?>" alt="<?php echo h($proofTitle); ?>" style="max-width:100%;height:auto;border-radius:18px;">
        </div>
      <?php else: ?>
        <div class="panel">
          <p class="muted">This proof type cannot be previewed here.</p>
          <a class="button" href="<?php echo h($proofUrl); ?>" target="_blank" rel="noopener">Open File</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>

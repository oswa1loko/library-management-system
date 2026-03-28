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
$paymentAmount = null;
$paymentStatus = '';
$paymentSubmittedAt = '';
$viewerBackUrl = app_url('index.php');
$viewerBackLabel = 'Back Home';

if (roles_match($viewerRole, 'admin')) {
    $viewerBackUrl = app_url('admin/payments_records.php');
    $viewerBackLabel = 'Back to Payments';
} elseif (roles_match($viewerRole, 'librarian')) {
    $viewerBackUrl = app_url('librarian/dashboard.php');
    $viewerBackLabel = 'Back to Dashboard';
} elseif (roles_match($viewerRole, 'student')) {
    $viewerBackUrl = app_url('student/payment_upload.php');
    $viewerBackLabel = 'Back to Payments';
} elseif (roles_match($viewerRole, 'faculty')) {
    $viewerBackUrl = app_url('faculty/payment_upload.php');
    $viewerBackLabel = 'Back to Payments';
}

if ($paymentId <= 0) {
    $notice = 'Payment proof reference is missing.';
} else {
    $stmt = $conn->prepare("
        SELECT p.id, p.user_id, p.proof_path, p.amount, p.status, p.created_at, u.fullname, u.username
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
            $paymentAmount = isset($payment['amount']) ? (float) $payment['amount'] : null;
            $paymentStatus = trim((string) ($payment['status'] ?? ''));
            $paymentSubmittedAt = trim((string) ($payment['created_at'] ?? ''));
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
<body class="payment-proof-screen">
<div class="site-shell member-shell">
  <div class="member-main">
    <div class="stack payment-proof-shell">
      <div class="panel payment-proof-header">
        <div>
          <p class="muted eyebrow-compact">Payment Review</p>
          <h1 class="payment-proof-title">Payment Proof Viewer</h1>
          <p class="muted payment-proof-subtitle">
            <?php echo h($proofTitle); ?>
            <?php if ($paymentOwnerLabel !== ''): ?>
              | Submitted by <?php echo h($paymentOwnerLabel); ?>
            <?php endif; ?>
          </p>
        </div>
        <div class="inline-actions">
          <a class="button secondary" href="<?php echo h($viewerBackUrl); ?>"><?php echo h($viewerBackLabel); ?></a>
          <a class="button secondary" href="javascript:window.close()">Close</a>
        </div>
      </div>

      <div class="panel payment-proof-overview">
        <div class="payment-proof-meta-card">
          <span class="code-pill">Payment</span>
          <strong>#<?php echo (int) $paymentId; ?></strong>
          <span class="muted">Reference number for this submission.</span>
        </div>
        <div class="payment-proof-meta-card">
          <span class="code-pill">Submitted By</span>
          <strong><?php echo h($paymentOwnerLabel !== '' ? $paymentOwnerLabel : 'Unknown member'); ?></strong>
          <span class="muted">Member linked to this uploaded payment proof.</span>
        </div>
        <div class="payment-proof-meta-card">
          <span class="code-pill">Amount</span>
          <strong><?php echo h($paymentAmount !== null ? format_currency($paymentAmount) : '-'); ?></strong>
          <span class="muted">Recorded payment amount for this proof.</span>
        </div>
        <div class="payment-proof-meta-card">
          <span class="code-pill">Status</span>
          <strong><?php echo h($paymentStatus !== '' ? ucfirst($paymentStatus) : 'Unknown'); ?></strong>
          <span class="muted"><?php echo h($paymentSubmittedAt !== '' ? format_display_datetime($paymentSubmittedAt) : '-'); ?></span>
        </div>
      </div>

      <?php if ($notice !== ''): ?>
        <div class="notice error"><?php echo h($notice); ?></div>
      <?php elseif ($isPdf): ?>
        <div class="panel payment-proof-viewer-shell">
          <div class="payment-proof-viewer-actions">
            <span class="muted">PDF preview</span>
            <div class="inline-actions">
              <a class="button secondary" href="<?php echo h($proofUrl); ?>" target="_blank" rel="noopener">Open Full File</a>
              <a class="button secondary" href="<?php echo h($proofUrl); ?>" download>Download</a>
            </div>
          </div>
          <object class="payment-proof-frame" data="<?php echo h($proofUrl); ?>" type="application/pdf">
            <p class="muted">This browser could not preview the PDF. <a href="<?php echo h($proofUrl); ?>" target="_blank" rel="noopener">Open the proof in a new tab</a>.</p>
          </object>
        </div>
      <?php elseif ($isImage): ?>
        <div class="panel payment-proof-viewer-shell">
          <div class="payment-proof-viewer-actions">
            <span class="muted">Image preview</span>
            <div class="inline-actions">
              <a class="button secondary" href="<?php echo h($proofUrl); ?>" target="_blank" rel="noopener">Open Full Image</a>
              <a class="button secondary" href="<?php echo h($proofUrl); ?>" download>Download</a>
            </div>
          </div>
          <div class="payment-proof-image-stage">
            <img class="payment-proof-image" src="<?php echo h($proofUrl); ?>" alt="<?php echo h($proofTitle); ?>">
          </div>
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

<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_roles(['student', 'faculty']);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) $_SESSION['role'];
$msg = '';
$msgType = 'success';

function upload_payment_proof(array $file, int $userId): array
{
    if (empty($file['name'])) {
        return ['path' => '', 'error' => 'Please upload proof of payment.'];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
    if (!in_array($extension, $allowed, true)) {
        return ['path' => '', 'error' => 'Only JPG, JPEG, PNG, and PDF files are allowed.'];
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['path' => '', 'error' => 'Proof file must be 5MB or smaller.'];
    }

    $dir = __DIR__ . '/../uploads/proofs';
    if (!ensure_upload_directory($dir)) {
        return ['path' => '', 'error' => 'Upload folder could not be created.'];
    }

    $filename = 'proof_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $fullPath = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        return ['path' => '', 'error' => 'Upload failed.'];
    }

    return ['path' => 'uploads/proofs/' . $filename, 'error' => ''];
}

if (isset($_POST['pay'])) {
    $penaltyId = (int) ($_POST['penalty_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);

    $penaltyCheck = $conn->prepare("
        SELECT
            p.id,
            p.amount,
            p.status,
            br.status AS borrow_status
        FROM penalties p
        LEFT JOIN borrows br ON br.id = p.borrow_id
        WHERE p.id = ? AND p.user_id = ?
        LIMIT 1
    ");
    $penaltyCheck->bind_param('ii', $penaltyId, $userId);
    $penaltyCheck->execute();
    $penalty = $penaltyCheck->get_result()->fetch_assoc();
    $penaltyCheck->close();

    if (!$penalty) {
        $msg = 'Selected penalty was not found.';
        $msgType = 'error';
    } elseif (($penalty['borrow_status'] ?? '') !== 'returned') {
        $msg = 'You can only pay this penalty after the book is confirmed returned.';
        $msgType = 'error';
    } elseif ($penalty['status'] === 'paid') {
        $msg = 'This penalty is already marked as paid.';
        $msgType = 'error';
    } elseif ($amount !== (float) $penalty['amount']) {
        $msg = 'Payment amount must match the full penalty amount.';
        $msgType = 'error';
    } elseif ($amount <= 0) {
        $msg = 'Enter a valid payment amount.';
        $msgType = 'error';
    } else {
        $existingPending = $conn->prepare("SELECT id FROM payments WHERE user_id = ? AND penalty_id = ? AND status = 'pending' LIMIT 1");
        $existingPending->bind_param('ii', $userId, $penaltyId);
        $existingPending->execute();
        $existingPending->store_result();

        if ($existingPending->num_rows > 0) {
            $msg = 'There is already a pending payment submission for this penalty.';
            $msgType = 'error';
        } else {
            $upload = upload_payment_proof($_FILES['proof'] ?? [], $userId);
            if ($upload['error'] !== '') {
                $msg = $upload['error'];
                $msgType = 'error';
            } else {
                $proofPath = $upload['path'];
                $insert = $conn->prepare("INSERT INTO payments (user_id, penalty_id, amount, proof_path, status) VALUES (?, ?, ?, ?, 'pending')");
                $insert->bind_param('iids', $userId, $penaltyId, $amount, $proofPath);

                if ($insert->execute()) {
                    $msg = 'Payment submitted. Wait for admin review.';
                } else {
                    remove_relative_file($proofPath);
                    $msg = 'Unable to save payment right now.';
                    $msgType = 'error';
                }

                $insert->close();
            }
        }

        $existingPending->close();
    }
}

$penaltiesStmt = $conn->prepare("
    SELECT p.id, p.amount, p.reason, p.status, p.created_at,
           (SELECT pay.status FROM payments pay WHERE pay.penalty_id = p.id ORDER BY pay.id DESC LIMIT 1) AS latest_payment_status
    FROM penalties p
    WHERE p.user_id = ?
    ORDER BY p.id DESC
");
$penaltiesStmt->bind_param('i', $userId);
$penaltiesStmt->execute();
$penalties = $penaltiesStmt->get_result();
$penaltiesStmt->close();

$paymentsStmt = $conn->prepare("
    SELECT id, penalty_id, amount, proof_path, status, created_at
    FROM payments
    WHERE user_id = ?
    ORDER BY id DESC
");
$paymentsStmt->bind_param('i', $userId);
$paymentsStmt->execute();
$payments = $paymentsStmt->get_result();
$paymentsStmt->close();

$overview = $conn->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_penalties,
      COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END), 0) AS unpaid_total
    FROM penalties
    WHERE user_id = ?
");
$overview->bind_param('i', $userId);
$overview->execute();
$overviewStats = $overview->get_result()->fetch_assoc();
$overview->close();

$paymentOverview = $conn->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_submissions,
      COALESCE(SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END), 0) AS approved_submissions
    FROM payments
    WHERE user_id = ?
");
$paymentOverview->bind_param('i', $userId);
$paymentOverview->execute();
$paymentStats = $paymentOverview->get_result()->fetch_assoc();
$paymentOverview->close();

$penaltyOptionStmt = $conn->prepare("
    SELECT
      p.id,
      p.amount,
      p.reason,
      p.status,
      p.created_at,
      br.status AS borrow_status,
      (SELECT pay.status FROM payments pay WHERE pay.penalty_id = p.id ORDER BY pay.id DESC LIMIT 1) AS latest_payment_status
    FROM penalties p
    LEFT JOIN borrows br ON br.id = p.borrow_id
    WHERE p.user_id = ?
    ORDER BY p.id DESC
");
$penaltyOptionStmt->bind_param('i', $userId);
$penaltyOptionStmt->execute();
$penaltyOptionRows = $penaltyOptionStmt->get_result();
$penaltyOptionStmt->close();

$payablePenaltyOptions = [];
$blockedPenaltyNotes = [];
while ($penaltyRow = $penaltyOptionRows->fetch_assoc()) {
    $penaltyId = (int) ($penaltyRow['id'] ?? 0);
    $penaltyAmount = (float) ($penaltyRow['amount'] ?? 0);
    $penaltyReason = (string) ($penaltyRow['reason'] ?? '');
    $penaltyStatus = (string) ($penaltyRow['status'] ?? '');
    $borrowStatus = (string) ($penaltyRow['borrow_status'] ?? '');
    $latestPaymentStatus = (string) ($penaltyRow['latest_payment_status'] ?? '');

    $blockReason = '';
    if ($penaltyStatus === 'paid') {
        $blockReason = 'Already marked as paid';
    } elseif ($borrowStatus !== 'returned') {
        $blockReason = 'Waiting for return confirmation';
    } elseif ($latestPaymentStatus === 'pending') {
        $blockReason = 'Payment already pending admin review';
    }

    if ($blockReason === '') {
        $payablePenaltyOptions[] = [
            'id' => $penaltyId,
            'amount' => $penaltyAmount,
            'reason' => $penaltyReason,
        ];
    } else {
        $blockedPenaltyNotes[] = [
            'id' => $penaltyId,
            'amount' => $penaltyAmount,
            'reason' => $penaltyReason,
            'block_reason' => $blockReason,
        ];
    }
}
$canSubmitPayment = count($payablePenaltyOptions) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title($role, 'Payments')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="<?php echo h($role); ?>-payments">
  <aside class="panel member-sidebar">
    <div class="member-sidebar-head">
      <button type="button" class="member-sidebar-toggle js-sidebar-toggle" aria-expanded="true" aria-label="Collapse sidebar">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Main Menu</span>
      </button>
    </div>
    <p class="member-sidebar-section member-sidebar-label">Main</p>
    <nav class="member-sidebar-nav">
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/dashboard.php" data-tooltip="Dashboard">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Dashboard</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/borrow_return.php" data-tooltip="Borrow and Return">
        <span class="dashboard-icon icon-books" aria-hidden="true"></span>
        <span class="member-sidebar-label">Borrow and Return</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/ebooks.php" data-tooltip="eBooks">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">eBooks</span>
      </a>
      <a class="member-sidebar-link is-active" href="/librarymanage/<?php echo h($role); ?>/payment_upload.php" data-tooltip="Payments">
        <span class="dashboard-icon icon-payments" aria-hidden="true"></span>
        <span class="member-sidebar-label">Payments</span>
      </a>
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/books.php" data-tooltip="Catalog">
        <span class="dashboard-icon icon-ledger" aria-hidden="true"></span>
        <span class="member-sidebar-label">Catalog</span>
      </a>
    </nav>
    <p class="member-sidebar-section member-sidebar-label">Account</p>
    <div class="topbar-nav member-sidebar-utilities">
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
        <p>Penalty payment submissions</p>
      </div>
    </div>

    <div class="stack">
    <?php if ($msg !== ''): ?>
      <div class="notice <?php echo $msgType === 'error' ? 'error' : 'success'; ?>"><?php echo h($msg); ?></div>
    <?php endif; ?>

    <div class="panel member-workspace-overview">
      <p class="muted eyebrow-compact stack-copy">Overview</p>
      <h3 class="heading-panel">Payment workspace</h3>
      <div class="stat-grid">
        <div class="stat-card">
          <strong><?php echo (int) ($overviewStats['unpaid_penalties'] ?? 0); ?></strong>
          <span class="muted">Unpaid penalties</span>
        </div>
        <div class="stat-card">
          <strong><?php echo h(format_currency($overviewStats['unpaid_total'] ?? 0)); ?></strong>
          <span class="muted">Outstanding balance</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($paymentStats['pending_submissions'] ?? 0); ?></strong>
          <span class="muted">Pending submissions</span>
        </div>
        <div class="stat-card">
          <strong><?php echo (int) ($paymentStats['approved_submissions'] ?? 0); ?></strong>
          <span class="muted">Approved submissions</span>
        </div>
      </div>
    </div>

    <div class="grid cards member-workspace-grid">
      <div class="panel member-workspace-main">
        <div class="card-head">
          <div class="dashboard-icon icon-payments" aria-hidden="true"></div>
          <div>
            <span class="chip">Payments</span>
            <h3 class="heading-top-md">Upload Proof of Payment</h3>
          </div>
        </div>
        <form method="post" enctype="multipart/form-data" class="stack chips-row member-workspace-form">
          <div>
            <label for="penalty_id">Penalty</label>
            <div class="ui-select-shell">
              <select id="penalty_id" name="penalty_id" class="ui-select" required <?php echo $canSubmitPayment ? '' : 'disabled'; ?>>
                <?php if ($canSubmitPayment): ?>
                  <option value="" disabled selected>Select a penalty</option>
                  <?php foreach ($payablePenaltyOptions as $option): ?>
                    <option value="<?php echo (int) $option['id']; ?>">
                      #<?php echo (int) $option['id']; ?> - <?php echo h(format_currency($option['amount'])); ?> - <?php echo h($option['reason']); ?>
                    </option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <option value="" selected>No payable penalties available</option>
                <?php endif; ?>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
          </div>
          <div>
            <label for="amount">Amount</label>
            <input id="amount" type="number" step="0.01" name="amount" placeholder="Enter amount" <?php echo $canSubmitPayment ? 'required' : 'disabled'; ?>>
          </div>
          <div>
            <label for="proof">Proof of payment</label>
            <input id="proof" type="file" name="proof" <?php echo $canSubmitPayment ? 'required' : 'disabled'; ?>>
          </div>
          <div class="inline-actions member-workspace-actions">
            <button type="submit" name="pay" value="1" <?php echo $canSubmitPayment ? '' : 'disabled'; ?>>Submit Payment</button>
            <span class="muted">Accepted files: JPG, JPEG, PNG, PDF up to 5MB.</span>
          </div>
          <?php if (!$canSubmitPayment): ?>
            <div class="notice warning">No penalties are currently eligible for payment. Return confirmation is required before payment upload.</div>
          <?php endif; ?>
        </form>
      </div>

      <div class="panel member-workspace-side">
        <div class="card-head">
          <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
          <div>
            <span class="chip">Notes</span>
            <h3 class="heading-top-md">Payment Notes</h3>
          </div>
        </div>
        <div class="stack">
          <div class="empty-state">Payment proof files are stored locally in <code>uploads/proofs</code>.</div>
          <div class="empty-state">Payments are only allowed after the linked borrow record is marked as returned.</div>
          <div class="empty-state">Pending submissions still need admin approval before penalties are fully settled.</div>
          <div class="empty-state">Payment submissions must match the full linked penalty amount.</div>
        </div>
      </div>
    </div>

    <div class="panel member-workspace-history">
      <div class="card-head">
        <div class="dashboard-icon icon-guide" aria-hidden="true"></div>
        <div>
          <span class="chip">Eligibility</span>
          <h3 class="heading-top-md">Why some penalties are blocked</h3>
        </div>
      </div>
      <p class="muted copy-bottom">Only penalties that pass all checks are shown in the payment dropdown.</p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Penalty ID</th>
              <th>Amount</th>
              <th>Reason</th>
              <th>Payment Eligibility</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($blockedPenaltyNotes) === 0): ?>
              <tr><td colspan="4" class="muted">No blocked penalties. All eligible unpaid penalties are ready for payment.</td></tr>
            <?php endif; ?>
            <?php foreach ($blockedPenaltyNotes as $blocked): ?>
              <tr>
                <td>#<?php echo (int) $blocked['id']; ?></td>
                <td><?php echo h(format_currency($blocked['amount'])); ?></td>
                <td><?php echo h($blocked['reason']); ?></td>
                <td><span class="badge"><span class="status-dot due"></span><?php echo h($blocked['block_reason']); ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel member-workspace-history">
      <div class="card-head">
        <div class="dashboard-icon icon-penalties" aria-hidden="true"></div>
        <div>
          <span class="chip">Penalties</span>
          <h3 class="heading-top-md">My Penalties</h3>
        </div>
      </div>
      <p class="muted copy-bottom">Review your penalty balances and the latest payment state attached to each record.</p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Latest Payment</th>
              <th>Reason</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($penalties->num_rows === 0): ?>
              <tr><td colspan="6" class="muted">No penalties found.</td></tr>
            <?php endif; ?>
            <?php while ($penalty = $penalties->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int) $penalty['id']; ?></td>
                <td><?php echo h(format_currency($penalty['amount'])); ?></td>
                <td><span class="badge"><span class="status-dot <?php echo h($penalty['status']); ?>"></span><?php echo h($penalty['status']); ?></span></td>
                <td><?php echo h($penalty['latest_payment_status'] ?: '-'); ?></td>
                <td><?php echo h($penalty['reason']); ?></td>
                <td><?php echo h(format_display_date((string) $penalty['created_at'])); ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel member-workspace-history">
      <div class="card-head">
        <div class="dashboard-icon icon-upload" aria-hidden="true"></div>
        <div>
          <span class="chip">Submissions</span>
          <h3 class="heading-top-md">My Payment Submissions</h3>
        </div>
      </div>
      <p class="muted copy-bottom">Check review status, linked penalty IDs, and uploaded proof references.</p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Penalty ID</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Proof</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($payments->num_rows === 0): ?>
              <tr><td colspan="6" class="muted">No payment submissions yet.</td></tr>
            <?php endif; ?>
            <?php while ($payment = $payments->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int) $payment['id']; ?></td>
                <td><?php echo (int) $payment['penalty_id']; ?></td>
                <td><?php echo h(format_currency($payment['amount'])); ?></td>
                <td><span class="badge"><span class="status-dot <?php echo h($payment['status']); ?>"></span><?php echo h($payment['status']); ?></span></td>
                <td>
                  <?php if (!empty($payment['proof_path'])): ?>
                    <a href="/librarymanage/<?php echo h($payment['proof_path']); ?>" target="_blank">View</a>
                  <?php else: ?>
                    <span class="muted">None</span>
                  <?php endif; ?>
                </td>
                <td><?php echo h($payment['created_at']); ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
</body>
</html>

<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/book_incidents.php';

require_roles(['student', 'faculty']);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) $_SESSION['role'];
$msg = '';
$msgType = 'success';
$paymentContext = trim((string) ($_GET['payment_context'] ?? ''));
$focusedIncidentId = (int) ($_GET['incident_id'] ?? 0);
$openedFromNotification = (string) ($_GET['from_notification'] ?? '') === '1';
$notificationState = trim(strtolower((string) ($_GET['notification_state'] ?? '')));
if (!in_array($notificationState, ['approved', 'rejected'], true)) {
    $notificationState = '';
}

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

if (isset($_POST['pay_incident'])) {
    $incidentId = (int) ($_POST['payment_incident_id'] ?? 0);
    $amount = (float) ($_POST['incident_amount'] ?? 0);

    if ($incidentId <= 0) {
        $msg = 'Select a payable incident first.';
        $msgType = 'error';
    } elseif ($amount <= 0) {
        $msg = 'Enter a valid incident payment amount.';
        $msgType = 'error';
    } else {
        $incidentCheck = $conn->prepare("
            SELECT
                bi.id,
                bi.book_id,
                bi.assessed_fee,
                bi.settlement_status,
                bi.workflow_status,
                b.title,
                (
                    SELECT pay.status
                    FROM payments pay
                    WHERE pay.user_id = bi.user_id
                      AND pay.incident_id = bi.id
                    ORDER BY pay.id DESC
                    LIMIT 1
                ) AS latest_payment_status
            FROM book_incidents bi
            JOIN books b ON b.id = bi.book_id
            WHERE bi.id = ?
              AND bi.user_id = ?
            LIMIT 1
        ");
        $incidentCheck->bind_param('ii', $incidentId, $userId);
        $incidentCheck->execute();
        $incidentRow = $incidentCheck->get_result()->fetch_assoc();
        $incidentCheck->close();

        if (!$incidentRow) {
            $msg = 'Selected incident payment record was not found.';
            $msgType = 'error';
        } else {
            $expectedAmount = round((float) ($incidentRow['assessed_fee'] ?? 0), 2);
            $incidentTitle = (string) ($incidentRow['title'] ?? '');

            $blockReason = book_incident_payment_block_reason($incidentRow);
            if ($blockReason !== '') {
                $msg = $blockReason . '.';
                $msgType = 'error';
            } elseif (round($amount, 2) !== $expectedAmount) {
                $msg = 'Payment amount must match the full assessed incident fee.';
                $msgType = 'error';
            } else {
                $upload = upload_payment_proof($_FILES['proof'] ?? [], $userId);
                if ($upload['error'] !== '') {
                    $msg = $upload['error'];
                    $msgType = 'error';
                } else {
                    $proofPath = $upload['path'];
                    $paymentBatch = 'inc-pay-' . bin2hex(random_bytes(8));

                    $conn->begin_transaction();
                    try {
                        $insert = $conn->prepare("
                            INSERT INTO payments (user_id, penalty_id, incident_id, payment_batch, amount, proof_path, status)
                            VALUES (?, NULL, ?, ?, ?, ?, 'pending')
                        ");
                        $insert->bind_param('iisds', $userId, $incidentId, $paymentBatch, $expectedAmount, $proofPath);
                        $insert->execute();
                        $paymentId = (int) $insert->insert_id;
                        $insert->close();

                        $conn->commit();
                        create_notification(
                            $conn,
                            'admin',
                            'New Incident Payment Submission',
                            role_label($role) . ' ' . (string) ($_SESSION['username'] ?? 'member') . ' submitted a payment proof for incident #' . $incidentId . ' on ' . ($incidentTitle !== '' ? $incidentTitle : 'a book') . '.',
                            'warning'
                        );
                        $msg = 'Incident payment submitted for ' . ($incidentTitle !== '' ? $incidentTitle : 'this book') . '. Wait for admin review.';
                    } catch (Throwable $e) {
                        $conn->rollback();
                        remove_relative_file($proofPath);
                        $msg = 'Unable to save incident payment right now.';
                        $msgType = 'error';
                    }
                }
            }
        }
    }
}

if (isset($_POST['pay_penalty'])) {
    $bookId = (int) ($_POST['payment_group_book_id'] ?? 0);
    $amount = (float) ($_POST['penalty_amount'] ?? 0);

    if ($bookId <= 0) {
        $msg = 'Select a grouped penalty first.';
        $msgType = 'error';
    } elseif ($amount <= 0) {
        $msg = 'Enter a valid grouped penalty amount.';
        $msgType = 'error';
    } else {
        $groupCheck = $conn->prepare("
            SELECT
                p.id,
                p.amount,
                p.status,
                b.title,
                br.status AS borrow_status,
                (
                    SELECT pay.status
                    FROM payments pay
                    LEFT JOIN payment_penalty_links ppl ON ppl.payment_id = pay.id
                    WHERE pay.user_id = p.user_id
                      AND (
                        pay.penalty_id = p.id
                        OR ppl.penalty_id = p.id
                      )
                    ORDER BY pay.id DESC
                    LIMIT 1
                ) AS latest_payment_status
            FROM penalties p
            JOIN borrows br ON br.id = p.borrow_id
            JOIN books b ON b.id = br.book_id
            WHERE p.user_id = ? AND br.book_id = ?
            ORDER BY p.id ASC
        ");
        $groupCheck->bind_param('ii', $userId, $bookId);
        $groupCheck->execute();
        $groupRows = $groupCheck->get_result()->fetch_all(MYSQLI_ASSOC);
        $groupCheck->close();

        $eligiblePenaltyIds = [];
        $groupTitle = '';
        $expectedAmount = 0.0;
        foreach ($groupRows as $groupRow) {
            $groupTitle = $groupTitle !== '' ? $groupTitle : (string) ($groupRow['title'] ?? '');
            if (
                (string) ($groupRow['status'] ?? '') === 'unpaid'
                && (string) ($groupRow['borrow_status'] ?? '') === 'returned'
                && (string) ($groupRow['latest_payment_status'] ?? '') !== 'pending'
            ) {
                $eligiblePenaltyIds[] = (int) ($groupRow['id'] ?? 0);
                $expectedAmount += (float) ($groupRow['amount'] ?? 0);
            }
        }

        $eligiblePenaltyIds = array_values(array_filter(array_unique($eligiblePenaltyIds)));
        $expectedAmount = round($expectedAmount, 2);

        if ($eligiblePenaltyIds === []) {
            $msg = 'No payable grouped penalties are available for this book right now.';
            $msgType = 'error';
        } elseif (round($amount, 2) !== $expectedAmount) {
            $msg = 'Payment amount must match the full grouped penalty amount.';
            $msgType = 'error';
        } else {
            $upload = upload_payment_proof($_FILES['proof'] ?? [], $userId);
            if ($upload['error'] !== '') {
                $msg = $upload['error'];
                $msgType = 'error';
            } else {
                $proofPath = $upload['path'];
                $paymentBatch = 'pay-' . bin2hex(random_bytes(8));
                $representativePenaltyId = (int) $eligiblePenaltyIds[0];

                $conn->begin_transaction();
                try {
                    $insert = $conn->prepare("
                        INSERT INTO payments (user_id, penalty_id, payment_batch, amount, proof_path, status)
                        VALUES (?, ?, ?, ?, ?, 'pending')
                    ");
                    $insert->bind_param('iisds', $userId, $representativePenaltyId, $paymentBatch, $expectedAmount, $proofPath);
                    $insert->execute();
                    $paymentId = (int) $insert->insert_id;
                    $insert->close();

                    $linkStmt = $conn->prepare("INSERT INTO payment_penalty_links (payment_id, penalty_id) VALUES (?, ?)");
                    foreach ($eligiblePenaltyIds as $linkedPenaltyId) {
                        $linkStmt->bind_param('ii', $paymentId, $linkedPenaltyId);
                        $linkStmt->execute();
                    }
                    $linkStmt->close();

                    $conn->commit();
                    $copyCount = count($eligiblePenaltyIds);
                    $copyLabel = $copyCount === 1 ? '1 copy' : $copyCount . ' copies';
                    if ($role === 'student') {
                        create_notification(
                            $conn,
                            'admin',
                            'New Payment Submission',
                            'A student payment proof was submitted by ' . (string) ($_SESSION['username'] ?? 'a member')
                                . ' for ' . $copyLabel . ' of ' . ($groupTitle !== '' ? $groupTitle : 'a book') . '.',
                            'warning'
                        );
                    }
                    $msg = 'Payment submitted for ' . $copyLabel . ' of ' . ($groupTitle !== '' ? $groupTitle : 'this book') . '. Wait for admin review.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    remove_relative_file($proofPath);
                    $msg = 'Unable to save grouped payment right now.';
                    $msgType = 'error';
                }
            }
        }
    }
}

$penaltiesStmt = $conn->prepare("
    SELECT p.id, p.amount, p.reason, p.status, p.created_at,
           (SELECT pay.status
              FROM payments pay
              LEFT JOIN payment_penalty_links ppl ON ppl.payment_id = pay.id
             WHERE pay.user_id = p.user_id
               AND (
                 pay.penalty_id = p.id
                 OR ppl.penalty_id = p.id
               )
             ORDER BY pay.id DESC
             LIMIT 1) AS latest_payment_status
    FROM penalties p
    WHERE p.user_id = ?
    ORDER BY p.id DESC
");
$penaltiesStmt->bind_param('i', $userId);
$penaltiesStmt->execute();
$penalties = $penaltiesStmt->get_result();
$penaltiesStmt->close();

$paymentsStmt = $conn->prepare("
    SELECT
        pay.id,
        pay.penalty_id,
        pay.incident_id,
        pay.payment_batch,
        pay.amount,
        pay.proof_path,
        pay.status,
        pay.created_at,
        COUNT(ppl.penalty_id) AS linked_penalty_count,
        MAX(b.title) AS linked_book_title,
        MAX(ib.title) AS linked_incident_book_title
    FROM payments pay
    LEFT JOIN payment_penalty_links ppl ON ppl.payment_id = pay.id
    LEFT JOIN penalties p ON p.id = ppl.penalty_id
    LEFT JOIN borrows br ON br.id = p.borrow_id
    LEFT JOIN books b ON b.id = br.book_id
    LEFT JOIN book_incidents bi ON bi.id = pay.incident_id
    LEFT JOIN books ib ON ib.id = bi.book_id
    WHERE pay.user_id = ?
    GROUP BY pay.id, pay.penalty_id, pay.incident_id, pay.payment_batch, pay.amount, pay.proof_path, pay.status, pay.created_at
    ORDER BY pay.id DESC
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

$incidentOverview = $conn->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN settlement_status = 'pending' AND assessed_fee > 0 THEN 1 ELSE 0 END), 0) AS payable_incidents,
      COALESCE(SUM(CASE WHEN settlement_status = 'pending' THEN assessed_fee ELSE 0 END), 0) AS incident_balance
    FROM book_incidents
    WHERE user_id = ?
      AND workflow_status IN ('for_payment', 'awaiting_settlement')
");
$incidentOverview->bind_param('i', $userId);
$incidentOverview->execute();
$incidentStats = $incidentOverview->get_result()->fetch_assoc();
$incidentOverview->close();

$penaltyOptionStmt = $conn->prepare("
    SELECT
      p.id,
      p.amount,
      p.reason,
      p.status,
      p.created_at,
      br.book_id,
      b.title,
      br.status AS borrow_status,
      (SELECT pay.status
         FROM payments pay
         LEFT JOIN payment_penalty_links ppl ON ppl.payment_id = pay.id
        WHERE pay.user_id = p.user_id
          AND (
            pay.penalty_id = p.id
            OR ppl.penalty_id = p.id
          )
        ORDER BY pay.id DESC
        LIMIT 1) AS latest_payment_status
    FROM penalties p
    LEFT JOIN borrows br ON br.id = p.borrow_id
    LEFT JOIN books b ON b.id = br.book_id
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
    $bookId = (int) ($penaltyRow['book_id'] ?? 0);
    $bookTitle = (string) ($penaltyRow['title'] ?? '');
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
        if ($bookId > 0) {
            if (!isset($payablePenaltyOptions[$bookId])) {
                $payablePenaltyOptions[$bookId] = [
                    'book_id' => $bookId,
                    'title' => $bookTitle !== '' ? $bookTitle : ('Book #' . $bookId),
                    'amount' => 0.0,
                    'copy_count' => 0,
                ];
            }

            $payablePenaltyOptions[$bookId]['amount'] += $penaltyAmount;
            $payablePenaltyOptions[$bookId]['copy_count']++;
        } else {
            $payablePenaltyOptions['penalty-' . $penaltyId] = [
                'book_id' => 0,
                'title' => $penaltyReason !== '' ? $penaltyReason : ('Penalty #' . $penaltyId),
                'amount' => $penaltyAmount,
                'copy_count' => 1,
            ];
        }
    } else {
        $blockedPenaltyNotes[] = [
            'id' => $penaltyId,
            'amount' => $penaltyAmount,
            'reason' => $bookTitle !== '' ? $bookTitle . ' - ' . $penaltyReason : $penaltyReason,
            'block_reason' => $blockReason,
        ];
    }
}
$payablePenaltyOptions = array_values(array_map(static function (array $option): array {
    $option['amount'] = round((float) ($option['amount'] ?? 0), 2);
    return $option;
}, $payablePenaltyOptions));

$incidentOptionStmt = $conn->prepare("
    SELECT
      bi.id,
      bi.assessed_fee,
      bi.settlement_status,
      bi.workflow_status,
      bi.incident_type,
      b.title,
      (
        SELECT pay.status
        FROM payments pay
        WHERE pay.user_id = bi.user_id
          AND pay.incident_id = bi.id
        ORDER BY pay.id DESC
        LIMIT 1
      ) AS latest_payment_status
    FROM book_incidents bi
    JOIN books b ON b.id = bi.book_id
    WHERE bi.user_id = ?
    ORDER BY bi.id DESC
");
$incidentOptionStmt->bind_param('i', $userId);
$incidentOptionStmt->execute();
$incidentOptionRows = $incidentOptionStmt->get_result();
$incidentOptionStmt->close();

$payableIncidentOptions = [];
$blockedIncidentNotes = [];
while ($incidentRow = $incidentOptionRows->fetch_assoc()) {
    $rowIncidentId = (int) ($incidentRow['id'] ?? 0);
    $incidentAmount = round((float) ($incidentRow['assessed_fee'] ?? 0), 2);
    $incidentTitle = (string) ($incidentRow['title'] ?? '');
    $incidentType = (string) ($incidentRow['incident_type'] ?? '');
    $blockReason = book_incident_payment_block_reason($incidentRow);

    if ($blockReason === '') {
        $payableIncidentOptions[] = [
            'incident_id' => $rowIncidentId,
            'title' => $incidentTitle !== '' ? $incidentTitle : ('Incident #' . $rowIncidentId),
            'amount' => $incidentAmount,
            'incident_type' => $incidentType,
        ];
    } else {
        $blockedIncidentNotes[] = [
            'id' => $rowIncidentId,
            'amount' => $incidentAmount,
            'reason' => ($incidentTitle !== '' ? $incidentTitle : ('Incident #' . $rowIncidentId)) . ' - ' . book_incident_type_label($incidentType),
            'block_reason' => $blockReason,
        ];
    }
}

$canSubmitPayment = count($payablePenaltyOptions) > 0 || count($payableIncidentOptions) > 0;
$paymentUploadBaseHref = app_url($role . '/payment_upload.php');
$notificationPaymentContext = in_array($paymentContext, ['penalty', 'incident'], true) ? $paymentContext : '';
$showIncidentDecisionModal = $openedFromNotification && $notificationPaymentContext === 'incident' && $notificationState !== '';
$focusedIncidentPayment = null;
if ($focusedIncidentId > 0) {
    $focusedIncidentStmt = $conn->prepare("
        SELECT
          bi.id,
          bi.assessed_fee,
          bi.settlement_status,
          bi.workflow_status,
          bi.incident_type,
          bi.resolution_action,
          bi.resolution_notes,
          bi.reported_at,
          b.title,
          (
            SELECT pay.status
            FROM payments pay
            WHERE pay.user_id = bi.user_id
              AND pay.incident_id = bi.id
            ORDER BY pay.id DESC
            LIMIT 1
          ) AS latest_payment_status
        FROM book_incidents bi
        JOIN books b ON b.id = bi.book_id
        WHERE bi.user_id = ?
          AND bi.id = ?
        LIMIT 1
    ");
    if ($focusedIncidentStmt) {
        $focusedIncidentStmt->bind_param('ii', $userId, $focusedIncidentId);
        $focusedIncidentStmt->execute();
        $focusedIncidentPayment = $focusedIncidentStmt->get_result()->fetch_assoc() ?: null;
        $focusedIncidentStmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title($role, 'Payments')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="<?php echo h($role); ?>-payments">
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
      <a class="member-sidebar-link is-active" href="/librarymanage/<?php echo h($role); ?>/payment_upload.php" data-tooltip="Payments">
        <span class="dashboard-icon icon-payments" aria-hidden="true"></span>
        <span class="member-sidebar-label">Payments</span>
      </a>
    </nav>
    <p class="member-sidebar-section member-sidebar-label">Account</p>
    <div class="topbar-nav member-sidebar-utilities">
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/profile.php" data-tooltip="Profile">
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
        <h1><?php echo h(role_label($role)); ?> Payments</h1>
        <p>Penalty payment submissions</p>
      </div>
    </div>

    <div class="stack">
    <?php if ($msg !== ''): ?>
      <div class="notice <?php echo $msgType === 'error' ? 'error' : 'success'; ?>"><?php echo h($msg); ?></div>
    <?php endif; ?>

    <?php if ($openedFromNotification && $notificationPaymentContext !== ''): ?>
      <div class="notice success">
        <?php echo h($notificationPaymentContext === 'incident'
          ? 'Opened the incident payment workspace from your notification.'
          : 'Opened the penalty payment workspace from your notification.'); ?>
      </div>
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
        <div class="stat-card">
          <strong><?php echo (int) ($incidentStats['payable_incidents'] ?? 0); ?></strong>
          <span class="muted">Payable incident cases</span>
        </div>
        <div class="stat-card">
          <strong><?php echo h(format_currency($incidentStats['incident_balance'] ?? 0)); ?></strong>
          <span class="muted">Incident payment balance</span>
        </div>
      </div>
    </div>

    <div class="grid cards member-workspace-grid">
      <div id="penalty-payment-form" class="panel member-workspace-main">
        <div class="card-head">
          <div class="dashboard-icon icon-payments" aria-hidden="true"></div>
          <div>
            <span class="chip">Penalty Payments</span>
            <h3 class="heading-top-md">Pay Grouped Penalties</h3>
          </div>
        </div>
        <form method="post" enctype="multipart/form-data" class="stack chips-row member-workspace-form">
          <div>
            <label for="payment_group_book_id">Penalty Group</label>
            <div class="ui-select-shell">
              <select id="payment_group_book_id" name="payment_group_book_id" class="ui-select" required <?php echo count($payablePenaltyOptions) > 0 ? '' : 'disabled'; ?>>
                <?php if (count($payablePenaltyOptions) > 0): ?>
                  <option value="" disabled selected>Select a grouped penalty</option>
                  <?php foreach ($payablePenaltyOptions as $option): ?>
                    <option value="<?php echo (int) $option['book_id']; ?>">
                      <?php echo h($option['title']); ?> - <?php echo (int) $option['copy_count']; ?> cop<?php echo (int) $option['copy_count'] === 1 ? 'y' : 'ies'; ?> - <?php echo h(format_currency($option['amount'])); ?>
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
            <label for="penalty_amount">Amount</label>
            <input id="penalty_amount" type="number" step="0.01" name="penalty_amount" placeholder="Enter grouped penalty amount" <?php echo count($payablePenaltyOptions) > 0 ? 'required' : 'disabled'; ?>>
          </div>
          <div>
            <label for="penalty_proof">Proof of payment</label>
            <input id="penalty_proof" type="file" name="proof" <?php echo count($payablePenaltyOptions) > 0 ? 'required' : 'disabled'; ?>>
          </div>
          <div class="inline-actions member-workspace-actions">
            <button type="submit" name="pay_penalty" value="1" <?php echo count($payablePenaltyOptions) > 0 ? '' : 'disabled'; ?>>Submit Penalty Payment</button>
            <span class="muted">Accepted files: JPG, JPEG, PNG, PDF up to 5MB.</span>
          </div>
          <?php if (count($payablePenaltyOptions) === 0): ?>
            <div class="notice warning">No grouped penalties are currently eligible for payment.</div>
          <?php endif; ?>
        </form>
      </div>

      <div id="incident-payment-form" class="panel member-workspace-main">
        <div class="card-head">
          <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
          <div>
            <span class="chip">Incident Payments</span>
            <h3 class="heading-top-md">Pay Lost or Damaged Fees</h3>
          </div>
        </div>
        <form method="post" enctype="multipart/form-data" class="stack chips-row member-workspace-form">
          <div>
            <label for="payment_incident_id">Book Incident</label>
            <div class="ui-select-shell">
              <select id="payment_incident_id" name="payment_incident_id" class="ui-select" required <?php echo count($payableIncidentOptions) > 0 ? '' : 'disabled'; ?>>
                <?php if (count($payableIncidentOptions) > 0): ?>
                  <option value="" disabled selected>Select a payable incident</option>
                  <?php foreach ($payableIncidentOptions as $option): ?>
                    <option value="<?php echo (int) $option['incident_id']; ?>">
                      Incident #<?php echo (int) $option['incident_id']; ?> - <?php echo h($option['title']); ?> - <?php echo h(book_incident_type_label((string) $option['incident_type'])); ?> - <?php echo h(format_currency($option['amount'])); ?>
                    </option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <option value="" selected>No payable incidents available</option>
                <?php endif; ?>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
          </div>
          <div>
            <label for="incident_amount">Amount</label>
            <input id="incident_amount" type="number" step="0.01" name="incident_amount" placeholder="Enter incident fee amount" <?php echo count($payableIncidentOptions) > 0 ? 'required' : 'disabled'; ?>>
          </div>
          <div>
            <label for="incident_proof">Proof of payment</label>
            <input id="incident_proof" type="file" name="proof" <?php echo count($payableIncidentOptions) > 0 ? 'required' : 'disabled'; ?>>
          </div>
          <div class="inline-actions member-workspace-actions">
            <button type="submit" name="pay_incident" value="1" <?php echo count($payableIncidentOptions) > 0 ? '' : 'disabled'; ?>>Submit Incident Payment</button>
            <span class="muted">Use this only for librarian-assessed lost or damaged fees.</span>
          </div>
          <?php if (count($payableIncidentOptions) === 0): ?>
            <div class="notice warning">No incident fees are currently eligible for payment.</div>
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
          <div class="empty-state">Grouped penalties only open after the linked borrow record is marked as returned.</div>
          <div class="empty-state">Incident payments only open after the librarian sets the assessed fee in Book Incidents.</div>
          <div class="empty-state">Pending submissions still need admin approval before balances are fully settled.</div>
          <div class="empty-state">Grouped penalties and incident fees use separate forms to avoid payment mix-ups.</div>
        </div>
      </div>
    </div>

    <div id="my-payment-submissions" class="panel member-workspace-history">
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
        <div class="dashboard-icon icon-guide" aria-hidden="true"></div>
        <div>
          <span class="chip">Incident Eligibility</span>
          <h3 class="heading-top-md">Why some incident fees are blocked</h3>
        </div>
      </div>
      <p class="muted copy-bottom">Incident payments only open after librarian review and fee assessment.</p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Incident ID</th>
              <th>Amount</th>
              <th>Book / Type</th>
              <th>Payment Eligibility</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($blockedIncidentNotes) === 0): ?>
              <tr><td colspan="4" class="muted">No blocked incident payments. All payable incident fees are listed in the dropdown.</td></tr>
            <?php endif; ?>
            <?php foreach ($blockedIncidentNotes as $blocked): ?>
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
              <th>Reference</th>
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
                <td>
                  <?php
                  $linkedPenaltyCount = max(0, (int) ($payment['linked_penalty_count'] ?? 0));
                  if ((int) ($payment['incident_id'] ?? 0) > 0) {
                      echo 'Incident #' . (int) $payment['incident_id'];
                      if (trim((string) ($payment['linked_incident_book_title'] ?? '')) !== '') {
                          echo ' / ' . h((string) $payment['linked_incident_book_title']);
                      }
                  } elseif ($linkedPenaltyCount > 1) {
                      echo h((string) ($payment['payment_batch'] ?: ('Payment #' . (int) $payment['id'])));
                      echo ' / ' . $linkedPenaltyCount . ' penalties';
                  } else {
                      echo (int) $payment['penalty_id'];
                  }
                  ?>
                </td>
                <td><?php echo h(format_currency($payment['amount'])); ?></td>
                <td><span class="badge"><span class="status-dot <?php echo h($payment['status']); ?>"></span><?php echo h($payment['status']); ?></span></td>
                <td>
                  <?php if (!empty($payment['proof_path'])): ?>
                    <a href="<?php echo h(app_url('proof_view.php?payment_id=' . (int) $payment['id'])); ?>" target="_blank">View</a>
                  <?php else: ?>
                    <span class="muted">None</span>
                  <?php endif; ?>
                </td>
                <td><?php echo h(format_display_datetime((string) $payment['created_at'])); ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    </div>
  </div>
</div>
<?php if ($openedFromNotification && $notificationPaymentContext !== ''): ?>
  <div class="desk-modal" data-member-notification-page-modal>
    <a class="desk-modal-backdrop" href="<?php echo h($paymentUploadBaseHref); ?>" aria-label="Close payment workspace details"></a>
    <div class="desk-modal-dialog panel member-notification-page-dialog" role="dialog" aria-modal="true" aria-labelledby="member-payment-notification-modal-title">
      <div class="desk-modal-head">
        <div>
          <p class="muted eyebrow-compact"><?php echo h($notificationPaymentContext === 'incident' ? 'Incident Payment' : 'Penalty Payment'); ?></p>
          <h3 id="member-payment-notification-modal-title" class="heading-card">
            <?php
              if ($showIncidentDecisionModal && $notificationState === 'approved') {
                  echo h('Incident Payment Approved');
              } elseif ($showIncidentDecisionModal && $notificationState === 'rejected') {
                  echo h('Incident Payment Rejected');
              } else {
                  echo h($notificationPaymentContext === 'incident' ? 'Incident Payment Workspace' : 'Penalty Payment Workspace');
              }
            ?>
          </h3>
          <p class="muted">
            <?php
              if ($showIncidentDecisionModal && $notificationState === 'approved') {
                  echo h('Your latest incident payment proof has been approved by admin. This modal shows the final settlement result for the selected incident.');
              } elseif ($showIncidentDecisionModal && $notificationState === 'rejected') {
                  echo h('Your latest incident payment proof was not approved. Review the rejection state here, then resubmit a valid proof from the incident payment form below.');
              } else {
                  echo h($notificationPaymentContext === 'incident'
                    ? 'This notification points you to the lost or damaged fee section so you can review the latest incident payment status or submit the next required proof.'
                    : 'This notification points you to the grouped penalty payment section so you can review the latest status or submit a new payment proof if needed.');
              }
            ?>
          </p>
        </div>
        <a class="button secondary" href="<?php echo h($paymentUploadBaseHref); ?>">Close</a>
      </div>
      <div class="panel member-return-batch-card member-notification-page-card">
        <div class="member-return-batch-head">
          <div>
            <?php if ($notificationPaymentContext === 'incident' && $focusedIncidentPayment): ?>
              <strong class="label-block">
                Incident #<?php echo (int) ($focusedIncidentPayment['id'] ?? 0); ?>:
                <?php echo h((string) ($focusedIncidentPayment['title'] ?? 'Incident payment')); ?>
              </strong>
              <span class="muted">
                <?php echo h(book_incident_type_label((string) ($focusedIncidentPayment['incident_type'] ?? ''))); ?> |
                <?php echo h($showIncidentDecisionModal
                  ? ($notificationState === 'approved' ? 'Approved by admin' : 'Rejected by admin')
                  : book_incident_payment_stage_label($focusedIncidentPayment)); ?> |
                <?php echo h(format_currency($focusedIncidentPayment['assessed_fee'] ?? 0)); ?>
              </span>
            <?php else: ?>
              <strong class="label-block">
                <?php echo h($notificationPaymentContext === 'incident' ? 'Incident Fees' : 'Penalty Payments'); ?>
              </strong>
              <span class="muted">
                <?php echo h($notificationPaymentContext === 'incident'
                  ? count($payableIncidentOptions) . ' payable incident case(s) currently available.'
                  : count($payablePenaltyOptions) . ' payable penalty group(s) currently available.'); ?>
              </span>
            <?php endif; ?>
            <div class="inline-actions chips-row batch-status-row">
              <?php if ($notificationPaymentContext === 'incident' && $focusedIncidentPayment): ?>
                <span class="chip"><?php echo h(book_incident_workflow_label((string) ($focusedIncidentPayment['workflow_status'] ?? 'open'))); ?></span>
                <span class="chip"><?php echo h($showIncidentDecisionModal
                  ? ($notificationState === 'approved' ? 'Approved' : 'Rejected')
                  : book_incident_payment_stage_label($focusedIncidentPayment)); ?></span>
                <span class="chip"><?php echo h(format_currency($focusedIncidentPayment['assessed_fee'] ?? 0)); ?></span>
              <?php else: ?>
                <span class="chip"><?php echo h(format_currency($overviewStats['unpaid_total'] ?? 0)); ?> outstanding penalties</span>
                <span class="chip"><?php echo h(format_currency($incidentStats['incident_balance'] ?? 0)); ?> incident balance</span>
                <span class="chip"><?php echo (int) ($paymentStats['pending_submissions'] ?? 0); ?> pending submissions</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($showIncidentDecisionModal && $notificationState === 'approved'): ?>
            <a class="button" href="#my-payment-submissions">View Payment Record</a>
          <?php else: ?>
            <a class="button" href="#<?php echo h($notificationPaymentContext === 'incident' ? 'incident-payment-form' : 'penalty-payment-form'); ?>">
              <?php echo h($notificationPaymentContext === 'incident' ? 'Go To Incident Form' : 'Go To Penalty Form'); ?>
            </a>
          <?php endif; ?>
        </div>
        <div class="stack member-return-batch-list">
          <div class="empty-state member-return-batch-item">
            <span class="grow">
              <strong class="label-block meta-top-sm">Next Step</strong>
              <span class="muted">
                <?php
                  if ($showIncidentDecisionModal && $notificationState === 'approved' && $focusedIncidentPayment) {
                      echo h('No further proof is needed. This incident payment is approved and the case is already marked as settled.');
                  } elseif ($showIncidentDecisionModal && $notificationState === 'rejected' && $focusedIncidentPayment) {
                      echo h('Please review the incident amount and upload a new valid proof from the incident payment form when you are ready to resubmit.');
                  } elseif ($notificationPaymentContext === 'incident' && $focusedIncidentPayment) {
                      $latestPaymentStatus = trim((string) ($focusedIncidentPayment['latest_payment_status'] ?? ''));
                      if ($latestPaymentStatus === 'approved' || (string) ($focusedIncidentPayment['settlement_status'] ?? '') === 'paid') {
                          echo h('This incident payment is already approved and marked as settled.');
                      } elseif ($latestPaymentStatus === 'rejected') {
                          echo h('This incident payment was rejected. Review the amount below and upload a new valid proof from the incident payment form.');
                      } else {
                          echo h('Review this incident fee below and continue from the incident payment form if another proof is still needed.');
                      }
                  } else {
                      echo h($notificationPaymentContext === 'incident'
                        ? 'Review the incident payment form below, confirm the assessed fee amount, then upload a valid proof if the case is still payable.'
                        : 'Review the grouped penalty form below, make sure the amount matches the total due, then upload your payment proof for admin review.');
                  }
                ?>
              </span>
            </span>
          </div>
          <div class="empty-state member-return-batch-item">
            <span class="grow">
              <strong class="label-block meta-top-sm"><?php echo h($showIncidentDecisionModal ? 'Decision Summary' : ($notificationPaymentContext === 'incident' ? 'Incident Status' : 'Status Reminder')); ?></strong>
              <span class="muted">
                <?php
                  if ($showIncidentDecisionModal && $focusedIncidentPayment) {
                      $resolutionNotes = trim((string) ($focusedIncidentPayment['resolution_notes'] ?? ''));
                      $statusLine = $notificationState === 'approved'
                        ? 'Admin approved your payment proof and the incident settlement is now marked as paid.'
                        : 'Admin rejected your payment proof. The incident remains payable until a new valid proof is approved.';
                      if ($resolutionNotes !== '') {
                          $statusLine .= ' ' . $resolutionNotes;
                      }
                      echo h($statusLine);
                  } elseif ($notificationPaymentContext === 'incident' && $focusedIncidentPayment) {
                      $resolutionNotes = trim((string) ($focusedIncidentPayment['resolution_notes'] ?? ''));
                      $statusLine = 'Case is ' . strtolower(book_incident_workflow_label((string) ($focusedIncidentPayment['workflow_status'] ?? 'open')))
                        . ' and settlement is ' . strtolower(book_incident_settlement_label((string) ($focusedIncidentPayment['settlement_status'] ?? 'pending'))) . '.';
                      if ($resolutionNotes !== '') {
                          $statusLine .= ' ' . $resolutionNotes;
                      }
                      echo h($statusLine);
                  } else {
                      echo 'Pending submissions still need admin approval before balances are fully settled.';
                  }
                ?>
              </span>
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var pageModal = document.querySelector('[data-member-notification-page-modal]');
  if (!pageModal) {
    return;
  }

  document.body.classList.add('modal-open');
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
      return;
    }
    var closeLink = pageModal.querySelector('.desk-modal-backdrop');
    if (closeLink) {
      window.location.href = closeLink.href;
    }
  });
});
</script>
</body>
</html>


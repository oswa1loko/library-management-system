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

if (isset($_POST['cancel_borrow_request'])) {
    $borrowIdsRaw = $_POST['borrow_ids'] ?? [];
    if (!is_array($borrowIdsRaw)) {
        $borrowIdsRaw = [];
    }

    $borrowIds = array_values(array_unique(array_filter(array_map('intval', $borrowIdsRaw), static function (int $id): bool {
        return $id > 0;
    })));
    $apiToken = ensure_member_api_token($conn, $userId);

    if ($borrowIds === []) {
        $msg = 'Select at least one pending borrow request first.';
        $msgType = 'error';
    } elseif ($apiToken === '') {
        $msg = 'Unable to initialize API token right now.';
        $msgType = 'error';
    } else {
        $response = member_api_post_request('borrows/cancel_request.php', [
            'borrow_ids' => $borrowIds,
        ], $apiToken);

        $json = $response['json'] ?? null;
        $isSuccess = is_array($json) && ($json['ok'] ?? false) === true;

        if ($isSuccess) {
            $canceledCount = (int) ($json['canceled_count'] ?? count($borrowIds));
            $msg = $canceledCount === 1 ? 'Pending borrow request canceled.' : $canceledCount . ' pending borrow requests canceled.';
        } else {
            $msg = (string) ($json['error'] ?? '');
            if ($msg === '' && (string) ($response['transport_error'] ?? '') !== '') {
                $msg = 'API request failed: ' . (string) $response['transport_error'];
            }
            if ($msg === '') {
                $msg = 'Unable to cancel borrow request right now.';
            }
            $msgType = 'error';
        }
    }
}

$overviewStmt = $conn->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_approvals,
      COALESCE(SUM(CASE WHEN status = 'borrowed' THEN 1 ELSE 0 END), 0) AS active_borrows,
      COALESCE(SUM(CASE WHEN status = 'return_requested' THEN 1 ELSE 0 END), 0) AS pending_returns,
      COALESCE(SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END), 0) AS completed_returns
    FROM borrows
    WHERE user_id = ?
");
$overviewStmt->bind_param('i', $userId);
$overviewStmt->execute();
$overview = $overviewStmt->get_result()->fetch_assoc();
$overviewStmt->close();

$trackingStmt = $conn->prepare("
    SELECT
      br.id,
      br.book_id,
      br.request_batch,
      br.status,
      br.requested_at,
      br.approved_at,
      br.borrow_date,
      br.due_at,
      br.due_date,
      br.return_date,
      br.returned_at,
      b.title,
      b.author
    FROM borrows br
    JOIN books b ON b.id = br.book_id
    WHERE br.user_id = ?
    ORDER BY COALESCE(br.requested_at, br.created_at) DESC, br.id ASC
");
$trackingStmt->bind_param('i', $userId);
$trackingStmt->execute();
$trackingRows = $trackingStmt->get_result();
$trackingStmt->close();

$incidentTracking = get_member_incidents($conn, $userId);
$incidentOverview = [
    'total' => count($incidentTracking),
    'under_review' => 0,
    'for_payment' => 0,
    'submitted' => 0,
    'closed' => 0,
];
foreach ($incidentTracking as $incidentItem) {
    $workflowStatus = book_incident_normalize_workflow_status((string) ($incidentItem['workflow_status'] ?? 'open'));
    $paymentStage = book_incident_payment_stage_label($incidentItem);

    if ($paymentStage === 'Payment Submitted') {
        $incidentOverview['submitted']++;
    } elseif ($workflowStatus === 'for_payment') {
        $incidentOverview['for_payment']++;
    } elseif ($workflowStatus === 'closed') {
        $incidentOverview['closed']++;
    } else {
        $incidentOverview['under_review']++;
    }
}

$borrowTrackingGroups = [];
while ($trackingRow = $trackingRows->fetch_assoc()) {
    $groupKey = (string) ($trackingRow['request_batch'] ?? '');
    if ($groupKey === '') {
        $groupKey = 'legacy-' . (int) $trackingRow['id'];
    }

    if (!isset($borrowTrackingGroups[$groupKey])) {
        $borrowTrackingGroups[$groupKey] = [
            'request_batch' => $groupKey,
            'requested_at' => (string) ($trackingRow['requested_at'] ?? ''),
            'total_items' => 0,
            'counts' => [
                'pending' => 0,
                'borrowed' => 0,
                'return_requested' => 0,
                'returned' => 0,
            ],
            'pending_borrow_ids' => [],
            'items' => [],
        ];
    }

    $borrowTrackingGroups[$groupKey]['total_items']++;
    $statusKey = (string) ($trackingRow['status'] ?? 'pending');
    if (isset($borrowTrackingGroups[$groupKey]['counts'][$statusKey])) {
        $borrowTrackingGroups[$groupKey]['counts'][$statusKey]++;
    }

    $itemGroupKey = (int) ($trackingRow['book_id'] ?? 0);
    if (!isset($borrowTrackingGroups[$groupKey]['items'][$itemGroupKey])) {
        $borrowTrackingGroups[$groupKey]['items'][$itemGroupKey] = [
            'book_id' => $itemGroupKey,
            'title' => (string) ($trackingRow['title'] ?? ''),
            'author' => (string) ($trackingRow['author'] ?? ''),
            'counts' => [
                'pending' => 0,
                'borrowed' => 0,
                'return_requested' => 0,
                'returned' => 0,
            ],
            'pending_borrow_ids' => [],
            'due_at' => (string) ($trackingRow['due_at'] ?? ''),
            'due_date' => (string) ($trackingRow['due_date'] ?? ''),
            'returned_at' => (string) ($trackingRow['returned_at'] ?? ''),
            'return_date' => (string) ($trackingRow['return_date'] ?? ''),
        ];
    }

    if (isset($borrowTrackingGroups[$groupKey]['items'][$itemGroupKey]['counts'][$statusKey])) {
        $borrowTrackingGroups[$groupKey]['items'][$itemGroupKey]['counts'][$statusKey]++;
    }

    if ($statusKey === 'pending') {
        $borrowTrackingGroups[$groupKey]['items'][$itemGroupKey]['pending_borrow_ids'][] = (int) ($trackingRow['id'] ?? 0);
    }
}

uasort($borrowTrackingGroups, static function (array $left, array $right): int {
    $leftPending = (int) (($left['counts']['pending'] ?? 0) > 0);
    $rightPending = (int) (($right['counts']['pending'] ?? 0) > 0);
    if ($leftPending !== $rightPending) {
        return $rightPending <=> $leftPending;
    }

    $leftTime = strtotime((string) ($left['requested_at'] ?? '')) ?: 0;
    $rightTime = strtotime((string) ($right['requested_at'] ?? '')) ?: 0;
    return $rightTime <=> $leftTime;
});

foreach ($borrowTrackingGroups as &$trackingGroup) {
    $trackingGroup['items'] = array_values($trackingGroup['items']);
}
unset($trackingGroup);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title($role, 'Records Tracking')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="<?php echo h($role); ?>-tracking">
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
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/book_incidents.php" data-tooltip="Book Incidents">
        <span class="dashboard-icon icon-notes" aria-hidden="true"></span>
        <span class="member-sidebar-label">Book Incidents</span>
      </a>
      <a class="member-sidebar-link is-active" href="/librarymanage/<?php echo h($role); ?>/tracking.php" data-tooltip="Records Tracking">
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
      <a class="member-sidebar-link" href="/librarymanage/<?php echo h($role); ?>/profile.php" data-tooltip="Profile">
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
        <p>Track the progress of your borrow requests, incident cases, and completed returns</p>
      </div>
    </div>

    <div class="stack">
      <?php if ($msg !== ''): ?>
        <div class="notice <?php echo $msgType === 'error' ? 'error' : 'success'; ?>"><?php echo h($msg); ?></div>
      <?php endif; ?>

      <div class="panel member-workspace-overview member-mobile-hide">
        <p class="muted eyebrow-compact stack-copy">Overview</p>
        <h3 class="heading-panel">Records tracking workspace</h3>
        <div class="stat-grid">
          <div class="stat-card">
            <strong><?php echo (int) ($overview['pending_approvals'] ?? 0); ?></strong>
            <span class="muted">Pending approvals</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($overview['active_borrows'] ?? 0); ?></strong>
            <span class="muted">Active borrows</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($overview['pending_returns'] ?? 0); ?></strong>
            <span class="muted">Return requests pending</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($overview['completed_returns'] ?? 0); ?></strong>
            <span class="muted">Completed returns</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($incidentOverview['for_payment'] ?? 0); ?></strong>
            <span class="muted">Incidents waiting for payment</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($incidentOverview['submitted'] ?? 0); ?></strong>
            <span class="muted">Incident payments under review</span>
          </div>
        </div>
      </div>

      <div class="panel member-workspace-history">
        <div class="card-head">
          <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
          <div>
            <span class="chip">Incident Tracking</span>
            <h3 class="heading-top-md">Book Incident Progress</h3>
          </div>
        </div>
        <p class="muted copy-bottom">Use this section to check whether the librarian is still reviewing your report, waiting for your payment, or finishing the final closeout.</p>
        <div class="stat-grid">
          <div class="stat-card">
            <strong><?php echo (int) ($incidentOverview['total'] ?? 0); ?></strong>
            <span class="muted">All incident reports</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($incidentOverview['under_review'] ?? 0); ?></strong>
            <span class="muted">Still under librarian review</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($incidentOverview['for_payment'] ?? 0); ?></strong>
            <span class="muted">Ready for payment upload</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($incidentOverview['submitted'] ?? 0); ?></strong>
            <span class="muted">Waiting for admin review</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($incidentOverview['closed'] ?? 0); ?></strong>
            <span class="muted">Closed incident cases</span>
          </div>
        </div>
        <div class="table-wrap member-tracking-desktop">
          <table>
            <thead>
              <tr>
                <th>Incident Ref</th>
                <th>Book</th>
                <th>Reported</th>
                <th>Case Status</th>
                <th>Payment Status</th>
                <th>Next Step</th>
                <th>Fee</th>
                <th>Resolution</th>
              </tr>
            </thead>
            <tbody>
          <?php if ($incidentTracking === []): ?>
              <tr><td colspan="8" class="muted">No incident reports found yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($incidentTracking as $incident): ?>
            <?php
            $workflowStatus = (string) ($incident['workflow_status'] ?? 'open');
            $settlementStatus = (string) ($incident['settlement_status'] ?? 'pending');
            $currentStep = book_incident_workflow_label($workflowStatus);
            $paymentStep = book_incident_payment_stage_label($incident);
            $nextActor = book_incident_next_actor_label($workflowStatus, $settlementStatus);
            $canUploadPayment = book_incident_can_accept_payment_submission($incident);
            $nextStepCopy = 'The librarian is still reviewing this incident and will update the final fee or inventory action after assessment.';
            if ($canUploadPayment) {
                $nextStepCopy = 'Ready for payment upload';
            } elseif ($paymentStep === 'Payment Submitted') {
                $nextStepCopy = 'Waiting for admin approval';
            } elseif ($paymentStep === 'Payment Rejected') {
                $nextStepCopy = 'Upload a new payment proof';
            } elseif ($paymentStep === 'No Payment Needed') {
                $nextStepCopy = 'Waiting for final closeout';
            } elseif ($currentStep === 'Closed') {
                $nextStepCopy = 'Completed';
            }
            ?>
              <tr>
                <td>
                  <strong class="label-block">Incident #<?php echo (int) ($incident['id'] ?? 0); ?></strong>
                  <span class="muted">Borrow #<?php echo (int) ($incident['borrow_id'] ?? 0); ?></span>
                </td>
                <td>
                  <strong class="label-block"><?php echo h((string) ($incident['title'] ?? 'Incident record')); ?></strong>
                  <span class="muted"><?php echo h(book_incident_type_label((string) ($incident['incident_type'] ?? ''))); ?> | <?php echo h(book_incident_severity_label((string) ($incident['severity'] ?? ''))); ?></span>
                </td>
                <td><?php echo h(format_display_datetime((string) ($incident['reported_at'] ?? ''))); ?></td>
                <td>
                  <span class="badge">
                    <span class="status-dot <?php echo h(book_incident_status_dot_class($workflowStatus)); ?>"></span>
                    <?php echo h($currentStep); ?>
                  </span>
                </td>
                <td>
                  <span class="badge">
                    <span class="status-dot <?php echo h(book_incident_payment_stage_dot_class($incident)); ?>"></span>
                    <?php echo h($paymentStep); ?>
                  </span>
                </td>
                <td>
                  <strong class="label-block"><?php echo h($nextActor); ?></strong>
                  <span class="muted"><?php echo h($nextStepCopy); ?></span>
                </td>
                <td><?php echo h(format_currency($incident['assessed_fee'] ?? 0)); ?></td>
                <td>
                  <strong class="label-block"><?php echo h(book_incident_resolution_label((string) ($incident['resolution_action'] ?? 'none'))); ?></strong>
                    <?php if (trim((string) ($incident['resolution_notes'] ?? '')) !== ''): ?>
                    <span class="muted"><?php echo nl2br(h((string) $incident['resolution_notes'])); ?></span>
                    <?php endif; ?>
                </td>
              </tr>
          <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel member-workspace-history">
        <div class="card-head">
          <div class="dashboard-icon icon-books" aria-hidden="true"></div>
          <div>
            <span class="chip">Records Tracking</span>
            <h3 class="heading-top-md">Borrow Request Progress</h3>
          </div>
        </div>
        <p class="muted copy-bottom">See where each borrow batch is in the workflow: pending approval, active borrow, return requested, or completed.</p>
        <div class="table-wrap member-tracking-desktop">
          <table>
            <thead>
              <tr>
                <th>Borrow Ref</th>
                <th>Requested</th>
                <th>Book</th>
                <th>Copies</th>
                <th>Due / Returned</th>
                <th>Item Progress</th>
                <th>Action</th>
                <th>Batch Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($borrowTrackingGroups === []): ?>
                <tr><td colspan="8" class="muted">No borrow activity found yet.</td></tr>
              <?php endif; ?>
              <?php foreach ($borrowTrackingGroups as $trackingGroup): ?>
                <?php
                $counts = $trackingGroup['counts'];
                $groupStatusLabel = 'Pending approval';
                $groupStatusDot = 'pending';
                if ((int) ($counts['returned'] ?? 0) === (int) ($trackingGroup['total_items'] ?? 0)) {
                    $groupStatusLabel = 'Completed';
                    $groupStatusDot = 'approved';
                } elseif ((int) ($counts['return_requested'] ?? 0) > 0) {
                    $groupStatusLabel = 'Waiting for return confirmation';
                    $groupStatusDot = 'return_requested';
                } elseif ((int) ($counts['borrowed'] ?? 0) > 0) {
                    $groupStatusLabel = 'Approved and borrowed';
                    $groupStatusDot = 'borrowed';
                }
                $requestLabel = format_batch_reference($trackingGroup['request_batch'], 'Borrow Ref');
                $requestedAt = format_display_datetime((string) ($trackingGroup['requested_at'] ?? ''), '-');
                ?>
                <?php foreach ($trackingGroup['items'] as $index => $trackingItem): ?>
                  <?php
                  $itemCounts = $trackingItem['counts'];
                  $copies = array_sum(array_map('intval', $itemCounts));
                  $itemStatusSummaryParts = [];
                  foreach (['pending' => 'pending', 'borrowed' => 'borrowed', 'return_requested' => 'return requested', 'returned' => 'returned'] as $itemStatusKey => $itemStatusText) {
                      $itemCount = (int) ($itemCounts[$itemStatusKey] ?? 0);
                      if ($itemCount > 0) {
                          $itemStatusSummaryParts[] = $itemCount . ' ' . $itemStatusText;
                      }
                  }
                  $itemStatusSummary = implode(' | ', $itemStatusSummaryParts);
                  $dateLabel = '-';
                  if ((int) ($itemCounts['borrowed'] ?? 0) > 0 || (int) ($itemCounts['return_requested'] ?? 0) > 0) {
                      $dateLabel = 'Due ' . format_display_datetime((string) (($trackingItem['due_at'] ?? '') ?: ($trackingItem['due_date'] ?? '')), '-');
                  } elseif ((int) ($itemCounts['returned'] ?? 0) > 0) {
                      $dateLabel = 'Returned ' . format_display_datetime((string) (($trackingItem['returned_at'] ?? '') ?: ($trackingItem['return_date'] ?? '')), '-');
                  }
                  ?>
                  <tr>
                    <?php if ($index === 0): ?>
                      <td rowspan="<?php echo count($trackingGroup['items']); ?>">
                        <strong class="label-block"><?php echo h($requestLabel); ?></strong>
                      </td>
                      <td rowspan="<?php echo count($trackingGroup['items']); ?>">
                        <?php echo h($requestedAt); ?>
                      </td>
                    <?php endif; ?>
                    <td>
                      <strong class="label-block"><?php echo h($trackingItem['title']); ?></strong>
                      <span class="muted"><?php echo h((string) ($trackingItem['author'] ?? '-')); ?></span>
                    </td>
                    <td><?php echo (int) $copies; ?></td>
                    <td><?php echo h($dateLabel); ?></td>
                    <td>
                      <span class="badge"><?php echo h($itemStatusSummary); ?></span>
                    </td>
                    <td>
                      <?php if ((int) ($itemCounts['pending'] ?? 0) > 0 && (($trackingItem['pending_borrow_ids'] ?? []) !== [])): ?>
                        <form method="post" class="inline-form" data-confirm="Cancel this pending borrow request?">
                          <?php foreach (($trackingItem['pending_borrow_ids'] ?? []) as $pendingBorrowId): ?>
                            <input type="hidden" name="borrow_ids[]" value="<?php echo (int) $pendingBorrowId; ?>">
                          <?php endforeach; ?>
                          <button type="submit" name="cancel_borrow_request" value="1" class="button secondary">Cancel</button>
                        </form>
                      <?php else: ?>
                        <span class="muted">Locked</span>
                      <?php endif; ?>
                    </td>
                    <?php if ($index === 0): ?>
                      <td rowspan="<?php echo count($trackingGroup['items']); ?>">
                        <span class="badge">
                          <span class="status-dot <?php echo h($groupStatusDot); ?>"></span>
                          <?php echo h($groupStatusLabel); ?>
                        </span>
                        <div class="muted meta-top-sm">
                          <?php echo (int) ($trackingGroup['total_items'] ?? 0); ?> total
                          <?php if ((int) ($counts['pending'] ?? 0) > 0): ?> | <?php echo (int) $counts['pending']; ?> pending<?php endif; ?>
                          <?php if ((int) ($counts['borrowed'] ?? 0) > 0): ?> | <?php echo (int) $counts['borrowed']; ?> borrowed<?php endif; ?>
                          <?php if ((int) ($counts['return_requested'] ?? 0) > 0): ?> | <?php echo (int) $counts['return_requested']; ?> return requested<?php endif; ?>
                          <?php if ((int) ($counts['returned'] ?? 0) > 0): ?> | <?php echo (int) $counts['returned']; ?> returned<?php endif; ?>
                        </div>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
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

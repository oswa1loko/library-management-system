<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/book_incidents.php';

require_role('admin');

$noticeItems = [];
$flash = trim((string) ($_GET['notice'] ?? ''));
$flashType = trim((string) ($_GET['notice_type'] ?? ''));
$settlementFilter = trim((string) ($_GET['settlement'] ?? ''));
$selectedIncidentId = (int) ($_GET['incident'] ?? ($_POST['incident_id'] ?? 0));

if ($flash !== '') {
    $noticeItems[] = [
        'type' => in_array($flashType, ['success', 'error', 'warning', 'info'], true) ? $flashType : 'info',
        'message' => $flash,
    ];
}

if (isset($_POST['approve_payment']) || isset($_POST['reject_payment'])) {
    $incidentId = (int) ($_POST['incident_id'] ?? 0);
    $paymentId = (int) ($_POST['payment_id'] ?? 0);
    $decision = isset($_POST['reject_payment']) ? 'reject' : 'approve';
    $result = review_admin_incident_payment($conn, $incidentId, $paymentId, (int) ($_SESSION['user_id'] ?? 0), $decision);

    $redirectQuery = [];
    if ($settlementFilter !== '') {
        $redirectQuery['settlement'] = $settlementFilter;
    }
    if ($incidentId > 0) {
        $redirectQuery['incident'] = $incidentId;
    }
    $redirectQuery['notice'] = $result['message'] ?? '';
    $redirectQuery['notice_type'] = !empty($result['ok']) ? 'success' : 'error';

    header('Location: book_incidents_records.php?' . http_build_query($redirectQuery));
    exit;
}

$summary = book_incident_summary($conn);
$incidents = get_admin_incidents($conn, $settlementFilter);
$selectedIncident = null;
foreach ($incidents as $incidentItem) {
    if ((int) ($incidentItem['id'] ?? 0) === $selectedIncidentId) {
        $selectedIncident = $incidentItem;
        break;
    }
}

$baseQuery = [];
if ($settlementFilter !== '') {
    $baseQuery['settlement'] = $settlementFilter;
}
$baseUrl = 'book_incidents_records.php';
$baseHref = $baseUrl . ($baseQuery !== [] ? '?' . http_build_query($baseQuery) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('admin', 'Book Incidents')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="<?php echo h(app_url('assets/theme.js?v=' . urlencode($themeVersion))); ?>"></script>
<link rel="stylesheet" href="<?php echo h(app_url('assets/app.css?v=' . urlencode($assetVersion))); ?>">
</head>
<body>
<div class="site-shell admin-shell member-shell js-member-sidebar" data-sidebar-key="admin-book-incidents" data-sidebar-default="expanded" data-sidebar-lock="expanded">
  <?php
  $sidebarPage = 'book_incidents';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Book Incident Records';
  $pageSubtitle = 'Monitor library-wide lost and damaged reports through a simpler incident workflow';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <?php require __DIR__ . '/partials/notices.php'; ?>

    <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Overview</p>
          <h3 class="heading-card">System-wide incident records</h3>
          <p class="muted">Admin now reviews the student's uploaded incident payment proof directly from this page after the librarian moves the case to payment.</p>
        </div>
      </div>
      <div class="stat-grid">
        <div class="stat-card">
          <span class="code-pill">Cases</span>
          <strong><?php echo (int) ($summary['total_incidents'] ?? 0); ?></strong>
          <span class="muted">All lost and damaged reports across the system.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Open</span>
          <strong><?php echo (int) ($summary['open_incidents'] ?? 0); ?></strong>
          <span class="muted">Cases not yet fully settled or closed.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Pending Fees</span>
          <strong><?php echo h(format_currency($summary['pending_fees'] ?? 0)); ?></strong>
          <span class="muted">Outstanding assessed amounts awaiting settlement action.</span>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="card-head card-head-tight">
        <div class="dashboard-icon icon-checklist" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Filter</p>
          <h3 class="heading-card">Settlement view</h3>
          <p class="muted">Use this when you want to focus on pending payments, waived fees, or replacement submissions only.</p>
        </div>
      </div>
      <form method="get" class="toolbar grow admin-record-filters penalties-record-filters">
        <div>
          <label for="settlement">Settlement status</label>
          <select id="settlement" name="settlement">
            <option value="">All settlement states</option>
            <?php foreach (book_incident_settlement_options() as $value => $label): ?>
              <option value="<?php echo h($value); ?>" <?php echo $settlementFilter === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="inline-actions">
          <button type="submit">Apply Filter</button>
          <a class="button secondary" href="<?php echo h(app_url('admin/book_incidents_records.php')); ?>">Reset</a>
        </div>
      </form>
    </div>

    <div class="stack">
      <?php if ($incidents === []): ?>
        <div class="panel"><div class="empty-state">No incident records matched the current filter.</div></div>
      <?php endif; ?>
      <?php foreach ($incidents as $incident): ?>
        <div class="panel member-return-batch-card">
          <div class="member-return-batch-head">
            <div>
              <strong class="label-block"><?php echo h($incident['title']); ?></strong>
              <span class="muted">
                Incident #<?php echo (int) $incident['id']; ?> |
                <?php echo h($incident['fullname']); ?> (<?php echo h(role_label((string) ($incident['role'] ?? ''))); ?>) |
                Borrow #<?php echo (int) $incident['borrow_id']; ?>
              </span>
              <div class="inline-actions chips-row batch-status-row">
                <span class="chip"><?php echo h(book_incident_type_label((string) ($incident['incident_type'] ?? ''))); ?></span>
                <span class="chip"><?php echo h(book_incident_resolution_label((string) ($incident['resolution_action'] ?? 'none'))); ?></span>
                <span class="chip"><?php echo h(format_currency($incident['assessed_fee'] ?? 0)); ?></span>
                <span class="chip"><?php echo h(book_incident_payment_stage_label($incident)); ?></span>
                <span class="chip"><?php echo h(book_incident_next_actor_label((string) ($incident['workflow_status'] ?? 'open'), (string) ($incident['settlement_status'] ?? 'pending'))); ?></span>
              </div>
            </div>
            <div class="stack flow-gap-sm">
              <span class="badge">
                <span class="status-dot <?php echo h(book_incident_status_dot_class((string) ($incident['workflow_status'] ?? 'open'))); ?>"></span>
                <?php echo h(book_incident_workflow_label((string) ($incident['workflow_status'] ?? 'open'))); ?>
              </span>
              <span class="badge">
                <span class="status-dot <?php echo h(book_incident_status_dot_class((string) ($incident['settlement_status'] ?? 'pending'))); ?>"></span>
                <?php echo h(book_incident_settlement_label((string) ($incident['settlement_status'] ?? 'pending'))); ?>
              </span>
              <span class="badge">
                <span class="status-dot <?php echo h(book_incident_payment_stage_dot_class($incident)); ?>"></span>
                <?php echo h(book_incident_payment_stage_label($incident)); ?>
              </span>
            </div>
          </div>

          <div class="grid cards">
            <div class="empty-state">
              <strong class="label-block-gap">Incident details</strong>
              Reported: <?php echo h(format_display_datetime((string) ($incident['reported_at'] ?? ''))); ?><br>
              Severity: <?php echo h(book_incident_severity_label((string) ($incident['severity'] ?? ''))); ?><br>
              Notes: <?php echo nl2br(h(trim((string) ($incident['resolution_notes'] ?? '')) !== '' ? (string) $incident['resolution_notes'] : 'No notes yet.')); ?>
            </div>
            <div class="empty-state">
              <strong class="label-block-gap">Payment review</strong>
              Submitted amount: <?php echo h(format_currency($incident['latest_payment_amount'] ?? 0)); ?><br>
              Proof:
              <?php if (!empty($incident['latest_payment_proof_path'])): ?>
                <a href="<?php echo h(app_url('proof_view.php?payment_id=' . (int) ($incident['latest_payment_id'] ?? 0))); ?>" target="_blank">View uploaded proof</a>
              <?php else: ?>
                <span class="muted">No proof uploaded yet.</span>
              <?php endif; ?><br>
              Latest payment status: <?php echo h(ucfirst((string) ($incident['latest_payment_status'] ?? 'none'))); ?>
            </div>
          </div>
          <div class="inline-actions member-workspace-actions">
            <a class="button" href="<?php echo h($baseUrl . '?' . http_build_query($baseQuery + ['incident' => (int) $incident['id']])); ?>">View Details</a>
            <?php if ((string) ($incident['latest_payment_status'] ?? '') === 'pending' && (int) ($incident['latest_payment_id'] ?? 0) > 0): ?>
              <form method="post" class="inline-form">
                <input type="hidden" name="incident_id" value="<?php echo (int) $incident['id']; ?>">
                <input type="hidden" name="payment_id" value="<?php echo (int) $incident['latest_payment_id']; ?>">
                <button type="submit" name="approve_payment" value="1">Approve Payment</button>
              </form>
              <form method="post" class="inline-form">
                <input type="hidden" name="incident_id" value="<?php echo (int) $incident['id']; ?>">
                <input type="hidden" name="payment_id" value="<?php echo (int) $incident['latest_payment_id']; ?>">
                <button type="submit" class="danger" name="reject_payment" value="1">Reject Payment</button>
              </form>
            <?php else: ?>
              <span class="muted">Approve or reject uploaded proofs here once the student submits payment.</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  </div>
</div>
<?php if ($selectedIncident): ?>
  <div class="desk-modal" data-desk-modal>
    <a class="desk-modal-backdrop" href="<?php echo h($baseHref); ?>" aria-label="Close settlement details"></a>
    <div class="desk-modal-dialog panel" role="dialog" aria-modal="true" aria-labelledby="incident-settlement-modal-title">
      <div class="desk-modal-head">
        <div>
          <p class="muted eyebrow-compact">Incident Details</p>
          <h3 id="incident-settlement-modal-title" class="heading-card"><?php echo h($selectedIncident['title']); ?></h3>
          <p class="muted">Review the final incident state, inspect the uploaded proof, and approve the payment from this incident workspace.</p>
        </div>
        <a class="button secondary" href="<?php echo h($baseHref); ?>">Close</a>
      </div>

      <div class="grid cards">
        <div class="empty-state">
          <strong class="label-block-gap">Incident details</strong>
          Incident #<?php echo (int) $selectedIncident['id']; ?><br>
          Borrower: <?php echo h($selectedIncident['fullname']); ?> (<?php echo h(role_label((string) ($selectedIncident['role'] ?? ''))); ?>)<br>
          Borrow #<?php echo (int) $selectedIncident['borrow_id']; ?><br>
          Type: <?php echo h(book_incident_type_label((string) ($selectedIncident['incident_type'] ?? ''))); ?><br>
          Severity: <?php echo h(book_incident_severity_label((string) ($selectedIncident['severity'] ?? ''))); ?><br>
          Reported: <?php echo h(format_display_datetime((string) ($selectedIncident['reported_at'] ?? ''))); ?>
        </div>
        <div class="empty-state">
          <strong class="label-block-gap">Review outcome</strong>
          Workflow: <?php echo h(book_incident_workflow_label((string) ($selectedIncident['workflow_status'] ?? 'open'))); ?><br>
          Settlement: <?php echo h(book_incident_settlement_label((string) ($selectedIncident['settlement_status'] ?? 'pending'))); ?><br>
          Payment stage: <?php echo h(book_incident_payment_stage_label($selectedIncident)); ?><br>
          Inventory action: <?php echo h(book_incident_resolution_label((string) ($selectedIncident['resolution_action'] ?? 'none'))); ?><br>
          Assessed fee: <?php echo h(format_currency($selectedIncident['assessed_fee'] ?? 0)); ?><br>
          Submitted amount: <?php echo h(format_currency($selectedIncident['latest_payment_amount'] ?? 0)); ?><br>
          Next: <?php echo h(book_incident_next_actor_label((string) ($selectedIncident['workflow_status'] ?? 'open'), (string) ($selectedIncident['settlement_status'] ?? 'pending'))); ?>
        </div>
        <div class="empty-state">
          <strong class="label-block-gap">Notes and proof</strong>
          <?php if (!empty($selectedIncident['latest_payment_proof_path'])): ?>
            <a class="button secondary flow-bottom-sm" href="<?php echo h(app_url('proof_view.php?payment_id=' . (int) ($selectedIncident['latest_payment_id'] ?? 0))); ?>" target="_blank">View Uploaded Proof</a><br>
          <?php else: ?>
            <span class="muted">No payment proof uploaded yet.</span><br>
          <?php endif; ?>
          <?php echo nl2br(h(trim((string) ($selectedIncident['resolution_notes'] ?? '')) !== '' ? (string) $selectedIncident['resolution_notes'] : 'No notes yet.')); ?>
        </div>
      </div>
      <div class="inline-actions member-workspace-actions">
        <?php if ((string) ($selectedIncident['latest_payment_status'] ?? '') === 'pending' && (int) ($selectedIncident['latest_payment_id'] ?? 0) > 0): ?>
          <form method="post" class="inline-form">
            <input type="hidden" name="incident_id" value="<?php echo (int) $selectedIncident['id']; ?>">
            <input type="hidden" name="payment_id" value="<?php echo (int) $selectedIncident['latest_payment_id']; ?>">
            <button type="submit" name="approve_payment" value="1">Approve Payment</button>
          </form>
          <form method="post" class="inline-form">
            <input type="hidden" name="incident_id" value="<?php echo (int) $selectedIncident['id']; ?>">
            <input type="hidden" name="payment_id" value="<?php echo (int) $selectedIncident['latest_payment_id']; ?>">
            <button type="submit" class="danger" name="reject_payment" value="1">Reject Payment</button>
          </form>
        <?php else: ?>
          <span class="muted">This incident will move to `Paid` automatically after approval, or wait for a new upload after rejection.</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
<script src="<?php echo h(app_url('assets/member_sidebar.js?v=' . urlencode($memberSidebarVersion))); ?>"></script>
</body>
</html>

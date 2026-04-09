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
$selectedIncidentId = (int) ($_GET['incident'] ?? 0);

if ($flash !== '') {
    $noticeItems[] = [
        'type' => in_array($flashType, ['success', 'error', 'warning', 'info'], true) ? $flashType : 'info',
        'message' => $flash,
    ];
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

    <div class="panel" data-filter-panel>
      <div class="card-head">
        <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Overview</p>
          <h3 class="heading-card">System-wide incident records</h3>
          <p class="muted">Admin can monitor incident progress, borrower context, and case outcomes here after the librarian finishes the assessment.</p>
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
          <p class="muted">Use this when you want to focus on pending fees, paid incidents, or waived cases only.</p>
        </div>
      </div>
      <form method="get" class="toolbar grow admin-record-filters penalties-record-filters" data-auto-submit-filter>
        <div>
          <label for="settlement">Settlement status</label>
          <select id="settlement" name="settlement" data-auto-submit-control>
            <option value="">All settlement states</option>
            <?php foreach (book_incident_settlement_options() as $value => $label): ?>
              <option value="<?php echo h($value); ?>" <?php echo $settlementFilter === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="inline-actions">
          <noscript><button type="submit">Apply Filter</button></noscript>
          <?php if ($settlementFilter !== ''): ?>
            <a class="button secondary" href="<?php echo h(app_url('admin/book_incidents_records.php')); ?>">Clear Filter</a>
          <?php endif; ?>
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
              </div>
            </div>
            <div class="stack flow-gap-sm">
              <span class="badge">
                <span class="status-dot <?php echo h(book_incident_status_dot_class((string) ($incident['workflow_status'] ?? 'open'))); ?>"></span>
                Case: <?php echo h(book_incident_workflow_label((string) ($incident['workflow_status'] ?? 'open'))); ?>
              </span>
              <span class="badge">
                <span class="status-dot <?php echo h(book_incident_payment_stage_dot_class($incident)); ?>"></span>
                Payment: <?php echo h(book_incident_payment_stage_label($incident)); ?>
              </span>
            </div>
          </div>

          <div class="stack member-return-batch-list">
            <div class="empty-state member-return-batch-item">
              <span class="grow">
                <strong class="label-block meta-top-sm">Incident Details</strong>
                <span class="muted">
                  Reported: <?php echo h(format_display_datetime((string) ($incident['reported_at'] ?? ''))); ?> |
                  Severity: <?php echo h(book_incident_severity_label((string) ($incident['severity'] ?? ''))); ?>
                </span>
                <?php if (trim((string) ($incident['description'] ?? '')) !== ''): ?>
                  <span class="muted meta-top-sm"><?php echo nl2br(h((string) $incident['description'])); ?></span>
                <?php else: ?>
                  <span class="muted meta-top-sm">No description was submitted for this incident.</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="empty-state member-return-batch-item">
              <span class="grow">
                <strong class="label-block meta-top-sm">Resolution</strong>
                <span class="muted">
                  Action: <?php echo h(book_incident_resolution_label((string) ($incident['resolution_action'] ?? 'none'))); ?> |
                  Fee: <?php echo h(format_currency($incident['assessed_fee'] ?? 0)); ?> |
                  Payment: <?php echo h(book_incident_payment_stage_label($incident)); ?>
                </span>
                <?php if (trim((string) ($incident['resolution_notes'] ?? '')) !== ''): ?>
                  <span class="muted meta-top-sm"><?php echo nl2br(h((string) $incident['resolution_notes'])); ?></span>
                <?php else: ?>
                  <span class="muted meta-top-sm">No resolution notes yet.</span>
                <?php endif; ?>
              </span>
            </div>
          </div>
          <div class="inline-actions member-workspace-actions">
            <a class="button" href="<?php echo h($baseUrl . '?' . http_build_query($baseQuery + ['incident' => (int) $incident['id']])); ?>">View Details</a>
            <?php if ((string) ($incident['latest_payment_status'] ?? '') === 'pending' && (int) ($incident['latest_payment_id'] ?? 0) > 0): ?>
              <a class="button secondary" href="<?php echo h(app_url('admin/payments_records.php?scope=incidents&search=' . urlencode((string) ($incident['latest_payment_id'] ?? 0)))); ?>">Open Payment Review</a>
            <?php else: ?>
              <span class="muted">Review actions for incident proofs now live in Incident Payments.</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var scrollStorageKey = 'admin-book-incidents-filter-scroll';
  var savedScrollTarget = '';

  try {
    savedScrollTarget = window.sessionStorage.getItem(scrollStorageKey) || '';
  } catch (error) {
    savedScrollTarget = '';
  }

  if (savedScrollTarget === 'filter-panel') {
    var filterPanel = document.querySelector('[data-filter-panel]');
    if (filterPanel) {
      window.requestAnimationFrame(function () {
        var top = filterPanel.getBoundingClientRect().top + window.scrollY - 24;
        window.scrollTo({ top: Math.max(0, top), behavior: 'auto' });
      });
    }

    try {
      window.sessionStorage.removeItem(scrollStorageKey);
    } catch (error) {
      // Ignore session storage cleanup failures.
    }
  }

  document.querySelectorAll('[data-auto-submit-filter]').forEach(function (form) {
    var control = form.querySelector('[data-auto-submit-control]');
    if (!control || control.dataset.autoSubmitBound === '1') {
      return;
    }

    control.dataset.autoSubmitBound = '1';
    control.addEventListener('change', function () {
      try {
        window.sessionStorage.setItem(scrollStorageKey, 'filter-panel');
      } catch (error) {
        // Ignore session storage failures and continue with submit.
      }

      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
        return;
      }

      form.submit();
    });
  });
});
</script>
<?php if ($selectedIncident): ?>
  <div class="desk-modal" data-desk-modal>
    <a class="desk-modal-backdrop" href="<?php echo h($baseHref); ?>" aria-label="Close settlement details"></a>
    <div class="desk-modal-dialog panel" role="dialog" aria-modal="true" aria-labelledby="incident-settlement-modal-title">
      <div class="desk-modal-head">
        <div>
          <p class="muted eyebrow-compact">Incident Details</p>
          <h3 id="incident-settlement-modal-title" class="heading-card"><?php echo h($selectedIncident['title']); ?></h3>
          <p class="muted">Review the final incident state, inspect the uploaded proof, and jump to Incident Payments when an admin decision is needed.</p>
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
          Payment stage: <?php echo h(book_incident_payment_stage_label($selectedIncident)); ?><br>
          Inventory action: <?php echo h(book_incident_resolution_label((string) ($selectedIncident['resolution_action'] ?? 'none'))); ?><br>
          Assessed fee: <?php echo h(format_currency($selectedIncident['assessed_fee'] ?? 0)); ?><br>
          Submitted amount: <?php echo h(format_currency($selectedIncident['latest_payment_amount'] ?? 0)); ?>
        </div>
        <div class="empty-state">
          <strong class="label-block-gap">Description and notes</strong>
          <?php echo nl2br(h(trim((string) ($selectedIncident['description'] ?? '')) !== '' ? (string) $selectedIncident['description'] : 'No description was submitted for this incident.')); ?><br><br>
          <strong class="label-block-gap">Payment proof</strong>
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
          <a class="button" href="<?php echo h(app_url('admin/payments_records.php?scope=incidents&search=' . urlencode((string) ($selectedIncident['latest_payment_id'] ?? 0)))); ?>">Open Payment Review</a>
          <span class="muted">Approve or reject this uploaded proof from the Incident Payments workspace.</span>
        <?php else: ?>
          <span class="muted">This modal is now view-only so you can inspect the incident without leaving the page.</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
<script src="<?php echo h(app_url('assets/member_sidebar.js?v=' . urlencode($memberSidebarVersion))); ?>"></script>
</body>
</html>

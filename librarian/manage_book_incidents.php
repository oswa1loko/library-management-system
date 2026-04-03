<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/book_incidents.php';

require_role('librarian');

$noticeItems = [];
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$typeFilter = trim((string) ($_GET['type'] ?? ''));
$selectedIncidentId = (int) ($_GET['incident'] ?? ($_POST['incident_id'] ?? 0));

if (isset($_POST['update_incident'])) {
    $result = update_librarian_book_incident($conn, (int) ($_POST['incident_id'] ?? 0), (int) ($_SESSION['user_id'] ?? 0), [
        'workflow_status' => (string) ($_POST['workflow_status'] ?? ''),
        'resolution_action' => (string) ($_POST['resolution_action'] ?? ''),
        'settlement_status' => (string) ($_POST['settlement_status'] ?? ''),
        'severity' => (string) ($_POST['severity'] ?? ''),
        'assessed_fee' => (string) ($_POST['assessed_fee'] ?? '0'),
        'resolution_notes' => (string) ($_POST['resolution_notes'] ?? ''),
    ]);
    $noticeItems[] = [
        'type' => ($result['ok'] ?? false) ? 'success' : 'error',
        'message' => (string) ($result['message'] ?? ''),
    ];
}

$summary = book_incident_summary($conn);
$incidents = get_librarian_incidents($conn, $statusFilter, $typeFilter);
$selectedIncident = null;
foreach ($incidents as $incidentItem) {
    if ((int) ($incidentItem['id'] ?? 0) === $selectedIncidentId) {
        $selectedIncident = $incidentItem;
        break;
    }
}

$baseQuery = [];
if ($statusFilter !== '') {
    $baseQuery['status'] = $statusFilter;
}
if ($typeFilter !== '') {
    $baseQuery['type'] = $typeFilter;
}
$baseUrl = 'manage_book_incidents.php';
$baseHref = $baseUrl . ($baseQuery !== [] ? '?' . http_build_query($baseQuery) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Book Incidents')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="<?php echo h(app_url('assets/theme.js?v=' . urlencode($themeVersion))); ?>"></script>
<link rel="stylesheet" href="<?php echo h(app_url('assets/app.css?v=' . urlencode($assetVersion))); ?>">
</head>
<body>
<div class="site-shell librarian-shell member-shell js-member-sidebar" data-sidebar-key="librarian-book-incidents" data-sidebar-default="expanded">
  <?php
  $sidebarPage = 'book_incidents';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Book Incident Desk';
  $pageSubtitle = 'Review lost and damaged reports, then connect them to stock and settlement actions';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <?php require __DIR__ . '/partials/notices.php'; ?>

    <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Overview</p>
          <h3 class="heading-card">Incident review workspace</h3>
          <p class="muted">Review the report, choose the inventory action, then send the case to settlement or close it.</p>
        </div>
      </div>
      <div class="stat-grid">
        <div class="stat-card">
          <span class="code-pill">All cases</span>
          <strong><?php echo (int) ($summary['total_incidents'] ?? 0); ?></strong>
          <span class="muted">All recorded lost and damaged incidents.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Open</span>
          <strong><?php echo (int) ($summary['open_incidents'] ?? 0); ?></strong>
          <span class="muted">Still waiting for review or settlement follow-through.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Lost</span>
          <strong><?php echo (int) ($summary['lost_incidents'] ?? 0); ?></strong>
          <span class="muted">Cases reported as lost books.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Damaged</span>
          <strong><?php echo (int) ($summary['damaged_incidents'] ?? 0); ?></strong>
          <span class="muted">Cases reported as damaged books.</span>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="card-head card-head-tight">
        <div class="dashboard-icon icon-checklist" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Filters</p>
          <h3 class="heading-card">Focus the current queue</h3>
          <p class="muted">Start with `Reported`, move to `Under Review`, then finish with `Awaiting Settlement` or `Resolved`.</p>
        </div>
      </div>
      <form method="get" class="toolbar grow admin-record-filters penalties-record-filters">
        <div>
          <label for="status">Workflow</label>
          <select id="status" name="status">
            <option value="">All workflow states</option>
            <?php foreach (book_incident_workflow_options() as $value => $label): ?>
              <option value="<?php echo h($value); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="type">Incident type</label>
          <select id="type" name="type">
            <option value="">All incident types</option>
            <?php foreach (book_incident_type_options() as $value => $label): ?>
              <option value="<?php echo h($value); ?>" <?php echo $typeFilter === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="inline-actions">
          <button type="submit">Apply Filters</button>
          <a class="button secondary" href="<?php echo h(app_url('librarian/manage_book_incidents.php')); ?>">Reset</a>
        </div>
      </form>
    </div>

    <div class="stack">
      <?php if ($incidents === []): ?>
        <div class="panel"><div class="empty-state">No incidents matched the current filters.</div></div>
      <?php endif; ?>
      <?php foreach ($incidents as $incident): ?>
        <?php $isLocked = in_array((string) ($incident['workflow_status'] ?? ''), ['resolved', 'rejected'], true); ?>
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
                <span class="chip">Severity: <?php echo h(book_incident_severity_label((string) ($incident['severity'] ?? ''))); ?></span>
                <span class="chip">Reported <?php echo h(format_display_datetime((string) ($incident['reported_at'] ?? ''))); ?></span>
                <span class="chip"><?php echo h(book_incident_next_actor_label((string) ($incident['workflow_status'] ?? 'reported'), (string) ($incident['settlement_status'] ?? 'pending'))); ?></span>
              </div>
            </div>
            <div class="stack flow-gap-sm">
              <span class="badge">
                <span class="status-dot <?php echo h(book_incident_status_dot_class((string) ($incident['workflow_status'] ?? 'reported'))); ?>"></span>
                <?php echo h(book_incident_workflow_label((string) ($incident['workflow_status'] ?? 'reported'))); ?>
              </span>
              <span class="badge">
                <span class="status-dot <?php echo h(book_incident_status_dot_class((string) ($incident['settlement_status'] ?? 'pending'))); ?>"></span>
                <?php echo h(book_incident_settlement_label((string) ($incident['settlement_status'] ?? 'pending'))); ?>
              </span>
            </div>
          </div>

          <div class="grid cards">
            <div class="empty-state">
              <strong class="label-block-gap">Borrow context</strong>
              Status: <?php echo h(ucfirst((string) ($incident['borrow_status'] ?? 'n/a'))); ?><br>
              Borrowed: <?php echo h(format_display_date((string) ($incident['borrow_date'] ?? ''), '-')); ?><br>
              Due: <?php echo h(format_display_date((string) ($incident['due_date'] ?? ''), '-')); ?>
            </div>
            <div class="empty-state">
              <strong class="label-block-gap">Member description</strong>
              <?php echo nl2br(h((string) ($incident['description'] ?? ''))); ?>
            </div>
          </div>
          <div class="inline-actions member-workspace-actions">
            <a class="button" href="<?php echo h($baseUrl . '?' . http_build_query($baseQuery + ['incident' => (int) $incident['id']])); ?>">Review Incident</a>
            <span class="muted">Open the full review form in a modal so the queue stays visible.</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  </div>
</div>
<?php if ($selectedIncident): ?>
  <?php $isLocked = in_array((string) ($selectedIncident['workflow_status'] ?? ''), ['resolved', 'rejected'], true); ?>
  <div class="desk-modal" data-desk-modal>
    <a class="desk-modal-backdrop" href="<?php echo h($baseHref); ?>" aria-label="Close incident review"></a>
    <div class="desk-modal-dialog panel" role="dialog" aria-modal="true" aria-labelledby="incident-review-modal-title">
      <div class="desk-modal-head">
        <div>
          <p class="muted eyebrow-compact">Incident Review</p>
          <h3 id="incident-review-modal-title" class="heading-card"><?php echo h($selectedIncident['title']); ?></h3>
          <p class="muted">Review the report, choose the inventory action, then move the case toward settlement or closure.</p>
        </div>
        <a class="button secondary" href="<?php echo h($baseHref); ?>">Close</a>
      </div>

      <div class="grid cards">
        <div class="empty-state">
          <strong class="label-block-gap">Borrow context</strong>
          Incident #<?php echo (int) $selectedIncident['id']; ?><br>
          Borrower: <?php echo h($selectedIncident['fullname']); ?> (<?php echo h(role_label((string) ($selectedIncident['role'] ?? ''))); ?>)<br>
          Borrow #<?php echo (int) $selectedIncident['borrow_id']; ?><br>
          Status: <?php echo h(ucfirst((string) ($selectedIncident['borrow_status'] ?? 'n/a'))); ?><br>
          Borrowed: <?php echo h(format_display_date((string) ($selectedIncident['borrow_date'] ?? ''), '-')); ?><br>
          Due: <?php echo h(format_display_date((string) ($selectedIncident['due_date'] ?? ''), '-')); ?>
        </div>
        <div class="empty-state">
          <strong class="label-block-gap">Report details</strong>
          Type: <?php echo h(book_incident_type_label((string) ($selectedIncident['incident_type'] ?? ''))); ?><br>
          Severity: <?php echo h(book_incident_severity_label((string) ($selectedIncident['severity'] ?? ''))); ?><br>
          Reported: <?php echo h(format_display_datetime((string) ($selectedIncident['reported_at'] ?? ''))); ?><br>
          Next: <?php echo h(book_incident_next_actor_label((string) ($selectedIncident['workflow_status'] ?? 'reported'), (string) ($selectedIncident['settlement_status'] ?? 'pending'))); ?>
        </div>
        <div class="empty-state">
          <strong class="label-block-gap">Member description</strong>
          <?php echo nl2br(h((string) ($selectedIncident['description'] ?? ''))); ?>
        </div>
      </div>

      <form method="post" class="stack flow-gap-md">
        <input type="hidden" name="incident_id" value="<?php echo (int) $selectedIncident['id']; ?>">
        <div class="field-grid three-up">
          <div>
            <label for="workflow_status_selected">Workflow status</label>
            <select id="workflow_status_selected" name="workflow_status" <?php echo $isLocked ? 'disabled' : ''; ?>>
              <?php foreach (book_incident_workflow_form_options((string) ($selectedIncident['workflow_status'] ?? '')) as $value => $label): ?>
                <option value="<?php echo h($value); ?>" <?php echo (string) ($selectedIncident['workflow_status'] ?? '') === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="resolution_action_selected">Inventory action</label>
            <select id="resolution_action_selected" name="resolution_action" <?php echo $isLocked ? 'disabled' : ''; ?>>
              <?php foreach (book_incident_resolution_form_options((string) ($selectedIncident['resolution_action'] ?? '')) as $value => $label): ?>
                <option value="<?php echo h($value); ?>" <?php echo (string) ($selectedIncident['resolution_action'] ?? '') === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="settlement_status_selected">Settlement state</label>
            <select id="settlement_status_selected" name="settlement_status" <?php echo $isLocked ? 'disabled' : ''; ?>>
              <?php foreach (book_incident_settlement_form_options((string) ($selectedIncident['settlement_status'] ?? '')) as $value => $label): ?>
                <option value="<?php echo h($value); ?>" <?php echo (string) ($selectedIncident['settlement_status'] ?? '') === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field-grid two-up">
          <div>
            <label for="severity_selected">Severity</label>
            <select id="severity_selected" name="severity" <?php echo $isLocked ? 'disabled' : ''; ?>>
              <option value="">No severity</option>
              <?php foreach (book_incident_severity_options() as $value => $label): ?>
                <option value="<?php echo h($value); ?>" <?php echo (string) ($selectedIncident['severity'] ?? '') === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="assessed_fee_selected">Assessed fee</label>
            <input id="assessed_fee_selected" type="number" step="0.01" min="0" name="assessed_fee" value="<?php echo h(number_format((float) ($selectedIncident['assessed_fee'] ?? 0), 2, '.', '')); ?>" <?php echo $isLocked ? 'readonly' : ''; ?>>
          </div>
        </div>
        <div>
          <label for="resolution_notes_selected">Review notes</label>
          <textarea id="resolution_notes_selected" name="resolution_notes" rows="4" <?php echo $isLocked ? 'readonly' : ''; ?>><?php echo h((string) ($selectedIncident['resolution_notes'] ?? '')); ?></textarea>
        </div>
        <div class="inline-actions member-workspace-actions">
          <?php if ($isLocked): ?>
            <span class="muted">This case is already closed. Admin can still update settlement if needed.</span>
          <?php else: ?>
            <button type="submit" name="update_incident" value="1">Save Incident Update</button>
            <span class="muted">`Awaiting Settlement` sends the case to admin. `Resolved` closes it now.</span>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
<script src="<?php echo h(app_url('assets/member_sidebar.js?v=' . urlencode($memberSidebarVersion))); ?>"></script>
</body>
</html>

<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/book_incidents.php';

require_roles(['student', 'faculty']);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = canonical_role((string) ($_SESSION['role'] ?? 'student'));
$msg = '';
$msgType = 'success';
$focusedIncidentId = (int) ($_GET['incident'] ?? 0);
$focusedBorrowId = (int) ($_GET['borrow_id'] ?? 0);
$openedFromNotification = (string) ($_GET['from_notification'] ?? '') === '1';

if (isset($_POST['submit_incident'])) {
    $result = create_member_book_incident($conn, $userId, $role, [
        'borrow_id' => (int) ($_POST['borrow_id'] ?? 0),
        'incident_type' => (string) ($_POST['incident_type'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
        'incident_photo_file' => $_FILES['incident_photo'] ?? [],
    ]);
    $msg = (string) ($result['message'] ?? '');
    $msgType = ($result['ok'] ?? false) ? 'success' : 'error';
}

$summary = member_book_incident_summary($conn, $userId);
$reportableBorrows = get_member_reportable_borrows($conn, $userId);
$incidents = get_member_incidents($conn, $userId);
$focusedIncident = null;
foreach ($incidents as $incident) {
    if ((int) ($incident['id'] ?? 0) === $focusedIncidentId) {
        $focusedIncident = $incident;
        break;
    }
}
if (!$focusedIncident && $focusedBorrowId > 0) {
    foreach ($incidents as $incident) {
        if ((int) ($incident['borrow_id'] ?? 0) === $focusedBorrowId) {
            $focusedIncident = $incident;
            $focusedIncidentId = (int) ($incident['id'] ?? 0);
            break;
        }
    }
}
$bookIncidentsBaseHref = app_url($role . '/book_incidents.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title($role, 'Book Incidents')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="<?php echo h(app_url('assets/theme.js?v=' . urlencode($themeVersion))); ?>"></script>
<link rel="stylesheet" href="<?php echo h(app_url('assets/app.css?v=' . urlencode($assetVersion))); ?>">
</head>
<body>
<div class="site-shell member-shell js-member-sidebar" data-sidebar-key="<?php echo h($role); ?>-book-incidents">
  <aside class="panel member-sidebar">
    <div class="member-sidebar-head">
      <div class="member-sidebar-toggle" aria-hidden="true">
        <span class="member-sidebar-label">Main Menu</span>
      </div>
    </div>
    <nav class="member-sidebar-nav">
      <p class="member-sidebar-group-label">Overview</p>
      <a class="member-sidebar-link" href="<?php echo h(app_url($role . '/dashboard.php')); ?>" data-tooltip="Dashboard">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Dashboard</span>
      </a>
      <p class="member-sidebar-group-label">Library</p>
      <a class="member-sidebar-link" href="<?php echo h(app_url($role . '/books.php')); ?>" data-tooltip="Books">
        <span class="dashboard-icon icon-books" aria-hidden="true"></span>
        <span class="member-sidebar-label">Books</span>
      </a>
      <a class="member-sidebar-link" href="<?php echo h(app_url($role . '/catalog.php')); ?>" data-tooltip="Catalog">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">Catalog</span>
      </a>
      <a class="member-sidebar-link" href="<?php echo h(app_url($role . '/ebooks.php')); ?>" data-tooltip="eBooks">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">eBooks</span>
      </a>
      <p class="member-sidebar-group-label">My Activity</p>
      <a class="member-sidebar-link" href="<?php echo h(app_url($role . '/borrow_return.php')); ?>" data-tooltip="Returns">
        <span class="dashboard-icon icon-checklist" aria-hidden="true"></span>
        <span class="member-sidebar-label">Returns</span>
      </a>
      <a class="member-sidebar-link is-active" href="<?php echo h(app_url($role . '/book_incidents.php')); ?>" data-tooltip="Book Incidents">
        <span class="dashboard-icon icon-notes" aria-hidden="true"></span>
        <span class="member-sidebar-label">Book Incidents</span>
      </a>
      <a class="member-sidebar-link" href="<?php echo h(app_url($role . '/tracking.php')); ?>" data-tooltip="Records Tracking">
        <span class="dashboard-icon icon-ledger" aria-hidden="true"></span>
        <span class="member-sidebar-label">Records Tracking</span>
      </a>
      <a class="member-sidebar-link" href="<?php echo h(app_url($role . '/payment_upload.php')); ?>" data-tooltip="Payments">
        <span class="dashboard-icon icon-payments" aria-hidden="true"></span>
        <span class="member-sidebar-label">Payments</span>
      </a>
    </nav>
    <p class="member-sidebar-section member-sidebar-label">Account</p>
    <div class="topbar-nav member-sidebar-utilities">
      <a class="member-sidebar-link" href="<?php echo h(app_url($role . '/profile.php')); ?>" data-tooltip="Profile">
        <span class="dashboard-icon icon-edit" aria-hidden="true"></span>
        <span class="member-sidebar-label">Profile</span>
      </a>
      <a class="member-sidebar-link" href="<?php echo h(app_url('index.php')); ?>" data-tooltip="Portal Home">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">Portal Home</span>
      </a>
      <a class="member-sidebar-link" href="<?php echo h(app_url('logout.php')); ?>" data-tooltip="Logout">
        <span class="dashboard-icon icon-logout" aria-hidden="true"></span>
        <span class="member-sidebar-label">Logout</span>
      </a>
    </div>
  </aside>

  <div class="member-main">
    <div class="topbar topbar-member">
      <div>
        <p class="topbar-kicker"><?php echo h(role_label($role)); ?> Portal</p>
        <h1><?php echo h(role_label($role)); ?> Book Incidents</h1>
        <p>Report lost or damaged borrowed books and monitor a simpler incident workflow</p>
      </div>
    </div>

    <div class="stack">
      <?php if ($msg !== ''): ?>
        <div class="notice <?php echo $msgType === 'error' ? 'error' : 'success'; ?>"><?php echo h($msg); ?></div>
      <?php endif; ?>

      <?php if ($openedFromNotification && $focusedIncidentId > 0): ?>
        <div class="notice <?php echo $focusedIncident ? 'success' : 'warning'; ?>">
          <?php echo h($focusedIncident
            ? 'Opened the incident case from your notification.'
            : 'The incident from your notification is no longer available in your current list.'); ?>
        </div>
      <?php endif; ?>

      <div class="panel member-workspace-overview member-mobile-hide">
        <p class="muted eyebrow-compact stack-copy">Overview</p>
        <h3 class="heading-panel">My incident workspace</h3>
        <div class="stat-grid">
          <div class="stat-card">
            <strong><?php echo (int) ($summary['total_incidents'] ?? 0); ?></strong>
            <span class="muted">All book incident reports</span>
          </div>
          <div class="stat-card">
            <strong><?php echo (int) ($summary['active_incidents'] ?? 0); ?></strong>
            <span class="muted">Still open or waiting for payment</span>
          </div>
          <div class="stat-card">
            <strong><?php echo h(format_currency($summary['pending_fees'] ?? 0)); ?></strong>
            <span class="muted">Pending incident fees</span>
          </div>
        </div>
      </div>

      <div class="grid cards">
        <div class="panel">
          <div class="card-head">
            <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Report</p>
              <h3 class="heading-card">Submit Lost or Damaged Case</h3>
              <p class="muted">Choose the active borrow first, then describe the problem so the librarian can review it quickly.</p>
            </div>
          </div>
          <?php if ($reportableBorrows === []): ?>
            <div class="empty-state">No active borrowed items are available for incident reporting right now.</div>
          <?php else: ?>
            <form method="post" enctype="multipart/form-data" class="stack flow-gap-md">
              <div class="field-grid two-up">
                <div>
                  <label for="borrow_id">Borrowed book</label>
                  <select id="borrow_id" name="borrow_id" required>
                    <option value="">Select a borrowed book</option>
                    <?php foreach ($reportableBorrows as $borrow): ?>
                      <?php
                      $openIncidentCount = (int) ($borrow['open_incident_count'] ?? 0);
                      $isDisabled = $openIncidentCount > 0;
                      ?>
                      <option value="<?php echo (int) $borrow['id']; ?>" <?php echo $isDisabled ? 'disabled' : ''; ?>>
                        <?php
                        $borrowLabel = $borrow['title'];
                        $copyId = trim((string) ($borrow['copy_id'] ?? ''));
                        if ($copyId !== '') {
                            $borrowLabel .= ' | Copy ' . $copyId;
                        }
                        $borrowLabel .= ' | Due ' . format_display_date((string) ($borrow['due_date'] ?? ''), '-');
                        if ($isDisabled) {
                            $borrowLabel .= ' | Already has active report';
                        }
                        echo h($borrowLabel);
                        ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="incident_type">Incident type</label>
                  <select id="incident_type" name="incident_type" required>
                    <option value="">Select incident type</option>
                    <?php foreach (book_incident_type_options() as $value => $label): ?>
                      <option value="<?php echo h($value); ?>"><?php echo h($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="empty-state">
                <strong class="label-block-gap">Workflow note</strong>
                Report the issue here first. The librarian will inspect the book and assign the severity during review.
              </div>
              <div class="muted">
                Use the copy label from your borrowed books list to pick the exact item you want to report.
              </div>
              <div id="incident-photo-field" class="stack flow-gap-xs" hidden>
                <div>
                  <label for="incident_photo">Damage photo</label>
                  <input id="incident_photo" type="file" name="incident_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                </div>
                <div class="muted">Required only for damaged reports. Upload a clear photo so the librarian can assess the issue faster.</div>
                <div class="muted">Damaged books should still be returned to the library for inspection whenever possible. The photo helps the review, but it does not replace the physical return.</div>
              </div>
              <div>
                <label for="description">What happened?</label>
                <textarea id="description" name="description" rows="5" placeholder="State when it happened, what condition the book is in, and whether the item can still be physically returned." required></textarea>
              </div>
              <div class="inline-actions">
                <button type="submit" name="submit_incident" value="1">Submit Incident Report</button>
              </div>
            </form>
          <?php endif; ?>
        </div>

        <div class="panel">
          <div class="card-head">
            <div class="dashboard-icon icon-checklist" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Status Guide</p>
              <h3 class="heading-card">Simple Workflow</h3>
              <p class="muted">The case now moves through three simple stages so everyone knows what happens next.</p>
            </div>
          </div>
          <div class="stack">
            <div class="empty-state">
              <strong class="label-block-gap">1. Open</strong>
              Your report is saved and waits for the librarian to review and assess the case.
            </div>
            <div class="empty-state">
              <strong class="label-block-gap">2. For Payment</strong>
              If the librarian assigns a fee, the case waits for your payment upload and admin approval.
            </div>
            <div class="empty-state">
              <strong class="label-block-gap">3. Closed</strong>
              The case is fully finished, either because it was paid, waived, or needed no payment.
            </div>
          </div>
        </div>
      </div>

      <div class="panel member-workspace-history">
        <div class="card-head">
          <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
          <div>
            <span class="chip">History</span>
            <h3 class="heading-top-md">My Lost and Damaged Reports</h3>
          </div>
        </div>
        <p class="muted copy-bottom">Each record below shows the current case status, payment status, fee, and final inventory action.</p>
        <div class="stack">
          <?php if ($incidents === []): ?>
            <div class="empty-state">No incident reports yet.</div>
          <?php endif; ?>
          <?php foreach ($incidents as $incident): ?>
            <div class="panel member-return-batch-card<?php echo $focusedIncidentId > 0 && (int) ($incident['id'] ?? 0) === $focusedIncidentId ? ' is-targeted' : ''; ?>"<?php echo $focusedIncidentId > 0 && (int) ($incident['id'] ?? 0) === $focusedIncidentId ? ' data-focused-incident="true"' : ''; ?>>
              <div class="member-return-batch-head">
                <div>
                  <strong class="label-block"><?php echo h($incident['title']); ?></strong>
                  <span class="muted">
                    <?php if (trim((string) ($incident['copy_id'] ?? '')) !== ''): ?>
                      Copy <?php echo h((string) $incident['copy_id']); ?> |
                    <?php endif; ?>
                    Reported <?php echo h(format_display_datetime((string) ($incident['reported_at'] ?? ''))); ?>
                  </span>
                  <span class="muted">
                    Incident #<?php echo (int) $incident['id']; ?>
                    <?php if (trim((string) ($incident['barcode'] ?? '')) !== ''): ?>
                      | Reference <?php echo h((string) $incident['barcode']); ?>
                    <?php endif; ?>
                    | Borrow #<?php echo (int) $incident['borrow_id']; ?>
                  </span>
                  <div class="inline-actions chips-row batch-status-row">
                    <span class="chip"><?php echo h(book_incident_type_label((string) ($incident['incident_type'] ?? ''))); ?></span>
                    <span class="chip"><?php echo h(book_incident_severity_label((string) ($incident['severity'] ?? ''))); ?></span>
                    <span class="chip"><?php echo h(format_currency($incident['assessed_fee'] ?? 0)); ?></span>
                    <span class="chip"><?php echo h(book_incident_payment_stage_label($incident)); ?></span>
                  </div>
                </div>
                <div class="stack flow-gap-sm">
                  <button type="button" class="button secondary" data-incident-print-button data-incident-print-id="<?php echo (int) $incident['id']; ?>">Print Report</button>
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
              <div class="member-incident-print-source" data-incident-print-source="<?php echo (int) $incident['id']; ?>" hidden>
                <article class="member-incident-print-report">
                  <header class="member-incident-print-header">
                    <img src="<?php echo h(app_url('assets/images/RMLOGO.jfif')); ?>" alt="Regis Marie College logo">
                    <div>
                      <p>Regis Marie College Library</p>
                      <h1>Book Incident Report</h1>
                      <span>Generated <?php echo h(format_display_datetime(date('Y-m-d H:i:s'))); ?></span>
                    </div>
                  </header>
                  <section class="member-incident-print-grid">
                    <div><strong>Incident ID</strong><span>#<?php echo (int) $incident['id']; ?></span></div>
                    <div><strong>Borrow ID</strong><span>#<?php echo (int) $incident['borrow_id']; ?></span></div>
                    <div><strong>Borrower Role</strong><span><?php echo h(role_label($role)); ?></span></div>
                    <div><strong>Reported At</strong><span><?php echo h(format_display_datetime((string) ($incident['reported_at'] ?? ''))); ?></span></div>
                    <div><strong>Book Title</strong><span><?php echo h((string) ($incident['title'] ?? '')); ?></span></div>
                    <div><strong>Copy / Reference</strong><span><?php echo h(trim((string) ($incident['copy_id'] ?? '')) !== '' ? (string) $incident['copy_id'] : (trim((string) ($incident['barcode'] ?? '')) !== '' ? (string) $incident['barcode'] : '-')); ?></span></div>
                    <div><strong>Incident Type</strong><span><?php echo h(book_incident_type_label((string) ($incident['incident_type'] ?? ''))); ?></span></div>
                    <div><strong>Severity</strong><span><?php echo h(book_incident_severity_label((string) ($incident['severity'] ?? ''))); ?></span></div>
                    <div><strong>Case Status</strong><span><?php echo h(book_incident_workflow_label((string) ($incident['workflow_status'] ?? 'open'))); ?></span></div>
                    <div><strong>Payment Status</strong><span><?php echo h(book_incident_payment_stage_label($incident)); ?></span></div>
                    <div><strong>Assessed Fee</strong><span><?php echo h(format_currency($incident['assessed_fee'] ?? 0)); ?></span></div>
                    <div><strong>Borrow Status</strong><span><?php echo h(ucfirst((string) ($incident['borrow_status'] ?? 'n/a'))); ?></span></div>
                    <div><strong>Resolution Action</strong><span><?php echo h(book_incident_resolution_label((string) ($incident['resolution_action'] ?? 'none'))); ?></span></div>
                    <div><strong>Damage Photo</strong><span><?php echo trim((string) ($incident['incident_photo_path'] ?? '')) !== '' ? 'Uploaded' : 'None'; ?></span></div>
                  </section>
                  <section class="member-incident-print-block">
                    <h2>Description</h2>
                    <p><?php echo nl2br(h((string) ($incident['description'] ?? ''))); ?></p>
                  </section>
                  <section class="member-incident-print-block">
                    <h2>Resolution Notes</h2>
                    <p><?php echo trim((string) ($incident['resolution_notes'] ?? '')) !== '' ? nl2br(h((string) $incident['resolution_notes'])) : 'No resolution notes yet.'; ?></p>
                  </section>
                </article>
              </div>
              <div class="stack member-return-batch-list">
                <div class="empty-state member-return-batch-item">
                  <span class="grow">
                    <strong class="label-block meta-top-sm">Description</strong>
                    <span class="muted"><?php echo nl2br(h((string) ($incident['description'] ?? ''))); ?></span>
                    <?php if (trim((string) ($incident['incident_photo_path'] ?? '')) !== ''): ?>
                      <span class="muted meta-top-sm">
                        Damage photo:
                        <a href="<?php echo h(app_url('incident_photo_view.php?incident_id=' . (int) ($incident['id'] ?? 0))); ?>" target="_blank">View uploaded photo</a>
                      </span>
                    <?php endif; ?>
                  </span>
                </div>
                <div class="empty-state member-return-batch-item">
                  <span class="grow">
                    <strong class="label-block meta-top-sm">Resolution</strong>
                    <span class="muted">
                      Action: <?php echo h(book_incident_resolution_label((string) ($incident['resolution_action'] ?? 'none'))); ?> |
                      Borrow status: <?php echo h(ucfirst((string) ($incident['borrow_status'] ?? 'n/a'))); ?>
                    </span>
                    <?php if (trim((string) ($incident['resolution_notes'] ?? '')) !== ''): ?>
                      <span class="muted meta-top-sm"><?php echo nl2br(h((string) $incident['resolution_notes'])); ?></span>
                    <?php endif; ?>
                  </span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="member-incident-print-host" data-incident-print-host aria-hidden="true"></div>
<?php if ($openedFromNotification && $focusedIncident): ?>
  <div class="desk-modal" data-member-notification-page-modal>
    <a class="desk-modal-backdrop" href="<?php echo h($bookIncidentsBaseHref); ?>" aria-label="Close incident details"></a>
    <div class="desk-modal-dialog panel member-notification-page-dialog" role="dialog" aria-modal="true" aria-labelledby="member-incident-notification-modal-title">
      <div class="desk-modal-head">
        <div>
          <p class="muted eyebrow-compact">Incident Case</p>
          <h3 id="member-incident-notification-modal-title" class="heading-card"><?php echo h((string) ($focusedIncident['title'] ?? 'Book incident')); ?></h3>
          <p class="muted">This incident record was opened directly from your notification so you can review the latest case and payment status immediately.</p>
        </div>
        <div class="inline-actions">
          <button type="button" class="button secondary" data-incident-print-button data-incident-print-id="<?php echo (int) ($focusedIncident['id'] ?? 0); ?>">Print Report</button>
          <a class="button secondary" href="<?php echo h($bookIncidentsBaseHref); ?>">Close</a>
        </div>
      </div>
      <div class="panel member-return-batch-card member-notification-page-card">
        <div class="member-return-batch-head">
          <div>
            <strong class="label-block">Incident #<?php echo (int) ($focusedIncident['id'] ?? 0); ?></strong>
            <span class="muted">
              Reported <?php echo h(format_display_datetime((string) ($focusedIncident['reported_at'] ?? ''))); ?>
              <?php if (trim((string) ($focusedIncident['copy_id'] ?? '')) !== ''): ?>
                | Copy <?php echo h((string) $focusedIncident['copy_id']); ?>
              <?php endif; ?>
            </span>
            <div class="inline-actions chips-row batch-status-row">
              <span class="chip"><?php echo h(book_incident_type_label((string) ($focusedIncident['incident_type'] ?? ''))); ?></span>
              <span class="chip"><?php echo h(book_incident_severity_label((string) ($focusedIncident['severity'] ?? ''))); ?></span>
              <span class="chip"><?php echo h(format_currency($focusedIncident['assessed_fee'] ?? 0)); ?></span>
              <span class="chip"><?php echo h(book_incident_payment_stage_label($focusedIncident)); ?></span>
            </div>
          </div>
          <div class="stack flow-gap-sm">
            <span class="badge">
              <span class="status-dot <?php echo h(book_incident_status_dot_class((string) ($focusedIncident['workflow_status'] ?? 'open'))); ?>"></span>
              Case: <?php echo h(book_incident_workflow_label((string) ($focusedIncident['workflow_status'] ?? 'open'))); ?>
            </span>
            <span class="badge">
              <span class="status-dot <?php echo h(book_incident_payment_stage_dot_class($focusedIncident)); ?>"></span>
              Payment: <?php echo h(book_incident_payment_stage_label($focusedIncident)); ?>
            </span>
          </div>
        </div>
        <div class="stack member-return-batch-list">
          <div class="empty-state member-return-batch-item">
            <span class="grow">
              <strong class="label-block meta-top-sm">Description</strong>
              <span class="muted"><?php echo nl2br(h((string) ($focusedIncident['description'] ?? ''))); ?></span>
            </span>
          </div>
          <div class="empty-state member-return-batch-item">
            <span class="grow">
              <strong class="label-block meta-top-sm">Resolution</strong>
              <span class="muted">
                Action: <?php echo h(book_incident_resolution_label((string) ($focusedIncident['resolution_action'] ?? 'none'))); ?> |
                Borrow status: <?php echo h(ucfirst((string) ($focusedIncident['borrow_status'] ?? 'n/a'))); ?>
              </span>
              <?php if (trim((string) ($focusedIncident['resolution_notes'] ?? '')) !== ''): ?>
                <span class="muted meta-top-sm"><?php echo nl2br(h((string) $focusedIncident['resolution_notes'])); ?></span>
              <?php endif; ?>
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<script src="<?php echo h(app_url('assets/member_sidebar.js?v=' . urlencode($memberSidebarVersion))); ?>"></script>
<script>
(() => {
  const incidentType = document.getElementById('incident_type');
  const photoField = document.getElementById('incident-photo-field');
  const photoInput = document.getElementById('incident_photo');
  if (!incidentType || !photoField || !photoInput) {
    return;
  }

  const syncIncidentPhotoRequirement = () => {
    const requiresPhoto = incidentType.value === 'damaged';
    photoField.hidden = !requiresPhoto;
    photoInput.required = requiresPhoto;
    if (!requiresPhoto) {
      photoInput.value = '';
    }
  };

  incidentType.addEventListener('change', syncIncidentPhotoRequirement);
  syncIncidentPhotoRequirement();
})();

document.addEventListener('DOMContentLoaded', function () {
  var pageModal = document.querySelector('[data-member-notification-page-modal]');
  if (pageModal) {
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
  }

  var focusedIncident = document.querySelector('[data-focused-incident="true"]');
  if (!focusedIncident) {
    return;
  }

  try {
    focusedIncident.scrollIntoView({ behavior: 'smooth', block: 'center' });
  } catch (error) {
    focusedIncident.scrollIntoView();
  }
});

document.addEventListener('click', function (event) {
  var printButton = event.target.closest('[data-incident-print-button]');
  if (!printButton) {
    return;
  }

  var incidentId = printButton.getAttribute('data-incident-print-id') || '';
  var source = document.querySelector('[data-incident-print-source="' + incidentId + '"]');
  var host = document.querySelector('[data-incident-print-host]');
  if (!source || !host) {
    return;
  }

  host.innerHTML = source.innerHTML;
  document.body.classList.add('is-printing-incident-report');
  window.print();
});

window.addEventListener('afterprint', function () {
  var host = document.querySelector('[data-incident-print-host]');
  if (host) {
    host.innerHTML = '';
  }
  document.body.classList.remove('is-printing-incident-report');
});
</script>
</body>
</html>

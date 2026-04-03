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

if (isset($_POST['submit_incident'])) {
    $result = create_member_book_incident($conn, $userId, $role, [
        'borrow_id' => (int) ($_POST['borrow_id'] ?? 0),
        'incident_type' => (string) ($_POST['incident_type'] ?? ''),
        'severity' => (string) ($_POST['severity'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
    ]);
    $msg = (string) ($result['message'] ?? '');
    $msgType = ($result['ok'] ?? false) ? 'success' : 'error';
}

$summary = member_book_incident_summary($conn, $userId);
$reportableBorrows = get_member_reportable_borrows($conn, $userId);
$incidents = get_member_incidents($conn, $userId);
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
      <a class="member-sidebar-link" href="<?php echo h(app_url($role . '/dashboard.php')); ?>" data-tooltip="Dashboard">
        <span class="dashboard-icon icon-view" aria-hidden="true"></span>
        <span class="member-sidebar-label">Dashboard</span>
      </a>
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
      <a class="member-sidebar-link" href="<?php echo h(app_url('index.php')); ?>" data-tooltip="Home">
        <span class="dashboard-icon icon-guide" aria-hidden="true"></span>
        <span class="member-sidebar-label">Home</span>
      </a>
      <a class="member-sidebar-link" href="<?php echo h(app_url('logout.php')); ?>" data-tooltip="Logout">
        <span class="dashboard-icon icon-logout" aria-hidden="true"></span>
        <span class="member-sidebar-label">Logout</span>
      </a>
    </div>
  </aside>

  <div class="member-main">
    <div class="topbar">
      <div>
        <h1><?php echo h(role_label($role)); ?> Portal</h1>
        <p>Report lost or damaged borrowed books and monitor the settlement workflow</p>
      </div>
    </div>

    <div class="stack">
      <?php if ($msg !== ''): ?>
        <div class="notice <?php echo $msgType === 'error' ? 'error' : 'success'; ?>"><?php echo h($msg); ?></div>
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
            <span class="muted">Still under review or settlement</span>
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
            <form method="post" class="stack flow-gap-md">
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
                        echo h($borrow['title'] . ' | Borrow #' . (int) $borrow['id'] . ' | Due ' . format_display_date((string) ($borrow['due_date'] ?? ''), '-') . ($isDisabled ? ' | Already has active report' : ''));
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
              <div class="field-grid two-up">
                <div>
                  <label for="severity">Damage severity</label>
                  <select id="severity" name="severity">
                    <option value="">Use this for damaged books</option>
                    <?php foreach (book_incident_severity_options() as $value => $label): ?>
                      <option value="<?php echo h($value); ?>"><?php echo h($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="empty-state">
                  <strong class="label-block-gap">Workflow note</strong>
                  Lost and damaged reports go first to the librarian. Admin only updates the final settlement status afterward.
                </div>
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
              <h3 class="heading-card">How the workflow moves</h3>
              <p class="muted">This keeps the student or faculty member, librarian, admin, and inventory record connected.</p>
            </div>
          </div>
          <div class="stack">
            <div class="empty-state">
              <strong class="label-block-gap">1. Reported</strong>
              Your report is saved and waits for the librarian to inspect the case.
            </div>
            <div class="empty-state">
              <strong class="label-block-gap">2. Under review</strong>
              The librarian checks the borrow record, book title, severity, and inventory action.
            </div>
            <div class="empty-state">
              <strong class="label-block-gap">3. Awaiting settlement</strong>
              The inventory decision is done and admin now tracks payment, waiver, or replacement status.
            </div>
            <div class="empty-state">
              <strong class="label-block-gap">4. Resolved</strong>
              The case is closed and the final action is already recorded in the system.
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
        <p class="muted copy-bottom">Each record below shows the current workflow stage, fee, settlement state, and final inventory action.</p>
        <div class="stack">
          <?php if ($incidents === []): ?>
            <div class="empty-state">No incident reports yet.</div>
          <?php endif; ?>
          <?php foreach ($incidents as $incident): ?>
            <div class="panel member-return-batch-card">
              <div class="member-return-batch-head">
                <div>
                  <strong class="label-block"><?php echo h($incident['title']); ?></strong>
                  <span class="muted">
                    Incident #<?php echo (int) $incident['id']; ?> |
                    Borrow #<?php echo (int) $incident['borrow_id']; ?> |
                    Reported <?php echo h(format_display_datetime((string) ($incident['reported_at'] ?? ''))); ?>
                  </span>
                  <div class="inline-actions chips-row batch-status-row">
                    <span class="chip"><?php echo h(book_incident_type_label((string) ($incident['incident_type'] ?? ''))); ?></span>
                    <span class="chip"><?php echo h(book_incident_severity_label((string) ($incident['severity'] ?? ''))); ?></span>
                    <span class="chip"><?php echo h(format_currency($incident['assessed_fee'] ?? 0)); ?></span>
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
              <div class="stack member-return-batch-list">
                <div class="empty-state member-return-batch-item">
                  <span class="grow">
                    <strong class="label-block meta-top-sm">Description</strong>
                    <span class="muted"><?php echo nl2br(h((string) ($incident['description'] ?? ''))); ?></span>
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
<script src="<?php echo h(app_url('assets/member_sidebar.js?v=' . urlencode($memberSidebarVersion))); ?>"></script>
</body>
</html>

<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

$hasBookCopies = table_exists($conn, 'book_copies');
$noticeItems = [];
$auditRows = [];
$summary = [
    'total' => 0,
    'reflected' => 0,
    'missing_copy_link' => 0,
    'mismatched_status' => 0,
    'pending_workflow' => 0,
];

if ($hasBookCopies) {
    if (isset($_POST['apply_safe_fixes'])) {
        library_enforce_csrf_for_post();

        $fixQuery = "
            SELECT
                bi.id,
                COALESCE(bi.book_copy_id, br.book_copy_id) AS effective_book_copy_id,
                bi.resolution_action,
                bi.workflow_status,
                bi.inventory_applied_at,
                bc.status AS effective_copy_status
            FROM book_incidents bi
            LEFT JOIN borrows br ON br.id = bi.borrow_id
            LEFT JOIN book_copies bc ON bc.id = COALESCE(bi.book_copy_id, br.book_copy_id)
            WHERE bi.resolution_action IN ('write_off_lost', 'write_off_damaged')
        ";
        $fixResult = $conn->query($fixQuery);
        $fixedCount = 0;
        $skippedCount = 0;

        while ($fixResult && ($fixRow = $fixResult->fetch_assoc())) {
            $resolutionAction = (string) ($fixRow['resolution_action'] ?? '');
            $expectedStatus = $resolutionAction === 'write_off_lost' ? 'lost' : 'damaged';
            $workflowStatus = trim((string) ($fixRow['workflow_status'] ?? ''));
            $inventoryAppliedAt = trim((string) ($fixRow['inventory_applied_at'] ?? ''));
            $effectiveCopyId = (int) ($fixRow['effective_book_copy_id'] ?? 0);
            $effectiveCopyStatus = trim((string) ($fixRow['effective_copy_status'] ?? ''));

            $inventoryShouldBeApplied = $inventoryAppliedAt !== ''
                || in_array($workflowStatus, ['for_payment', 'closed', 'resolved', 'awaiting_settlement'], true);

            if (!$inventoryShouldBeApplied || $effectiveCopyId <= 0 || $effectiveCopyStatus === $expectedStatus) {
                $skippedCount++;
                continue;
            }

            if (set_book_copy_status($conn, $effectiveCopyId, $expectedStatus)) {
                $fixedCount++;
            } else {
                $skippedCount++;
            }
        }

        if ($fixedCount > 0) {
            $noticeItems[] = [
                'type' => 'success',
                'message' => 'Safe fixes applied: ' . $fixedCount . '. Skipped: ' . $skippedCount . '. Only linked mismatched copies were updated.',
            ];
        } else {
            $noticeItems[] = [
                'type' => 'warning',
                'message' => 'No safe fixes were applied. The remaining rows likely need manual review because they are already correct, still pending, or missing a copy link.',
            ];
        }
    }

    $auditSql = "
        SELECT
            bi.id,
            bi.book_id,
            bi.borrow_id,
            bi.book_copy_id AS incident_book_copy_id,
            br.book_copy_id AS borrow_book_copy_id,
            COALESCE(bi.book_copy_id, br.book_copy_id) AS effective_book_copy_id,
            bi.incident_type,
            bi.workflow_status,
            bi.resolution_action,
            bi.settlement_status,
            bi.reported_at,
            bi.reviewed_at,
            bi.resolved_at,
            bi.inventory_applied_at,
            b.title,
            u.fullname,
            u.role,
            bc.status AS effective_copy_status,
            bc.copy_id AS effective_copy_code,
            bc.barcode AS effective_copy_barcode
        FROM book_incidents bi
        JOIN books b ON b.id = bi.book_id
        JOIN users u ON u.id = bi.user_id
        LEFT JOIN borrows br ON br.id = bi.borrow_id
        LEFT JOIN book_copies bc ON bc.id = COALESCE(bi.book_copy_id, br.book_copy_id)
        WHERE bi.resolution_action IN ('write_off_lost', 'write_off_damaged')
        ORDER BY COALESCE(bi.inventory_applied_at, bi.resolved_at, bi.reviewed_at, bi.reported_at) DESC, bi.id DESC
    ";
    $auditResult = $conn->query($auditSql);

    while ($auditResult && ($row = $auditResult->fetch_assoc())) {
        $resolutionAction = (string) ($row['resolution_action'] ?? '');
        $expectedStatus = $resolutionAction === 'write_off_lost' ? 'lost' : 'damaged';
        $workflowStatus = trim((string) ($row['workflow_status'] ?? ''));
        $inventoryAppliedAt = trim((string) ($row['inventory_applied_at'] ?? ''));
        $effectiveCopyId = (int) ($row['effective_book_copy_id'] ?? 0);
        $effectiveCopyStatus = trim((string) ($row['effective_copy_status'] ?? ''));

        $inventoryShouldBeApplied = $inventoryAppliedAt !== ''
            || in_array($workflowStatus, ['for_payment', 'closed', 'resolved', 'awaiting_settlement'], true);

        if (!$inventoryShouldBeApplied) {
            $auditStatus = 'pending_workflow';
            $auditLabel = 'Pending Workflow';
            $auditNote = 'This incident has not yet reached the inventory-applied stage.';
        } elseif ($effectiveCopyId <= 0) {
            $auditStatus = 'missing_copy_link';
            $auditLabel = 'Missing Copy Link';
            $auditNote = 'No specific book copy is linked, so the lost/damaged status cannot be verified in book_copies.';
        } elseif ($effectiveCopyStatus !== $expectedStatus) {
            $auditStatus = 'mismatched_status';
            $auditLabel = 'Mismatched Copy Status';
            $auditNote = 'A copy is linked, but its current status is `' . ($effectiveCopyStatus !== '' ? $effectiveCopyStatus : 'unknown') . '` instead of `' . $expectedStatus . '`.';
        } else {
            $auditStatus = 'reflected';
            $auditLabel = 'Reflected';
            $auditNote = 'The linked copy already matches the expected inventory status.';
        }

        $summary['total']++;
        $summary[$auditStatus]++;

        $row['expected_status'] = $expectedStatus;
        $row['audit_status'] = $auditStatus;
        $row['audit_label'] = $auditLabel;
        $row['audit_note'] = $auditNote;
        $auditRows[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Incident Inventory Audit')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="<?php echo h(app_url('assets/theme.js?v=' . urlencode($themeVersion))); ?>"></script>
<link rel="stylesheet" href="<?php echo h(app_url('assets/app.css?v=' . urlencode($assetVersion))); ?>">
</head>
<body>
<div class="site-shell librarian-shell member-shell js-member-sidebar" data-sidebar-key="librarian-book-incidents-audit" data-sidebar-default="expanded">
  <?php
  $sidebarPage = 'book_incidents';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Incident Inventory Audit';
  $pageSubtitle = 'Check which lost and damaged incident resolutions already match book copy statuses';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <?php require __DIR__ . '/partials/notices.php'; ?>
    <div class="panel">
      <div class="toolbar toolbar-top">
        <div class="grow">
          <p class="muted eyebrow-compact">Audit Mode</p>
          <h3 class="heading-card">Safe review before any backfill</h3>
          <p class="muted">This page only reports whether old incident resolutions are already reflected in `book_copies`. It does not change data.</p>
        </div>
        <div class="inline-actions">
          <?php if ($hasBookCopies): ?>
            <form method="post" class="inline-form">
              <button type="submit" name="apply_safe_fixes" value="1">Apply Safe Fixes</button>
            </form>
          <?php endif; ?>
          <a class="button secondary" href="<?php echo h(app_url('librarian/manage_book_incidents.php')); ?>">Back to Book Incidents</a>
        </div>
      </div>

      <?php if (!$hasBookCopies): ?>
        <div class="empty-state">The `book_copies` table is not available in this database, so the inventory audit cannot run.</div>
      <?php else: ?>
        <div class="stat-grid">
          <div class="stat-card">
            <span class="code-pill">Total Incident Checks</span>
            <strong><?php echo (int) $summary['total']; ?></strong>
            <span class="muted">All lost or damaged write-off incidents reviewed by this audit.</span>
          </div>
          <div class="stat-card">
            <span class="code-pill">Reflected</span>
            <strong><?php echo (int) $summary['reflected']; ?></strong>
            <span class="muted">Linked copy already matches the expected `lost` or `damaged` status.</span>
          </div>
          <div class="stat-card">
            <span class="code-pill">Missing Copy Link</span>
            <strong><?php echo (int) $summary['missing_copy_link']; ?></strong>
            <span class="muted">Older incidents without a specific copy link need manual review before any safe backfill.</span>
          </div>
          <div class="stat-card">
            <span class="code-pill">Status Mismatch</span>
            <strong><?php echo (int) $summary['mismatched_status']; ?></strong>
            <span class="muted">A copy is linked, but its current status does not match the incident resolution.</span>
          </div>
          <div class="stat-card">
            <span class="code-pill">Pending Workflow</span>
            <strong><?php echo (int) $summary['pending_workflow']; ?></strong>
            <span class="muted">These incidents are not yet expected to be reflected in inventory.</span>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($hasBookCopies): ?>
      <div class="panel">
        <p class="muted eyebrow-compact">Incident Checks</p>
        <h3 class="heading-card">Per-incident audit results</h3>
        <?php if ($auditRows === []): ?>
          <div class="empty-state">No lost or damaged write-off incidents were found for auditing.</div>
        <?php else: ?>
          <div class="activity-feed">
            <?php foreach ($auditRows as $auditRow): ?>
              <?php
              $dotClass = 'due';
              if (($auditRow['audit_status'] ?? '') === 'reflected') {
                  $dotClass = 'approved';
              } elseif (($auditRow['audit_status'] ?? '') === 'pending_workflow') {
                  $dotClass = 'idle';
              } elseif (($auditRow['audit_status'] ?? '') === 'missing_copy_link') {
                  $dotClass = 'warning';
              } elseif (($auditRow['audit_status'] ?? '') === 'mismatched_status') {
                  $dotClass = 'unpaid';
              }
              ?>
              <div class="activity-item">
                <strong>
                  <span class="status-dot <?php echo h($dotClass); ?>"></span>
                  Incident #<?php echo (int) ($auditRow['id'] ?? 0); ?> · <?php echo h((string) ($auditRow['title'] ?? 'Unknown book')); ?>
                </strong>
                <div class="meta">Borrower: <?php echo h((string) ($auditRow['fullname'] ?? 'Member')); ?> (<?php echo h(role_label((string) ($auditRow['role'] ?? ''))); ?>)</div>
                <div class="meta">Resolution: <?php echo h((string) ($auditRow['audit_label'] ?? 'Unknown')); ?> · Expected copy status: <?php echo h((string) ($auditRow['expected_status'] ?? '')); ?></div>
                <div class="meta">Workflow: <?php echo h((string) ($auditRow['workflow_status'] ?? '')); ?> · Settlement: <?php echo h((string) ($auditRow['settlement_status'] ?? '')); ?></div>
                <div class="meta">Effective copy link: <?php echo (int) ($auditRow['effective_book_copy_id'] ?? 0) > 0 ? '#' . (int) $auditRow['effective_book_copy_id'] : 'Missing'; ?></div>
                <?php if ((int) ($auditRow['effective_book_copy_id'] ?? 0) > 0): ?>
                  <div class="meta">Current copy status: <?php echo h((string) ($auditRow['effective_copy_status'] ?? 'unknown')); ?><?php echo trim((string) ($auditRow['effective_copy_code'] ?? '')) !== '' ? ' · Copy ID ' . h((string) $auditRow['effective_copy_code']) : ''; ?></div>
                <?php endif; ?>
                <div class="meta"><?php echo h((string) ($auditRow['audit_note'] ?? '')); ?></div>
                <div class="inline-actions meta-top">
                  <span class="chip">Book #<?php echo (int) ($auditRow['book_id'] ?? 0); ?></span>
                  <span class="chip">Borrow #<?php echo (int) ($auditRow['borrow_id'] ?? 0); ?></span>
                  <span class="chip">Reported <?php echo h(format_display_datetime((string) ($auditRow['reported_at'] ?? ''))); ?></span>
                  <?php if (trim((string) ($auditRow['inventory_applied_at'] ?? '')) !== ''): ?>
                    <span class="chip">Inventory applied <?php echo h(format_display_datetime((string) ($auditRow['inventory_applied_at'] ?? ''))); ?></span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  </div>
</div>
<script src="<?php echo h(app_url('assets/member_sidebar.js?v=' . urlencode($memberSidebarVersion))); ?>"></script>
</body>
</html>

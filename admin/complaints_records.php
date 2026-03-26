<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$statusFilter = trim($_GET['status'] ?? '');
$statusOptions = complaint_statuses();
$isValidStatusFilter = $statusFilter !== '' && in_array($statusFilter, $statusOptions, true);

if (isset($_POST['save_response'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $adminResponse = trim((string) ($_POST['admin_response'] ?? ''));

    if ($id > 0 && $adminResponse !== '') {
        $fetchStmt = $conn->prepare("
            SELECT fullname, email, status
            FROM complaints
            WHERE id = ?
            LIMIT 1
        ");
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $complaintRow = $fetchStmt->get_result()->fetch_assoc() ?: null;
        $fetchStmt->close();

        if ($complaintRow) {
            $nextStatus = (string) ($complaintRow['status'] ?? 'new');
            if ($nextStatus === 'new') {
                $nextStatus = 'reviewed';
            }

            $actorUserId = (int) ($_SESSION['user_id'] ?? 0);
            $updateStmt = $conn->prepare("
                UPDATE complaints
                SET admin_response = ?, responded_at = NOW(), responded_by = ?, status = ?
                WHERE id = ?
            ");
            $updateStmt->bind_param('sisi', $adminResponse, $actorUserId, $nextStatus, $id);
            $updateStmt->execute();
            $saved = $updateStmt->affected_rows >= 0;
            $updateStmt->close();

            if ($saved) {
                $complainantEmail = trim((string) ($complaintRow['email'] ?? ''));
                if ($complainantEmail !== '') {
                    $fullName = trim((string) ($complaintRow['fullname'] ?? 'Library User'));
                    $subject = 'Complaint Update | Regis Marie College Library';
                    $textBody = "Good day, {$fullName}.\n\n"
                        . "This email is to provide an update regarding your submitted complaint/report.\n\n"
                        . "Complaint reference: #{$id}\n"
                        . "Current status: " . ucfirst($nextStatus) . "\n\n"
                        . "Admin response:\n{$adminResponse}\n\n"
                        . "If you need further clarification, you may contact the library through the official school channels.\n\n"
                        . "Thank you.\n\n"
                        . library_email_signature();
                    $htmlBody = '<div style="font-family:Segoe UI,Arial,sans-serif;line-height:1.6;color:#10233a;">'
                        . '<p>Good day, <strong>' . h($fullName) . '</strong>.</p>'
                        . '<p>This email is to provide an update regarding your submitted complaint/report.</p>'
                        . '<p><strong>Complaint reference:</strong> #' . (int) $id . '<br><strong>Current status:</strong> ' . h(ucfirst($nextStatus)) . '</p>'
                        . '<p><strong>Admin response:</strong></p>'
                        . '<div style="margin:16px 0;padding:14px 16px;border:1px solid #d7e8fb;border-radius:14px;background:#f8fbff;">'
                        . nl2br(h($adminResponse))
                        . '</div>'
                        . '<p>If you need further clarification, you may contact the library through the official school channels.</p>'
                        . '<p>Thank you.</p>'
                        . '<p style="margin-top:22px;">' . h(library_email_signature()) . '</p>'
                        . '</div>';
                    send_library_email($complainantEmail, $subject, $textBody, $htmlBody);
                }

                audit_log($conn, 'admin.complaint.respond', [
                    'complaint_id' => $id,
                    'status' => $nextStatus,
                    'emailed' => !empty($complaintRow['email']),
                ]);
            }
        }
    }

    header('Location: complaints_records.php');
    exit;
}

if (isset($_POST['mark_reviewed']) || isset($_POST['mark_resolved'])) {
    $id = (int) ($_POST['id'] ?? 0);
    if (isset($_POST['mark_reviewed'])) {
        $stmt = $conn->prepare("UPDATE complaints SET status = 'reviewed' WHERE id = ? AND status = 'new'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        if ($changed) {
            audit_log($conn, 'admin.complaint.mark_reviewed', ['complaint_id' => $id]);
        }
    } else {
        $stmt = $conn->prepare("UPDATE complaints SET status = 'resolved' WHERE id = ? AND status IN ('new', 'reviewed')");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();
        if ($changed) {
            audit_log($conn, 'admin.complaint.mark_resolved', ['complaint_id' => $id]);
        }
    }
    header('Location: complaints_records.php');
    exit;
}

if (isset($_POST['delete_resolved'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM complaints WHERE id = ? AND status = 'resolved'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $deleted = $stmt->affected_rows === 1;
    $stmt->close();
    if ($deleted) {
        audit_log($conn, 'admin.complaint.delete_resolved', ['complaint_id' => $id]);
    }
    header('Location: complaints_records.php');
    exit;
}

$summary = $conn->query("
    SELECT
      COUNT(*) AS total_records,
      COALESCE(SUM(status = 'new'), 0) AS new_records,
      COALESCE(SUM(status = 'reviewed'), 0) AS reviewed_records,
      COALESCE(SUM(status = 'resolved'), 0) AS resolved_records,
      COALESCE(SUM(status <> 'resolved'), 0) AS open_records
    FROM complaints
")->fetch_assoc();

$recentComplaints = $conn->query("
    SELECT id, fullname, role, status, created_at
    FROM complaints
    ORDER BY id DESC
    LIMIT 5
");

$sql = "SELECT * FROM complaints WHERE 1=1";
$types = '';
$params = [];
if ($isValidStatusFilter) {
    $sql .= " AND status = ?";
    $types = 's';
    $params[] = $statusFilter;
}
$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$complaints = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Complaint Records</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $themeVersion = (string) filemtime(__DIR__ . '/../assets/theme.js'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js?v=<?php echo urlencode($themeVersion); ?>"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell admin-shell member-shell js-member-sidebar" data-sidebar-key="admin-complaints" data-sidebar-default="expanded" data-sidebar-lock="expanded">
  <?php
  $sidebarPage = 'complaints';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Complaint Records';
  $pageSubtitle = 'Submitted feedback, complaints, and reports';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-feedback" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Overview</p>
          <h3 class="heading-card">Complaint review summary</h3>
          <p class="muted">Track incoming reports, move issues from new to resolved, and keep the complaint queue manageable for follow-up and cleanup.</p>
        </div>
      </div>
      <div class="stat-grid">
        <div class="stat-card">
          <span class="code-pill">Records</span>
          <strong><?php echo (int) ($summary['total_records'] ?? 0); ?></strong>
          <span class="muted">All submitted complaint and feedback entries.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">New</span>
          <strong><?php echo (int) ($summary['new_records'] ?? 0); ?></strong>
          <span class="muted">Fresh issues that still need first review.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Reviewed</span>
          <strong><?php echo (int) ($summary['reviewed_records'] ?? 0); ?></strong>
          <span class="muted">Items already seen but not fully closed.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Open</span>
          <strong><?php echo (int) ($summary['open_records'] ?? 0); ?></strong>
          <span class="muted">Records still active and not yet resolved.</span>
        </div>
      </div>
    </div>

    <div class="grid cards">
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-recent" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Recent Issues</p>
            <h3 class="heading-card">Latest submitted reports</h3>
            <p class="muted">Check the newest reports first so unresolved issues do not stay in the queue without a status update.</p>
          </div>
        </div>
        <div class="activity-feed">
          <?php if (!$recentComplaints || $recentComplaints->num_rows === 0): ?>
            <div class="empty-state">No complaints have been submitted yet.</div>
          <?php endif; ?>
          <?php while ($entry = $recentComplaints->fetch_assoc()): ?>
            <div class="activity-item">
              <strong>
                <span class="badge complaint-status-badge complaint-status-<?php echo h((string) ($entry['status'] ?? 'new')); ?>">
                  <span class="status-dot <?php echo h($entry['status']); ?>"></span>
                  <?php echo h(ucfirst((string) ($entry['status'] ?? 'new'))); ?>
                </span>
                <?php echo h($entry['fullname']); ?>
              </strong>
              <div class="meta"><?php echo h(ucfirst($entry['role'])); ?> &bull; Complaint #<?php echo (int) $entry['id']; ?></div>
              <div class="meta meta-top"><?php echo h(date('F j, Y g:i A', strtotime($entry['created_at']))); ?></div>
            </div>
          <?php endwhile; ?>
        </div>
      </div>

      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Triage Notes</p>
            <h3 class="heading-card">Complaint workflow guide</h3>
            <p class="muted">Keep the complaint queue readable by using the right status in the right order, then remove only fully resolved records.</p>
          </div>
        </div>
        <div class="stack">
          <div class="empty-state">
            <strong class="label-block-gap">New status</strong>
            New means the issue has been submitted but not yet checked by admin.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Reviewed status</strong>
            Reviewed means the complaint has been seen and may still need follow-up or internal action.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Resolved cleanup</strong>
            Delete is only available after resolution so archived noise can be removed safely.
          </div>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="toolbar toolbar-top">
        <div class="grow">
          <div class="card-head card-head-tight">
            <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
            <div>
              <p class="muted eyebrow-compact">Complaint Queue</p>
              <h3 class="heading-card">Submitted issues and actions</h3>
              <p class="muted">Filter complaints by status, then review, resolve, or delete resolved records from the main queue.</p>
            </div>
          </div>
        </div>
        <form method="get" class="toolbar grow admin-record-filters admin-record-filters-compact">
          <div>
            <label for="status">Status</label>
            <div class="ui-select-shell">
              <select id="status" name="status" class="ui-select">
                <option value="">All statuses</option>
                <?php foreach ($statusOptions as $status): ?>
                  <option value="<?php echo h($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo h(ucfirst($status)); ?></option>
                <?php endforeach; ?>
              </select>
              <span class="ui-select-caret" aria-hidden="true"></span>
            </div>
          </div>
          <div class="inline-actions">
            <a class="button secondary" href="complaints_records.php">Reset</a>
          </div>
        </form>
      </div>
      <div class="inline-actions chips-row">
        <span class="chip">Open issues: <?php echo (int) ($summary['open_records'] ?? 0); ?></span>
        <span class="chip">Resolved: <?php echo (int) ($summary['resolved_records'] ?? 0); ?></span>
        <span class="chip">Reviewed: <?php echo (int) ($summary['reviewed_records'] ?? 0); ?></span>
      </div>

      <div class="table-wrap table-wrap-top">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Role</th>
              <th>Mobile Number</th>
              <th>Message</th>
              <th>Status</th>
              <th>Admin Response</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($complaints->num_rows === 0): ?>
              <tr><td colspan="8" class="muted">No complaint records found.</td></tr>
            <?php endif; ?>
            <?php while ($complaint = $complaints->fetch_assoc()): ?>
              <?php
              $complaintEmail = (string) ($complaint['email'] ?? '');
              $mobileNumber = (string) ($complaint['mobile_number'] ?? '');
              $legacySubject = isset($complaint['subject']) ? (string) $complaint['subject'] : '';
              $displayMobile = $mobileNumber !== '' ? $mobileNumber : ($legacySubject !== '' ? $legacySubject : '-');
              $status = (string) ($complaint['status'] ?? '');
              $isEditingResponse = isset($_GET['edit_response']) && (int) $_GET['edit_response'] === (int) $complaint['id'];
              ?>
              <tr>
                <td><?php echo (int) $complaint['id']; ?></td>
                <td>
                  <strong class="label-block"><?php echo h($complaint['fullname']); ?></strong>
                  <span class="muted"><?php echo h($complaintEmail !== '' ? $complaintEmail : '-'); ?></span>
                </td>
                <td><span class="badge"><?php echo h($complaint['role']); ?></span></td>
                <td><?php echo h($displayMobile); ?></td>
                <td><?php echo nl2br(h($complaint['message'])); ?></td>
                <td>
                  <span class="badge complaint-status-badge complaint-status-<?php echo h($status); ?>">
                    <span class="status-dot <?php echo h($status); ?>"></span>
                    <?php echo h(ucfirst($status)); ?>
                  </span>
                </td>
                <td>
                  <?php if (trim((string) ($complaint['admin_response'] ?? '')) !== ''): ?>
                    <div class="complaint-response-card">
                      <strong>Admin reply</strong>
                      <p><?php echo nl2br(h((string) $complaint['admin_response'])); ?></p>
                      <span class="muted">Complaint submitted: <?php echo h(format_display_datetime((string) $complaint['created_at'])); ?></span>
                      <?php if (!empty($complaint['responded_at'])): ?>
                        <span class="muted">Response saved: <?php echo h(format_display_datetime((string) $complaint['responded_at'])); ?></span>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <div class="complaint-response-card complaint-response-card-empty">
                      <strong>No response yet</strong>
                      <p>Add a short and professional response to acknowledge or resolve the complaint.</p>
                      <span class="muted">Complaint submitted: <?php echo h(format_display_datetime((string) $complaint['created_at'])); ?></span>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="inline-actions complaint-action-stack">
                    <?php if ($status === 'new'): ?>
                      <form method="post" class="inline-form">
                        <input type="hidden" name="id" value="<?php echo (int) $complaint['id']; ?>">
                        <button type="submit" name="mark_reviewed" value="1">Mark Reviewed</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($status !== 'resolved'): ?>
                      <form method="post" class="inline-form">
                        <input type="hidden" name="id" value="<?php echo (int) $complaint['id']; ?>">
                        <button type="submit" class="secondary" name="mark_resolved" value="1">Resolve</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($status === 'resolved'): ?>
                      <form method="post" class="inline-form" data-confirm="Delete this resolved complaint record?">
                        <input type="hidden" name="id" value="<?php echo (int) $complaint['id']; ?>">
                        <button type="submit" class="danger" name="delete_resolved" value="1">Delete</button>
                      </form>
                    <?php endif; ?>
                    <?php if (!$isEditingResponse): ?>
                      <a class="button secondary" href="complaints_records.php?<?php echo http_build_query(array_filter(['status' => $statusFilter, 'edit_response' => (int) $complaint['id']])); ?>">
                        <?php echo trim((string) ($complaint['admin_response'] ?? '')) !== '' ? 'Edit Response' : 'Add Response'; ?>
                      </a>
                    <?php else: ?>
                      <form method="post" class="inline-form complaint-response-form">
                        <input type="hidden" name="id" value="<?php echo (int) $complaint['id']; ?>">
                        <textarea name="admin_response" class="complaint-response-box" rows="3" placeholder="Add a professional admin response..."><?php echo h((string) ($complaint['admin_response'] ?? '')); ?></textarea>
                        <div class="inline-actions complaint-response-form-actions">
                          <button type="submit" class="secondary" name="save_response" value="1">Save Response</button>
                          <a class="button secondary" href="complaints_records.php<?php echo $statusFilter !== '' ? '?' . http_build_query(['status' => $statusFilter]) : ''; ?>">Cancel</a>
                        </div>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
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
<script src="/librarymanage/assets/shared_confirm.js"></script>
<script>
(() => {
  const filterForm = document.querySelector('.admin-record-filters-compact');
  const statusFilter = document.getElementById('status');

  if (!filterForm || !statusFilter) {
    return;
  }

  statusFilter.addEventListener('change', () => {
    if (filterForm.requestSubmit) {
      filterForm.requestSubmit();
      return;
    }
    filterForm.submit();
  });
})();
</script>
</body>
</html>


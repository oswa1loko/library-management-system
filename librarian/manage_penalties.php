<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('librarian');

$notice = trim($_GET['notice'] ?? '');
$bookTitleSql = column_exists($conn, 'books', 'title') ? 'b.title' : (column_exists($conn, 'books', 'book_title') ? 'b.book_title' : "''");

$summary = $conn->query("
    SELECT
      COUNT(*) AS total_penalties,
      COALESCE(SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_penalties,
      COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_penalties,
      COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END), 0) AS unpaid_balance,
      COALESCE(SUM(amount), 0) AS total_amount
    FROM penalties
")->fetch_assoc();

$penalties = $conn->query("
    SELECT
        p.*,
        u.username,
        {$bookTitleSql} AS title,
        br.status AS borrow_status,
        (
            SELECT pay.status
            FROM payments pay
            WHERE pay.penalty_id = p.id
            ORDER BY pay.id DESC
            LIMIT 1
        ) AS latest_payment_status
    FROM penalties p
    JOIN users u ON u.id = p.user_id
    JOIN borrows br ON br.id = p.borrow_id
    JOIN books b ON b.id = br.book_id
    ORDER BY p.id DESC
");

$recentUnpaid = $conn->query("
    SELECT p.id, p.amount, p.reason, u.username, {$bookTitleSql} AS title
    FROM penalties p
    JOIN users u ON u.id = p.user_id
    JOIN borrows br ON br.id = p.borrow_id
    JOIN books b ON b.id = br.book_id
    WHERE p.status = 'unpaid'
    ORDER BY p.id DESC
    LIMIT 4
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h(page_title('librarian', 'Penalties')); ?></title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell librarian-shell member-shell js-member-sidebar" data-sidebar-key="librarian-penalties" data-sidebar-default="expanded">
  <?php
  $sidebarPage = 'penalties';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
  <?php
  $pageTitle = 'Librarian Penalties';
  $pageSubtitle = 'Penalty review and settlement status updates';
  require __DIR__ . '/partials/topbar.php';
  ?>

  <div class="stack">
    <?php
    $noticeItems = [];
    if ($notice !== '') {
        $noticeItems[] = ['type' => 'error', 'message' => $notice];
    }
    require __DIR__ . '/partials/notices.php';
    ?>

    <div class="panel">
      <div class="card-head">
        <div class="dashboard-icon icon-penalties" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Overview</p>
          <h3 class="heading-card">Penalty settlement queue</h3>
          <p class="muted">Review unsettled penalties first, wait for admin payment decisions, and keep borrower balances accurate before closing the day.</p>
        </div>
      </div>
      <div class="stat-grid">
        <div class="stat-card">
          <span class="code-pill">Records</span>
          <strong><?php echo (int) ($summary['total_penalties'] ?? 0); ?></strong>
          <span class="muted">Total penalty records in the system.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Unpaid</span>
          <strong><?php echo (int) ($summary['unpaid_penalties'] ?? 0); ?></strong>
          <span class="muted">Penalty records still waiting for settlement.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Paid</span>
          <strong><?php echo (int) ($summary['paid_penalties'] ?? 0); ?></strong>
          <span class="muted">Penalty records already cleared.</span>
        </div>
        <div class="stat-card">
          <span class="code-pill">Unpaid Balance</span>
          <strong><?php echo h(format_currency($summary['unpaid_balance'] ?? 0)); ?></strong>
          <span class="muted">Outstanding amount that still needs verification or collection.</span>
        </div>
      </div>
    </div>

    <div class="grid cards">
      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-payments" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Recent Unpaid</p>
            <h3 class="heading-card">Borrowers needing review</h3>
            <p class="muted">Start with the newest unpaid penalties so reported balances and payment uploads stay synchronized.</p>
          </div>
        </div>
        <div class="activity-feed">
          <?php if ($recentUnpaid->num_rows === 0): ?>
            <div class="empty-state">No unpaid penalties are waiting right now.</div>
          <?php endif; ?>
          <?php while ($item = $recentUnpaid->fetch_assoc()): ?>
            <div class="activity-item">
              <strong><?php echo h($item['username']); ?> &bull; <?php echo h($item['title']); ?></strong>
              <div class="meta"><?php echo h(format_currency($item['amount'])); ?> &bull; <?php echo h($item['reason']); ?></div>
            </div>
          <?php endwhile; ?>
        </div>
      </div>

      <div class="panel">
        <div class="card-head">
          <div class="dashboard-icon icon-notes" aria-hidden="true"></div>
          <div>
            <p class="muted eyebrow-compact">Settlement Notes</p>
            <h3 class="heading-card">Librarian checklist</h3>
            <p class="muted">Use this page to monitor balances and borrow status. Payment approval and final settlement changes are handled by admin.</p>
          </div>
        </div>
        <div class="stack">
          <div class="empty-state">
            <strong class="label-block-gap">Monitor only</strong>
            Librarians can review which borrowers have open penalties, but admin handles final payment approval and settlement status.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Payment coordination</strong>
            Match payment uploads with the correct borrower and title, then let admin review the proof from payment records.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Reporting readiness</strong>
            Keep borrow returns updated so admin sees the correct penalty context during payment verification.
          </div>
        </div>
      </div>
    </div>

    <div class="panel librarian-penalties-panel">
      <div class="card-head card-head-tight">
        <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Penalty Records</p>
          <h3 class="heading-card">Status updates and balance review</h3>
          <p class="muted">Each row shows the borrower, related book, amount, current state, and the action needed to keep records accurate.</p>
        </div>
      </div>
      <div class="inline-actions chips-row librarian-penalties-summary">
        <span class="chip">Total amount: <?php echo h(format_currency($summary['total_amount'] ?? 0)); ?></span>
        <span class="chip">Unpaid balance: <?php echo h(format_currency($summary['unpaid_balance'] ?? 0)); ?></span>
        <span class="chip">Open items: <?php echo (int) ($summary['unpaid_penalties'] ?? 0); ?></span>
      </div>
      <div class="table-wrap table-wrap-top librarian-penalties-table">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>User</th>
              <th>Book</th>
              <th>Borrow ID</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Reason</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($penalties->num_rows === 0): ?>
              <tr><td colspan="8" class="muted">No penalties found.</td></tr>
            <?php endif; ?>
            <?php while ($penalty = $penalties->fetch_assoc()): ?>
              <tr>
                <td><?php echo (int) $penalty['id']; ?></td>
                <td>
                  <strong class="label-block"><?php echo h($penalty['username']); ?></strong>
                  <span class="muted">Borrower account</span>
                </td>
                <td>
                  <strong class="label-block"><?php echo h($penalty['title']); ?></strong>
                  <span class="muted">Linked book record</span>
                </td>
                <td><?php echo (int) $penalty['borrow_id']; ?></td>
                <td><?php echo h(format_currency($penalty['amount'])); ?></td>
                <td><span class="badge"><span class="status-dot <?php echo h($penalty['status']); ?>"></span><?php echo h(ucfirst($penalty['status'])); ?></span></td>
                <td><?php echo h($penalty['reason']); ?></td>
                <td>
                  <div class="stack flow-gap-sm">
                    <?php if (($penalty['latest_payment_status'] ?? '') === 'pending'): ?>
                      <span class="muted">Pending admin payment review</span>
                    <?php elseif (($penalty['latest_payment_status'] ?? '') === 'approved' || $penalty['status'] === 'paid'): ?>
                      <span class="muted">Settled by approved payment</span>
                    <?php elseif (($penalty['borrow_status'] ?? '') !== 'returned'): ?>
                      <span class="muted">Waiting for confirmed return</span>
                    <?php else: ?>
                      <span class="muted">Awaiting admin settlement decision</span>
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
</body>
</html>

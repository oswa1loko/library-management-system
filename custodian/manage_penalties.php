<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('custodian');

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

if (isset($_POST['mark_paid'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $borrowCheck = $conn->prepare("
        SELECT br.status
        FROM penalties p
        JOIN borrows br ON br.id = p.borrow_id
        WHERE p.id = ?
        LIMIT 1
    ");
    $borrowCheck->bind_param('i', $id);
    $borrowCheck->execute();
    $borrowState = (string) (($borrowCheck->get_result()->fetch_assoc()['status'] ?? ''));
    $borrowCheck->close();

    if ($borrowState !== 'returned') {
        header('Location: manage_penalties.php?notice=' . urlencode('Penalty can only be marked as paid after the book is confirmed returned.'));
        exit;
    }

    $paymentCheck = $conn->prepare("
        SELECT status
        FROM payments
        WHERE penalty_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $paymentCheck->bind_param('i', $id);
    $paymentCheck->execute();
    $latestPayment = $paymentCheck->get_result()->fetch_assoc();
    $paymentCheck->close();

    if (($latestPayment['status'] ?? '') === 'pending') {
        header('Location: manage_penalties.php?notice=' . urlencode('Pending payment reviews must be handled by admin before marking a penalty as paid.'));
        exit;
    }

    $stmt = $conn->prepare("UPDATE penalties SET status = 'paid' WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    header('Location: manage_penalties.php');
    exit;
}

if (isset($_POST['mark_unpaid'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $paymentCheck = $conn->prepare("
        SELECT status
        FROM payments
        WHERE penalty_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $paymentCheck->bind_param('i', $id);
    $paymentCheck->execute();
    $latestPayment = $paymentCheck->get_result()->fetch_assoc();
    $paymentCheck->close();

    if (($latestPayment['status'] ?? '') === 'approved') {
        header('Location: manage_penalties.php?notice=' . urlencode('Approved payments must be changed from the admin payment records page.'));
        exit;
    }

    $stmt = $conn->prepare("UPDATE penalties SET status = 'unpaid' WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    header('Location: manage_penalties.php');
    exit;
}

$penalties = $conn->query("
    SELECT p.*, u.username, {$bookTitleSql} AS title
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
<title>Manage Penalties</title>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css">
</head>
<body>
<div class="site-shell">
  <?php
  $pageTitle = 'Manage Penalties';
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
            <h3 class="heading-card">Custodian checklist</h3>
            <p class="muted">Use the admin payment records page when proof has been submitted, then change penalty state here only when no payment review is blocking it.</p>
          </div>
        </div>
        <div class="stack">
          <div class="empty-state">
            <strong class="label-block-gap">Unpaid first</strong>
            Focus on unsettled records before marking anything paid so balances stay credible for admin review.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Payment coordination</strong>
            Match payment uploads with the correct borrower and title before changing penalty status.
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Reporting readiness</strong>
            Consistent penalty status makes admin records and monthly totals easier to audit later.
          </div>
        </div>
      </div>
    </div>

    <div class="panel custodian-penalties-panel">
      <div class="card-head card-head-tight">
        <div class="dashboard-icon icon-ledger" aria-hidden="true"></div>
        <div>
          <p class="muted eyebrow-compact">Penalty Records</p>
          <h3 class="heading-card">Status updates and balance review</h3>
          <p class="muted">Each row shows the borrower, related book, amount, current state, and the action needed to keep records accurate.</p>
        </div>
      </div>
      <div class="inline-actions chips-row custodian-penalties-summary">
        <span class="chip">Total amount: <?php echo h(format_currency($summary['total_amount'] ?? 0)); ?></span>
        <span class="chip">Unpaid balance: <?php echo h(format_currency($summary['unpaid_balance'] ?? 0)); ?></span>
        <span class="chip">Open items: <?php echo (int) ($summary['unpaid_penalties'] ?? 0); ?></span>
      </div>
      <div class="table-wrap table-wrap-top custodian-penalties-table">
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
                  <div class="inline-actions custodian-penalties-actions">
                    <form method="post" class="inline-form">
                      <input type="hidden" name="id" value="<?php echo (int) $penalty['id']; ?>">
                      <button type="submit" name="mark_paid" value="1">Mark Paid</button>
                    </form>
                    <form method="post" class="inline-form">
                      <input type="hidden" name="id" value="<?php echo (int) $penalty['id']; ?>">
                      <button type="submit" class="secondary" name="mark_unpaid" value="1">Mark Unpaid</button>
                    </form>
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
</body>
</html>

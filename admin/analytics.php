<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_role('admin');

$monthlyTopBooks = $conn->query("
    SELECT ranked.borrow_month, ranked.title, ranked.author, ranked.borrow_count
    FROM (
        SELECT
            DATE_FORMAT(br.borrow_date, '%Y-%m') AS borrow_month,
            b.title,
            b.author,
            COUNT(*) AS borrow_count,
            ROW_NUMBER() OVER (
                PARTITION BY DATE_FORMAT(br.borrow_date, '%Y-%m')
                ORDER BY COUNT(*) DESC, b.title ASC
            ) AS row_num
        FROM borrows br
        JOIN books b ON b.id = br.book_id
        WHERE br.status IN ('borrowed', 'return_requested', 'returned')
        GROUP BY DATE_FORMAT(br.borrow_date, '%Y-%m'), b.id, b.title, b.author
    ) AS ranked
    WHERE ranked.row_num = 1
    ORDER BY ranked.borrow_month DESC
    LIMIT 12
");
$topBooksByMonth = [];
if ($monthlyTopBooks) {
    while ($row = $monthlyTopBooks->fetch_assoc()) {
        $topBooksByMonth[] = $row;
    }
}

$monthlyBorrowBreakdownResult = $conn->query("
    SELECT
      DATE_FORMAT(br.borrow_date, '%Y-%m') AS borrow_month,
      b.title,
      b.author,
      COUNT(*) AS borrow_count
    FROM borrows br
    JOIN books b ON b.id = br.book_id
    WHERE br.status IN ('borrowed', 'return_requested', 'returned')
    GROUP BY DATE_FORMAT(br.borrow_date, '%Y-%m'), b.id, b.title, b.author
    ORDER BY borrow_month DESC, borrow_count DESC, b.title ASC
    LIMIT 60
");

$monthlyBorrowBreakdown = [];
if ($monthlyBorrowBreakdownResult) {
    while ($row = $monthlyBorrowBreakdownResult->fetch_assoc()) {
        $monthlyBorrowBreakdown[$row['borrow_month']][] = $row;
    }
}

$monthlyBorrowTotals = $conn->query("
    SELECT
      DATE_FORMAT(borrow_date, '%Y-%m') AS borrow_month,
      COUNT(*) AS borrow_count
    FROM borrows
    WHERE status IN ('borrowed', 'return_requested', 'returned')
    GROUP BY DATE_FORMAT(borrow_date, '%Y-%m')
    ORDER BY borrow_month DESC
    LIMIT 6
");

$borrowTrend = [];
if ($monthlyBorrowTotals) {
    while ($trendRow = $monthlyBorrowTotals->fetch_assoc()) {
        $borrowTrend[] = $trendRow;
    }
    $borrowTrend = array_reverse($borrowTrend);
}

$currentTrend = !empty($borrowTrend) ? $borrowTrend[count($borrowTrend) - 1] : null;
$previousTrend = count($borrowTrend) > 1 ? $borrowTrend[count($borrowTrend) - 2] : null;
$currentBorrowMonth = (string) ($currentTrend['borrow_month'] ?? date('Y-m'));
$currentBorrowCount = (int) ($currentTrend['borrow_count'] ?? 0);
$previousBorrowCount = (int) ($previousTrend['borrow_count'] ?? 0);
$borrowDelta = $currentBorrowCount - $previousBorrowCount;
$borrowDeltaLabel = $previousTrend
    ? (($borrowDelta >= 0 ? '+' : '') . $borrowDelta . ' vs ' . date('M Y', strtotime(($previousTrend['borrow_month'] ?? date('Y-m')) . '-01')))
    : 'First tracked month';
$borrowDeltaTone = $borrowDelta > 0 ? 'good' : ($borrowDelta < 0 ? 'warning' : 'neutral');

$weeklyBorrowRowsStmt = $conn->prepare("
    SELECT
      CASE
        WHEN DAYOFMONTH(borrow_date) <= 7 THEN 1
        WHEN DAYOFMONTH(borrow_date) <= 14 THEN 2
        WHEN DAYOFMONTH(borrow_date) <= 21 THEN 3
        ELSE 4
      END AS week_slot,
      COUNT(*) AS borrow_count
    FROM borrows
    WHERE DATE_FORMAT(borrow_date, '%Y-%m') = ?
      AND status IN ('borrowed', 'return_requested', 'returned')
    GROUP BY week_slot
    ORDER BY week_slot ASC
");
$weeklyBorrowRowsStmt->bind_param('s', $currentBorrowMonth);
$weeklyBorrowRowsStmt->execute();
$weeklyBorrowRowsResult = $weeklyBorrowRowsStmt->get_result();
$weeklyBorrowMap = [];
while ($weeklyBorrowRowsResult && ($row = $weeklyBorrowRowsResult->fetch_assoc())) {
    $weeklyBorrowMap[(int) ($row['week_slot'] ?? 0)] = (int) ($row['borrow_count'] ?? 0);
}
$weeklyBorrowRowsStmt->close();

$monthStart = strtotime($currentBorrowMonth . '-01');
$monthEnd = strtotime(date('Y-m-t', $monthStart));
$weeklyBorrowTrend = [];
$maxWeeklyBorrowCount = 0;
for ($week = 1; $week <= 4; $week++) {
    $rangeStartDay = (($week - 1) * 7) + 1;
    $rangeEndDay = $week === 4 ? (int) date('t', $monthStart) : min((int) date('t', $monthStart), $week * 7);
    $rangeStart = strtotime($currentBorrowMonth . '-' . str_pad((string) $rangeStartDay, 2, '0', STR_PAD_LEFT));
    $rangeEnd = strtotime($currentBorrowMonth . '-' . str_pad((string) $rangeEndDay, 2, '0', STR_PAD_LEFT));
    $count = (int) ($weeklyBorrowMap[$week] ?? 0);
    $weeklyBorrowTrend[] = [
        'label' => 'Week ' . $week,
        'range' => date('M j', $rangeStart) . ' - ' . date('M j', min($rangeEnd, $monthEnd)),
        'borrow_count' => $count,
    ];
    $maxWeeklyBorrowCount = max($maxWeeklyBorrowCount, $count);
}
$highestWeeklyBorrow = null;
$lowestWeeklyBorrow = null;
foreach ($weeklyBorrowTrend as $weekRow) {
    if ($highestWeeklyBorrow === null || (int) $weekRow['borrow_count'] > (int) $highestWeeklyBorrow['borrow_count']) {
        $highestWeeklyBorrow = $weekRow;
    }
    if ($lowestWeeklyBorrow === null || (int) $weekRow['borrow_count'] < (int) $lowestWeeklyBorrow['borrow_count']) {
        $lowestWeeklyBorrow = $weekRow;
    }
}
$weeklyChartScaleMax = max(10, (int) ceil(max(1, $maxWeeklyBorrowCount) / 10) * 10);
$weeklyChartTicks = [];
foreach ([1, 0.75, 0.5, 0.25, 0] as $ratio) {
    $weeklyChartTicks[] = (int) round($weeklyChartScaleMax * $ratio);
}
$highestWeekShare = $currentBorrowCount > 0
    ? (int) round((((int) ($highestWeeklyBorrow['borrow_count'] ?? 0)) / $currentBorrowCount) * 100)
    : 0;
$weeklyInsight = $currentBorrowCount > 0
    ? sprintf(
        '%s led borrowing activity with %d borrow%s, accounting for %d%% of %s volume.',
        (string) ($highestWeeklyBorrow['label'] ?? 'Week 1'),
        (int) ($highestWeeklyBorrow['borrow_count'] ?? 0),
        (int) ($highestWeeklyBorrow['borrow_count'] ?? 0) === 1 ? '' : 's',
        $highestWeekShare,
        date('F Y', strtotime($currentBorrowMonth . '-01'))
    )
    : 'No borrowing activity has been recorded for this month yet.';

$topBooksThisMonthResult = $conn->prepare("
    SELECT
      b.title,
      b.author,
      COUNT(*) AS borrow_count
    FROM borrows br
    JOIN books b ON b.id = br.book_id
    WHERE DATE_FORMAT(br.borrow_date, '%Y-%m') = ?
      AND br.status IN ('borrowed', 'return_requested', 'returned')
    GROUP BY b.id, b.title, b.author
    ORDER BY borrow_count DESC, b.title ASC
    LIMIT 5
");
$topBooksThisMonthResult->bind_param('s', $currentBorrowMonth);
$topBooksThisMonthResult->execute();
$topBooksThisMonthQuery = $topBooksThisMonthResult->get_result();
$topBooksThisMonth = [];
$maxTopBookCount = 0;
while ($topBooksThisMonthQuery && ($row = $topBooksThisMonthQuery->fetch_assoc())) {
    $topBooksThisMonth[] = $row;
    $maxTopBookCount = max($maxTopBookCount, (int) ($row['borrow_count'] ?? 0));
}
$topBooksThisMonthResult->close();

$topTitleThisMonth = $topBooksThisMonth[0] ?? null;
$topBookSharePercent = $currentBorrowCount > 0 && $topTitleThisMonth
    ? (int) round((((int) ($topTitleThisMonth['borrow_count'] ?? 0)) / $currentBorrowCount) * 100)
    : 0;
$topBookInsight = $topTitleThisMonth
    ? sprintf(
        '%s led this month with %d%% of all borrows.',
        (string) ($topTitleThisMonth['title'] ?? 'This title'),
        $topBookSharePercent
    )
    : 'No borrow share data is available for this month yet.';

$roleBreakdownStmt = $conn->prepare("
    SELECT
      u.role,
      COUNT(*) AS borrow_count
    FROM borrows br
    JOIN users u ON u.id = br.user_id
    WHERE DATE_FORMAT(br.borrow_date, '%Y-%m') = ?
      AND br.status IN ('borrowed', 'return_requested', 'returned')
      AND u.role IN ('student', 'faculty')
    GROUP BY u.role
    ORDER BY borrow_count DESC, u.role ASC
    LIMIT 1
");
$roleBreakdownStmt->bind_param('s', $currentBorrowMonth);
$roleBreakdownStmt->execute();
$activeRoleThisMonth = $roleBreakdownStmt->get_result()->fetch_assoc() ?: null;
$roleBreakdownStmt->close();

$topBooksShare = array_slice($topBooksThisMonth, 0, 3);
$topBooksShareCount = 0;
foreach ($topBooksShare as $shareRow) {
    $topBooksShareCount += (int) ($shareRow['borrow_count'] ?? 0);
}
$otherBooksShareCount = max(0, $currentBorrowCount - $topBooksShareCount);
$allBooksShareSegments = [];
$shareColors = ['book-1', 'book-2', 'book-3'];
foreach ($topBooksShare as $index => $shareRow) {
    $count = (int) ($shareRow['borrow_count'] ?? 0);
    $allBooksShareSegments[] = [
        'label' => (string) ($shareRow['title'] ?? 'Untitled'),
        'count' => $count,
        'percent' => $currentBorrowCount > 0 ? (int) round(($count / $currentBorrowCount) * 100) : 0,
        'color' => $shareColors[$index] ?? 'book-1',
    ];
}
if ($otherBooksShareCount > 0 || count($allBooksShareSegments) === 0) {
    $allBooksShareSegments[] = [
        'label' => 'Other books',
        'count' => $otherBooksShareCount,
        'percent' => $currentBorrowCount > 0 ? max(0, 100 - array_sum(array_column($allBooksShareSegments, 'percent'))) : 0,
        'color' => 'others',
    ];
}
$segmentOffsets = [];
$runningOffset = 0;
foreach ($allBooksShareSegments as $segment) {
    $segmentOffsets[] = [
        'offset' => $runningOffset,
        'percent' => (int) ($segment['percent'] ?? 0),
    ];
    $runningOffset += (int) ($segment['percent'] ?? 0);
}
$donutLabels = [];
foreach ($allBooksShareSegments as $index => $segment) {
    $percent = (int) ($segment['percent'] ?? 0);
    if ($percent <= 0) {
        continue;
    }

    $offset = (int) ($segmentOffsets[$index]['offset'] ?? 0);
    $midAngle = -90 + (($offset + ($percent / 2)) * 3.6);
    $angleRad = deg2rad($midAngle);
    $x = (int) round(cos($angleRad) * 72);
    $y = (int) round(sin($angleRad) * 68);

    $donutLabels[] = [
        'percent' => $percent,
        'x' => $x,
        'y' => $y,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Analytics</title>
<?php $assetVersion = (string) filemtime(__DIR__ . '/../assets/app.css'); ?>
<?php $memberSidebarVersion = (string) filemtime(__DIR__ . '/../assets/member_sidebar.js'); ?>
<?php $adminDashboardVersion = (string) filemtime(__DIR__ . '/../assets/admin_dashboard.js'); ?>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="/librarymanage/assets/app.css?v=<?php echo urlencode($assetVersion); ?>">
</head>
<body>
<div class="site-shell admin-shell member-shell js-member-sidebar" data-sidebar-key="admin-analytics" data-sidebar-default="expanded" data-sidebar-lock="expanded">
  <?php
  $sidebarPage = 'analytics';
  require __DIR__ . '/partials/sidebar.php';
  ?>

  <div class="member-main">
    <?php
    $pageTitle = 'Admin Analytics';
    $pageSubtitle = 'Borrowing trends, leaders, and monthly breakdowns';
    require __DIR__ . '/partials/topbar.php';
    ?>

    <div class="stack">
      <div class="panel">
        <div class="inline-actions inline-actions-spread analytics-print-head">
          <div>
            <p class="muted eyebrow-compact stack-copy">Analytics</p>
            <h3 class="heading-card">Borrowing performance</h3>
            <p class="muted copy-bottom">Monthly borrowing activity and top title leaders based on the <code>borrows</code> table.</p>
            <p class="analytics-print-note">For best print results, enable browser background graphics when saving this report as PDF.</p>
          </div>
          <div class="inline-actions analytics-print-actions">
            <button type="button" class="button secondary" onclick="window.print()">Print Report</button>
          </div>
        </div>

        <div class="analytics-summary-grid">
          <div class="analytics-summary-card analytics-summary-card-primary">
            <span class="analytics-summary-label">Current month volume</span>
            <strong><?php echo $currentBorrowCount; ?></strong>
            <span class="analytics-summary-meta"><?php echo h(date('F Y', strtotime($currentBorrowMonth . '-01'))); ?></span>
          </div>
          <div class="analytics-summary-card">
            <span class="analytics-summary-label">Month-over-month</span>
            <strong><?php echo $borrowDelta >= 0 ? '+' : ''; ?><?php echo $borrowDelta; ?></strong>
            <span class="analytics-summary-meta analytics-summary-tone-<?php echo h($borrowDeltaTone); ?>"><?php echo h($borrowDeltaLabel); ?></span>
          </div>
          <div class="analytics-summary-card">
            <span class="analytics-summary-label">Top book this month</span>
            <strong><?php echo h($topTitleThisMonth['title'] ?? 'No borrowing yet'); ?></strong>
            <span class="analytics-summary-meta">
              <?php if ($topTitleThisMonth): ?>
                <?php echo (int) $topTitleThisMonth['borrow_count']; ?> borrow<?php echo (int) $topTitleThisMonth['borrow_count'] === 1 ? '' : 's'; ?> by <?php echo h($topTitleThisMonth['author']); ?>
              <?php else: ?>
                Waiting for activity
              <?php endif; ?>
            </span>
          </div>
          <div class="analytics-summary-card">
            <span class="analytics-summary-label">Most active member group</span>
            <strong><?php echo h(role_label((string) ($activeRoleThisMonth['role'] ?? '')) ?: 'No data'); ?></strong>
            <span class="analytics-summary-meta">
              <?php if ($activeRoleThisMonth): ?>
                <?php echo (int) $activeRoleThisMonth['borrow_count']; ?> borrow<?php echo (int) $activeRoleThisMonth['borrow_count'] === 1 ? '' : 's'; ?> this month
              <?php else: ?>
                No borrowing mix yet
              <?php endif; ?>
            </span>
          </div>
        </div>

        <div class="dashboard-chart">
          <div class="inline-actions inline-actions-spread">
            <span class="muted">Borrow volume by week</span>
            <span class="code-pill"><?php echo h(date('F Y', strtotime($currentBorrowMonth . '-01'))); ?> | 4 weeks</span>
          </div>
          <div class="analytics-chart-layout">
            <div>
              <div class="analytics-weekly-summary">
                <div class="analytics-weekly-summary-item">
                  <span class="analytics-summary-label">Highest week</span>
                  <strong><?php echo h((string) ($highestWeeklyBorrow['label'] ?? 'Week 1')); ?></strong>
                  <span class="analytics-summary-meta"><?php echo (int) ($highestWeeklyBorrow['borrow_count'] ?? 0); ?> borrow<?php echo (int) ($highestWeeklyBorrow['borrow_count'] ?? 0) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="analytics-weekly-summary-item">
                  <span class="analytics-summary-label">Lowest week</span>
                  <strong><?php echo h((string) ($lowestWeeklyBorrow['label'] ?? 'Week 1')); ?></strong>
                  <span class="analytics-summary-meta"><?php echo (int) ($lowestWeeklyBorrow['borrow_count'] ?? 0); ?> borrow<?php echo (int) ($lowestWeeklyBorrow['borrow_count'] ?? 0) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="analytics-weekly-summary-item">
                  <span class="analytics-summary-label">Monthly total</span>
                  <strong><?php echo $currentBorrowCount; ?></strong>
                  <span class="analytics-summary-meta"><?php echo h(date('F Y', strtotime($currentBorrowMonth . '-01'))); ?></span>
                </div>
              </div>
              <p class="analytics-weekly-insight"><?php echo h($weeklyInsight); ?></p>
              <div class="analytics-weekly-chart" data-weekly-chart>
                <div class="analytics-weekly-axis">
                  <?php foreach ($weeklyChartTicks as $tick): ?>
                    <span><?php echo $tick; ?></span>
                  <?php endforeach; ?>
                </div>
                <div class="analytics-weekly-plot">
                  <div class="analytics-weekly-grid-lines" aria-hidden="true">
                    <?php foreach ($weeklyChartTicks as $tick): ?>
                      <span></span>
                    <?php endforeach; ?>
                  </div>
                  <?php if (count($weeklyBorrowTrend) === 0): ?>
                    <div class="empty-state chart-empty">No weekly borrow data yet.</div>
                  <?php endif; ?>
                  <div class="analytics-weekly-grid">
                  <?php foreach ($weeklyBorrowTrend as $weekRow): ?>
                    <?php
                      $weekCount = (int) ($weekRow['borrow_count'] ?? 0);
                      $isPeakWeek = $highestWeeklyBorrow && $weekCount === (int) ($highestWeeklyBorrow['borrow_count'] ?? -1) && $weekCount > 0;
                      $weekShare = $currentBorrowCount > 0 ? (int) round(($weekCount / $currentBorrowCount) * 100) : 0;
                    ?>
                    <div class="analytics-week-col<?php echo $isPeakWeek ? ' is-peak' : ''; ?>">
                      <div class="analytics-week-bar-wrap">
                        <?php if ($isPeakWeek): ?>
                          <span class="analytics-week-badge">Peak</span>
                        <?php endif; ?>
                        <div
                          class="analytics-week-bar<?php echo $weekCount === 0 ? ' is-empty' : ''; ?>"
                          data-week-bar
                          data-value="<?php echo $weekCount; ?>"
                          data-max="<?php echo max(1, $weeklyChartScaleMax); ?>"
                        >
                          <span class="analytics-week-bar-value"><?php echo $weekCount === 0 ? 'No borrows' : $weekCount; ?></span>
                        </div>
                      </div>
                      <div class="chart-label"><?php echo h($weekRow['label']); ?></div>
                      <div class="analytics-week-share"><?php echo $weekShare; ?>% of total</div>
                      <div class="analytics-week-range"><?php echo h($weekRow['range']); ?></div>
                    </div>
                  <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>

            <div class="analytics-donut-panel">
              <div class="inline-actions inline-actions-spread analytics-section-head">
                <div>
                  <p class="muted eyebrow-compact stack-copy">Book Share</p>
                  <h3 class="heading-top-md">Borrowed books mix</h3>
                </div>
                <span class="code-pill"><?php echo h(date('M Y', strtotime($currentBorrowMonth . '-01'))); ?></span>
              </div>

              <div class="analytics-donut-wrap">
                <div
                  class="analytics-donut"
                  style="<?php foreach ($segmentOffsets as $index => $segmentMeta): ?>--donut-segment-<?php echo $index + 1; ?>: <?php echo (int) $segmentMeta['offset']; ?>%; --donut-segment-<?php echo $index + 1; ?>-end: <?php echo (int) ($segmentMeta['offset'] + $segmentMeta['percent']); ?>%; <?php endforeach; ?>"
                >
                  <div class="analytics-donut-center">
                    <?php if ($topTitleThisMonth): ?>
                      <p class="analytics-donut-center-label">Top book</p>
                      <strong title="<?php echo h((string) ($topTitleThisMonth['title'] ?? '')); ?>">
                        <?php echo h((string) ($topTitleThisMonth['title'] ?? '')); ?>
                      </strong>
                      <span><?php echo (int) ($topTitleThisMonth['borrow_count'] ?? 0); ?> borrows | <?php echo $topBookSharePercent; ?>%</span>
                    <?php else: ?>
                      <strong><?php echo $currentBorrowCount; ?></strong>
                      <span>total</span>
                    <?php endif; ?>
                  </div>
                  <?php foreach ($donutLabels as $label): ?>
                    <span
                      class="analytics-donut-label"
                      style="--label-x: <?php echo (int) ($label['x'] ?? 0); ?>px; --label-y: <?php echo (int) ($label['y'] ?? 0); ?>px;"
                    >
                      <?php echo (int) ($label['percent'] ?? 0); ?>%
                    </span>
                  <?php endforeach; ?>
                </div>
              </div>
              <p class="analytics-donut-insight"><?php echo h($topBookInsight); ?></p>

              <div class="analytics-donut-legend">
                <?php foreach ($allBooksShareSegments as $index => $segment): ?>
                  <?php $count = (int) ($segment['count'] ?? 0); ?>
                  <div class="analytics-donut-legend-item">
                    <span class="analytics-donut-swatch analytics-donut-swatch-<?php echo h((string) ($segment['color'] ?? 'others')); ?>"></span>
                    <div>
                      <strong title="<?php echo h((string) ($segment['label'] ?? 'Unknown')); ?>">#<?php echo $index + 1; ?> <?php echo h((string) ($segment['label'] ?? 'Unknown')); ?></strong>
                      <div class="muted"><?php echo $count; ?> borrow<?php echo $count === 1 ? '' : 's'; ?> | <?php echo (int) ($segment['percent'] ?? 0); ?>%</div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="analytics-layout flow-top-md">
          <div class="panel analytics-panel">
            <div class="inline-actions inline-actions-spread analytics-section-head">
              <div>
                <p class="muted eyebrow-compact stack-copy">Top Books</p>
                <h3 class="heading-top-md">Most borrowed titles in <?php echo h(date('F Y', strtotime($currentBorrowMonth . '-01'))); ?></h3>
              </div>
              <span class="code-pill"><?php echo count($topBooksThisMonth); ?> ranked title<?php echo count($topBooksThisMonth) === 1 ? '' : 's'; ?></span>
            </div>
            <div class="analytics-ranking-list">
              <?php if (count($topBooksThisMonth) === 0): ?>
                <div class="empty-state">No borrow data for this month yet.</div>
              <?php endif; ?>
              <?php foreach ($topBooksThisMonth as $index => $row): ?>
                <?php $count = (int) ($row['borrow_count'] ?? 0); ?>
                <?php $width = $maxTopBookCount > 0 ? max(10, (int) round(($count / $maxTopBookCount) * 100)) : 10; ?>
                <?php $share = $currentBorrowCount > 0 ? (int) round(($count / $currentBorrowCount) * 100) : 0; ?>
                <div class="analytics-ranking-item<?php echo $index === 0 ? ' is-top' : ''; ?>">
                  <div class="analytics-ranking-copy">
                    <span class="analytics-ranking-rank">#<?php echo $index + 1; ?></span>
                    <div>
                      <strong><?php echo h($row['title']); ?></strong>
                      <div class="muted"><?php echo h($row['author']); ?> | <?php echo $share; ?>% share</div>
                    </div>
                  </div>
                  <div class="analytics-ranking-meter">
                    <span class="analytics-ranking-fill" style="width: <?php echo $width; ?>%"></span>
                  </div>
                  <span class="badge"><?php echo $count; ?> borrow<?php echo $count === 1 ? '' : 's'; ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="panel analytics-panel">
            <div class="inline-actions inline-actions-spread analytics-section-head">
              <div>
                <p class="muted eyebrow-compact stack-copy">Leaders</p>
                <h3 class="heading-top-md">Top book per month</h3>
              </div>
              <span class="code-pill">Last <?php echo count($topBooksByMonth); ?> tracked month(s)</span>
            </div>
            <div class="analytics-leader-list">
              <?php if (count($topBooksByMonth) === 0): ?>
                <div class="empty-state">No borrow analytics available yet.</div>
              <?php endif; ?>
              <?php foreach ($topBooksByMonth as $row): ?>
                <div class="analytics-leader-item">
                  <div>
                    <strong><?php echo h($row['title']); ?></strong>
                    <div class="muted"><?php echo h($row['author']); ?></div>
                  </div>
                  <div class="analytics-leader-meta">
                    <span class="code-pill"><?php echo h(date('M Y', strtotime($row['borrow_month'] . '-01'))); ?></span>
                    <span class="badge"><?php echo (int) $row['borrow_count']; ?> borrow<?php echo (int) $row['borrow_count'] === 1 ? '' : 's'; ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="grid cards flow-top-md">
          <div class="panel">
            <p class="muted eyebrow-compact stack-copy">Full Breakdown</p>
            <h3 class="heading-top-md">All borrowed books per month</h3>
            <div class="stack analytics-breakdown-list">
              <?php if (count($monthlyBorrowBreakdown) === 0): ?>
                <div class="empty-state">No monthly borrow breakdown available yet.</div>
              <?php endif; ?>
              <?php foreach ($monthlyBorrowBreakdown as $month => $items): ?>
                <details class="analytics-breakdown-item" <?php echo $month === $currentBorrowMonth ? 'open' : ''; ?>>
                  <summary class="analytics-breakdown-summary">
                    <strong><?php echo h(date('F Y', strtotime($month . '-01'))); ?></strong>
                    <span class="code-pill"><?php echo count($items); ?> title<?php echo count($items) === 1 ? '' : 's'; ?></span>
                  </summary>
                  <div class="table-wrap flow-top-sm">
                    <table>
                      <thead>
                        <tr>
                          <th>Book</th>
                          <th>Author</th>
                          <th>Borrow Count</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($items as $item): ?>
                          <tr>
                            <td><?php echo h($item['title']); ?></td>
                            <td><?php echo h($item['author']); ?></td>
                            <td><span class="badge"><?php echo (int) $item['borrow_count']; ?> borrow<?php echo (int) $item['borrow_count'] === 1 ? '' : 's'; ?></span></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </details>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="/librarymanage/assets/member_sidebar.js?v=<?php echo urlencode($memberSidebarVersion); ?>"></script>
<script src="/librarymanage/assets/admin_dashboard.js?v=<?php echo urlencode($adminDashboardVersion); ?>"></script>
</body>
</html>

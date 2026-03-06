<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('GET');
$user = api_require_login();

global $conn;
$limit = api_query_limit(30, 100);

$stmt = $conn->prepare("
    SELECT
        p.id,
        p.borrow_id,
        p.amount,
        p.reason,
        p.status,
        p.created_at,
        br.status AS borrow_status,
        (SELECT pay.status FROM payments pay WHERE pay.penalty_id = p.id ORDER BY pay.id DESC LIMIT 1) AS latest_payment_status
    FROM penalties p
    LEFT JOIN borrows br ON br.id = p.borrow_id
    WHERE p.user_id = ?
    ORDER BY id DESC
    LIMIT ?
");
$stmt->bind_param('ii', $user['id'], $limit);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $row['borrow_id'] = (int) $row['borrow_id'];
    $row['amount'] = (float) $row['amount'];
    $borrowStatus = (string) ($row['borrow_status'] ?? '');
    $penaltyStatus = (string) ($row['status'] ?? '');
    $latestPaymentStatus = (string) ($row['latest_payment_status'] ?? '');

    $row['payment_eligible'] = true;
    $row['payment_block_reason'] = null;

    if ($penaltyStatus === 'paid') {
        $row['payment_eligible'] = false;
        $row['payment_block_reason'] = 'already_paid';
    } elseif ($borrowStatus !== 'returned') {
        $row['payment_eligible'] = false;
        $row['payment_block_reason'] = 'waiting_return_confirmation';
    } elseif ($latestPaymentStatus === 'pending') {
        $row['payment_eligible'] = false;
        $row['payment_block_reason'] = 'pending_admin_review';
    }

    $items[] = $row;
}
$stmt->close();

api_json([
    'ok' => true,
    'count' => count($items),
    'items' => $items,
]);

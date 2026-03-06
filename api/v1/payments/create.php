<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

api_require_method('POST');
$user = api_require_token_auth();
api_require_token_scope($user, 'write');

global $conn;

$penaltyId = (int) ($_POST['penalty_id'] ?? 0);
$amount = (float) ($_POST['amount'] ?? 0);
$file = $_FILES['proof'] ?? [];

if ($penaltyId <= 0) {
    api_error('Invalid penalty_id.', 422);
}

if ($amount <= 0) {
    api_error('Enter a valid payment amount.', 422);
}

$penaltyCheck = $conn->prepare("
    SELECT
        p.id,
        p.amount,
        p.status,
        br.status AS borrow_status
    FROM penalties p
    LEFT JOIN borrows br ON br.id = p.borrow_id
    WHERE p.id = ? AND p.user_id = ?
    LIMIT 1
");
$penaltyCheck->bind_param('ii', $penaltyId, $user['id']);
$penaltyCheck->execute();
$penalty = $penaltyCheck->get_result()->fetch_assoc();
$penaltyCheck->close();

if (!$penalty) {
    api_error('Selected penalty was not found.', 404);
}

if (($penalty['borrow_status'] ?? '') !== 'returned') {
    api_error('You can only pay this penalty after the book is confirmed returned.', 409);
}

if ($penalty['status'] === 'paid') {
    api_error('This penalty is already marked as paid.', 409);
}

if ($amount !== (float) $penalty['amount']) {
    api_error('Payment amount must match the full penalty amount.', 422);
}

$existingPending = $conn->prepare("SELECT id FROM payments WHERE user_id = ? AND penalty_id = ? AND status = 'pending' LIMIT 1");
$existingPending->bind_param('ii', $user['id'], $penaltyId);
$existingPending->execute();
$existingPending->store_result();
$hasPending = $existingPending->num_rows > 0;
$existingPending->close();

if ($hasPending) {
    api_error('There is already a pending payment submission for this penalty.', 409);
}

if (empty($file['name'])) {
    api_error('Please upload proof of payment.', 422);
}

$extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'pdf'];
if (!in_array($extension, $allowed, true)) {
    api_error('Only JPG, JPEG, PNG, and PDF files are allowed.', 422);
}

if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
    api_error('Proof file must be 5MB or smaller.', 422);
}

$dir = dirname(__DIR__, 3) . '/uploads/proofs';
if (!ensure_upload_directory($dir)) {
    api_error('Upload folder could not be created.', 500);
}

$filename = 'proof_' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
$fullPath = $dir . '/' . $filename;
if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $fullPath)) {
    api_error('Upload failed.', 500);
}

$proofPath = 'uploads/proofs/' . $filename;

$insert = $conn->prepare("INSERT INTO payments (user_id, penalty_id, amount, proof_path, status) VALUES (?, ?, ?, ?, 'pending')");
$insert->bind_param('iids', $user['id'], $penaltyId, $amount, $proofPath);
$ok = $insert->execute();
$paymentId = (int) $insert->insert_id;
$insert->close();

if (!$ok) {
    remove_relative_file($proofPath);
    api_error('Unable to save payment right now.', 500);
}

audit_log($conn, 'api.payment.create', [
    'payment_id' => $paymentId,
    'penalty_id' => $penaltyId,
    'amount' => $amount,
], $user['id'], $user['role']);

create_notification(
    $conn,
    'admin',
    'New Payment Submission',
    'Payment #' . $paymentId . ' was submitted by ' . $user['username'] . '.',
    'warning'
);

api_json([
    'ok' => true,
    'message' => 'Payment submitted. Wait for admin review.',
    'payment' => [
        'id' => $paymentId,
        'penalty_id' => $penaltyId,
        'amount' => $amount,
        'proof_path' => $proofPath,
        'status' => 'pending',
    ],
], 201);

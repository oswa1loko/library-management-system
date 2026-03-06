<?php
require_once __DIR__ . '/includes/config.php';

$newPass = "admin123";
$hash = password_hash($newPass, PASSWORD_DEFAULT);

$sql = "UPDATE users SET password = ? WHERE username IN ('admin','student1','custodian1')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $hash);
$stmt->execute();

echo "DONE. Password of admin, student1, custodian1 is now: admin123";
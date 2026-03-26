<?php
// Legacy route kept for backward compatibility.
$queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');
$target = '/admin/manage_accounts.php' . ($queryString !== '' ? '?' . $queryString : '');
header('Location: ' . $target);
exit;

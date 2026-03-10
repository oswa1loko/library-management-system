<?php
require_once __DIR__ . '/includes/session.php';

app_start_session();
session_unset();
session_destroy();
header("Location: loginpage.php");
exit;

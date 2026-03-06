<?php
// includes/auth.php
if (session_status() === PHP_SESSION_NONE) session_start();

function require_login(): void {
    if (empty($_SESSION['role']) || empty($_SESSION['username'])) {
        header("Location: /librarymanage/loginpage.php");
        exit;
    }

    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli && function_exists('sync_overdue_penalties_if_needed')) {
        sync_overdue_penalties_if_needed($GLOBALS['conn']);
    }
}

function require_role(string $role): void {
    require_login();
    if (($_SESSION['role'] ?? '') !== $role) {
        header("Location: /librarymanage/loginpage.php");
        exit;
    }
}

function require_roles(array $roles): void {
    require_login();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        header("Location: /librarymanage/loginpage.php");
        exit;
    }
}

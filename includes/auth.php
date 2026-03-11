<?php
// includes/auth.php
require_once __DIR__ . '/session.php';

app_start_session();

function require_login(): void {
    if (empty($_SESSION['role']) || empty($_SESSION['username'])) {
        header("Location: /librarymanage/loginpage.php");
        exit;
    }
}

function require_role(string $role): void {
    require_login();
    if (!function_exists('roles_match') || !roles_match((string) ($_SESSION['role'] ?? ''), $role)) {
        header("Location: /librarymanage/loginpage.php");
        exit;
    }
}

function require_roles(array $roles): void {
    require_login();
    $actualRole = (string) ($_SESSION['role'] ?? '');
    $matched = false;
    foreach ($roles as $role) {
        if (function_exists('roles_match') && roles_match($actualRole, (string) $role)) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        header("Location: /librarymanage/loginpage.php");
        exit;
    }
}

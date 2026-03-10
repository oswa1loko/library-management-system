<?php
function app_start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $sessionDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0777, true);
    }

    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    session_start();
}

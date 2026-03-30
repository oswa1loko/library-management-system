<?php
date_default_timezone_set('Asia/Manila');

$GLOBALS['library_runtime_config'] = [];
$localRuntimeConfigPaths = [
    __DIR__ . '/local_runtime_config.php',
    __DIR__ . '/local_mail_config.php',
];
foreach ($localRuntimeConfigPaths as $localRuntimeConfigPath) {
    if (is_file($localRuntimeConfigPath)) {
        $localRuntimeConfig = require $localRuntimeConfigPath;
        if (is_array($localRuntimeConfig)) {
            $GLOBALS['library_runtime_config'] = array_merge($GLOBALS['library_runtime_config'], $localRuntimeConfig);
        }
    }
}

function library_config_value(string $key, string $fallback = ''): string
{
    $envValue = getenv($key);
    if (is_string($envValue) && $envValue !== '') {
        return $envValue;
    }

    $config = $GLOBALS['library_runtime_config'] ?? [];
    if (isset($config[$key])) {
        $value = trim((string) $config[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

function library_is_local_database_host(string $host): bool
{
    $host = strtolower(trim($host));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

$servername = library_config_value('DB_HOST', 'localhost');
$dbusername = library_config_value('DB_USER', 'root');
$dbpassword = library_config_value('DB_PASS', '');
$dbname = library_config_value('DB_NAME', 'librarymanage');
$dbport = (int) library_config_value('DB_PORT', '3306');

function library_open_connection(): mysqli
{
    global $servername, $dbusername, $dbpassword, $dbname, $dbport;

    mysqli_report(MYSQLI_REPORT_OFF);

    $db = mysqli_init();
    if (!$db instanceof mysqli) {
        throw new RuntimeException('Unable to initialize database connection.');
    }

    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $shouldBootstrapDatabase = library_is_local_database_host($servername) && $dbusername === 'root';
    $targetDatabase = $shouldBootstrapDatabase ? null : $dbname;

    $connected = @$db->real_connect($servername, $dbusername, $dbpassword, $targetDatabase, $dbport);
    if (!$connected) {
        throw new RuntimeException('Database connection failed: ' . mysqli_connect_error());
    }

    if ($shouldBootstrapDatabase) {
        $db->query("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $db->select_db($dbname);
    }

    $db->set_charset("utf8mb4");
    $db->query("SET time_zone = '+08:00'");

    return $db;
}

function library_database_error_message(): string
{
    if (library_is_local_database_host((string) ($GLOBALS['servername'] ?? ''))) {
        return 'Database connection failed. Check that MySQL is running and that your local database settings are correct, then refresh this page.';
    }

    return 'Database connection failed. Check the deployed database credentials and database server status for this site.';
}

function library_is_connection_lost(Throwable $exception): bool
{
    $message = strtolower($exception->getMessage());
    return str_contains($message, 'server has gone away')
        || str_contains($message, 'lost connection')
        || str_contains($message, 'error while sending');
}

function library_ping_or_reconnect(mysqli &$conn): void
{
    try {
        if ($conn->ping()) {
            return;
        }
    } catch (Throwable $exception) {
        // Reconnect below.
    }

    $conn = library_open_connection();
    if (library_should_run_schema_bootstrap($conn)) {
        ensure_library_schema($conn);
    }
}

function library_safe_query(mysqli &$conn, string $sql)
{
    library_ping_or_reconnect($conn);

    try {
        return $conn->query($sql);
    } catch (mysqli_sql_exception $exception) {
        if (!library_is_connection_lost($exception)) {
            throw $exception;
        }

        $conn = library_open_connection();
        if (library_should_run_schema_bootstrap($conn)) {
            ensure_library_schema($conn);
        }
        return $conn->query($sql);
    }
}

function library_safe_prepare(mysqli &$conn, string $sql): mysqli_stmt
{
    library_ping_or_reconnect($conn);

    try {
        $stmt = $conn->prepare($sql);
    } catch (mysqli_sql_exception $exception) {
        if (!library_is_connection_lost($exception)) {
            throw $exception;
        }

        $conn = library_open_connection();
        if (library_should_run_schema_bootstrap($conn)) {
            ensure_library_schema($conn);
        }
        $stmt = $conn->prepare($sql);
    }

    if (!$stmt instanceof mysqli_stmt) {
        throw new RuntimeException('Unable to prepare database statement.');
    }

    return $stmt;
}

try {
    $conn = library_open_connection();
} catch (Throwable $exception) {
    http_response_code(500);
    die(library_database_error_message());
}

if ($conn->connect_error) {
    http_response_code(500);
    die(library_database_error_message());
}

function library_should_rewrite_public_output(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');

    if ($scriptName !== '' && preg_match('#/(ebook_stream|api/v1)/#', $scriptName) === 1) {
        return false;
    }

    if ($requestUri !== '' && preg_match('#^/(api/v1|ebook_stream\\.php)#', $requestUri) === 1) {
        return false;
    }

    return true;
}

function library_rewrite_public_output(string $buffer): string
{
    if (function_exists('library_inject_csrf_hidden_fields')) {
        $buffer = library_inject_csrf_hidden_fields($buffer);
    }

    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    $configuredBasePath = trim((string) ($GLOBALS['library_runtime_config']['app_base_path'] ?? ''));
    $configuredBasePath = $configuredBasePath === '' ? '' : '/' . trim($configuredBasePath, '/');
    $faviconFile = dirname(__DIR__) . '/assets/images/regismarielogo.png';
    $faviconVersion = is_file($faviconFile) ? '?v=' . urlencode((string) filemtime($faviconFile)) : '';

    if ($isLocal) {
        $buffer = strtr($buffer, [
            '="/assets/' => '="/librarymanage/assets/',
            "='/assets/" => "'/librarymanage/assets/",
            '="/index.php' => '="/librarymanage/index.php',
            "='/index.php" => "'/librarymanage/index.php",
            '="/loginpage.php' => '="/librarymanage/loginpage.php',
            "='/loginpage.php" => "'/librarymanage/loginpage.php",
            '="/api/v1/' => '="/librarymanage/api/v1/',
            "='/api/v1/" => "'/librarymanage/api/v1/",
            'url("/assets/' => 'url("/librarymanage/assets/',
            "url('/assets/" => "url('/librarymanage/assets/",
        ]);

        if (stripos($buffer, '<head') !== false && stripos($buffer, 'rel="icon"') === false && stripos($buffer, "rel='icon'") === false) {
            $faviconMarkup = "\n"
                . '    <link rel="icon" type="image/png" href="/librarymanage/assets/images/regismarielogo.png' . $faviconVersion . '" />' . "\n"
                . '    <link rel="shortcut icon" type="image/png" href="/librarymanage/assets/images/regismarielogo.png' . $faviconVersion . '" />' . "\n"
                . '    <link rel="apple-touch-icon" href="/librarymanage/assets/images/regismarielogo.png' . $faviconVersion . '" />' . "\n";
            $buffer = preg_replace('/<\/head>/i', $faviconMarkup . '</head>', $buffer, 1) ?? $buffer;
        }

        return $buffer;
    }

    if ($configuredBasePath === '') {
        $buffer = str_replace('/librarymanage/', '/', $buffer);
    } elseif ($configuredBasePath !== '/librarymanage') {
        $buffer = str_replace('/librarymanage/', $configuredBasePath . '/', $buffer);
    }

    if (stripos($buffer, '<head') !== false && stripos($buffer, 'rel="icon"') === false && stripos($buffer, "rel='icon'") === false) {
        $faviconMarkup = "\n"
            . '    <link rel="icon" type="image/png" href="' . ($configuredBasePath !== '' ? $configuredBasePath : '') . '/assets/images/regismarielogo.png' . $faviconVersion . '" />' . "\n"
            . '    <link rel="shortcut icon" type="image/png" href="' . ($configuredBasePath !== '' ? $configuredBasePath : '') . '/assets/images/regismarielogo.png' . $faviconVersion . '" />' . "\n"
            . '    <link rel="apple-touch-icon" href="' . ($configuredBasePath !== '' ? $configuredBasePath : '') . '/assets/images/regismarielogo.png' . $faviconVersion . '" />' . "\n";
        $buffer = preg_replace('/<\/head>/i', $faviconMarkup . '</head>', $buffer, 1) ?? $buffer;
    }

    return $buffer;
}

if (!defined('LIBRARY_PUBLIC_OUTPUT_REWRITE')) {
    define('LIBRARY_PUBLIC_OUTPUT_REWRITE', true);

    if (library_should_rewrite_public_output()) {
        ob_start('library_rewrite_public_output');
    }
}

function table_exists(mysqli $conn, string $table): bool
{
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function library_should_run_schema_bootstrap(mysqli $conn): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    return !table_exists($conn, 'users');
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    if (!table_exists($conn, $table)) {
        return false;
    }

    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function index_exists(mysqli $conn, string $table, string $index): bool
{
    if (!table_exists($conn, $table)) {
        return false;
    }

    $safeTable = $conn->real_escape_string($table);
    $safeIndex = $conn->real_escape_string($index);
    $result = $conn->query("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function ensure_library_schema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fullname VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','student','faculty','librarian') NOT NULL,
            account_status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            login_otp_hash CHAR(64) DEFAULT NULL,
            login_otp_expires_at DATETIME DEFAULT NULL,
            login_otp_sent_at DATETIME DEFAULT NULL,
            password_setup_required TINYINT(1) NOT NULL DEFAULT 0,
            password_setup_completed_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS catalogs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            description TEXT DEFAULT NULL,
            cover_path VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS books (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            author VARCHAR(255) DEFAULT '',
            category VARCHAR(120) DEFAULT '',
            catalog_id INT DEFAULT NULL,
            isbn VARCHAR(50) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            cover_path VARCHAR(255) DEFAULT NULL,
            qty_total INT NOT NULL DEFAULT 1,
            qty_available INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS borrows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            book_id INT NOT NULL,
            request_batch VARCHAR(40) DEFAULT NULL,
            return_batch VARCHAR(40) DEFAULT NULL,
            requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            borrow_date DATE DEFAULT NULL,
            approved_at DATETIME DEFAULT NULL,
            due_date DATE DEFAULT NULL,
            due_at DATETIME DEFAULT NULL,
            borrow_days INT NOT NULL DEFAULT 7,
            due_reminder_sent_at DATETIME DEFAULT NULL,
            overdue_notice_sent_at DATETIME DEFAULT NULL,
            return_requested_at DATETIME DEFAULT NULL,
            return_date DATE DEFAULT NULL,
            returned_at DATETIME DEFAULT NULL,
            status ENUM('pending','borrowed','return_requested','returned') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("ALTER TABLE borrows MODIFY status ENUM('pending','borrowed','return_requested','returned') NOT NULL DEFAULT 'pending'");

    $conn->query("
        CREATE TABLE IF NOT EXISTS penalties (
            id INT AUTO_INCREMENT PRIMARY KEY,
            borrow_id INT NOT NULL,
            user_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            reason VARCHAR(255) NOT NULL,
            status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            penalty_id INT DEFAULT NULL,
            payment_batch VARCHAR(40) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            proof_path VARCHAR(255) DEFAULT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS payment_penalty_links (
            payment_id INT NOT NULL,
            penalty_id INT NOT NULL,
            PRIMARY KEY (payment_id, penalty_id),
            INDEX idx_payment_penalty_links_penalty (penalty_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS complaints (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fullname VARCHAR(100) NOT NULL,
            email VARCHAR(100) DEFAULT NULL,
            role VARCHAR(30) NOT NULL DEFAULT 'guest',
            mobile_number VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            admin_response TEXT DEFAULT NULL,
            responded_at DATETIME DEFAULT NULL,
            responded_by INT DEFAULT NULL,
            status ENUM('new','reviewed','resolved') NOT NULL DEFAULT 'new',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS api_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            label VARCHAR(100) NOT NULL DEFAULT 'default',
            scopes VARCHAR(100) NOT NULL DEFAULT 'read,write',
            last_used_at DATETIME DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_api_tokens_user_id (user_id),
            INDEX idx_api_tokens_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actor_user_id INT DEFAULT NULL,
            actor_role VARCHAR(30) NOT NULL DEFAULT 'system',
            event_name VARCHAR(120) NOT NULL,
            context_json TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_actor_user_id (actor_user_id),
            INDEX idx_audit_event_name (event_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role VARCHAR(30) NOT NULL,
            user_id INT DEFAULT NULL,
            title VARCHAR(160) NOT NULL,
            body TEXT NOT NULL,
            severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_role (role),
            INDEX idx_notifications_user_id (user_id),
            INDEX idx_notifications_is_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            audience ENUM('student','faculty','both') NOT NULL,
            title VARCHAR(160) NOT NULL,
            body TEXT NOT NULL,
            severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_announcements_audience (audience),
            INDEX idx_announcements_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS email_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_type VARCHAR(60) NOT NULL,
            recipient_email VARCHAR(160) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            text_body MEDIUMTEXT NOT NULL,
            html_body MEDIUMTEXT DEFAULT NULL,
            status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email_jobs_status_available (status, available_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS password_setup_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            purpose ENUM('account_setup','password_reset') NOT NULL DEFAULT 'account_setup',
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_setup_tokens_user (user_id),
            INDEX idx_password_setup_tokens_expires (expires_at),
            INDEX idx_password_setup_tokens_purpose_used (purpose, used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS ebooks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            author VARCHAR(255) DEFAULT '',
            description TEXT DEFAULT NULL,
            cover_path VARCHAR(255) DEFAULT NULL,
            file_path VARCHAR(255) NOT NULL,
            uploaded_by INT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ebooks_active (is_active),
            INDEX idx_ebooks_uploaded_by (uploaded_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("ALTER TABLE users MODIFY role ENUM('admin','student','faculty','custodian','librarian') NOT NULL");
    $conn->query("UPDATE users SET role = 'librarian' WHERE role = 'custodian'");
    $conn->query("ALTER TABLE users MODIFY role ENUM('admin','student','faculty','librarian') NOT NULL");

    if (column_exists($conn, 'books', 'book_title') && !column_exists($conn, 'books', 'title')) {
        $conn->query("ALTER TABLE books CHANGE book_title title VARCHAR(255) NOT NULL");
    }

    if (!column_exists($conn, 'users', 'login_otp_hash')) {
        $conn->query("ALTER TABLE users ADD COLUMN login_otp_hash CHAR(64) DEFAULT NULL AFTER role");
    }

    if (!column_exists($conn, 'users', 'login_otp_expires_at')) {
        $conn->query("ALTER TABLE users ADD COLUMN login_otp_expires_at DATETIME DEFAULT NULL AFTER login_otp_hash");
    }

    if (!column_exists($conn, 'users', 'login_otp_sent_at')) {
        $conn->query("ALTER TABLE users ADD COLUMN login_otp_sent_at DATETIME DEFAULT NULL AFTER login_otp_expires_at");
    }

    if (!column_exists($conn, 'users', 'profile_photo_path')) {
        $conn->query("ALTER TABLE users ADD COLUMN profile_photo_path VARCHAR(255) DEFAULT NULL AFTER login_otp_sent_at");
    }

    if (!column_exists($conn, 'users', 'course')) {
        $conn->query("ALTER TABLE users ADD COLUMN course VARCHAR(120) DEFAULT NULL AFTER profile_photo_path");
    }

    if (!column_exists($conn, 'users', 'password_setup_required')) {
        $conn->query("ALTER TABLE users ADD COLUMN password_setup_required TINYINT(1) NOT NULL DEFAULT 0 AFTER course");
    }

    if (!column_exists($conn, 'users', 'password_setup_completed_at')) {
        $conn->query("ALTER TABLE users ADD COLUMN password_setup_completed_at DATETIME DEFAULT NULL AFTER password_setup_required");
    }

    if (!column_exists($conn, 'users', 'account_status')) {
        $conn->query("ALTER TABLE users ADD COLUMN account_status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER role");
    }

    if (!column_exists($conn, 'notifications', 'user_id')) {
        $conn->query("ALTER TABLE notifications ADD COLUMN user_id INT DEFAULT NULL AFTER role");
        $conn->query("ALTER TABLE notifications ADD INDEX idx_notifications_user_id (user_id)");
    }

    if (!column_exists($conn, 'books', 'category')) {
        $conn->query("ALTER TABLE books ADD COLUMN category VARCHAR(120) DEFAULT '' AFTER author");
    }

    if (!column_exists($conn, 'catalogs', 'cover_path')) {
        $conn->query("ALTER TABLE catalogs ADD COLUMN cover_path VARCHAR(255) DEFAULT NULL AFTER description");
    }

    if (!column_exists($conn, 'books', 'catalog_id')) {
        $conn->query("ALTER TABLE books ADD COLUMN catalog_id INT DEFAULT NULL AFTER category");
    }

    if (!column_exists($conn, 'books', 'isbn')) {
        $conn->query("ALTER TABLE books ADD COLUMN isbn VARCHAR(50) DEFAULT NULL AFTER catalog_id");
    }

    if (!column_exists($conn, 'books', 'description')) {
        $conn->query("ALTER TABLE books ADD COLUMN description TEXT DEFAULT NULL AFTER isbn");
    }

    if (!index_exists($conn, 'books', 'idx_books_catalog_id')) {
        $conn->query("CREATE INDEX idx_books_catalog_id ON books (catalog_id)");
    }

    if (table_exists($conn, 'catalogs')) {
        $conn->query("
            INSERT IGNORE INTO catalogs (name)
            SELECT DISTINCT category
            FROM books
            WHERE category IS NOT NULL
              AND category <> ''
        ");

        $conn->query("
            UPDATE books b
            JOIN catalogs c ON c.name = b.category
            SET b.catalog_id = c.id
            WHERE (b.catalog_id IS NULL OR b.catalog_id = 0)
              AND b.category IS NOT NULL
              AND b.category <> ''
        ");

        $conn->query("
            UPDATE books b
            JOIN catalogs c ON c.id = b.catalog_id
            SET b.category = c.name
            WHERE b.catalog_id IS NOT NULL
              AND (b.category IS NULL OR b.category = '' OR b.category <> c.name)
        ");
    }

    if (!column_exists($conn, 'books', 'cover_path')) {
        $conn->query("ALTER TABLE books ADD COLUMN cover_path VARCHAR(255) DEFAULT NULL AFTER description");
    }

    if (!column_exists($conn, 'books', 'qty_total')) {
        $conn->query("ALTER TABLE books ADD COLUMN qty_total INT NOT NULL DEFAULT 1 AFTER cover_path");
    }

    if (!column_exists($conn, 'books', 'qty_available')) {
        $conn->query("ALTER TABLE books ADD COLUMN qty_available INT NOT NULL DEFAULT 1 AFTER qty_total");
    }

    if (!column_exists($conn, 'borrows', 'borrow_days')) {
        $conn->query("ALTER TABLE borrows ADD COLUMN borrow_days INT NOT NULL DEFAULT 7 AFTER due_date");
    }

    if (!column_exists($conn, 'borrows', 'requested_at')) {
        $conn->query("ALTER TABLE borrows ADD COLUMN requested_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER return_batch");
    }

    if (!column_exists($conn, 'borrows', 'approved_at')) {
        $conn->query("ALTER TABLE borrows ADD COLUMN approved_at DATETIME DEFAULT NULL AFTER borrow_date");
    }

    if (!column_exists($conn, 'borrows', 'approval_notice_sent_at')) {
        $conn->query("ALTER TABLE borrows ADD COLUMN approval_notice_sent_at DATETIME DEFAULT NULL AFTER approved_at");
    }

    if (!column_exists($conn, 'borrows', 'due_at')) {
        $conn->query("ALTER TABLE borrows ADD COLUMN due_at DATETIME DEFAULT NULL AFTER due_date");
    }

    if (!column_exists($conn, 'borrows', 'due_reminder_sent_at')) {
        $conn->query("ALTER TABLE borrows ADD COLUMN due_reminder_sent_at DATETIME DEFAULT NULL AFTER borrow_days");
    }

    if (!column_exists($conn, 'borrows', 'overdue_notice_sent_at')) {
        $conn->query("ALTER TABLE borrows ADD COLUMN overdue_notice_sent_at DATETIME DEFAULT NULL AFTER due_reminder_sent_at");
    }

    if (!column_exists($conn, 'borrows', 'request_batch')) {
        $conn->query("ALTER TABLE borrows ADD COLUMN request_batch VARCHAR(40) DEFAULT NULL AFTER book_id");
    }

    if (!column_exists($conn, 'borrows', 'return_batch')) {
        $conn->query("ALTER TABLE borrows ADD COLUMN return_batch VARCHAR(40) DEFAULT NULL AFTER request_batch");
    }

    if (!column_exists($conn, 'borrows', 'return_requested_at')) {
        $conn->query("ALTER TABLE borrows ADD COLUMN return_requested_at DATETIME DEFAULT NULL AFTER overdue_notice_sent_at");
    }

    if (!column_exists($conn, 'borrows', 'returned_at')) {
        $conn->query("ALTER TABLE borrows ADD COLUMN returned_at DATETIME DEFAULT NULL AFTER return_date");
    }

    $conn->query("ALTER TABLE borrows MODIFY borrow_date DATE DEFAULT NULL");
    $conn->query("ALTER TABLE borrows MODIFY due_date DATE DEFAULT NULL");
    $conn->query("ALTER TABLE borrows MODIFY return_date DATE DEFAULT NULL");

    if (!index_exists($conn, 'borrows', 'idx_borrows_request_batch')) {
        $conn->query("CREATE INDEX idx_borrows_request_batch ON borrows (request_batch)");
    }

    if (!index_exists($conn, 'borrows', 'idx_borrows_return_batch')) {
        $conn->query("CREATE INDEX idx_borrows_return_batch ON borrows (return_batch)");
    }

    if (!index_exists($conn, 'borrows', 'idx_borrows_status_due_date')) {
        $conn->query("CREATE INDEX idx_borrows_status_due_date ON borrows (status, due_date)");
    }

    if (!index_exists($conn, 'borrows', 'idx_borrows_user_status')) {
        $conn->query("CREATE INDEX idx_borrows_user_status ON borrows (user_id, status)");
    }

    if (!index_exists($conn, 'borrows', 'idx_borrows_book_status')) {
        $conn->query("CREATE INDEX idx_borrows_book_status ON borrows (book_id, status)");
    }

    if (!index_exists($conn, 'borrows', 'idx_borrows_requested_at')) {
        $conn->query("CREATE INDEX idx_borrows_requested_at ON borrows (requested_at)");
    }

    if (!index_exists($conn, 'books', 'idx_books_category_title')) {
        $conn->query("CREATE INDEX idx_books_category_title ON books (category, title)");
    }

    if (!index_exists($conn, 'notifications', 'idx_notifications_role_read_created')) {
        $conn->query("CREATE INDEX idx_notifications_role_read_created ON notifications (role, is_read, created_at)");
    }

    if (!index_exists($conn, 'notifications', 'idx_notifications_user_read_created')) {
        $conn->query("CREATE INDEX idx_notifications_user_read_created ON notifications (user_id, is_read, created_at)");
    }

    if (column_exists($conn, 'borrows', 'request_batch')) {
        $conn->query("
            UPDATE borrows
            SET request_batch = CONCAT('legacy-', id)
            WHERE request_batch IS NULL OR request_batch = ''
        ");
    }

    if (column_exists($conn, 'borrows', 'borrow_days')) {
        $conn->query("
            UPDATE borrows
            SET borrow_days = CASE
                WHEN due_date >= borrow_date THEN LEAST(GREATEST(DATEDIFF(due_date, borrow_date), 1), 30)
                ELSE 7
            END
            WHERE borrow_days IS NULL OR borrow_days < 1 OR borrow_days > 30
        ");
    }

    if (column_exists($conn, 'borrows', 'requested_at')) {
        $conn->query("
            UPDATE borrows
            SET requested_at = COALESCE(DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s'), NOW())
            WHERE requested_at IS NULL
        ");
    }

    if (column_exists($conn, 'borrows', 'approved_at')) {
        $conn->query("
            UPDATE borrows
            SET approved_at = CONCAT(borrow_date, ' 00:00:00')
            WHERE approved_at IS NULL
              AND borrow_date IS NOT NULL
              AND status IN ('borrowed', 'return_requested', 'returned')
        ");
    }

    if (column_exists($conn, 'borrows', 'due_at')) {
        $conn->query("
            UPDATE borrows
            SET due_at = CONCAT(due_date, ' 23:59:59')
            WHERE due_at IS NULL
              AND due_date IS NOT NULL
              AND status IN ('borrowed', 'return_requested', 'returned')
        ");
    }

    if (column_exists($conn, 'borrows', 'return_requested_at')) {
        $conn->query("
            UPDATE borrows
            SET return_requested_at = CONCAT(return_date, ' 00:00:00')
            WHERE return_requested_at IS NULL
              AND return_date IS NOT NULL
              AND status = 'return_requested'
        ");
    }

    if (column_exists($conn, 'borrows', 'returned_at')) {
        $conn->query("
            UPDATE borrows
            SET returned_at = CONCAT(return_date, ' 00:00:00')
            WHERE returned_at IS NULL
              AND return_date IS NOT NULL
              AND status = 'returned'
        ");
    }

    $conn->query("
        UPDATE borrows
        SET
            borrow_date = NULL,
            approved_at = NULL,
            due_date = NULL,
            due_at = NULL,
            due_reminder_sent_at = NULL,
            overdue_notice_sent_at = NULL,
            return_requested_at = NULL,
            return_date = NULL,
            returned_at = NULL,
            approval_notice_sent_at = NULL
        WHERE status = 'pending'
    ");

    if (column_exists($conn, 'books', 'copies')) {
        $conn->query("UPDATE books SET qty_total = copies WHERE (qty_total IS NULL OR qty_total = 1) AND copies IS NOT NULL");
        $conn->query("UPDATE books SET qty_available = copies WHERE (qty_available IS NULL OR qty_available = 1) AND copies IS NOT NULL");
    }

    if (column_exists($conn, 'books', 'quantity')) {
        $conn->query("UPDATE books SET qty_total = quantity WHERE (qty_total IS NULL OR qty_total = 1) AND quantity IS NOT NULL");
    }

    if (column_exists($conn, 'books', 'available')) {
        $conn->query("UPDATE books SET qty_available = available WHERE (qty_available IS NULL OR qty_available = 1) AND available IS NOT NULL");
    }

    $conn->query("UPDATE books SET qty_total = 1 WHERE qty_total IS NULL OR qty_total < 0");
    $conn->query("UPDATE books SET qty_available = qty_total WHERE qty_available IS NULL OR qty_available < 0 OR qty_available > qty_total");

    if (column_exists($conn, 'books', 'copies')) {
        $conn->query("ALTER TABLE books DROP COLUMN copies");
    }

    if (column_exists($conn, 'books', 'quantity')) {
        $conn->query("ALTER TABLE books DROP COLUMN quantity");
    }

    if (column_exists($conn, 'books', 'available')) {
        $conn->query("ALTER TABLE books DROP COLUMN available");
    }

    if (!column_exists($conn, 'complaints', 'mobile_number')) {
        $conn->query("ALTER TABLE complaints ADD COLUMN mobile_number VARCHAR(20) NOT NULL DEFAULT '' AFTER role");
    }

    if (!column_exists($conn, 'complaints', 'admin_response')) {
        $conn->query("ALTER TABLE complaints ADD COLUMN admin_response TEXT DEFAULT NULL AFTER message");
    }

    if (!column_exists($conn, 'complaints', 'responded_at')) {
        $conn->query("ALTER TABLE complaints ADD COLUMN responded_at DATETIME DEFAULT NULL AFTER admin_response");
    }

    if (!column_exists($conn, 'complaints', 'responded_by')) {
        $conn->query("ALTER TABLE complaints ADD COLUMN responded_by INT DEFAULT NULL AFTER responded_at");
    }

    if (!column_exists($conn, 'api_tokens', 'scopes')) {
        $conn->query("ALTER TABLE api_tokens ADD COLUMN scopes VARCHAR(100) NOT NULL DEFAULT 'read,write' AFTER label");
    }

    if (!column_exists($conn, 'payments', 'payment_batch')) {
        $conn->query("ALTER TABLE payments ADD COLUMN payment_batch VARCHAR(40) DEFAULT NULL AFTER penalty_id");
    }

    if (!index_exists($conn, 'payments', 'idx_payments_payment_batch')) {
        $conn->query("CREATE INDEX idx_payments_payment_batch ON payments (payment_batch)");
    }

    if (!index_exists($conn, 'payment_penalty_links', 'idx_payment_penalty_links_penalty')) {
        $conn->query("CREATE INDEX idx_payment_penalty_links_penalty ON payment_penalty_links (penalty_id)");
    }

    // Skip ebook schema touch-ups here because the current ebooks table is corrupted
    // and probing it during bootstrap can crash MariaDB before the app loads.

    if (column_exists($conn, 'complaints', 'subject') && column_exists($conn, 'complaints', 'mobile_number')) {
        $conn->query("
            UPDATE complaints
            SET mobile_number = subject
            WHERE (mobile_number = '' OR mobile_number IS NULL)
              AND subject IS NOT NULL
              AND subject <> ''
        ");
    }
}

if (library_should_run_schema_bootstrap($conn)) {
    ensure_library_schema($conn);
}
?>


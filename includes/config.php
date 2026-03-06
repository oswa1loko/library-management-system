<?php
$servername = "localhost";
$dbusername = "root";
$dbpassword = "";
$dbname = "librarymanage";

try {
    $conn = new mysqli($servername, $dbusername, $dbpassword);
} catch (mysqli_sql_exception $exception) {
    http_response_code(500);
    die('Database connection failed. Start MySQL in XAMPP, then refresh this page.');
}

if ($conn->connect_error) {
    http_response_code(500);
    die('Database connection failed. Start MySQL in XAMPP, then refresh this page.');
}

$conn->query("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$conn->select_db($dbname);
$conn->set_charset("utf8mb4");

function table_exists(mysqli $conn, string $table): bool
{
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
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

function ensure_library_schema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fullname VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','student','faculty','custodian') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS books (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            author VARCHAR(255) DEFAULT '',
            category VARCHAR(120) DEFAULT '',
            isbn VARCHAR(50) DEFAULT NULL,
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
            borrow_date DATE NOT NULL,
            due_date DATE NOT NULL,
            return_date DATE DEFAULT NULL,
            status ENUM('borrowed','return_requested','returned') NOT NULL DEFAULT 'borrowed',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("ALTER TABLE borrows MODIFY status ENUM('borrowed','return_requested','returned') NOT NULL DEFAULT 'borrowed'");

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
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            proof_path VARCHAR(255) DEFAULT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
            title VARCHAR(160) NOT NULL,
            body TEXT NOT NULL,
            severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_role (role),
            INDEX idx_notifications_is_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS ebooks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            file_path VARCHAR(255) NOT NULL,
            uploaded_by INT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ebooks_active (is_active),
            INDEX idx_ebooks_uploaded_by (uploaded_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("ALTER TABLE users MODIFY role ENUM('admin','student','faculty','custodian') NOT NULL");

    if (column_exists($conn, 'books', 'book_title') && !column_exists($conn, 'books', 'title')) {
        $conn->query("ALTER TABLE books CHANGE book_title title VARCHAR(255) NOT NULL");
    }

    if (!column_exists($conn, 'books', 'category')) {
        $conn->query("ALTER TABLE books ADD COLUMN category VARCHAR(120) DEFAULT '' AFTER author");
    }

    if (!column_exists($conn, 'books', 'isbn')) {
        $conn->query("ALTER TABLE books ADD COLUMN isbn VARCHAR(50) DEFAULT NULL AFTER category");
    }

    if (!column_exists($conn, 'books', 'cover_path')) {
        $conn->query("ALTER TABLE books ADD COLUMN cover_path VARCHAR(255) DEFAULT NULL AFTER isbn");
    }

    if (!column_exists($conn, 'books', 'qty_total')) {
        $conn->query("ALTER TABLE books ADD COLUMN qty_total INT NOT NULL DEFAULT 1 AFTER cover_path");
    }

    if (!column_exists($conn, 'books', 'qty_available')) {
        $conn->query("ALTER TABLE books ADD COLUMN qty_available INT NOT NULL DEFAULT 1 AFTER qty_total");
    }

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

    if (!column_exists($conn, 'api_tokens', 'scopes')) {
        $conn->query("ALTER TABLE api_tokens ADD COLUMN scopes VARCHAR(100) NOT NULL DEFAULT 'read,write' AFTER label");
    }

    if (!column_exists($conn, 'ebooks', 'description')) {
        $conn->query("ALTER TABLE ebooks ADD COLUMN description TEXT DEFAULT NULL AFTER title");
    }

    if (!column_exists($conn, 'ebooks', 'uploaded_by')) {
        $conn->query("ALTER TABLE ebooks ADD COLUMN uploaded_by INT DEFAULT NULL AFTER file_path");
    }

    if (!column_exists($conn, 'ebooks', 'is_active')) {
        $conn->query("ALTER TABLE ebooks ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER uploaded_by");
    }

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

ensure_library_schema($conn);
?>

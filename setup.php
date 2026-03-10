<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/helpers.php';

$setupLocked = false;
$usersReady = $conn->query("SHOW TABLES LIKE 'users'");
if ($usersReady instanceof mysqli_result && $usersReady->num_rows > 0) {
    $userCountResult = $conn->query("SELECT COUNT(*) AS total FROM users");
    $userCount = (int) ($userCountResult->fetch_assoc()['total'] ?? 0);
    $setupLocked = $userCount > 0;
}

if ($setupLocked && ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /librarymanage/loginpage.php');
    exit;
}

$messages = [];

function add_message(array &$messages, string $message): void
{
    $messages[] = $message;
}

function run_sql(mysqli $conn, string $sql, array &$messages, string $successLabel): void
{
    if ($conn->query($sql) === true) {
        add_message($messages, $successLabel);
        return;
    }

    add_message($messages, 'Database update issue: ' . $conn->error);
}

run_sql(
    $conn,
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fullname VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin','student','faculty','librarian') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    $messages,
    'Users table ready.'
);

run_sql(
    $conn,
    "CREATE TABLE IF NOT EXISTS books (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        author VARCHAR(255) DEFAULT '',
        category VARCHAR(120) DEFAULT '',
        isbn VARCHAR(50) DEFAULT NULL,
        cover_path VARCHAR(255) DEFAULT NULL,
        qty_total INT NOT NULL DEFAULT 1,
        qty_available INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    $messages,
    'Books table ready.'
);

run_sql(
    $conn,
    "CREATE TABLE IF NOT EXISTS borrows (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        book_id INT NOT NULL,
        borrow_date DATE NOT NULL,
        due_date DATE NOT NULL,
        borrow_days INT NOT NULL DEFAULT 7,
        return_date DATE DEFAULT NULL,
        status ENUM('pending','borrowed','return_requested','returned') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_borrows_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_borrows_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    $messages,
    'Borrows table ready.'
);

run_sql(
    $conn,
    "CREATE TABLE IF NOT EXISTS penalties (
        id INT AUTO_INCREMENT PRIMARY KEY,
        borrow_id INT NOT NULL,
        user_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        reason VARCHAR(255) NOT NULL,
        status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_penalties_borrow FOREIGN KEY (borrow_id) REFERENCES borrows(id) ON DELETE CASCADE,
        CONSTRAINT fk_penalties_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    $messages,
    'Penalties table ready.'
);

run_sql(
    $conn,
    "CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        penalty_id INT DEFAULT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        proof_path VARCHAR(255) DEFAULT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_payments_penalty FOREIGN KEY (penalty_id) REFERENCES penalties(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    $messages,
    'Payments table ready.'
);

run_sql(
    $conn,
    "CREATE TABLE IF NOT EXISTS complaints (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fullname VARCHAR(100) NOT NULL,
        email VARCHAR(100) DEFAULT NULL,
        role VARCHAR(30) NOT NULL DEFAULT 'guest',
        mobile_number VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('new','reviewed','resolved') NOT NULL DEFAULT 'new',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    $messages,
    'Complaints table ready.'
);

$conn->query("ALTER TABLE users MODIFY role ENUM('admin','student','faculty','custodian','librarian') NOT NULL");
$conn->query("UPDATE users SET role = 'librarian' WHERE role = 'custodian'");
$conn->query("ALTER TABLE users MODIFY role ENUM('admin','student','faculty','librarian') NOT NULL");
$conn->query("ALTER TABLE borrows MODIFY status ENUM('pending','borrowed','return_requested','returned') NOT NULL DEFAULT 'pending'");

if (!column_exists($conn, 'borrows', 'borrow_days')) {
    $conn->query("ALTER TABLE borrows ADD COLUMN borrow_days INT NOT NULL DEFAULT 7 AFTER due_date");
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

if (column_exists($conn, 'books', 'book_title') && !column_exists($conn, 'books', 'title')) {
    $conn->query("ALTER TABLE books CHANGE book_title title VARCHAR(255) NOT NULL");
}

if (!column_exists($conn, 'books', 'category')) {
    $conn->query("ALTER TABLE books ADD COLUMN category VARCHAR(120) DEFAULT '' AFTER author");
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
    $conn->query("UPDATE books SET qty_total = copies WHERE (qty_total = 1 OR qty_total IS NULL) AND copies IS NOT NULL");
    $conn->query("UPDATE books SET qty_available = copies WHERE (qty_available = 1 OR qty_available IS NULL) AND copies IS NOT NULL");
}

if (column_exists($conn, 'books', 'quantity')) {
    $conn->query("UPDATE books SET qty_total = quantity WHERE (qty_total = 1 OR qty_total IS NULL) AND quantity IS NOT NULL");
}

if (column_exists($conn, 'books', 'available')) {
    $conn->query("UPDATE books SET qty_available = available WHERE (qty_available = 1 OR qty_available IS NULL) AND available IS NOT NULL");
}

$conn->query("UPDATE books SET qty_total = 1 WHERE qty_total IS NULL OR qty_total < 0");
$conn->query("UPDATE books SET qty_available = LEAST(qty_total, GREATEST(qty_available, 0))");

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

if (column_exists($conn, 'complaints', 'subject') && column_exists($conn, 'complaints', 'mobile_number')) {
    $conn->query("
        UPDATE complaints
        SET mobile_number = subject
        WHERE (mobile_number = '' OR mobile_number IS NULL)
          AND subject IS NOT NULL
          AND subject <> ''
    ");
}

$defaultPassword = 'admin123';
$hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
$defaults = [
    ['Library Admin', 'admin@gmail.com', 'admin', $hash, 'admin'],
    ['Student One', 'student1@gmail.com', 'student1', $hash, 'student'],
    ['Faculty One', 'faculty1@gmail.com', 'faculty1', $hash, 'faculty'],
    ['Librarian One', 'librarian1@gmail.com', 'librarian1', $hash, 'librarian'],
];

$stmt = $conn->prepare("INSERT IGNORE INTO users (fullname, email, username, password, role) VALUES (?, ?, ?, ?, ?)");
foreach ($defaults as $user) {
    $stmt->bind_param('sssss', $user[0], $user[1], $user[2], $user[3], $user[4]);
    $stmt->execute();
}
$stmt->close();

add_message($messages, 'Setup finished. Default password for sample accounts: admin123');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setup | Library</title>
<script src="/librarymanage/assets/theme.js"></script>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="auth-shell">
  <div class="card surface-shell-xl">
    <div class="split split-stretch">
      <div class="panel-pad-lg">
        <p class="muted eyebrow">System Setup</p>
        <h2 class="heading-section">Library Setup Complete</h2>
        <p class="muted text-measure text-measure-wide">This page aligns the database with the current application structure and prepares sample accounts for role-based testing.</p>

        <div class="grid cards flow-top-lg">
          <div class="stat-card">
            <strong>5</strong>
            <span class="muted">Core tables prepared</span>
          </div>
          <div class="stat-card">
            <strong>4</strong>
            <span class="muted">Sample user roles created</span>
          </div>
        </div>

        <div class="stack flow-top-xl">
          <?php foreach ($messages as $message): ?>
            <div class="notice success"><?php echo h($message); ?></div>
          <?php endforeach; ?>
        </div>

        <div class="inline-actions flow-top-md">
          <a class="button" href="loginpage.php">Open Login</a>
          <a class="button secondary" href="index.php">Back Home</a>
        </div>
      </div>

      <div class="panel-pad-lg hero-panel-dark-soft">
        <span class="chip">Ready to Test</span>
        <h3 class="heading-top">Default Accounts</h3>
        <div class="stack flow-top-md">
          <div class="empty-state">
            <strong class="label-block-gap">Admin</strong>
            Username: <code>admin</code><br>
            Password: <code>admin123</code>
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Student</strong>
            Username: <code>student1</code><br>
            Password: <code>admin123</code>
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Faculty</strong>
            Username: <code>faculty1</code><br>
            Password: <code>admin123</code>
          </div>
          <div class="empty-state">
            <strong class="label-block-gap">Librarian</strong>
            Username: <code>librarian1</code><br>
            Password: <code>admin123</code>
          </div>
        </div>

        <div class="panel flow-top-md">
          <p class="muted eyebrow stack-copy">Next Steps</p>
          <div class="stack">
            <div class="muted">1. Open the login page and sign in using the correct role.</div>
            <div class="muted">2. Test books, borrow and return flow, then penalties and payments.</div>
            <div class="muted">3. Create your own user accounts from the admin dashboard if needed.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>

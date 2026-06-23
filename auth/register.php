<?php
require_once __DIR__ . '/../backend-php/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$registeredStaffId = '';

function register_base_url(string $path = ''): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $pos = stripos($script, '/qoda/');
    $root = $pos !== false ? substr($script, 0, $pos + 6) : '/';
    return $root . ltrim($path, '/');
}

function registerEnsureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function registerEnsureTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(100) NULL,
            userId VARCHAR(100) NULL,
            username VARCHAR(120) NULL,
            staff_id VARCHAR(100) NULL,
            title VARCHAR(50) NULL,
            full_name VARCHAR(255) NULL,
            fullName VARCHAR(255) NULL,
            name VARCHAR(255) NULL,
            email VARCHAR(255) NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(30) NOT NULL DEFAULT 'LECTURER',
            status VARCHAR(30) NOT NULL DEFAULT 'Active',
            department VARCHAR(255) NULL,
            profile_pic MEDIUMTEXT NULL,
            created_at DATETIME NULL,
            createdAt DATETIME NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uq_users_email (email),
            INDEX idx_users_role_status (role, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lecturers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            userId INT NOT NULL,
            lecturerId VARCHAR(100) NOT NULL,
            department VARCHAR(255) NULL,
            title VARCHAR(50) NULL,
            createdAt DATETIME NULL,
            updatedAt DATETIME NULL,
            UNIQUE KEY uq_lecturers_user (userId)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    foreach ([
        'username' => 'VARCHAR(120) NULL',
        'staff_id' => 'VARCHAR(100) NULL',
        'title' => 'VARCHAR(50) NULL',
        'deleted_at' => 'DATETIME NULL',
        'profile_pic' => 'MEDIUMTEXT NULL',
    ] as $column => $definition) {
        try {
            registerEnsureColumn($pdo, 'users', $column, $definition);
        } catch (Throwable $ignored) {
        }
    }
}

function departmentAbbreviation(string $department): string
{
    $parts = preg_split('/\s+/', strtoupper(trim($department)), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return 'GEN';
    }
    if (count($parts) === 1) {
        return preg_replace('/[^A-Z0-9]/', '', substr($parts[0], 0, 4)) ?: 'GEN';
    }
    $abbr = '';
    foreach ($parts as $part) {
        $abbr .= $part[0] ?? '';
    }
    return preg_replace('/[^A-Z0-9]/', '', substr($abbr, 0, 5)) ?: 'GEN';
}

function generateLecturerStaffId(PDO $pdo, string $department): string
{
    $prefix = 'PULC/' . departmentAbbreviation($department) . '/';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'LECTURER' OR staff_id LIKE ?");
    $stmt->execute([$prefix . '%']);
    $next = max(1, (int)$stmt->fetchColumn() + 1);

    do {
        $staffId = $prefix . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
        $check = $pdo->prepare("SELECT id FROM users WHERE staff_id = ? OR user_id = ? OR userId = ? LIMIT 1");
        $check->execute([$staffId, $staffId, $staffId]);
        $exists = (bool)$check->fetch();
        $next++;
    } while ($exists);

    return $staffId;
}

try {
    registerEnsureTables($pdo);
} catch (Exception $schemaError) {
    $error = 'Registration setup failed: ' . $schemaError->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $title = trim($_POST['title'] ?? 'Mr.');

    if ($name === '' || $email === '' || $department === '') {
        $error = 'Please fill all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $staffId = generateLecturerStaffId($pdo, $department);
            $username = $staffId;
            $check = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = ? OR username = ? OR staff_id = ? OR user_id = ? OR userId = ?
                LIMIT 1
            ");
            $check->execute([$email, $username, $staffId, $staffId, $staffId]);

            if ($check->fetch()) {
                $error = 'This lecturer account already exists.';
            } else {
                $pdo->beginTransaction();
                $hashedPassword = password_hash($staffId, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users
                        (user_id, userId, username, staff_id, title, full_name, fullName, name, email, password,
                         role, status, department, created_at, createdAt, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'LECTURER', 'Active', ?, NOW(), NOW(), NOW())
                ");
                $stmt->execute([
                    $staffId,
                    $staffId,
                    $username,
                    $staffId,
                    $title,
                    $name,
                    $name,
                    $name,
                    $email,
                    $hashedPassword,
                    $department
                ]);

                $userId = (int)$pdo->lastInsertId();
                $profile = $pdo->prepare("
                    INSERT INTO lecturers (userId, lecturerId, department, title, createdAt, updatedAt)
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE department = VALUES(department), title = VALUES(title), updatedAt = NOW()
                ");
                $profile->execute([$userId, $staffId, $department, $title]);
                $pdo->commit();

                $registeredStaffId = $staffId;
                $_POST = [];
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Registration failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Registration - QODA</title>
    <link rel="icon" type="image/png" href="../assets/qoda-logo.png">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, Arial, sans-serif;
            color: #0f172a;
            background: #020617 url("../assets/qoda-landing.png") center / cover no-repeat fixed;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, .58);
        }
        .page {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
        }
        .card {
            width: min(760px, 100%);
            border: 1px solid rgba(255,255,255,.5);
            border-radius: 24px;
            background: rgba(255,255,255,.92);
            box-shadow: 0 24px 70px rgba(0,0,0,.34);
            backdrop-filter: blur(16px);
            padding: 28px;
        }
        .top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
        }
        .logo {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            border-radius: 16px;
            background: rgba(15,23,42,.08);
            border: 1px solid rgba(15,23,42,.12);
            overflow: hidden;
        }
        .logo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        h1 { margin: 0; font-size: 28px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        label { display:block; margin-bottom: 7px; font-size: 13px; font-weight: 800; color: #334155; }
        input, select {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            padding: 13px 14px;
            font: inherit;
            color: #0f172a;
            background: #fff;
            outline: none;
        }
        input:focus, select:focus { border-color: #0ea5e9; box-shadow: 0 0 0 4px rgba(14,165,233,.14); }
        .full { grid-column: 1 / -1; }
        .submit {
            width: 100%;
            margin-top: 18px;
            border: 0;
            border-radius: 16px;
            padding: 14px;
            color: #fff;
            font-weight: 900;
            cursor: pointer;
            background: linear-gradient(135deg, #0ea5e9, #7c3aed);
            box-shadow: 0 16px 32px rgba(37,99,235,.28);
        }
        .error { margin-bottom: 14px; padding: 12px 14px; border-radius: 14px; background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .notice { margin-bottom: 14px; padding: 14px; border-radius: 16px; background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; line-height:1.45; }
        .notice strong { display:block; margin-bottom:4px; color:#1e3a8a; }
        .field-hint { display:block; margin-top:6px; color:#64748b; font-size:12px; line-height:1.35; }
        .back { display:block; margin-top: 14px; text-align:center; color:#2563eb; font-weight:800; text-decoration:none; }
        .modal {
            position: fixed;
            inset: 0;
            z-index: 5;
            display: none;
            place-items: center;
            background: rgba(2,6,23,.66);
            padding: 20px;
        }
        .modal.show { display: grid; }
        .modal-card {
            width: min(440px, 100%);
            border-radius: 22px;
            background: #fff;
            padding: 28px;
            box-shadow: 0 24px 60px rgba(0,0,0,.32);
        }
        .generated-id {
            margin: 16px 0;
            padding: 14px;
            border-radius: 14px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            font-size: 20px;
            font-weight: 900;
            text-align: center;
        }
        .modal-card a {
            display:block;
            text-align:center;
            text-decoration:none;
            color:#fff;
            background:#2563eb;
            border-radius:14px;
            padding:13px;
            font-weight:900;
        }
        @media (max-width: 680px) {
            .grid { grid-template-columns: 1fr; }
            .full { grid-column: auto; }
            .top { align-items:flex-start; }
        }
    </style>
</head>
<body>
    <main class="page">
        <section class="card">
            <div class="top">
                <div>
                    <h1>Lecturer Registration</h1>
                    <p style="margin:6px 0 0;color:#64748b;">Create a lecturer account for managing QODA exams.</p>
                </div>
                <div class="logo"><img src="../assets/qoda-logo.png" alt="QODA logo"></div>
            </div>

            <div class="notice">
                <strong>Lecturers only</strong>
                Students should not register here. Student IDs such as PUIT/, PUSE/, PUAS/, PU/, or PUC/ must be used on the login page with the password given by the lecturer.
            </div>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="grid">
                    <div>
                        <label for="title">Title</label>
                        <select id="title" name="title" required>
                            <?php foreach (['Mr.', 'Mrs.', 'Miss', 'Dr.', 'Prof.', 'Rev.', 'Ing.'] as $titleOption): ?>
                                <option value="<?= htmlspecialchars($titleOption) ?>" <?= ($_POST['title'] ?? 'Mr.') === $titleOption ? 'selected' : '' ?>><?= htmlspecialchars($titleOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="name">Full Name</label>
                        <input id="name" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label for="email">Email</label>
                        <input id="email" type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="full">
                        <label for="department">Department</label>
                        <input id="department" name="department" required
                            placeholder="e.g., Information Technology"
                            value="<?= htmlspecialchars($_POST['department'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <small class="field-hint">QODA will generate your lecturer ID from this department, for example PULC/IT/00001.</small>
                    </div>
                    <div class="full">
                        <label>Account Type</label>
                        <input value="Lecturer account only - student accounts are created by lecturers" readonly>
                        <small class="field-hint">Students should log in with their assigned student ID and password. They do not register here.</small>
                    </div>
                </div>
                <button class="submit" type="submit">Create Lecturer Account</button>
            </form>
            <a class="back" href="<?= htmlspecialchars(register_base_url('web-client/login.php'), ENT_QUOTES, 'UTF-8') ?>">Back to login</a>
        </section>
    </main>

    <div class="modal <?= $registeredStaffId ? 'show' : '' ?>" id="successModal">
        <div class="modal-card">
            <h2 style="margin:0 0 8px;">Registration Successful</h2>
            <p style="color:#475569;line-height:1.55;">Use this generated lecturer ID as your login ID and default password. Change it after first login.</p>
            <div class="generated-id"><?= htmlspecialchars($registeredStaffId, ENT_QUOTES, 'UTF-8') ?></div>
            <a href="<?= htmlspecialchars(register_base_url('web-client/login.php'), ENT_QUOTES, 'UTF-8') ?>">Go to Login</a>
        </div>
    </div>
</body>
</html>

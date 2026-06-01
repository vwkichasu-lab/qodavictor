<?php
require_once __DIR__ . '/../backend-php/config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';

function register_base_url(string $path = ''): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $pos = stripos($script, '/qoda/');
    $root = $pos !== false ? substr($script, 0, $pos + 6) : '/';
    return $root . ltrim($path, '/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staffId = trim($_POST['staff_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = strtoupper(trim($_POST['role'] ?? 'LECTURER'));

    if ($role !== 'LECTURER') {
        $error = 'Only lecturers can register. Students must log in with accounts created by a lecturer.';
    } elseif ($staffId === '' || $name === '' || $email === '' || $password === '') {
        $error = 'Please fill all required fields.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        try {
            $check = $pdo->prepare("
                SELECT id
                FROM users
                WHERE user_id = ? OR userId = ? OR email = ? OR staff_id = ?
                LIMIT 1
            ");
            $check->execute([$staffId, $staffId, $email, $staffId]);

            if ($check->fetch()) {
                $error = 'This lecturer account already exists.';
            } else {
                $pdo->beginTransaction();

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users
                        (user_id, userId, full_name, fullName, name, email, password,
                         role, status, staff_id, department, created_at, createdAt)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, 'LECTURER', 'Active', ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $staffId,
                    $staffId,
                    $name,
                    $name,
                    $name,
                    $email,
                    $hashedPassword,
                    $staffId,
                    $department
                ]);

                $userId = (int)$pdo->lastInsertId();

                $profile = $pdo->prepare("
                    INSERT INTO lecturers (userId, lecturerId, department, title, createdAt, updatedAt)
                    VALUES (?, ?, ?, 'Lecturer', NOW(), NOW())
                ");
                $profile->execute([$userId, $staffId, $department]);

                $pdo->commit();

                $_SESSION['user_id'] = $userId;
                $_SESSION['user_role'] = 'LECTURER';
                $_SESSION['user_id_value'] = $staffId;
                $_SESSION['fullName'] = $name;

                header('Location: ' . register_base_url('web-client/lecturer_dashboard.php'));
                exit;
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
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background: #f4f7fb; font-family: Arial, sans-serif; }
        .qoda-loader {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at 30% 35%, #2563eb, #07111f 70%);
            transition: opacity .35s ease, visibility .35s ease;
        }
        .qoda-loader.hide { opacity: 0; visibility: hidden; }
        .qoda-loader-content { text-align: center; color: #fff; padding: 32px; }
        .qoda-loader-logo {
            width: 86px;
            height: 86px;
            margin: 0 auto 22px;
            display: grid;
            place-items: center;
            border: 5px solid rgba(255, 255, 255, .95);
            border-radius: 50%;
            color: #fff;
            font-size: 48px;
            font-weight: 900;
            position: relative;
            background: linear-gradient(145deg, rgba(255,255,255,.22), rgba(37,99,235,.18) 48%, rgba(2,6,23,.18));
            box-shadow:
                inset 8px 8px 18px rgba(255,255,255,.2),
                inset -12px -14px 24px rgba(0,0,0,.28),
                0 20px 45px rgba(0, 0, 0, .36);
            transform-style: preserve-3d;
            animation: qLogoFloat 2.8s ease-in-out infinite;
            text-shadow:
                0 1px 0 #dbeafe,
                0 2px 0 #93c5fd,
                0 4px 0 #2563eb,
                0 12px 18px rgba(0,0,0,.45);
        }
        .qoda-loader-logo::after {
            content: "";
            position: absolute;
            width: 22px;
            height: 5px;
            right: 10px;
            bottom: 13px;
            background: #fff;
            border-radius: 999px;
            transform: rotate(45deg);
            box-shadow: 0 3px 0 #93c5fd, 0 10px 18px rgba(0,0,0,.35);
        }
        .qoda-loader-logo::before {
            content: "";
            position: absolute;
            inset: 8px;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 25%, rgba(255,255,255,.55), rgba(255,255,255,.08) 34%, transparent 55%);
            transform: translateZ(8px);
            pointer-events: none;
        }
        .qoda-loader-word {
            display: flex;
            justify-content: center;
            gap: 4px;
            color: #fff;
            font-size: 48px;
            font-weight: 900;
            text-shadow: 0 14px 36px rgba(0, 0, 0, .35);
        }
        .qoda-loader-word span {
            display: inline-block;
            opacity: 0;
            transform: translateY(28px) scale(.92);
            animation: qodaLetter 1.35s ease-in-out infinite;
            animation-delay: calc(var(--i) * .16s);
        }
        .qoda-loader-copy { margin-top: 18px; font-size: 15px; color: rgba(255, 255, 255, .76); }
        .qoda-loader-bar {
            width: min(280px, 70vw);
            height: 4px;
            margin: 26px auto 0;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(255, 255, 255, .2);
        }
        .qoda-loader-bar::before {
            content: "";
            display: block;
            width: 0%;
            height: 100%;
            background: #fff;
            animation: qodaProgress 10s linear forwards;
        }
        @keyframes qodaLetter {
            0%, 100% { opacity: .35; transform: translateY(10px) scale(.96); }
            35%, 65% { opacity: 1; transform: translateY(-12px) scale(1.04); }
        }
        @keyframes qodaProgress {
            to { width: 100%; }
        }
        @keyframes qLogoFloat {
            0%, 100% { transform: perspective(700px) rotateX(12deg) rotateY(-16deg) translateY(0); }
            50% { transform: perspective(700px) rotateX(18deg) rotateY(16deg) translateY(-8px); }
        }
        .register-card { max-width: 460px; margin: 48px auto; background: #fff; padding: 28px; border-radius: 8px; box-shadow: 0 12px 30px rgba(15, 23, 42, .12); }
        .register-card h1 { margin: 0 0 20px; font-size: 26px; color: #111827; }
        label { display: block; margin-top: 14px; font-weight: 700; color: #374151; }
        input { width: 100%; padding: 12px; margin-top: 6px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 15px; }
        button { width: 100%; margin-top: 22px; padding: 13px; border: 0; border-radius: 6px; background: #2563eb; color: #fff; font-weight: 700; cursor: pointer; }
        .error { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .muted { color: #64748b; font-size: 14px; margin-top: 14px; text-align: center; }
        .muted a { color: #2563eb; }
    </style>
</head>
<body>
    <div class="qoda-loader" id="qodaLoader" aria-hidden="true">
        <div class="qoda-loader-content">
            <div class="qoda-loader-logo">Q</div>
            <div class="qoda-loader-word" aria-label="Qoda PU">
                <span style="--i:0">Q</span><span style="--i:1">o</span><span style="--i:2">d</span><span style="--i:3">a</span>
                <span style="--i:4">&nbsp;</span><span style="--i:5">P</span><span style="--i:6">U</span>
            </div>
            <div class="qoda-loader-copy">Preparing your lecturer workspace</div>
            <div class="qoda-loader-bar"></div>
        </div>
    </div>

    <div class="register-card">
        <h1>Lecturer Registration</h1>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="role" value="LECTURER">

            <label for="staff_id">Lecturer ID</label>
            <input id="staff_id" name="staff_id" required value="<?= htmlspecialchars($_POST['staff_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <label for="name">Full Name</label>
            <input id="name" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <label for="email">Email</label>
            <input id="email" type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <label for="department">Department</label>
            <input id="department" name="department" value="<?= htmlspecialchars($_POST['department'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <label for="password">Password</label>
            <input id="password" type="password" name="password" required minlength="6">

            <button type="submit">Create Lecturer Account</button>
        </form>

        <p class="muted">Students cannot register. Their accounts must be created by a lecturer.</p>
        <p class="muted"><a href="<?= htmlspecialchars(register_base_url('web-client/login.php'), ENT_QUOTES, 'UTF-8') ?>">Back to login</a></p>
    </div>
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                var loader = document.getElementById('qodaLoader');
                if (loader) loader.classList.add('hide');
            }, 10000);
        });
    </script>
</body>
</html>

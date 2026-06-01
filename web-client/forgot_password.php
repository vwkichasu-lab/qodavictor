<?php
require_once __DIR__ . '/../backend-php/config/database.php';
require_once __DIR__ . '/config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = trim($_POST['userId'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $newPassword = $_POST['newPassword'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    if ($userId === '' || $email === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Please fill all fields.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        try {
            $db = getDB();
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmt = $db->prepare("
                UPDATE users
                SET password = ?, updated_at = NOW(), updatedAt = NOW()
                WHERE role = 'LECTURER'
                  AND (user_id = ? OR userId = ? OR staff_id = ?)
                  AND email = ?
                LIMIT 1
            ");
            $stmt->execute([$hash, $userId, $userId, $userId, $email]);

            if ($stmt->rowCount() > 0) {
                $success = 'Password updated. You can now log in.';
            } else {
                $error = 'No lecturer account matched those details. Students must contact their lecturer for password help.';
            }
        } catch (Exception $e) {
            $error = 'Password reset failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - QODA</title>
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            background: radial-gradient(circle at 20% 20%, #2563eb, #07111f 72%);
            font-family: Arial, sans-serif;
            padding: 20px;
        }
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
        .card {
            width: min(440px, 100%);
            background: rgba(255, 255, 255, .96);
            border-radius: 8px;
            padding: 28px;
            box-shadow: 0 24px 50px rgba(0, 0, 0, .28);
        }
        h1 { margin: 0 0 18px; color: #111827; font-size: 26px; }
        label { display: block; margin-top: 14px; font-weight: 700; color: #374151; }
        input {
            width: 100%;
            padding: 12px;
            margin-top: 6px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 15px;
        }
        button {
            width: 100%;
            margin-top: 22px;
            padding: 13px;
            border: 0;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }
        .message { padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .error { background: #fee2e2; color: #991b1b; }
        .success { background: #dcfce7; color: #166534; }
        .muted { color: #64748b; font-size: 14px; line-height: 1.5; }
        .link { display: block; margin-top: 14px; color: #2563eb; text-align: center; text-decoration: none; }
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
            <div class="qoda-loader-copy">Preparing account recovery</div>
            <div class="qoda-loader-bar"></div>
        </div>
    </div>

    <div class="card">
        <h1>Forgot Password</h1>
        <p class="muted">Lecturers can reset their password by confirming their lecturer ID and email.</p>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="userId">Lecturer ID</label>
            <input id="userId" name="userId" required value="<?= htmlspecialchars($_POST['userId'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <label for="email">Email</label>
            <input id="email" type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <label for="newPassword">New Password</label>
            <input id="newPassword" type="password" name="newPassword" required minlength="6">

            <label for="confirmPassword">Confirm Password</label>
            <input id="confirmPassword" type="password" name="confirmPassword" required minlength="6">

            <button type="submit">Reset Password</button>
        </form>

        <p class="muted">Students cannot reset passwords here. Their lecturer must reset student accounts from the dashboard.</p>
        <a class="link" href="login.php">Back to login</a>
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

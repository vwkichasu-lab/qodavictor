<?php
require_once __DIR__ . '/../backend-php/config/database.php';
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';
$step = $_SESSION['password_reset_pending'] ?? false ? 'verify' : 'request';

function qodaFindLecturerForReset(PDO $db, string $lecturerId, string $email): ?array
{
    $stmt = $db->prepare("
        SELECT id, email, full_name, name, user_id, userId, staff_id
        FROM users
        WHERE role = 'LECTURER'
          AND deleted_at IS NULL
          AND (user_id = ? OR userId = ? OR staff_id = ?)
          AND email = ?
        LIMIT 1
    ");
    $stmt->execute([$lecturerId, $lecturerId, $lecturerId, $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = getDB();
        $action = $_POST['action'] ?? 'request_code';

        if ($action === 'request_code') {
            $lecturerId = trim($_POST['userId'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if ($lecturerId === '' || $email === '') {
                $error = 'Please enter your lecturer ID and email.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                $user = qodaFindLecturerForReset($db, $lecturerId, $email);
                if (!$user) {
                    $error = 'No lecturer account matched those details.';
                } else {
                    $code = (string)random_int(100000, 999999);
                    $_SESSION['password_reset_pending'] = true;
                    $_SESSION['password_reset_user_id'] = (int)$user['id'];
                    $_SESSION['password_reset_code'] = password_hash($code, PASSWORD_DEFAULT);
                    $_SESSION['password_reset_expires'] = time() + 900;
                    $_SESSION['password_reset_email'] = $email;

                    $subject = 'QODA Password Reset Code';
                    $message = "Your QODA password reset code is {$code}. It expires in 15 minutes.";
                    @mail($email, $subject, $message);

                    $success = 'Reset code sent to your email. SMS delivery requires an SMS provider setup.';
                    if (in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true)) {
                        $success .= ' Local test code: ' . $code;
                    }
                    $step = 'verify';
                }
            }
        }

        if ($action === 'reset_password') {
            $code = trim($_POST['code'] ?? '');
            $newPassword = $_POST['newPassword'] ?? '';
            $confirmPassword = $_POST['confirmPassword'] ?? '';
            $storedHash = $_SESSION['password_reset_code'] ?? '';
            $expires = (int)($_SESSION['password_reset_expires'] ?? 0);
            $userId = (int)($_SESSION['password_reset_user_id'] ?? 0);

            if (!$userId || !$storedHash || time() > $expires) {
                $error = 'Reset code has expired. Please request a new one.';
                $step = 'request';
                unset($_SESSION['password_reset_pending'], $_SESSION['password_reset_code'], $_SESSION['password_reset_expires'], $_SESSION['password_reset_user_id']);
            } elseif ($code === '' || !password_verify($code, $storedHash)) {
                $error = 'Invalid reset code.';
                $step = 'verify';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Passwords do not match.';
                $step = 'verify';
            } elseif (strlen($newPassword) < 6) {
                $error = 'Password must be at least 6 characters.';
                $step = 'verify';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW(), updatedAt = NOW() WHERE id = ? AND role = 'LECTURER' LIMIT 1");
                $stmt->execute([$hash, $userId]);

                unset($_SESSION['password_reset_pending'], $_SESSION['password_reset_code'], $_SESSION['password_reset_expires'], $_SESSION['password_reset_user_id'], $_SESSION['password_reset_email']);
                $success = 'Password updated. You can now log in.';
                $step = 'request';
            }
        }
    } catch (Exception $e) {
        $error = 'Password reset failed: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - QODA</title>
    <link rel="icon" type="image/png" href="../assets/qoda-logo.png">
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            background: #020617 url("../assets/qoda-landing.png") center / cover no-repeat fixed;
            font-family: Inter, Arial, sans-serif;
            padding: 20px;
        }
        body::before { content:""; position:fixed; inset:0; background:rgba(2,6,23,.68); }
        .card {
            position: relative;
            width: min(460px, 100%);
            background: rgba(255,255,255,.96);
            border-radius: 22px;
            padding: 28px;
            box-shadow: 0 24px 60px rgba(0,0,0,.3);
        }
        h1 { margin: 0 0 10px; color: #0f172a; font-size: 28px; }
        p { color:#64748b; line-height:1.5; }
        label { display:block; margin-top: 14px; font-weight: 800; color:#334155; font-size: 13px; }
        input {
            width: 100%;
            padding: 13px;
            margin-top: 7px;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            font: inherit;
        }
        button {
            width: 100%;
            margin-top: 20px;
            padding: 14px;
            border: 0;
            border-radius: 14px;
            background: linear-gradient(135deg, #0ea5e9, #7c3aed);
            color: #fff;
            font-weight: 900;
            cursor: pointer;
        }
        .message { padding: 12px; border-radius: 14px; margin-bottom: 14px; }
        .error { background: #fee2e2; color: #991b1b; }
        .success { background: #dcfce7; color: #166534; }
        .link { display:block; margin-top: 14px; color:#2563eb; text-align:center; text-decoration:none; font-weight:800; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Forgot Password</h1>
        <p>Lecturers can reset their password with a secure verification code.</p>

        <?php if ($error): ?><div class="message error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($success): ?><div class="message success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <?php if ($step === 'verify'): ?>
            <form method="post">
                <input type="hidden" name="action" value="reset_password">
                <label for="code">Reset Code</label>
                <input id="code" name="code" inputmode="numeric" required>
                <label for="newPassword">New Password</label>
                <input id="newPassword" type="password" name="newPassword" required minlength="6">
                <label for="confirmPassword">Confirm Password</label>
                <input id="confirmPassword" type="password" name="confirmPassword" required minlength="6">
                <button type="submit">Change Password</button>
            </form>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="action" value="request_code">
                <label for="userId">Lecturer ID</label>
                <input id="userId" name="userId" required value="<?= htmlspecialchars($_POST['userId'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit">Send Reset Code</button>
            </form>
        <?php endif; ?>

        <a class="link" href="login.php">Back to login</a>
    </div>
</body>
</html>

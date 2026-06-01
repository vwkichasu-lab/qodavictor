<?php
// ========== login.php ==========
// Include database first (it has getDB function)
require_once __DIR__ . '/../backend-php/config/database.php';

// Then include config.php (which now checks if functions exist)
require_once __DIR__ . '/config.php';

// Then include other files
require_once __DIR__ . '/../backend-php/config/response.php';
require_once __DIR__ . '/../backend-php/config/auth.php';

$error = '';
$success = '';

// Check if user is already logged in via session
if (isLoggedIn()) {
    $role = $_SESSION['user_role'];
    if ($role === 'STUDENT') {
        header('Location: student_dashboard.php');
        exit;
    } elseif ($role === 'LECTURER') {
        header('Location: lecturer_dashboard.php');
        exit;
    } elseif ($role === 'ADMIN') {
        session_unset();
        session_destroy();
        $error = 'Admin login has been disabled. Please use a lecturer account.';
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = trim($_POST['userId'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($userId) || empty($password)) {
        $error = 'Please enter your User ID and Password';
    } else {
        try {
            $db = getDB();
            
            // First try users table (for lecturers and admins)
            $stmt = $db->prepare("
                SELECT *
                FROM users
                WHERE user_id = ? OR userId = ? OR email = ? OR staff_id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId, $userId, $userId, $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If not found in users, try students table
            if (!$user) {
                $stmt = $db->prepare("
                    SELECT *
                    FROM students
                    WHERE student_id = ? OR studentId = ? OR email = ?
                    LIMIT 1
                ");
                $stmt->execute([$userId, $userId, $userId]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student) {
                    // Convert student data to match user format
                    $user = [
                        'id' => $student['id'],
                        'user_id' => $student['student_id'],
                        'password' => $student['password'],
                        'email' => $student['email'],
                        'full_name' => $student['full_name'],
                        'role' => 'STUDENT'
                    ];
                }
            }

            if ($user && password_verify($password, $user['password'])) {
                if ($user['role'] === 'ADMIN') {
                    $error = 'Admin login has been disabled. Please use a lecturer account.';
                } else {
                // Set SESSION variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_id_value'] = $user['user_id'] ?? $userId;
                $_SESSION['fullName'] = $user['full_name'] ?? '';
                
                // Generate JWT token if JwtHandler exists
                if (class_exists('JwtHandler')) {
                    try {
                        $jwt = new JwtHandler();
                        $token = $jwt->encode([
                            'userId' => $user['user_id'] ?? $userId,
                            'id' => $user['id'],
                            'role' => $user['role']
                        ]);
                        
                        // Set cookies
                        setcookie('token', $token, time() + 86400, '/');
                        setcookie('role', $user['role'], time() + 86400, '/');
                        setcookie('userId', $user['user_id'] ?? $userId, time() + 86400, '/');
                    } catch (Exception $e) {
                        error_log("JWT Error: " . $e->getMessage());
                    }
                }
                
                // Redirect based on role
                if ($user['role'] === 'STUDENT') {
                    header('Location: student_dashboard.php');
                } elseif ($user['role'] === 'LECTURER') {
                    header('Location: lecturer_dashboard.php');
                } else {
                    $error = 'Unknown user role';
                }
                if (!$error) {
                    exit;
                }
                }
            } else {
                $error = 'Invalid User ID or Password';
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Qoda - Log In</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        height: 100vh;
        overflow: hidden;
        background: #e1dede;
    }

    .qoda-loader {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        background: radial-gradient(circle at 30% 35%, #2563eb, #07111f 70%);
        transition: opacity 0.35s ease, visibility 0.35s ease;
    }

    .qoda-loader.hide {
        opacity: 0;
        visibility: hidden;
    }

    .qoda-loader-content {
        text-align: center;
        color: #fff;
        padding: 32px;
    }

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
        letter-spacing: 0;
        text-shadow: 0 14px 36px rgba(0, 0, 0, 0.35);
    }

    .qoda-loader-word span {
        display: inline-block;
        opacity: 0;
        transform: translateY(28px) scale(.92);
        animation: qodaLetter 1.35s ease-in-out infinite;
        animation-delay: calc(var(--i) * .16s);
    }

    .qoda-loader-copy {
        margin-top: 18px;
        font-size: 15px;
        color: rgba(255, 255, 255, .76);
    }

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

    /* Animated gradient background similar to the image */
    .background {
        position: absolute;
        inset: 0;
        overflow: hidden;
        z-index: 1;
    }

    .gradient-bg {
        position: absolute;
        width: 100%;
        height: 100%;
        background: radial-gradient(circle at 20% 50%, rgba(11, 48, 231, 0.9), rgba(5, 10, 25, 0.95));
    }

    .glow-effect {
        position: absolute;
        width: 100%;
        height: 100%;
        background: radial-gradient(circle at 80% 30%, rgba(243, 246, 248, 0.78), transparent 60%);
    }

    /* Particles animation */
    .particles {
        position: absolute;
        width: 100%;
        height: 100%;
        overflow: hidden;
    }

    .particle {
        position: absolute;
        background: rgba(255, 255, 255, 0.92);
        border-radius: 50%;
        animation: floatParticle 15s infinite ease-in-out;
    }

    @keyframes floatParticle {

        0%,
        100% {
            transform: translateY(0) translateX(0);
            opacity: 0.3;
        }

        50% {
            transform: translateY(-30px) translateX(20px);
            opacity: 0.6;
        }
    }

    .overlay {
        position: absolute;
        inset: 0;
        background: rgba(193, 190, 190, 0.4);
        z-index: 2;
    }

    .container {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 10;
        width: 100%;
        max-width: 420px;
        padding: 0 20px;
    }

    .glass-card {
        background: rgba(20, 25, 45, 0.75);
        backdrop-filter: blur(16px);
        border-radius: 28px;
        padding: 48px 36px;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
        border: 1px solid rgba(66, 165, 245, 0.2);
    }

    .form-title {
        color: white;
        font-size: 34px;
        font-weight: 700;
        text-align: center;
        margin-bottom: 35px;
        letter-spacing: -0.5px;
        background: linear-gradient(135deg, #fff, #90caf9);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
    }

    .error-message {
        background: rgba(205, 12, 12, 0.63);
        border: 1px solid rgba(252, 6, 6, 0.99);
        color: #fcf3f3;
        padding: 12px;
        border-radius: 12px;
        margin-bottom: 20px;
        text-align: center;
        font-size: 14px;
    }

    .input-group {
        margin-bottom: 28px;
    }

    .input-label {
        display: block;
        color: rgba(255, 255, 255, 0.8);
        font-size: 13px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 10px;
    }

    .input-field {
        width: 100%;
        background: rgba(113, 113, 114, 0.71);
        border: 1.5px solid rgba(255, 255, 255, 0.87);
        border-radius: 16px;
        padding: 14px 18px;
        color: white;
        font-size: 16px;
        outline: none;
        transition: all 0.3s ease;
    }

    .input-field:focus {
        border-color: #42A5F5;
        background: rgba(255, 255, 255, 0.08);
        box-shadow: 0 0 12px rgba(66, 165, 245, 0.2);
    }

    .input-field::placeholder {
        color: rgba(171, 165, 165, 0.77);
        font-size: 14px;
    }

    .password-wrapper {
        position: relative;
    }

    .toggle-btn {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        cursor: pointer;
        color: rgba(255, 255, 255, 0.5);
        font-size: 18px;
        transition: color 0.2s;
    }

    .toggle-btn:hover {
        color: #42A5F5;
    }

    .login-button {
        width: 100%;
        background: linear-gradient(135deg, #42A5F5 0%, #1e88e5 100%);
        color: white;
        border: none;
        border-radius: 40px;
        padding: 16px;
        font-size: 18px;
        font-weight: 700;
        cursor: pointer;
        margin: 20px 0 20px;
        transition: all 0.3s ease;
        box-shadow: 0 5px 20px rgba(66, 165, 245, 0.3);
    }

    .login-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(66, 165, 245, 0.4);
    }

    .login-button:active {
        transform: translateY(0);
    }

    .login-button:disabled {
        opacity: 0.7;
        transform: none;
    }

    .forgot-link {
        display: block;
        text-align: center;
        color: rgba(255, 255, 255, 0.7);
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: color 0.2s;
        letter-spacing: 0.5px;
    }

    .forgot-link:hover {
        color: #42A5F5;
    }

    .login-links {
        display: grid;
        gap: 10px;
    }

    @media (max-width: 480px) {
        .glass-card {
            padding: 35px 24px;
        }

        .form-title {
            font-size: 28px;
            margin-bottom: 25px;
        }

        .input-field {
            padding: 12px 16px;
        }
    }
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
            <div class="qoda-loader-copy">Preparing your secure examination portal</div>
            <div class="qoda-loader-bar"></div>
        </div>
    </div>

    <div class="background">
        <div class="gradient-bg"></div>
        <div class="glow-effect"></div>
        <div class="particles">
            <?php for($i = 0; $i < 30; $i++): ?>
            <div class="particle" style="
                width: <?php echo rand(2, 6); ?>px;
                height: <?php echo rand(2, 6); ?>px;
                left: <?php echo rand(0, 100); ?>%;
                top: <?php echo rand(0, 100); ?>%;
                animation-delay: <?php echo rand(0, 20); ?>s;
                animation-duration: <?php echo rand(10, 25); ?>s; "></div>
            <?php endfor; ?>
        </div>
        <div class="overlay"></div>
    </div>

    <div class="container">
        <div class="glass-card">
            <h1 class="form-title">User Log in</h1>

            <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle" style="margin-right: 8px;"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <div class="input-group">
                    <label class="input-label">USER ID</label>
                    <input type="text" class="input-field" id="userId" name="userId" placeholder="User ID" required
                        value="<?php echo isset($_POST['userId']) ? htmlspecialchars($_POST['userId']) : ''; ?>" />
                </div>

                <div class="input-group">
                    <label class="input-label">PASSWORD</label>
                    <div class="password-wrapper">
                        <input type="password" class="input-field" id="password" name="password" placeholder="Password"
                            required />
                        <button type="button" class="toggle-btn" onclick="togglePassword()">
                            <i class="far fa-eye-slash" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="login-button" id="loginBtn">LOGIN</button>
            </form>

            <div class="login-links">
                <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
                <a href="../auth/register.php" class="forgot-link">Lecturer Registration</a>
            </div>
        </div>
    </div>

    <script>
    window.addEventListener('load', function() {
        setTimeout(function() {
            const loader = document.getElementById('qodaLoader');
            if (loader) loader.classList.add('hide');
        }, 10000);
    });

    function togglePassword() {
        const passwordField = document.getElementById('password');
        const icon = document.getElementById('toggleIcon');

        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        } else {
            passwordField.type = 'password';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        }
    }

    // When user types in User ID, same text appears in Password field
    const userIdInput = document.getElementById('userId');
    const passwordInput = document.getElementById('password');

    if (userIdInput && passwordInput) {
        userIdInput.addEventListener('input', function() {
            passwordInput.value = this.value;
        });
    }

    // Form submission handling
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', function() {
            const btn = document.getElementById('loginBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> LOGGING IN...';
            btn.disabled = true;
        });
    }
    </script>
</body>

</html>

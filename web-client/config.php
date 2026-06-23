<?php

// ========== web-client/config.php ==========
// Remove duplicate function declarations

// ========== DATABASE CONFIGURATION ==========
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'qoda_db';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'ANSI_QUOTES')");
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('Database connection is temporarily unavailable. Please try again later.');
}

// ========== SESSION START ==========
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ========== HELPER FUNCTIONS (check if they exist first) ==========
if (!function_exists('getDB')) {
    function getDB()
    {
        global $pdo;
        return $pdo;
    }
}

if (!function_exists('isLoggedIn')) {
    function isLoggedIn()
    {
        return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin()
    {
        if (!isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }
}

if (!function_exists('requireRole')) {
    function requireRole($role)
    {
        requireLogin();
        if ($_SESSION['user_role'] !== $role) {
            header('Location: login.php');
            exit;
        }
    }
}

if (!function_exists('escapeHTML')) {
    function escapeHTML($str)
    {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('showToast')) {
    function showToast($message, $type = 'success')
    {
        $_SESSION['toast'] = ['message' => $message, 'type' => $type];
    }
}

if (!function_exists('displayToast')) {
    function displayToast()
    {
        if (isset($_SESSION['toast'])) {
            $msg = $_SESSION['toast']['message'];
            $type = $_SESSION['toast']['type'];
            echo "<script>showToast('$msg', '$type');</script>";
            unset($_SESSION['toast']);
        }
    }
}

if (!function_exists('getCurrentStudent')) {
    function getCurrentStudent($pdo)
    {
        global $_SESSION;
        if (!isset($_SESSION['user_id_value'])) {
            return null;
        }
        $userId = $_SESSION['user_id_value'];
        $stmt = $pdo->prepare("SELECT * FROM students WHERE studentId = ? OR id = ? LIMIT 1");
        $stmt->execute([$userId, $_SESSION['user_id'] ?? 0]);
        return $stmt->fetch();
    }
}

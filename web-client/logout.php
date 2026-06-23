<?php
// logout.php
session_start();

$role = $_SESSION['user_role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$sessionId = session_id();

if ($role === 'STUDENT' && $userId > 0 && $sessionId !== '') {
    try {
        require_once __DIR__ . '/../backend-php/config/database.php';
        $db = getDB();
        $stmt = $db->prepare("
            UPDATE student_active_sessions
            SET active = 0, released_at = NOW(), release_reason = 'student_logout'
            WHERE student_id = ? AND session_id = ? AND active = 1
        ");
        $stmt->execute([$userId, $sessionId]);
    } catch (Throwable $error) {
        error_log('Student logout session release failed: ' . $error->getMessage());
    }
}

// Destroy all session variables
$_SESSION = array();

// Delete session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Clear all auth cookies
setcookie('token', '', time() - 3600, '/');
setcookie('role', '', time() - 3600, '/');
setcookie('userId', '', time() - 3600, '/');

// Destroy the session
session_destroy();

// Return to the public landing page after logout.
header('Location: ../index.php');
exit;
?>

<?php
// logout.php
session_start();

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

// Redirect to login page
header('Location: login.php');
exit;
?>
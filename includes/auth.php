<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function base_url($path = '') {
    $script = $_SERVER['SCRIPT_NAME'];
    $pos = stripos($script, '/qoda/');
    $root = $pos !== false ? substr($script, 0, $pos + 6) : '/';
    return $root . ltrim($path, '/');
}

function require_login($role) {
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== $role) {
        header('Location: ' . base_url('auth/login.php'));
        exit;
    }
}

function current_user() {
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id'    => $_SESSION['user_id'],
        'name'  => $_SESSION['user_name'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'role'  => $_SESSION['role'] ?? '',
    ];
}

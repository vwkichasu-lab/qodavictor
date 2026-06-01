<?php
require_once __DIR__ . '/config.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit;
}

$theme = $_POST['theme'] ?? 'dark';
if (!in_array($theme, ['dark', 'light'])) {
    $theme = 'dark';
}

$_SESSION['theme'] = $theme;
echo json_encode(['success' => true]);

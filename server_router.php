<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . ltrim($path, '/');

$blockedPatterns = [
    '#^/\\.git/#',
    '#^/runtime/#',
    '#^/uploads/#',
    '#^/tools/#',
    '#^/database/#',
    '#^/scripts/#',
    '#^/backend/#',
    '#^/desktop-client/#',
    '#^/hash\.php$#',
    '#^/web-client/fix_admin_password\.php$#',
    '#^/web-client/test_exams\.php$#',
    '#^/backend-php/test_login_direct\.php$#',
    '#^/backend-php/check\.php$#',
    '#^/backend-php/seed_demo\.php$#',
];

foreach ($blockedPatterns as $pattern) {
    if (preg_match($pattern, $path)) {
        http_response_code(404);
        echo 'Not found';
        return true;
    }
}

$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

return false;

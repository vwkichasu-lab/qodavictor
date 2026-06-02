<?php

if (!function_exists('qodaStartSecureSession')) {
    function qodaIsHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    function qodaStartSecureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => qodaIsHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $lastSeen = (int)($_SESSION['_last_seen'] ?? 0);
        $created = (int)($_SESSION['_created_at'] ?? $now);
        $idleLimit = (int)(getenv('QODA_SESSION_IDLE_SECONDS') ?: 7200);
        $absoluteLimit = (int)(getenv('QODA_SESSION_MAX_SECONDS') ?: 28800);

        if (($lastSeen && ($now - $lastSeen) > $idleLimit) || (($now - $created) > $absoluteLimit)) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            session_start();
        }

        $_SESSION['_last_seen'] = $now;
        $_SESSION['_created_at'] = $_SESSION['_created_at'] ?? $now;

        if (empty($_SESSION['_session_regenerated_at']) || ($now - (int)$_SESSION['_session_regenerated_at']) > 900) {
            session_regenerate_id(true);
            $_SESSION['_session_regenerated_at'] = $now;
        }
    }

    function qodaCsrfToken(): string
    {
        qodaStartSecureSession();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    function qodaCsrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(qodaCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    function qodaVerifyCsrf(?string $token): bool
    {
        qodaStartSecureSession();
        return is_string($token)
            && isset($_SESSION['_csrf_token'])
            && hash_equals($_SESSION['_csrf_token'], $token);
    }

    function qodaRequireCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!qodaVerifyCsrf($token)) {
            http_response_code(419);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Security token expired. Refresh the page and try again.']);
            exit;
        }
    }

    function qodaSafeRedirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }

    function qodaSecurityHeaders(): void
    {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if (qodaIsHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

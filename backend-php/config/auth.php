<?php

/**
 * Authentication Middleware
 */

function authenticate()
{
    $headers = getallheaders();
    $token = null;

    // Get token from Authorization header
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }

    // Get token from cookie (for web pages)
    if (!$token && isset($_COOKIE['token'])) {
        $token = $_COOKIE['token'];
    }

    if (!$token) {
        return null;
    }

    try {
        $jwt = new JwtHandler();
        $decoded = $jwt->decode($token);

        if (!$decoded) {
            errorResponse('Invalid token', 401);
        }

        // Store user info in global variable for use in controllers
        $GLOBALS['user'] = $decoded;
        return $decoded;
    } catch (Exception $e) {
        errorResponse('Invalid token', 401);
    }
}

function authorize($roles = [])
{
    $user = authenticate();

    if (!in_array($user->role, $roles)) {
        errorResponse('Unauthorized access', 403);
    }

    return $user;
}

function getCurrentUser()
{
    return isset($GLOBALS['user']) ? $GLOBALS['user'] : null;
}

/**
 * Simple JWT Handler
 */
class JwtHandler
{
    private $secret;
    private $algorithm = 'HS256';

    public function __construct()
    {
        $this->secret = getenv('JWT_SECRET') ?: getenv('QODA_APP_SECRET') ?: '';
        if ($this->secret === '') {
            if (getenv('RAILWAY_ENVIRONMENT') || getenv('RAILWAY_ENVIRONMENT_ID')) {
                throw new RuntimeException('JWT_SECRET is not configured.');
            }
            $this->secret = hash('sha256', __DIR__ . '|qoda-local-development-secret');
        }
    }

    public function encode($payload)
    {
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => $this->algorithm]));

        $payload['iat'] = time();
        $payload['exp'] = time() + 86400; // 24 hours

        $payloadEncoded = base64_encode(json_encode($payload));

        $signature = hash_hmac('sha256', "$header.$payloadEncoded", $this->secret, true);
        $signatureEncoded = base64_encode($signature);

        return "$header.$payloadEncoded.$signatureEncoded";
    }

    public function decode($token)
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return false;
        }

        $header = $parts[0];
        $payload = $parts[1];
        $signature = $parts[2];

        $expectedSignature = hash_hmac('sha256', "$header.$payload", $this->secret, true);
        $expectedSignatureEncoded = base64_encode($expectedSignature);

        if ($signature !== $expectedSignatureEncoded) {
            return false;
        }

        $payloadData = json_decode(base64_decode($payload));

        if ($payloadData->exp < time()) {
            return false;
        }

        return $payloadData;
    }

    public function sign($userId, $role)
    {
        return $this->encode([
            'userId' => $userId,
            'role' => $role
        ]);
    }
}

/**
 * Password Hashing
 */
function hashPassword($password)
{
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword($password, $hash)
{
    return password_verify($password, $hash);
}

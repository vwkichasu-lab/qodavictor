<?php
/**
 * Simple JWT Handler for authentication
 */
class JwtHandler
{
    private $secret;
    private $algorithm = 'HS256';

    public function __construct()
    {
        $this->secret = 'qoda-secret-key-change-in-production-2024';
    }

    public function encode($payload)
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => $this->algorithm]);
        $headerEncoded = $this->base64UrlEncode($header);

        $payload['iat'] = time();
        $payload['exp'] = time() + 86400; // 24 hours
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->secret, true);
        $signatureEncoded = $this->base64UrlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    public function decode($token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        $signature = $this->base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->secret, true);

        if (!hash_equals($signature, $expectedSignature)) {
            return false;
        }

        $payload = json_decode($this->base64UrlDecode($payloadEncoded));

        if ($payload->exp < time()) {
            return false;
        }

        return $payload;
    }

    public function sign($userId, $role, $id = null)
    {
        return $this->encode([
            'userId' => $userId,
            'role' => $role,
            'id' => $id
        ]);
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
?>
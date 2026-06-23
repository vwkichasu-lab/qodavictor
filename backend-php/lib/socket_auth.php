<?php

function qodaSocketSecret(): string
{
    $parts = [
        getenv('QODA_SOCKET_SECRET') ?: '',
        getenv('APP_KEY') ?: '',
        getenv('RAILWAY_ENVIRONMENT_ID') ?: '',
        getenv('DB_NAME') ?: '',
        getenv('DB_USER') ?: '',
        getenv('DB_PASS') ?: '',
        __DIR__,
    ];
    return implode('|', array_filter($parts, fn($part) => $part !== ''));
}

function qodaBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function qodaCreateSocketToken(array $claims): string
{
    $claims['iat'] = time();
    $claims['exp'] = time() + 7200;
    $payload = qodaBase64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signature = qodaBase64UrlEncode(hash_hmac('sha256', $payload, qodaSocketSecret(), true));
    return $payload . '.' . $signature;
}


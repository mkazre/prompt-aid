<?php

namespace App\Services\Video;

use RuntimeException;

/**
 * Minimal JWT signer/verifier for Jitsi tokens — RS256 (8x8 JaaS) and
 * HS256 (self-hosted prosody token auth). Implemented on openssl_sign /
 * hash_hmac so no extra composer package has to be deployed.
 */
class JitsiJwt
{
    public static function encode(array $header, array $payload, string $key): string
    {
        $alg = $header['alg'] ?? 'RS256';
        $segments = [
            self::b64(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
        $input = implode('.', $segments);

        $segments[] = self::b64(self::sign($input, $key, $alg));

        return implode('.', $segments);
    }

    /**
     * Verify a token's signature and time claims; returns the payload.
     * Used by "Test configuration" and the self-check, not on the hot path.
     */
    public static function decode(string $jwt, string $key, string $alg): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token.');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::unb64($h), true);
        $payload = json_decode(self::unb64($p), true);
        if (! is_array($header) || ! is_array($payload) || ($header['alg'] ?? null) !== $alg) {
            throw new RuntimeException('Unexpected token header.');
        }

        $sig = self::unb64($s);
        $input = $h.'.'.$p;
        if ($alg === 'HS256') {
            $ok = hash_equals(hash_hmac('sha256', $input, $key, true), $sig);
        } else {
            $pub = openssl_pkey_get_public($key);
            $ok = $pub !== false && openssl_verify($input, $sig, $pub, OPENSSL_ALGO_SHA256) === 1;
        }
        if (! $ok) {
            throw new RuntimeException('Signature verification failed.');
        }

        $now = time();
        if (isset($payload['nbf']) && $payload['nbf'] > $now + 60) {
            throw new RuntimeException('Token not yet valid.');
        }
        if (isset($payload['exp']) && $payload['exp'] < $now) {
            throw new RuntimeException('Token expired.');
        }

        return $payload;
    }

    /** Public key PEM derived from an RSA private key PEM (safe to display). */
    public static function publicKeyFromPrivate(string $privatePem): ?string
    {
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false) {
            return null;
        }
        $details = openssl_pkey_get_details($key);

        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA ? ($details['key'] ?? null) : null;
    }

    protected static function sign(string $input, string $key, string $alg): string
    {
        if ($alg === 'HS256') {
            return hash_hmac('sha256', $input, $key, true);
        }
        if ($alg !== 'RS256') {
            throw new RuntimeException('Unsupported JWT algorithm.');
        }

        $pkey = openssl_pkey_get_private($key);
        if ($pkey === false) {
            // Never include the key material in the message.
            throw new RuntimeException('The stored private key could not be read. Paste the full PEM including the BEGIN/END lines.');
        }
        $signature = '';
        if (! openssl_sign($input, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing the video token failed.');
        }

        return $signature;
    }

    protected static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    protected static function unb64(string $s): string
    {
        $pad = strlen($s) % 4;

        return (string) base64_decode(strtr($s, '-_', '+/').($pad ? str_repeat('=', 4 - $pad) : ''), true);
    }
}

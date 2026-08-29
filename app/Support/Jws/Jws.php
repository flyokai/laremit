<?php

declare(strict_types=1);

namespace App\Support\Jws;

use JsonException;

/**
 * JWS compact serialization (RFC 7515) restricted to ES256 — the one
 * algorithm App Store Server Notifications use. No `alg` negotiation: a
 * token claiming anything else is rejected before its signature is looked
 * at, which is the fix for the classic "alg: none" family of bugs.
 */
final class Jws
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $header, array $payload, string $privateKeyPem): string
    {
        $header['alg'] = 'ES256';

        $signingInput = self::base64UrlEncode(self::json($header)).'.'.self::base64UrlEncode(self::json($payload));

        return $signingInput.'.'.self::base64UrlEncode(Es256::sign($signingInput, $privateKeyPem));
    }

    /**
     * Verify and decode. Throws rather than returning null: an unverifiable
     * token is not "no data", it is an attack or a misconfiguration.
     *
     * @return array{header: array<string, mixed>, payload: array<string, mixed>}
     */
    public static function decode(string $token, string $publicKeyPem): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new JwsException('JWS is not three dot-separated segments.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = self::decodeSegment($encodedHeader);

        if (($header['alg'] ?? null) !== 'ES256') {
            throw new JwsException('JWS alg is not ES256.');
        }

        $signature = self::base64UrlDecode($encodedSignature);

        if (! Es256::verify($encodedHeader.'.'.$encodedPayload, $signature, $publicKeyPem)) {
            throw new JwsException('JWS signature does not verify.');
        }

        return ['header' => $header, 'payload' => self::decodeSegment($encodedPayload)];
    }

    /**
     * Read a segment WITHOUT verifying. Only for routing decisions the
     * verified payload is then re-read for (e.g. which key to verify with).
     *
     * @return array<string, mixed>
     */
    public static function peekPayload(string $token): array
    {
        $parts = explode('.', $token);

        return count($parts) === 3 ? self::decodeSegment($parts[1]) : [];
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($decoded === false) {
            throw new JwsException('JWS segment is not base64url.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeSegment(string $segment): array
    {
        try {
            $decoded = json_decode(self::base64UrlDecode($segment), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JwsException('JWS segment is not JSON.', previous: $e);
        }

        if (! is_array($decoded)) {
            throw new JwsException('JWS segment is not a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

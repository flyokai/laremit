<?php

declare(strict_types=1);

namespace App\Domain\Billing\Webhooks;

/**
 * The HMAC scheme the PSP signs webhooks with (Stripe-shaped):
 *
 *     X-Psp-Signature: t=<unix seconds>,v1=<hex hmac-sha256 of "<t>.<raw body>">
 *
 * Signing the timestamp INTO the MAC is what makes the tolerance window
 * mean anything: a captured delivery replayed a day later carries a
 * timestamp that is authentic and old, and an attacker who freshens the
 * timestamp breaks the signature. Checked in that order — signature first,
 * age second — so "stale" is a statement about a genuine message.
 */
final class WebhookSignature
{
    public const HEADER = 'X-Psp-Signature';

    public static function sign(string $body, string $secret, int $timestamp): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, self::mac($body, $secret, $timestamp));
    }

    public static function verify(
        string $body,
        ?string $header,
        string $secret,
        int $toleranceSeconds,
        ?int $now = null,
    ): SignatureVerdict {
        if ($header === null || $header === '') {
            return SignatureVerdict::Missing;
        }

        $parts = [];

        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);

            if (is_string($key) && is_string($value)) {
                $parts[trim($key)] = trim($value);
            }
        }

        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || ! is_string($signature) || $signature === '') {
            return SignatureVerdict::Malformed;
        }

        if (! hash_equals(self::mac($body, $secret, (int) $timestamp), $signature)) {
            return SignatureVerdict::Invalid;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $toleranceSeconds) {
            return SignatureVerdict::Stale;
        }

        return SignatureVerdict::Valid;
    }

    private static function mac(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }
}

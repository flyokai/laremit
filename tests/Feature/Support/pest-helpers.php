<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;

/**
 * Deliver a PSP webhook payload to the endpoint exactly as the mock PSP
 * would: raw JSON body, HMAC signature over those exact bytes. Shared by
 * the webhook and chaos suites. Lives under tests/Feature so it shares the
 * Pest-context PHPStan exclusion (tech-debt #1) with its call sites.
 *
 * @param  array<string, mixed>  $payload
 */
function deliverPspWebhook(array $payload): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return test()->call('POST', '/v1/psp/webhook', [], [], [], [
        'HTTP_X_PSP_SIGNATURE' => hash_hmac('sha256', $body, (string) config('billing.webhook_secret')),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

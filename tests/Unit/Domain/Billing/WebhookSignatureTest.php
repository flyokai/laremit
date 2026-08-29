<?php

declare(strict_types=1);

use App\Domain\Billing\Webhooks\SignatureVerdict;
use App\Domain\Billing\Webhooks\WebhookSignature;

it('signs and verifies over the timestamp and the exact bytes', function (): void {
    $header = WebhookSignature::sign('{"a":1}', 'secret', 1_000_000);

    expect($header)->toStartWith('t=1000000,v1=')
        ->and(WebhookSignature::verify('{"a":1}', $header, 'secret', 300, now: 1_000_100))->toBe(SignatureVerdict::Valid)
        ->and(WebhookSignature::verify('{"a":1} ', $header, 'secret', 300, now: 1_000_100))->toBe(SignatureVerdict::Invalid)
        ->and(WebhookSignature::verify('{"a":1}', $header, 'other', 300, now: 1_000_100))->toBe(SignatureVerdict::Invalid);
});

it('names what is wrong with a header', function (): void {
    expect(WebhookSignature::verify('x', null, 's', 300))->toBe(SignatureVerdict::Missing)
        ->and(WebhookSignature::verify('x', '', 's', 300))->toBe(SignatureVerdict::Missing)
        ->and(WebhookSignature::verify('x', 'deadbeef', 's', 300))->toBe(SignatureVerdict::Malformed)
        ->and(WebhookSignature::verify('x', 't=abc,v1=00', 's', 300))->toBe(SignatureVerdict::Malformed)
        ->and(WebhookSignature::verify('x', 't=1,v1=', 's', 300))->toBe(SignatureVerdict::Malformed);
});

it('calls a genuine but old signature stale, only after it verified', function (): void {
    $header = WebhookSignature::sign('body', 'secret', 1_000_000);

    expect(WebhookSignature::verify('body', $header, 'secret', 300, now: 1_000_301))->toBe(SignatureVerdict::Stale)
        ->and(WebhookSignature::verify('body', $header, 'secret', 300, now: 999_699))->toBe(SignatureVerdict::Stale)
        ->and(WebhookSignature::verify('body', $header, 'secret', 300, now: 1_000_300))->toBe(SignatureVerdict::Valid)
        // A freshened timestamp on a captured message breaks the MAC: invalid, not stale.
        ->and(WebhookSignature::verify('body', preg_replace('/^t=\d+/', 't=1000200', $header) ?? '', 'secret', 300, now: 1_000_200))->toBe(SignatureVerdict::Invalid);
});

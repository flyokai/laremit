<?php

declare(strict_types=1);

use App\Domain\Events\Contracts\EventBuffer;
use Illuminate\Support\Str;
use Tests\Support\FakeEventBuffer;

/** @return array<string, mixed> */
function ingestEvent(array $overrides = []): array
{
    return array_merge([
        'event_id' => (string) Str::uuid(),
        'type' => 'video.watched',
        'schema_version' => 2,
        'occurred_at' => now()->toISOString(),
        'user_id' => 42,
        'product' => 'edtech',
        'priority' => 'analytics',
        'payload' => ['position_ms' => 1000],
    ], $overrides);
}

/** @return array<string, string> */
function ingestHeaders(): array
{
    return ['Authorization' => 'Bearer test-token', 'Content-Type' => 'application/json'];
}

beforeEach(function (): void {
    $this->buffer = new FakeEventBuffer;
    $this->app->instance(EventBuffer::class, $this->buffer);
});

it('requires the bearer token', function (): void {
    $this->postJson('/v1/events', ['events' => [ingestEvent()]])
        ->assertStatus(401);

    $this->postJson('/v1/events', ['events' => [ingestEvent()]], ['Authorization' => 'Bearer wrong'])
        ->assertStatus(401);

    expect($this->buffer->appended)->toBeEmpty();
});

it('fails closed when no token is configured', function (): void {
    config()->set('events.ingest_token', null);

    $this->postJson('/v1/events', ['events' => [ingestEvent()]], ingestHeaders())
        ->assertStatus(401);
});

it('answers 202 with per-event status in submission order', function (): void {
    $duplicate = ingestEvent();
    $this->buffer->seen[strtolower($duplicate['event_id'])] = true;

    $response = $this->postJson('/v1/events', [
        'events' => [ingestEvent(), $duplicate, ingestEvent(['schema_version' => 9])],
    ], ingestHeaders());

    $response->assertStatus(202)
        ->assertJsonPath('accepted', 1)
        ->assertJsonPath('duplicates', 1)
        ->assertJsonPath('invalid', 1)
        ->assertJsonPath('shed', 0)
        ->assertJsonPath('results.0.status', 'accepted')
        ->assertJsonPath('results.1.status', 'duplicate')
        ->assertJsonPath('results.2.status', 'invalid')
        ->assertJsonPath('results.2.errors.schema_version', fn (mixed $value): bool => is_string($value));

    expect($this->buffer->appended)->toHaveCount(1);
});

it('accepts a gzip-compressed body', function (): void {
    $body = gzencode((string) json_encode(['events' => [ingestEvent(), ingestEvent()]]));

    $response = $this->call('POST', '/v1/events', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer test-token',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertStatus(202)->assertJsonPath('accepted', 2);
    expect($this->buffer->appended)->toHaveCount(2);
});

it('rejects a corrupt gzip body', function (): void {
    $this->call('POST', '/v1/events', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer test-token',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'CONTENT_TYPE' => 'application/json',
    ], 'definitely-not-gzip')->assertStatus(400)->assertJsonPath('error', 'malformed_gzip');
});

it('rejects unsupported content encodings', function (): void {
    $this->call('POST', '/v1/events', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer test-token',
        'HTTP_CONTENT_ENCODING' => 'br',
        'CONTENT_TYPE' => 'application/json',
    ], 'x')->assertStatus(415);
});

it('rejects malformed JSON', function (): void {
    $this->call('POST', '/v1/events', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer test-token',
        'CONTENT_TYPE' => 'application/json',
    ], '{"events": [')->assertStatus(400)->assertJsonPath('error', 'malformed_json');
});

it('rejects a body that is not an events list', function (mixed $body): void {
    $this->postJson('/v1/events', $body, ingestHeaders())->assertStatus(422);
})->with([
    'missing events key' => [['data' => []]],
    'events not a list' => [['events' => ['a' => 1]]],
    'empty batch' => [['events' => []]],
]);

it('rejects a batch over 500 events', function (): void {
    $events = array_map(fn (): array => ingestEvent(), range(1, 501));

    $this->postJson('/v1/events', ['events' => $events], ingestHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error', 'batch_too_large');

    expect($this->buffer->appended)->toBeEmpty();
});

it('accepts a full 500-event batch', function (): void {
    $events = array_map(fn (): array => ingestEvent(), range(1, 500));

    $this->postJson('/v1/events', ['events' => $events], ingestHeaders())
        ->assertStatus(202)
        ->assertJsonPath('accepted', 500);
});

it('answers 429 with Retry-After when the buffer is over the reject watermark', function (): void {
    config()->set('events.backpressure.shed_analytics_above', 10);
    config()->set('events.backpressure.reject_all_above', 20);
    $this->buffer->depth = 25;

    $this->postJson('/v1/events', ['events' => [ingestEvent()]], ingestHeaders())
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error', 'over_capacity');

    expect($this->buffer->appended)->toBeEmpty();
});

it('sheds analytics with a Retry-After header while accepting operational', function (): void {
    config()->set('events.backpressure.shed_analytics_above', 10);
    config()->set('events.backpressure.reject_all_above', 20);
    $this->buffer->depth = 15;

    $this->postJson('/v1/events', [
        'events' => [ingestEvent(['priority' => 'analytics']), ingestEvent(['priority' => 'operational'])],
    ], ingestHeaders())
        ->assertStatus(202)
        ->assertHeader('Retry-After')
        ->assertJsonPath('shed', 1)
        ->assertJsonPath('accepted', 1);
});

it('never starts a session or sets a cookie', function (): void {
    $response = $this->postJson('/v1/events', ['events' => [ingestEvent()]], ingestHeaders());

    expect($response->headers->getCookies())->toBeEmpty();
});

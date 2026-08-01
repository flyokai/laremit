<?php

declare(strict_types=1);

namespace App\Support\Health;

final readonly class CheckResult
{
    /**
     * @param  array<string, scalar>  $detail
     */
    private function __construct(
        public string $name,
        public bool $healthy,
        public float $durationMs,
        public array $detail = [],
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, scalar>  $detail
     */
    public static function healthy(string $name, float $durationMs, array $detail = []): self
    {
        return new self($name, true, $durationMs, $detail);
    }

    public static function failed(string $name, float $durationMs, string $error): self
    {
        return new self($name, false, $durationMs, [], $error);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->healthy ? 'ok' : 'failing',
            'duration_ms' => round($this->durationMs, 2),
            'detail' => $this->detail === [] ? null : $this->detail,
            'error' => $this->error,
        ], static fn (mixed $value): bool => $value !== null);
    }
}

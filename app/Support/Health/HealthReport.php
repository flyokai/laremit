<?php

declare(strict_types=1);

namespace App\Support\Health;

final readonly class HealthReport
{
    /**
     * @param  list<CheckResult>  $results
     */
    public function __construct(public array $results) {}

    public function healthy(): bool
    {
        foreach ($this->results as $result) {
            if (! $result->healthy) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $checks = [];

        foreach ($this->results as $result) {
            $checks[$result->name] = $result->toArray();
        }

        return [
            'status' => $this->healthy() ? 'ok' : 'failing',
            'checks' => $checks,
        ];
    }
}

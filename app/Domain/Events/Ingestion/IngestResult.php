<?php

declare(strict_types=1);

namespace App\Domain\Events\Ingestion;

use App\Domain\Events\Enums\EventStatus;

final readonly class IngestResult
{
    /**
     * @param  list<array{event_id: string|null, status: EventStatus, errors?: array<string, string>}>  $rows
     */
    private function __construct(
        public bool $rejected,
        public int $retryAfterSeconds,
        public array $rows,
        public int $accepted,
        public int $duplicates,
        public int $invalid,
        public int $shed,
    ) {}

    public static function rejected(int $retryAfterSeconds): self
    {
        return new self(true, $retryAfterSeconds, [], 0, 0, 0, 0);
    }

    /**
     * @param  list<array{event_id: string|null, status: EventStatus, errors?: array<string, string>}>  $rows
     */
    public static function of(array $rows, int $retryAfterSeconds): self
    {
        $counts = [
            EventStatus::Accepted->value => 0,
            EventStatus::Duplicate->value => 0,
            EventStatus::Invalid->value => 0,
            EventStatus::Shed->value => 0,
        ];

        foreach ($rows as $row) {
            $counts[$row['status']->value]++;
        }

        return new self(
            false,
            $retryAfterSeconds,
            $rows,
            $counts[EventStatus::Accepted->value],
            $counts[EventStatus::Duplicate->value],
            $counts[EventStatus::Invalid->value],
            $counts[EventStatus::Shed->value],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $results = [];

        foreach ($this->rows as $index => $row) {
            $entry = [
                'index' => $index,
                'event_id' => $row['event_id'],
                'status' => $row['status']->value,
            ];

            if (isset($row['errors'])) {
                $entry['errors'] = $row['errors'];
            }

            $results[] = $entry;
        }

        return [
            'accepted' => $this->accepted,
            'duplicates' => $this->duplicates,
            'invalid' => $this->invalid,
            'shed' => $this->shed,
            'results' => $results,
        ];
    }
}

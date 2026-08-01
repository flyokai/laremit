<?php

declare(strict_types=1);

namespace App\Support\Health\Checks;

use App\Support\Health\Check;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseCheck implements Check
{
    public function __construct(private ?string $connection = null) {}

    public function name(): string
    {
        return 'database';
    }

    /**
     * @return array<string, scalar>
     */
    public function probe(): array
    {
        $connection = DB::connection($this->connection);

        // Deliberately not a table read: readiness answers "can I reach the
        // database", not "is the schema migrated". Conflating the two makes a
        // mid-deploy migration look like an outage to the load balancer.
        $connection->select('select 1');

        return [
            'driver' => $connection->getDriverName(),
            'name' => $connection->getName() ?? 'default',
        ];
    }
}

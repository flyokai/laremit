<?php

declare(strict_types=1);

use App\Domain\Events\Support\ArchivePartitions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            // Test/portable shape: same columns and keys, no partitioning.
            // Anything that depends on partition behaviour must be exercised
            // against MySQL (tech-debt #2).
            Schema::create('events_archive', function (Blueprint $table): void {
                $table->id();
                $table->string('event_id', 36);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('product', 64);
                $table->string('type', 128);
                $table->unsignedSmallInteger('schema_version');
                $table->string('priority', 16);
                $table->json('payload');
                $table->dateTime('occurred_at', 3);
                $table->dateTime('received_at');
                $table->dateTime('created_at')->nullable();

                $table->unique(['event_id', 'received_at']);
                $table->index(['product', 'type', 'occurred_at']);
                $table->index(['user_id', 'occurred_at']);
            });

            return;
        }

        // Raw DDL: the schema builder cannot express partitioning.
        //
        // Partitioned by month of received_at — the server's clock, because
        // the producer's (occurred_at) is untrusted and a bogus 1970 value
        // must not create a partition-routing problem. MySQL requires the
        // partition column in every unique key, which is why uniqueness is
        // (event_id, received_at) rather than event_id alone; global
        // exactly-once rests on the Redis dedup window plus idempotent
        // consumers, and the honest residual is recorded as tech-debt #8.
        //
        // DATETIME rather than TIMESTAMP throughout: TIMESTAMP ends in 2038
        // and client-supplied occurred_at values must not be able to hit
        // column range errors inside a consumer.
        $partitions = ArchivePartitions::initialClauses(CarbonImmutable::now(), 1);

        DB::statement(<<<SQL
            CREATE TABLE events_archive (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id CHAR(36) NOT NULL,
                user_id BIGINT UNSIGNED NULL,
                product VARCHAR(64) NOT NULL,
                type VARCHAR(128) NOT NULL,
                schema_version SMALLINT UNSIGNED NOT NULL,
                priority VARCHAR(16) NOT NULL,
                payload JSON NOT NULL,
                occurred_at DATETIME(3) NOT NULL,
                received_at DATETIME NOT NULL,
                created_at DATETIME NULL,
                PRIMARY KEY (id, received_at),
                UNIQUE KEY events_archive_event_id_received_at_unique (event_id, received_at),
                KEY events_archive_product_type_occurred_at_index (product, type, occurred_at),
                KEY events_archive_user_id_occurred_at_index (user_id, occurred_at)
            )
            PARTITION BY RANGE (TO_DAYS(received_at)) (
                {$partitions}
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('events_archive');
    }
};

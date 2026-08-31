<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('aggregate_type', 32);
            $table->string('aggregate_id', 64);
            $table->string('type', 128);
            $table->unsignedSmallInteger('schema_version');
            $table->json('payload');
            // The fact's identity. One fact, one row, however many times the
            // publisher's job is redelivered.
            $table->string('idempotency_key', 160)->unique();
            // Millisecond precision: subscription events are keyed to the
            // state machine's millisecond watermark.
            $table->dateTime('occurred_at', 3);
            $table->dateTime('available_at');
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('dead_lettered_at')->nullable();
            $table->string('last_error')->nullable();
            $table->dateTime('created_at');

            // The relay's claim is `dispatched_at IS NULL ... ORDER BY id`:
            // the IS NULL equality walks this index straight into id order,
            // so claiming is a range read, not a sort. dead_lettered_at and
            // available_at are filtered on the fly — both are rare misses.
            // Pruning ranges on dispatched_at and uses the same index.
            $table->index(['dispatched_at', 'id']);
            // Forensics: "what did this aggregate emit, in order".
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};

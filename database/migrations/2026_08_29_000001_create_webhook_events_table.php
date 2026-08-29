<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();

            // Which store spoke (psp | apple | google) and the id IT assigned
            // to the delivery: event_id, notificationUUID, Pub/Sub messageId.
            $table->string('provider', 8);
            $table->string('provider_event_id', 128);
            $table->string('type', 64);

            // The exact bytes that were signature-verified, untouched. Not a
            // JSON column on purpose: MySQL normalises JSON on write, and a
            // payload we might need to re-verify or reprocess must be the
            // bytes the provider sent, not our idea of them.
            $table->mediumText('payload');

            $table->dateTime('provider_created_at')->nullable();
            $table->dateTime('received_at');

            // pending -> processed | discarded | failed. The edge dispatches
            // whenever it finds `pending` — "have I handled this?", not
            // "have I seen this?" — which is what closes the crash-between-
            // insert-and-dispatch gap a wasRecentlyCreated check leaves open.
            $table->string('status', 16);
            $table->string('outcome', 32)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->dateTime('processed_at')->nullable();

            // The edge's dedup floor: a redelivery is an INSERT IGNORE no-op
            // decided by the database, never by a read-then-write.
            $table->unique(['provider', 'provider_event_id']);

            // Reaper ("pending older than N minutes") and pruning.
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};

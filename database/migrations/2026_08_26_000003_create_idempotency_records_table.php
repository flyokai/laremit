<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->id();

            // ADR-004 layer 1: inbound HTTP idempotency. The INSERT into
            // this unique key is the atomic claim — two concurrent requests
            // with one key race on the constraint, and exactly one proceeds.
            //
            // The roadmap sketch scopes uniqueness per (key, user_id); until
            // real per-actor auth exists (Module 8) the key alone is unique
            // and user_id is retained for that future scoping (ADR-004).
            $table->string('key', 128)->unique();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('request_hash', 64);
            $table->string('status', 16); // running | completed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->mediumText('response_body')->nullable();

            // When the running claim was taken; a crashed holder's claim is
            // taken over once this is older than the configured lock window.
            $table->dateTime('locked_at');
            $table->timestamps();

            $table->index('created_at'); // pruning
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};

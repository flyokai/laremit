<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->id();
            // Billing rows outlive convenience: nothing here cascades away.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 32)->default('initial');

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);

            $table->string('status', 16);

            // ADR-004 layer 2: OUR key sent to the PSP, one per intent for
            // its whole life. Every ChargeJob retry reuses it, which is what
            // turns an ambiguous timeout into a safe retry instead of a
            // second charge. (The roadmap sketch calls this idempotency_key;
            // qualified here to distinguish it from the inbound layer-1 keys
            // in idempotency_records.)
            $table->string('psp_idempotency_key', 64)->unique();

            // The PSP's charge id, once known — from the sync response or
            // whichever webhook lands first. Unique: one charge, one intent.
            $table->string('psp_reference', 64)->nullable()->unique();

            $table->string('last_error', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            // Phase 4 reconciliation sweep: stale non-terminal intents.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};

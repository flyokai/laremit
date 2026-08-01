<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            $table->string('status', 16);

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('canceled_at')->nullable();

            $table->string('store', 8)->default('psp');
            $table->string('store_original_transaction_id')->nullable();

            // Provider timestamp of the most recent event applied. Phase 4 uses
            // it to reject stale transitions when webhooks arrive out of order.
            $table->timestamp('last_event_at')->nullable();

            $table->timestamps();

            // One row per store subscription. NULLs repeat freely in a MySQL
            // unique index, so PSP subscriptions (no store transaction id) are
            // unconstrained while Apple/Google replays collide and are rejected
            // by the database rather than by application logic.
            $table->unique(
                ['store', 'store_original_transaction_id'],
                'subscriptions_store_txn_unique'
            );

            $table->index(['user_id', 'product_id', 'status']);

            // Renewal sweep: "which subscriptions expire in the next hour".
            $table->index(['status', 'current_period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

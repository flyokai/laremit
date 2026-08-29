<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The pretend App Store / Play Store's own record of a subscription
        // — the source of truth ADR-005 defers to. Laremit's domain code
        // never reads this table; it asks the store's (mock) API.
        Schema::create('mock_store_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('store', 8); // apple | google

            // Apple originalTransactionId (stable for life) or Google
            // purchaseToken (changes on resubscribe; see linked_identifier).
            $table->string('identifier', 128);
            $table->string('linked_identifier', 128)->nullable();

            $table->string('product_id', 64); // com.laremit.edtech.monthly
            $table->uuid('app_account_token')->nullable();

            // Store-native status vocabulary, deliberately not ours.
            $table->string('status', 32);
            $table->boolean('auto_renew')->default(true);
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->unsignedSmallInteger('period_days')->default(30);
            $table->string('environment', 16);
            $table->boolean('acknowledged')->default(false);

            // The store's version clock: bumped on every change, stamped on
            // every notification. Ordering is decided against this, never
            // against our received_at.
            $table->dateTime('event_at', 3);
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['store', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_store_subscriptions');
    }
};

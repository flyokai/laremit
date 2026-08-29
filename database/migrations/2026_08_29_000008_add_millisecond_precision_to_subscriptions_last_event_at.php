<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // The stores' clocks are milliseconds (Apple signedDate, Google
            // eventTimeMillis). A watermark that truncates to seconds would
            // call the second of two same-second events stale — exactly
            // the Stripe-second-precision trap — so the column keeps what
            // the store said.
            $table->dateTime('last_event_at', 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('last_event_at')->nullable()->change();
        });
    }
};

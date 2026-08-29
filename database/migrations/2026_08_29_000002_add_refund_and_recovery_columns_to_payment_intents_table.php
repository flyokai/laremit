<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            // Running total of refunds booked against this charge. The
            // ledger is the truth; this is the projection the revocation
            // rule reads under the intent's row lock (full refund = revoke).
            $table->unsignedBigInteger('refunded_minor')->default(0)->after('last_error');

            // How many times reconciliation re-dispatched a stuck charge. A
            // permanently broken intent must alert, not be re-queued every
            // hour forever.
            $table->unsignedTinyInteger('recovery_attempts')->default(0)->after('refunded_minor');
            $table->dateTime('last_recovered_at')->nullable()->after('recovery_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->dropColumn(['refunded_minor', 'recovery_attempts', 'last_recovered_at']);
        });
    }
};

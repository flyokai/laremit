<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two mock-side column bugs the sqlite suite could never see, caught the
 * first time the whole suite ran against MySQL (Phase 7 nightly job):
 *
 * - mock_store_subscriptions.status was VARCHAR(32); sqlite ignores the
 *   length, MySQL strict mode rejects Google's own
 *   SUBSCRIPTION_STATE_IN_GRACE_PERIOD (34 chars) — the store-native
 *   vocabulary this column exists to hold.
 *
 * - psp_charges.response_body was a JSON column, but MySQL's JSON type
 *   normalizes object key order, and this column's whole contract is
 *   byte-for-byte replay of the original response for a repeated
 *   idempotency key. TEXT stores what was said, exactly.
 *
 * - mock_store_subscriptions period/revocation columns were whole-second
 *   datetimes beside a millisecond event_at, and the store signs its
 *   notifications from a refresh()ed row. MySQL ROUNDS a .500+ fraction up
 *   to the next second, so about half the time the signed expiresDate
 *   landed AFTER the signedDate of the very event that set it, and a
 *   grace/expiry notification truthfully described a period that had not
 *   ended yet — mapped to Active. Flaky on MySQL, invisible on sqlite,
 *   which stores the full string either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mock_store_subscriptions', function (Blueprint $table): void {
            $table->string('status', 64)->change();
            $table->dateTime('period_start', 3)->change();
            $table->dateTime('period_end', 3)->change();
            $table->dateTime('revoked_at', 3)->nullable()->change();
        });

        Schema::table('psp_charges', function (Blueprint $table): void {
            $table->text('response_body')->change();
        });
    }

    public function down(): void
    {
        Schema::table('mock_store_subscriptions', function (Blueprint $table): void {
            $table->string('status', 32)->change();
            $table->dateTime('period_start')->change();
            $table->dateTime('period_end')->change();
            $table->dateTime('revoked_at')->nullable()->change();
        });

        Schema::table('psp_charges', function (Blueprint $table): void {
            $table->json('response_body')->change();
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();

            // Lines of one balanced transaction share a transaction_id and
            // sum to zero per currency. That invariant plus append-only is
            // the entire correctness story of this table.
            $table->string('transaction_id', 32);
            $table->string('account', 32);
            $table->string('entry_type', 16);

            // Signed: credit-normal lines are negative. BIGINT because money
            // in minor units at scale outgrows INT faster than intuition says.
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            $table->string('reference_type', 32);
            $table->string('reference_id', 64);

            // ADR-004 layer 3: the database-enforced floor under every
            // application-level idempotency check. A duplicate application
            // that slips every guard still cannot write the same line twice.
            $table->string('idempotency_key', 96)->unique();

            $table->dateTime('occurred_at');
            $table->dateTime('created_at');

            $table->index('transaction_id');
            $table->index(['account', 'currency']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};

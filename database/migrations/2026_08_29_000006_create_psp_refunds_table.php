<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mock PSP storage (provider side of the wire), like psp_charges.
        // Refunds are facts, not state: two partial refunds of one charge
        // are two rows, each with its own id — the ledger needs both.
        Schema::create('psp_refunds', function (Blueprint $table): void {
            $table->id();
            $table->string('refund_id', 40)->unique();
            $table->string('charge_id', 40)->index();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reason', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psp_refunds');
    }
};

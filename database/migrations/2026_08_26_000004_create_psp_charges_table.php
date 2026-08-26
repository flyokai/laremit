<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The mock PSP's own storage — this table plays the role of a real
        // provider's backend and is not part of Laremit's domain schema.
        // Idempotent replay is byte-exact: the stored response_status/body
        // are returned verbatim for a repeated key, like real PSPs do.
        Schema::create('psp_charges', function (Blueprint $table): void {
            $table->id();
            $table->string('charge_id', 40)->unique();
            $table->string('idempotency_key', 128)->unique();
            $table->string('request_hash', 64);

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 16); // succeeded | failed
            $table->string('decline_code', 32)->nullable();
            $table->json('metadata');

            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psp_charges');
    }
};

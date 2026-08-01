<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');

            // Integer minor units + ISO-4217 code. No floats touch money here or
            // anywhere downstream; the ledger depends on exact arithmetic.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);

            $table->string('interval', 8);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};

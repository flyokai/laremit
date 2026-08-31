<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('metric_date');
            $table->string('metric', 48);
            $table->unsignedBigInteger('value')->default(0);

            // The upsert target: one counter per (day, metric), incremented
            // atomically by the database so concurrent consumers never lose
            // an update.
            $table->unique(['metric_date', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_metrics');
    }
};

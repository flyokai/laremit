<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per reconciliation run: what was scanned, what disagreed,
        // what was fixed and what could not be. The mismatch metric the
        // roadmap asks for lives here as durable data — Phase 9's exporter
        // reads it; nothing has to be re-derived from logs.
        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at');
            $table->dateTime('window_start');
            $table->json('scanned');
            $table->json('findings');
            $table->unsignedInteger('fixed');
            $table->unsignedInteger('unresolved');
            $table->unsignedInteger('duration_ms');

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};

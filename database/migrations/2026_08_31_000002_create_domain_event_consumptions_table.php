<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_event_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->string('consumer', 48);
            $table->uuid('event_id');
            $table->dateTime('processed_at');

            // The exactly-once-effect constraint: ConsumeOnce's INSERT IGNORE
            // races resolve here, in the database, not in application code.
            $table->unique(['consumer', 'event_id']);
            $table->index('processed_at'); // pruning
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_event_consumptions');
    }
};

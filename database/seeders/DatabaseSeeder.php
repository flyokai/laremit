<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(CatalogSeeder::class);

        // One demo identity for local payment flows; idempotent like the
        // catalog seeder — this runs on every container start.
        if (! User::query()->where('email', 'demo@laremit.test')->exists()) {
            User::factory()->create([
                'name' => 'Demo User',
                'email' => 'demo@laremit.test',
            ]);
        }
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // The cross-platform account link. The mobile app attaches this
            // UUID to every store purchase (Apple appAccountToken, Google
            // obfuscatedExternalAccountId), so a store notification can find
            // OUR user without trusting anything the device claims.
            $table->uuid('app_account_token')->nullable()->unique()->after('email');
        });

        // Users that predate the column get one now; new users get theirs
        // on creation (User::booted). Nobody should be un-linkable.
        DB::table('users')->whereNull('app_account_token')->orderBy('id')
            ->each(static fn (object $user): int => DB::table('users')
                ->where('id', $user->id)
                ->update(['app_account_token' => (string) Str::uuid()]));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('app_account_token');
        });
    }
};

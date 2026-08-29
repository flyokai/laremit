<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Billing\Models\Subscription;
use Database\Factories\Identity\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * One identity across every product. Products do not own users; billing and
 * entitlements hang off this record, which is why Identity is its own module.
 *
 * app_account_token is the identity the mobile apps hand to the stores at
 * purchase time (Apple appAccountToken, Google obfuscatedExternalAccountId).
 * It is how a store notification finds this user, and it is the only
 * identity link the app ever needs to trust — the store echoes it back
 * signed, the device never asserts it.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $app_account_token
 * @property-read Collection<int, Subscription> $subscriptions
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        self::creating(static function (User $user): void {
            $user->app_account_token ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

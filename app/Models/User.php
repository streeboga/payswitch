<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTwoFactorAuthentication;
use App\Enums\UserRole as UserRoleEnum;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, UserRole> $roles
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTwoFactorAuthentication, Notifiable;

    /**
     * Get all user role assignments.
     *
     * @return HasMany<UserRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * Get the user's role for a specific organization.
     */
    public function roleForOrganization(int|string $organizationId): ?UserRoleEnum
    {
        $role = $this->roles()
            ->where('organization_id', $organizationId)
            ->first();

        return $role?->role;
    }

    /**
     * Get the user's role for the organization that owns the given merchant.
     */
    public function roleForMerchant(int|string $merchantId): ?UserRoleEnum
    {
        $role = $this->roles()
            ->whereHas('organization', function (Builder $q) use ($merchantId) {
                $q->whereHas('merchantAccounts', function (Builder $q2) use ($merchantId) {
                    $q2->where('id', $merchantId);
                });
            })
            ->first();

        return $role?->role;
    }

    /**
     * Check if user has access to the given merchant (any role).
     */
    public function hasAccessToMerchant(int|string $merchantId): bool
    {
        return $this->roleForMerchant($merchantId) !== null;
    }

    /**
     * Check if user has at least the given role level for a merchant.
     * Admin > Operator > Viewer
     */
    public function hasRoleForMerchant(int|string $merchantId, UserRoleEnum $minimumRole): bool
    {
        $role = $this->roleForMerchant($merchantId);

        if ($role === null) {
            return false;
        }

        return $role->getWeight() >= $minimumRole->getWeight();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}

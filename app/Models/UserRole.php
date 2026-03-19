<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole as UserRoleEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Streeboga\PaymentData\Models\Organization;

/**
 * @property int $id
 * @property int $user_id
 * @property int $organization_id
 * @property UserRoleEnum $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Organization $organization
 */
class UserRole extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'role' => UserRoleEnum::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}

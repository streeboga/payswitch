<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole as UserRoleEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Streeboga\PaymentData\Models\Organization;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}

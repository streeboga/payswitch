<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use App\Enums\ApiKeyType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    protected $fillable = [
        'merchant_account_id',
        'key_hash',
        'key_prefix',
        'name',
        'type',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ApiKeyType::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }
}

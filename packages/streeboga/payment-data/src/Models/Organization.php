<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Streeboga\PaymentData\Support\IdGenerator;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read int|null $merchant_accounts_count
 */
class Organization extends Model
{
    protected $fillable = [
        'name',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $model) {
            $model->key ??= IdGenerator::orgId();
        });
    }

    /** @return HasMany<MerchantAccount, $this> */
    public function merchantAccounts(): HasMany
    {
        return $this->hasMany(MerchantAccount::class, 'org_id');
    }
}

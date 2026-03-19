<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Streeboga\PaymentData\Support\IdGenerator;

/**
 * @property int $id
 * @property string $key
 * @property int $org_id
 * @property string $name
 * @property string $publishable_key
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read int|null $business_profiles_count
 * @property-read int|null $connector_accounts_count
 * @property-read Organization $organization
 */
class MerchantAccount extends Model
{
    protected $fillable = [
        'org_id',
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
        static::creating(function (MerchantAccount $model) {
            $model->key ??= IdGenerator::merchantId();
            $model->publishable_key ??= IdGenerator::publishableKey(
                config('payswitch.environment', 'sandbox'),
            );
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function businessProfiles(): HasMany
    {
        return $this->hasMany(BusinessProfile::class);
    }

    public function connectorAccounts(): HasMany
    {
        return $this->hasMany(MerchantConnectorAccount::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }
}

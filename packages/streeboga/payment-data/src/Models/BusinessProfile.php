<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Streeboga\PaymentData\Support\IdGenerator;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property int $merchant_account_id
 * @property string|null $webhook_url
 * @property string|null $payment_response_hash_key
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read int|null $connector_accounts_count
 * @property-read int|null $routing_rules_count
 * @property-read MerchantAccount $merchantAccount
 */
class BusinessProfile extends Model
{
    protected $fillable = [
        'merchant_account_id',
        'name',
        'webhook_url',
        'payment_response_hash_key',
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
        static::creating(function (BusinessProfile $model) {
            $model->key ??= IdGenerator::profileId();
            $model->payment_response_hash_key ??= Str::random(64);
        });
    }

    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class);
    }

    public function connectorAccounts(): HasMany
    {
        return $this->hasMany(MerchantConnectorAccount::class);
    }

    public function routingRules(): HasMany
    {
        return $this->hasMany(RoutingRule::class);
    }
}

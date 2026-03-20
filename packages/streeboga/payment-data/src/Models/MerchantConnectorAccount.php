<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Streeboga\PaymentData\Support\IdGenerator;

/**
 * @property int $id
 * @property string $key
 * @property int $merchant_account_id
 * @property int $business_profile_id
 * @property string $connector_name
 * @property string $connector_type
 * @property array<string, mixed> $connector_account_details
 * @property array<string, mixed>|null $payment_methods_enabled
 * @property array<string, mixed>|null $display_config
 * @property bool $test_mode
 * @property bool $disabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MerchantConnectorAccount extends Model
{
    protected $fillable = [
        'merchant_account_id',
        'business_profile_id',
        'connector_name',
        'connector_type',
        'connector_account_details',
        'payment_methods_enabled',
        'display_config',
        'test_mode',
        'disabled',
    ];

    protected function casts(): array
    {
        return [
            'connector_account_details' => 'encrypted:array',
            'payment_methods_enabled' => 'array',
            'display_config' => 'array',
            'test_mode' => 'boolean',
            'disabled' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    protected static function booted(): void
    {
        static::creating(function (MerchantConnectorAccount $model) {
            $model->key ??= IdGenerator::mcaId();
        });
    }

    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class);
    }

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class);
    }
}

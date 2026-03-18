<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Streeboga\PaymentData\Support\IdGenerator;

class MerchantConnectorAccount extends Model
{
    protected $fillable = [
        'merchant_account_id',
        'business_profile_id',
        'connector_name',
        'connector_type',
        'connector_account_details',
        'payment_methods_enabled',
        'test_mode',
        'disabled',
    ];

    protected function casts(): array
    {
        return [
            'payment_methods_enabled' => 'array',
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

    public function connectorAccountDetails(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => Crypt::decryptString($value),
            set: fn (string $value) => Crypt::encryptString($value),
        );
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

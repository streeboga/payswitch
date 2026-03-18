<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Streeboga\PaymentData\Support\IdGenerator;

class PaymentMethod extends Model
{
    protected $fillable = [
        'key',
        'customer_id',
        'merchant_account_id',
        'type',
        'card_last4',
        'card_brand',
        'card_exp_month',
        'card_exp_year',
        'card_holder_name',
        'connector_name',
        'connector_token',
        'is_default',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentMethod $model) {
            $model->key ??= IdGenerator::paymentMethodId();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class);
    }

    public function maskCardNumber(): string
    {
        return '**** **** **** '.$this->card_last4;
    }
}

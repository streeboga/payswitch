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
 * @property int $customer_id
 * @property int $merchant_account_id
 * @property string $type
 * @property string|null $card_last4
 * @property string|null $card_brand
 * @property string|null $card_exp_month
 * @property string|null $card_exp_year
 * @property string|null $card_holder_name
 * @property string $connector_name
 * @property string|null $connector_token
 * @property bool $is_default
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
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

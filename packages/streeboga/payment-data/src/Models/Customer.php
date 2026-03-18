<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Streeboga\PaymentData\Support\IdGenerator;

class Customer extends Model
{
    protected $fillable = [
        'key',
        'merchant_account_id',
        'name',
        'email',
        'phone',
        'phone_country_code',
        'description',
        'metadata',
        'default_payment_method_id',
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
        static::creating(function (Customer $model) {
            $model->key ??= IdGenerator::customerId();
        });
    }

    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentIntent::class, 'customer_id', 'key');
    }
}

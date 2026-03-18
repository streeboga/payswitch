<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Streeboga\PaymentData\Support\IdGenerator;

class BusinessProfile extends Model
{
    protected $fillable = [
        'merchant_account_id',
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
}

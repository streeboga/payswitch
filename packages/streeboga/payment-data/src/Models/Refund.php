<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Support\IdGenerator;

class Refund extends Model
{
    protected $fillable = [
        'payment_intent_id',
        'merchant_account_id',
        'business_profile_id',
        'amount',
        'currency',
        'status',
        'reason',
        'connector',
        'connector_refund_id',
        'error_code',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    protected static function booted(): void
    {
        static::creating(function (Refund $model) {
            $model->key ??= IdGenerator::refundId();
        });
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class);
    }
}

<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Streeboga\PaymentData\Enums\RefundStatus;
use Streeboga\PaymentData\Support\IdGenerator;

/**
 * @property int $id
 * @property string $key
 * @property int $payment_intent_id
 * @property int $merchant_account_id
 * @property int|null $business_profile_id
 * @property int $amount
 * @property string $currency
 * @property RefundStatus $status
 * @property string|null $reason
 * @property string|null $connector
 * @property string|null $connector_refund_id
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array<string, mixed>|null $metadata
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read PaymentIntent $paymentIntent
 */
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
        'idempotency_key',
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

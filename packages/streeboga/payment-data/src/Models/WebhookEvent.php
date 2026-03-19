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
 * @property string $event_type
 * @property int $merchant_account_id
 * @property int|null $business_profile_id
 * @property int|null $payment_intent_id
 * @property array<string, mixed> $content
 * @property bool $delivered
 * @property int $delivery_attempts
 * @property Carbon|null $next_retry_at
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WebhookEvent extends Model
{
    protected $fillable = [
        'event_type',
        'merchant_account_id',
        'business_profile_id',
        'payment_intent_id',
        'content',
        'delivered',
        'delivery_attempts',
        'next_retry_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'delivered' => 'boolean',
            'next_retry_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookEvent $model) {
            $model->key ??= IdGenerator::eventId();
        });
    }

    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class);
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }
}

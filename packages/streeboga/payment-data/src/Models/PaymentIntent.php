<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Streeboga\PaymentData\Enums\AuthenticationType;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Support\IdGenerator;

/**
 * @property int $id
 * @property string $key
 * @property int $merchant_account_id
 * @property int|null $business_profile_id
 * @property int $amount
 * @property int|null $net_amount
 * @property int|null $amount_capturable
 * @property int|null $amount_received
 * @property string $currency
 * @property PaymentStatus $status
 * @property string $client_secret
 * @property CaptureMethod $capture_method
 * @property AuthenticationType $authentication_type
 * @property string|null $customer_id
 * @property string|null $return_url
 * @property string|null $description
 * @property array<string, mixed>|null $metadata
 * @property string|null $connector
 * @property int $attempt_count
 * @property int $session_expiry
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $cancellation_reason
 * @property Carbon|null $expires_on
 * @property string|null $idempotency_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PaymentIntent extends Model
{
    protected $fillable = [
        'merchant_account_id',
        'business_profile_id',
        'amount',
        'net_amount',
        'amount_capturable',
        'amount_received',
        'currency',
        'status',
        'capture_method',
        'authentication_type',
        'customer_id',
        'return_url',
        'description',
        'metadata',
        'connector',
        'attempt_count',
        'session_expiry',
        'error_code',
        'error_message',
        'cancellation_reason',
        'expires_on',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'capture_method' => CaptureMethod::class,
            'authentication_type' => AuthenticationType::class,
            'metadata' => 'array',
            'expires_on' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentIntent $model) {
            $model->key ??= IdGenerator::paymentId();
            $model->client_secret ??= IdGenerator::clientSecret($model->key);
        });
    }

    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}

<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Streeboga\PaymentData\Enums\AuthenticationType;
use Streeboga\PaymentData\Enums\CaptureMethod;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Support\IdGenerator;

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

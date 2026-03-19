<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisputeStatus;
use App\Enums\DisputeType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\PaymentIntent;

/**
 * @property int $id
 * @property string $key
 * @property int $payment_intent_id
 * @property int $merchant_account_id
 * @property int $amount
 * @property string $currency
 * @property DisputeType $type
 * @property DisputeStatus $status
 * @property string|null $reason_code
 * @property string|null $reason_description
 * @property Carbon|null $deadline_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PaymentIntent $paymentIntent
 * @property-read MerchantAccount $merchantAccount
 * @property-read Collection<int, DisputeEvidence> $evidences
 */
class Dispute extends Model
{
    protected $fillable = [
        'payment_intent_id',
        'merchant_account_id',
        'amount',
        'currency',
        'type',
        'status',
        'reason_code',
        'reason_description',
        'deadline_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => DisputeType::class,
            'status' => DisputeStatus::class,
            'deadline_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->key ??= 'dsp_'.Str::ulid()->toBase32();
        });
    }

    /** @return BelongsTo<PaymentIntent, $this> */
    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    /** @return BelongsTo<MerchantAccount, $this> */
    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class);
    }

    /** @return HasMany<DisputeEvidence, $this> */
    public function evidences(): HasMany
    {
        return $this->hasMany(DisputeEvidence::class);
    }
}

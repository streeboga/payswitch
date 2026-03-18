<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisputeStatus;
use App\Enums\DisputeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\PaymentIntent;

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

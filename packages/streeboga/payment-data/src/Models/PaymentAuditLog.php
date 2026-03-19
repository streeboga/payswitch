<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $payment_intent_id
 * @property int $merchant_account_id
 * @property string $action
 * @property string|null $previous_status
 * @property string|null $new_status
 * @property string|null $actor
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
class PaymentAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'payment_audit_log';

    protected $fillable = [
        'payment_intent_id',
        'merchant_account_id',
        'action',
        'previous_status',
        'new_status',
        'actor',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
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

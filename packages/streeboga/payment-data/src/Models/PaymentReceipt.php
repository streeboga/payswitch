<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Streeboga\PaymentData\Enums\ReceiptStatus;

/**
 * @property int $id
 * @property int $payment_intent_id
 * @property string $connector
 * @property string $kind
 * @property ReceiptStatus $status
 * @property int $total
 * @property string|null $provider_reference
 * @property string|null $fiscal_url
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $registered_at
 */
class PaymentReceipt extends Model
{
    protected $fillable = [
        'payment_intent_id', 'connector', 'kind', 'status', 'total',
        'provider_reference', 'fiscal_url', 'error_code', 'error_message', 'registered_at',
    ];

    protected function casts(): array
    {
        return ['status' => ReceiptStatus::class, 'registered_at' => 'datetime'];
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }
}

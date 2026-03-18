<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $payment_intent_id
 * @property string $connector
 * @property string|null $connector_transaction_id
 * @property string $status
 * @property int $amount
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaymentAttempt extends Model
{
    protected $fillable = [
        'payment_intent_id',
        'connector',
        'connector_transaction_id',
        'status',
        'amount',
        'error_code',
        'error_message',
    ];

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }
}

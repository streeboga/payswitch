<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Проведённый захват или отмена платежа с ключом идемпотентности вызывающего.
 *
 * @property int $id
 * @property int $payment_intent_id
 * @property string $idempotency_key
 * @property string $action capture | cancel
 * @property int|null $amount
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PaymentAction extends Model
{
    protected $fillable = [
        'payment_intent_id',
        'idempotency_key',
        'action',
        'amount',
    ];
}

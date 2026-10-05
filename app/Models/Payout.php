<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $key
 * @property int $merchant_account_id
 * @property string $project_id
 * @property string|null $authorization_reference
 * @property string|null $reserve_reference
 * @property string|null $approved_by
 * @property string $operation_id
 * @property string $request_hash
 * @property int $recipient_version_id
 * @property int $amount
 * @property string $currency
 * @property int $precision
 * @property string $channel
 * @property PayoutStatus $status
 * @property CarbonImmutable|null $succeeded_at
 */
final class Payout extends Model
{
    protected $fillable = ['merchant_account_id', 'project_id', 'operation_id', 'settlement_id', 'idempotency_key', 'request_hash', 'recipient_version_id', 'amount', 'currency', 'precision', 'channel', 'purpose', 'status', 'approved_by', 'authorization_reference', 'reserve_reference', 'succeeded_at'];

    protected function casts(): array
    {
        return ['status' => PayoutStatus::class, 'succeeded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $model) {
            if ($model->isDirty(['merchant_account_id', 'project_id', 'operation_id', 'settlement_id', 'recipient_version_id', 'amount', 'currency', 'precision', 'channel', 'purpose', 'idempotency_key', 'request_hash'])) {
                throw new \LogicException('Payout execution fields are immutable; a new operation requires Genesis approval');
            }
        });
        self::creating(function (self $model) {
            $model->key ??= 'pout_'.Str::lower((string) Str::ulid());
        });
    }
}

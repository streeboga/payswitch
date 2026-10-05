<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $key
 * @property int $payout_id
 * @property string $channel
 * @property string|null $provider_reference
 * @property PayoutStatus $status
 */
final class PayoutAttempt extends Model
{
    protected $fillable = ['payout_id', 'channel', 'provider_reference', 'status'];

    protected function casts(): array
    {
        return ['status' => PayoutStatus::class];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model) {
            $model->key ??= 'pat_'.Str::lower((string) Str::ulid());
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $key
 * @property int $merchant_account_id
 * @property string $project_id
 * @property string|null $verification_purpose
 * @property string $verification_reference
 * @property array<string, string> $details
 */
final class PayoutRecipientVersion extends Model
{
    protected $fillable = ['merchant_account_id', 'project_id', 'recipient_reference', 'verification_reference', 'verification_purpose', 'details', 'details_hash', 'created_at'];

    public $timestamps = false;

    protected $hidden = ['details'];

    protected function casts(): array
    {
        return ['details' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model) {
            $model->key ??= 'prv_'.Str::lower((string) Str::ulid());
        });
        self::updating(function () {
            throw new \LogicException('Recipient versions are immutable; create a new verified version');
        });
        self::deleting(function () {
            throw new \LogicException('Recipient versions are retained for financial history');
        });
    }
}

<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Streeboga\PaymentData\Support\IdGenerator;

class Organization extends Model
{
    protected $fillable = [
        'name',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $model) {
            $model->key ??= IdGenerator::orgId();
        });
    }

    public function merchantAccounts(): HasMany
    {
        return $this->hasMany(MerchantAccount::class, 'org_id');
    }
}

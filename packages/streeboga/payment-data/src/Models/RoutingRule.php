<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Streeboga\PaymentData\Support\IdGenerator;

class RoutingRule extends Model
{
    protected $fillable = [
        'merchant_account_id',
        'business_profile_id',
        'type',
        'name',
        'rules',
        'active',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    protected static function booted(): void
    {
        static::creating(function (RoutingRule $model) {
            $model->key ??= IdGenerator::routingRuleId();
        });
    }

    public function merchantAccount(): BelongsTo
    {
        return $this->belongsTo(MerchantAccount::class);
    }

    public function businessProfile(): BelongsTo
    {
        return $this->belongsTo(BusinessProfile::class);
    }
}

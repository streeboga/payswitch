<?php

declare(strict_types=1);

namespace Streeboga\PaymentData\Models;

use App\Enums\RoutingRuleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Streeboga\PaymentData\Support\IdGenerator;

/**
 * @property int $id
 * @property string $key
 * @property int $merchant_account_id
 * @property int|null $business_profile_id
 * @property RoutingRuleType $type
 * @property string $name
 * @property array<string, mixed> $rules
 * @property bool $active
 * @property int $priority
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
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
            'type' => RoutingRuleType::class,
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

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $table_name
 * @property string $name
 * @property array<string, mixed> $filters
 * @property bool $is_preset
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class SavedFilter extends Model
{
    protected $fillable = [
        'user_id',
        'table_name',
        'name',
        'filters',
        'is_preset',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_preset' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

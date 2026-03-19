<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $timezone
 * @property string $date_format
 * @property string $number_format
 * @property string $base_currency
 * @property string $theme
 * @property string $data_density
 * @property bool $notification_email
 * @property bool $notification_inapp
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'timezone',
        'date_format',
        'number_format',
        'base_currency',
        'theme',
        'data_density',
        'notification_email',
        'notification_inapp',
    ];

    protected function casts(): array
    {
        return [
            'notification_email' => 'boolean',
            'notification_inapp' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

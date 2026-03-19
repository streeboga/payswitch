<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $dispute_id
 * @property string $type
 * @property string|null $file_path
 * @property string|null $text_content
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Dispute $dispute
 */
class DisputeEvidence extends Model
{
    protected $table = 'dispute_evidences';

    protected $fillable = [
        'dispute_id',
        'type',
        'file_path',
        'text_content',
    ];

    /** @return BelongsTo<Dispute, $this> */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }
}

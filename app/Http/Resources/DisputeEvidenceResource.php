<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class DisputeEvidenceResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'dispute-evidences';
    }

    public function toId(Request $request): string
    {
        return (string) $this->id;
    }

    public function toAttributes(Request $request): array
    {
        return [
            'type' => $this->type,
            'file_path' => $this->file_path,
            'text_content' => $this->text_content,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

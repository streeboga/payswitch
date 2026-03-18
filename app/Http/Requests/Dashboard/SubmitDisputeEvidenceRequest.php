<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitDisputeEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|string',
            'text_content' => 'sometimes|string',
            'file' => 'sometimes|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx',
        ];
    }
}

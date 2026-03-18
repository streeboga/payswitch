<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $trimmed = [];
        if ($this->has('code')) {
            $trimmed['code'] = trim($this->input('code'));
        }
        if ($this->has('recovery_code')) {
            $trimmed['recovery_code'] = trim($this->input('recovery_code'));
        }
        if ($trimmed) {
            $this->merge($trimmed);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ];
    }

    public function hasCode(): bool
    {
        return $this->filled('code');
    }

    public function hasRecoveryCode(): bool
    {
        return $this->filled('recovery_code');
    }
}

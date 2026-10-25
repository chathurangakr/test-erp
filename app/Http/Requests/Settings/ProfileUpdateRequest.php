<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),
            // A new email address can take over the account through "forgot password", so
            // changing it needs the current password.
            'current_password' => [Rule::requiredIf($this->changesEmail()), 'nullable', 'string', 'current_password'],
        ];
    }

    private function changesEmail(): bool
    {
        return mb_strtolower(trim((string) $this->input('email'))) !== mb_strtolower($this->user()->email);
    }
}

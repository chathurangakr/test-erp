<?php

namespace App\Http\Requests\Administration;

use App\Models\MailSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The outgoing email account. The password is optional: blank keeps the saved one.
 */
class UpdateMailSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'host' => ['required', 'string', 'max:150'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(MailSetting::ENCRYPTIONS)],
            'username' => ['nullable', 'required_if:is_enabled,true', 'email', 'max:150'],
            'password' => ['nullable', 'string', 'max:200'],
            'from_address' => ['nullable', 'email', 'max:150'],
            'from_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * Switching email on needs a password — typed now or saved before.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $typed = trim((string) $this->input('password')) !== '';

                if ($this->boolean('is_enabled') && ! $typed && MailSetting::current()->password === null) {
                    $validator->errors()->add('password', __('Enter the app password to switch email on.'));
                }
            },
        ];
    }
}

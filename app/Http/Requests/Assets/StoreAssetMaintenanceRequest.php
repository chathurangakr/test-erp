<?php

namespace App\Http\Requests\Assets;

use App\Enums\PaymentMethod;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Work done on an asset and what it cost. A free job (0) needs no account.
 */
class StoreAssetMaintenanceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $paid = (float) $this->input('cost', 0) > 0;

        return [
            'maintenance_date' => ['required', 'date'],
            'work_done' => ['required', 'string', 'max:255'],
            'done_by' => ['nullable', 'string', 'max:150'],
            'cost' => ['required', 'numeric', 'min:0', new Money],
            'cash_bank_account_id' => $paid ? ['required', 'integer', Rule::exists('cash_bank_accounts', 'id')->where('is_active', 1)] : ['nullable'],
            'payment_method' => $paid ? ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::BankTransfer->value])] : ['nullable'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}

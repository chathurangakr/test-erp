<?php

namespace App\Http\Requests\Cash;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class StoreCashBankAccountRequest extends CashBankAccountRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function accountRules(): array
    {
        return [
            'account_id' => [
                'required',
                'integer',
                $this->eligibleAccountRule(),
                // One configuration per account: two names for the same money would be two
                // versions of the truth.
                Rule::unique('cash_bank_accounts', 'account_id'),
            ],
        ];
    }
}

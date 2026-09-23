<?php

namespace App\Http\Requests\Cash;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Editing a cash/bank configuration.
 *
 * The underlying general-ledger account is deliberately NOT editable. Repointing a
 * configuration at a different account would silently rewrite the meaning of every document
 * already recorded against it. Deactivate this one and configure the other instead.
 */
class UpdateCashBankAccountRequest extends CashBankAccountRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function accountRules(): array
    {
        return [];
    }
}

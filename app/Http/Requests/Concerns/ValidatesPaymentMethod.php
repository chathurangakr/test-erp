<?php

namespace App\Http\Requests\Concerns;

use App\Enums\PaymentMethod;
use App\Models\CashBankAccount;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * How money moved on a supplier payment or customer receipt: cash, cheque or bank transfer.
 *
 * A cheque needs its number, date and bank. Cash must go through a cash account and a cheque
 * or transfer through a bank account — checked here for a clear message, and again by the
 * posting action. "Credit" is not a method: it is an invoice left outstanding.
 * See docs/ACCOUNTING_RULES.md §29.
 */
trait ValidatesPaymentMethod
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function paymentMethodRules(): array
    {
        $cheque = PaymentMethod::Cheque->value;

        return [
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'cheque_number' => ["required_if:payment_method,{$cheque}", 'nullable', 'string', 'max:50'],
            'cheque_date' => ["required_if:payment_method,{$cheque}", 'nullable', 'date'],
            'cheque_bank' => ["required_if:payment_method,{$cheque}", 'nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function paymentMethodMessages(): array
    {
        return [
            'payment_method.required' => __('Choose how the money was paid: cash, cheque or bank transfer.'),
            'cheque_number.required_if' => __('Enter the cheque number.'),
            'cheque_date.required_if' => __('Enter the date on the cheque.'),
            'cheque_bank.required_if' => __('Enter the bank the cheque is drawn on.'),
        ];
    }

    protected function validateMethodAgainstAccount(Validator $validator, string $accountField): void
    {
        $method = PaymentMethod::tryFrom((string) $this->input('payment_method'));
        $configuration = CashBankAccount::query()->where('account_id', (int) $this->input($accountField))->first();

        if ($method === null || $configuration === null || $configuration->account_kind === $method->accountKind()) {
            return;
        }

        $validator->errors()->add($accountField, __(':method must go through a :kind account; :name is a :actual account.', [
            'method' => $method->label(),
            'kind' => $method->accountKind()->label(),
            'name' => $configuration->display_name,
            'actual' => $configuration->account_kind->label(),
        ]));
    }
}

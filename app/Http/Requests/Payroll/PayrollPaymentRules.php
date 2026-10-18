<?php

namespace App\Http\Requests\Payroll;

use App\Enums\PaymentMethod;
use Illuminate\Validation\Rule;

/**
 * How payroll money leaves: cash from a till or a bank transfer, from a configured account.
 * Cheques are not offered — they need cheque details payroll does not record.
 */
trait PayrollPaymentRules
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function paymentRules(): array
    {
        return [
            'cash_bank_account_id' => ['required', 'integer', Rule::exists('cash_bank_accounts', 'id')->where('is_active', 1)],
            'payment_method' => ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::BankTransfer->value])],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return list<array{value: string, label: string, account_kind: string}>
     */
    public static function paymentMethodOptions(): array
    {
        return array_values(array_filter(
            PaymentMethod::options(),
            fn (array $option): bool => $option['value'] !== PaymentMethod::Cheque->value,
        ));
    }
}

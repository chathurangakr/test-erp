<?php

namespace App\Enums;

/**
 * How money changed hands on a supplier payment or customer receipt.
 *
 * "Credit" is deliberately absent: buying or selling on credit is not a way of paying — it is
 * an invoice left outstanding until a payment or receipt settles it, in full or in parts.
 *
 * A cheque records its number, date and bank. It has NO status lifecycle and no clearing
 * account: the money is posted straight to the chosen bank account, exactly as a transfer is.
 * Cheque status tracking (deposited / cleared / bounced) is TBD — see
 * docs/ACCOUNTING_RULES.md §29.
 */
enum PaymentMethod: string
{
    case Cash = 'CASH';
    case Cheque = 'CHEQUE';
    case BankTransfer = 'BANK_TRANSFER';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Cash => 'Cash',
            self::Cheque => 'Cheque',
            self::BankTransfer => 'Bank transfer',
        });
    }

    /**
     * Where this method's money is held: notes and coins in a till, a cheque or a transfer in
     * a bank account.
     */
    public function accountKind(): CashBankAccountKind
    {
        return $this === self::Cash ? CashBankAccountKind::Cash : CashBankAccountKind::Bank;
    }

    public function requiresChequeDetails(): bool
    {
        return $this === self::Cheque;
    }

    /**
     * @return list<array{value: string, label: string, account_kind: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'account_kind' => $case->accountKind()->value,
            ],
            self::cases(),
        );
    }
}

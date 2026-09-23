<?php

namespace Database\Seeders;

use App\Enums\CashBankAccountKind;
use App\Models\Account;
use App\Models\CashBankAccount;
use Illuminate\Database\Seeder;

/**
 * Configures the two money accounts the chart already has.
 *
 * **Configuration only.** No balances, no opening entries, no transactions — those come from
 * posted journals or they do not exist at all. This seeder simply records that `1110` is the
 * till and `1120` is the bank, so the modules that move money have a list to check against.
 *
 * More bank accounts (1121 Commercial Bank, 1122 Sampath Bank…) are added by an accountant
 * through the UI, not here: the software must not invent which banks a factory uses.
 *
 * Idempotent: re-running updates the description without disturbing history.
 */
class CashBankAccountSeeder extends Seeder
{
    /**
     * account code => [kind, display name].
     *
     * @var array<string, array{0: CashBankAccountKind, 1: string}>
     */
    private const CONFIGURATION = [
        '1110' => [CashBankAccountKind::Cash, 'Cash on Hand'],
        '1120' => [CashBankAccountKind::Bank, 'Bank'],
    ];

    public function run(): void
    {
        foreach (self::CONFIGURATION as $code => [$kind, $displayName]) {
            $account = Account::where('code', $code)->first();

            if ($account === null) {
                // The chart is the accountant's to change; a missing account is not this
                // seeder's to create.
                continue;
            }

            CashBankAccount::updateOrCreate(
                ['account_id' => $account->id],
                [
                    'account_kind' => $kind,
                    'display_name' => $displayName,
                    'is_active' => true,
                ],
            );
        }
    }
}

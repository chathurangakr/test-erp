<?php

namespace App\Actions\Cash;

use App\Enums\CashBankAccountKind;
use App\Models\BankReconciliation;
use App\Models\CashBankAccount;
use App\Services\Accounting\BankReconciliationService;
use App\Support\DecimalMath;
use App\Support\DisplayNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agrees a bank statement with the books: the journal lines the statement shows are ticked, and
 * the balance they leave (everything reconciled before, plus these) must equal the statement's
 * closing balance to the cent. Statements are reconciled in date order, each line once. Changes no
 * journal. See docs/ACCOUNTING_RULES.md §36.
 */
class RecordBankReconciliation
{
    public function __construct(private readonly BankReconciliationService $service) {}

    /**
     * @param  array{cash_bank_account_id: int, statement_date: string, statement_balance: string, journal_entry_line_ids?: list<int>, note?: string|null}  $data
     */
    public function handle(array $data, ?int $userId = null): BankReconciliation
    {
        return DB::transaction(function () use ($data, $userId): BankReconciliation {
            $account = CashBankAccount::query()->lockForUpdate()->findOrFail($data['cash_bank_account_id']);

            if ($account->account_kind !== CashBankAccountKind::Bank) {
                throw ValidationException::withMessages(['cash_bank_account_id' => __('Only a bank account is reconciled with a bank statement.')]);
            }

            $date = CarbonImmutable::parse($data['statement_date']);
            $latest = $this->service->latest($account);

            if ($latest !== null && ! $date->greaterThan($latest->statement_date)) {
                throw ValidationException::withMessages(['statement_date' => __('Statements are reconciled in order: the last one is dated :date.', ['date' => $latest->statement_date->toDateString()])]);
            }

            $available = $this->service->unreconciledLines($account, $date)->keyBy('id');
            $ticked = collect($data['journal_entry_line_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values();

            if ($ticked->contains(fn (int $id): bool => ! $available->has($id))) {
                throw ValidationException::withMessages(['journal_entry_line_ids' => __('Some ticked lines are not on this account, are dated after the statement, or were already reconciled.')]);
            }

            $cleared = $ticked->reduce(
                fn (string $sum, int $id): string => DecimalMath::add($sum, DecimalMath::sub((string) $available[$id]->debit, (string) $available[$id]->credit)),
                $this->service->reconciledBalance($account),
            );
            $statement = DecimalMath::money(DecimalMath::of((string) $data['statement_balance']));
            $difference = DecimalMath::money(DecimalMath::sub($statement, $cleared));

            if (! DecimalMath::isZero($difference)) {
                throw ValidationException::withMessages(['statement_balance' => __('The ticked lines leave :cleared, but the statement says :statement — a difference of :difference.', [
                    'cleared' => DisplayNumber::money($cleared),
                    'statement' => DisplayNumber::money($statement),
                    'difference' => DisplayNumber::money($difference),
                ])]);
            }

            $reconciliation = BankReconciliation::create([
                'cash_bank_account_id' => $account->id,
                'statement_date' => $date->toDateString(),
                'statement_balance' => $statement,
                'status' => BankReconciliation::COMPLETED,
                'note' => $data['note'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($ticked as $id) {
                $reconciliation->lines()->create(['journal_entry_line_id' => $id]);
            }

            return $reconciliation->refresh();
        });
    }
}

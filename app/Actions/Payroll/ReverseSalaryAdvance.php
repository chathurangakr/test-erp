<?php

namespace App\Actions\Payroll;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\PayrollDocumentStatus;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Services\Payroll\PayrollCalculator;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a salary advance recorded by mistake, with an exact mirror journal.
 *
 * Refused once a paid salary has already taken the money back: reversing it then would leave
 * the employee "owing" less than nothing. Reverse that payroll first.
 */
class ReverseSalaryAdvance
{
    public function __construct(
        private readonly ReverseJournalEntry $reverseJournal,
        private readonly PayrollCalculator $calculator,
    ) {}

    public function handle(SalaryAdvance $advance, ?int $userId = null): SalaryAdvance
    {
        return DB::transaction(function () use ($advance, $userId): SalaryAdvance {
            $locked = SalaryAdvance::query()->whereKey($advance->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse() || $locked->reversal_journal_entry_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Salary advance :reference has already been reversed.', ['reference' => $locked->advance_number]),
                ]);
            }

            // Serialised with PayPayroll, which locks the same employee rows.
            Employee::query()->whereKey($locked->employee_id)->lockForUpdate()->first();

            $remaining = DecimalMath::sub($this->calculator->outstandingAdvances($locked->employee_id), (string) $locked->amount);

            if (DecimalMath::lessThan($remaining, '0')) {
                throw ValidationException::withMessages([
                    'status' => __('A paid salary has already taken this advance back. Reverse that payroll first.'),
                ]);
            }

            $journal = $locked->journalEntry()->first();

            if ($journal === null) {
                throw ValidationException::withMessages([
                    'status' => __('Salary advance :reference has no journal to reverse.', ['reference' => $locked->advance_number]),
                ]);
            }

            $reversal = $this->reverseJournal->handle($journal, $userId);

            $locked->forceFill([
                'status' => PayrollDocumentStatus::Reversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversed_by' => $userId,
                'reversed_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }
}

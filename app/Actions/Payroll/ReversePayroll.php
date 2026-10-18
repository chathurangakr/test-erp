<?php

namespace App\Actions\Payroll;

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\PayrollDocumentStatus;
use App\Models\EmployeeAttendance;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a paid payroll recorded by mistake.
 *
 * The journal is mirrored exactly; the month's attendance days are released so they can be
 * corrected and paid again; and the advances it took back count as owed once more, because
 * only standing payrolls are counted. Nothing is deleted: the payroll and its lines stay on the
 * record, marked reversed.
 */
class ReversePayroll
{
    public function __construct(private readonly ReverseJournalEntry $reverseJournal) {}

    public function handle(PayrollRun $run, ?int $userId = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $userId): PayrollRun {
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canReverse() || $locked->reversal_journal_entry_id !== null) {
                throw ValidationException::withMessages([
                    'status' => __('Payroll :reference has already been reversed.', ['reference' => $locked->payroll_number]),
                ]);
            }

            $journal = $locked->journalEntry()->first();

            if ($journal === null) {
                throw ValidationException::withMessages([
                    'status' => __('Payroll :reference has no journal to reverse.', ['reference' => $locked->payroll_number]),
                ]);
            }

            $reversal = $this->reverseJournal->handle($journal, $userId);

            EmployeeAttendance::query()->where('payroll_run_id', $locked->id)->get()
                ->each(fn (EmployeeAttendance $day) => $day->update(['payroll_run_id' => null]));

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

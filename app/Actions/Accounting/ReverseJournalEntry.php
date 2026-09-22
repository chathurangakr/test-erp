<?php

namespace App\Actions\Accounting;

use App\Enums\JournalEntryStatus;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses a posted journal entry by posting a NEW, opposite one.
 *
 * The original is never modified beyond its reversal audit pointers, and its lines are never
 * touched — that is what makes the correction auditable: both the mistake and its undoing
 * remain visible, and the two sum to nothing.
 *
 * Every line is mirrored with debit and credit swapped, at the EXACT original amounts. The
 * reversal is not recalculated from anything, so it cancels the original to the last decimal.
 *
 * The reversal posts into the open period containing its own date — by default today, not
 * the original's date. A closed period is a period already reported on, and reaching back
 * into one would change a published figure.
 */
class ReverseJournalEntry
{
    public function __construct(
        private readonly GenerateJournalReference $references,
        private readonly PostJournalEntry $post,
    ) {}

    /**
     * @param  array{journal_date?: CarbonInterface|string|null, description?: string|null}  $attributes
     */
    public function handle(JournalEntry $original, ?int $userId = null, array $attributes = []): JournalEntry
    {
        return DB::transaction(function () use ($original, $userId, $attributes): JournalEntry {
            // Re-read under lock so two concurrent reversals cannot both pass the check.
            $locked = JournalEntry::query()
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertReversible($locked);

            $date = isset($attributes['journal_date'])
                ? Carbon::parse($attributes['journal_date'])
                : now();

            $period = FiscalPeriod::openFor($date);

            if ($period === null) {
                throw ValidationException::withMessages([
                    'journal_date' => __('There is no open fiscal period covering :date, so the reversal cannot be posted.', [
                        'date' => $date->toDateString(),
                    ]),
                ]);
            }

            $reversal = JournalEntry::create([
                'journal_number' => $this->references->forJournalEntry($date),
                'journal_date' => $date->toDateString(),
                'fiscal_period_id' => $period->id,
                'source_type' => $locked->source_type,
                'source_id' => $locked->source_id,
                'description' => $attributes['description'] ?? __('Reversal of :reference — :description', [
                    'reference' => $locked->journal_number,
                    'description' => $locked->description,
                ]),
                'status' => JournalEntryStatus::Draft,
                'reversal_of_id' => $locked->getKey(),
                'created_by' => $userId ?? auth()->id(),
            ]);

            foreach ($locked->lines()->orderBy('id')->get() as $line) {
                $reversal->lines()->create([
                    'account_id' => $line->account_id,
                    'description' => $line->description,
                    // Swapped, at the exact original amounts.
                    'debit' => DecimalMath::cost(DecimalMath::of($line->credit)),
                    'credit' => DecimalMath::cost(DecimalMath::of($line->debit)),
                ]);
            }

            // Posted through the ordinary action, so a reversal is held to every rule an
            // original is — including the period gate and the balance check.
            $posted = $this->post->handle($reversal->refresh(), $userId);

            // The only permitted writes to a posted entry: its reversal audit pointers.
            $locked->forceFill([
                'status' => JournalEntryStatus::Reversed,
                'reversed_by_id' => $posted->getKey(),
                'reversed_by_user_id' => $userId ?? auth()->id(),
                'reversed_at' => now(),
            ])->save();

            return $posted->refresh()->load(['lines.account', 'fiscalPeriod', 'reversalOf']);
        });
    }

    private function assertReversible(JournalEntry $entry): void
    {
        if ($entry->isReversal()) {
            throw ValidationException::withMessages([
                'status' => __('A reversing journal entry cannot itself be reversed.'),
            ]);
        }

        if (! $entry->status->canReverse()) {
            throw ValidationException::withMessages([
                'status' => __('Only a posted journal entry can be reversed. :reference is :status.', [
                    'reference' => $entry->journal_number,
                    'status' => $entry->status->label(),
                ]),
            ]);
        }

        if ($entry->reversed_by_id !== null) {
            throw ValidationException::withMessages([
                'status' => __('Journal entry :reference has already been reversed.', [
                    'reference' => $entry->journal_number,
                ]),
            ]);
        }
    }
}

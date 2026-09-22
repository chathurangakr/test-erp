<?php

namespace App\Actions\Accounting;

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a fiscal period.
 *
 * A period may not be closed while it still holds a DRAFT entry. The draft would become
 * permanently unpostable and invisible to every report — a silent loss of work. Refusing to
 * close, and naming the drafts, is the better failure.
 *
 * No year-end closing entries are made: rolling the income statement into retained earnings
 * is a later Finance phase.
 */
class CloseFiscalPeriod
{
    public function handle(FiscalPeriod $period, ?int $userId = null): FiscalPeriod
    {
        return DB::transaction(function () use ($period, $userId): FiscalPeriod {
            $locked = FiscalPeriod::query()
                ->whereKey($period->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => __('Fiscal period :period is already closed.', ['period' => $locked->name]),
                ]);
            }

            if ($locked->hasDraftEntries()) {
                throw ValidationException::withMessages([
                    'status' => __('Fiscal period :period still has draft journal entries. Post or cancel them before closing.', [
                        'period' => $locked->name,
                    ]),
                ]);
            }

            $locked->update([
                'status' => FiscalPeriodStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $userId ?? auth()->id(),
            ]);

            return $locked->refresh();
        });
    }
}

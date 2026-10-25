<?php

namespace App\Console\Commands;

use App\Enums\FiscalPeriodStatus;
use App\Models\FiscalPeriod;
use App\Support\BusinessClock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the accounting calendar open ahead: makes sure this month and next month each have an
 * OPEN fiscal period ("November 2026", the 1st to the last day), so postings never stop at a
 * month end because nobody opened the new month. Scheduled daily in routes/console.php.
 *
 * It only CONTINUES a calendar an accountant started — with no fiscal period at all it does
 * nothing — and it never touches an existing period: a month overlapped by any period, open or
 * closed, whatever its dates, is left alone (periods must never overlap). Closing stays a person's
 * decision. See docs/ACCOUNTING_RULES.md §7.
 */
#[Signature('finance:open-next-periods')]
#[Description('Open this month and next month as fiscal periods when they do not exist yet')]
class OpenNextFiscalPeriodsCommand extends Command
{
    public function handle(): int
    {
        if (! FiscalPeriod::query()->exists()) {
            $this->info('No fiscal periods yet; an accountant opens the first one.');

            return self::SUCCESS;
        }

        $thisMonth = BusinessClock::today()->startOfMonth();

        foreach ([$thisMonth, $thisMonth->addMonthNoOverflow()] as $start) {
            $end = $start->endOfMonth()->startOfDay();

            $opened = DB::transaction(function () use ($start, $end): bool {
                // Lock the calendar so two runs can never open the same month twice.
                FiscalPeriod::query()->lockForUpdate()->get(['id']);

                $overlaps = FiscalPeriod::query()
                    ->whereOnOrBefore('start_date', $end->toDateString())
                    ->whereOnOrAfter('end_date', $start->toDateString())
                    ->exists();

                if ($overlaps) {
                    return false;
                }

                FiscalPeriod::create([
                    'name' => $start->format('F Y'),
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'status' => FiscalPeriodStatus::Open,
                ]);

                return true;
            });

            if ($opened) {
                $this->info("Opened {$start->format('F Y')}");
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Actions\Cash;

use App\Models\ExpenseVoucher;
use App\Models\FundTransfer;
use App\Support\DailyDocumentSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Cash document numbers: EXP-YYYYMMDD-000001 and TRF-YYYYMMDD-000001.
 *
 * Reuses the shared daily sequence, so the concurrency handling — a locked read of the
 * current maximum inside the caller's transaction — lives in exactly one place. Like every
 * other reference in this system, the number encodes no business meaning.
 */
class GenerateCashReference
{
    public function __construct(private readonly DailyDocumentSequence $sequence) {}

    public function forExpenseVoucher(?CarbonInterface $date = null): string
    {
        return $this->sequence->next(
            'EXP',
            ExpenseVoucher::class,
            'voucher_number',
            $date === null ? null : Carbon::instance($date),
        );
    }

    public function forFundTransfer(?CarbonInterface $date = null): string
    {
        return $this->sequence->next(
            'TRF',
            FundTransfer::class,
            'transfer_number',
            $date === null ? null : Carbon::instance($date),
        );
    }
}

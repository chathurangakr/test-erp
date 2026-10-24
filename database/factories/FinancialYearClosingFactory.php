<?php

namespace Database\Factories;

use App\Enums\FinancialYearClosingStatus;
use App\Models\Account;
use App\Models\FinancialYearClosing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A standing year-end close of the year 1 April 2025 – 31 March 2026, with no journal (a year
 * with nothing to close). Real closes are written by CloseFinancialYear.
 *
 * @extends Factory<FinancialYearClosing>
 */
class FinancialYearClosingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'year_start_date' => '2025-04-01',
            'year_end_date' => '2026-03-31',
            'net_profit' => '0',
            'retained_earnings_account_id' => Account::factory(),
            'status' => FinancialYearClosingStatus::Posted,
            'posted_at' => now(),
        ];
    }
}

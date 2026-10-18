<?php

namespace App\Actions\Payroll;

use App\Models\PayrollRun;
use App\Models\SalaryAdvance;
use App\Support\DailyDocumentSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Payroll document numbers: ADV-YYYYMMDD-000001 for salary advances and PAY-YYYYMMDD-000001
 * for paid payrolls. Like every other reference here, the number encodes no business meaning.
 */
class GeneratePayrollReference
{
    public function __construct(private readonly DailyDocumentSequence $sequence) {}

    public function forSalaryAdvance(CarbonInterface $date): string
    {
        return $this->sequence->next('ADV', SalaryAdvance::class, 'advance_number', Carbon::instance($date));
    }

    public function forPayroll(CarbonInterface $date): string
    {
        return $this->sequence->next('PAY', PayrollRun::class, 'payroll_number', Carbon::instance($date));
    }
}

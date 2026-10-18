<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollLine>
 */
class PayrollLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payroll_run_id' => PayrollRun::factory(),
            'employee_id' => Employee::factory(),
            'full_days' => 1,
            'half_days' => 0,
            'leave_days' => 0,
            'gross_pay' => '2000.00',
            'advances_deducted' => '0.00',
            'net_pay' => '2000.00',
        ];
    }
}

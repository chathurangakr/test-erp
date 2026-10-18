<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAttendance>
 */
class EmployeeAttendanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'work_date' => '2026-09-01',
            'status' => AttendanceStatus::FullDay,
            'daily_rate' => '2000.00',
        ];
    }
}

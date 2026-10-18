<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Database\Factories\EmployeeAttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One employee's day: full day, half day or leave, at the rate in force when it was recorded.
 *
 * Once a payroll has paid the day (`payroll_run_id` set) it is frozen: the only permitted
 * change is clearing that pointer when the payroll is reversed. Record days through
 * App\Actions\Payroll\RecordAttendance, which enforces this before writing.
 */
class EmployeeAttendance extends Model
{
    /** @use HasFactory<EmployeeAttendanceFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'work_date',
        'status',
        'daily_rate',
        'note',
        'payroll_run_id',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'status' => AttendanceStatus::class,
            'daily_rate' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $attendance): void {
            if ($attendance->getOriginal('payroll_run_id') === null) {
                return;
            }

            // A paid day may only be released by reversing its payroll.
            $changed = array_diff(array_keys($attendance->getDirty()), ['payroll_run_id', 'updated_at']);

            if ($changed !== [] || $attendance->payroll_run_id !== null) {
                throw new RuntimeException('A paid attendance day is immutable. Reverse its payroll first.');
            }
        });

        static::deleting(function (self $attendance): void {
            if ($attendance->payroll_run_id !== null) {
                throw new RuntimeException('A paid attendance day cannot be deleted. Reverse its payroll first.');
            }
        });
    }

    /**
     * What this day earns.
     */
    public function pay(): string
    {
        return $this->status->payFor((string) $this->daily_rate);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }
}

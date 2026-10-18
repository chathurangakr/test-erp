<?php

namespace App\Models;

use Database\Factories\PayrollLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * What one employee was paid in one payroll — the days, the gross, the advances taken back and
 * the money handed over. Written once when the payroll is paid, never changed.
 */
class PayrollLine extends Model
{
    /** @use HasFactory<PayrollLineFactory> */
    use HasFactory;

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'full_days',
        'half_days',
        'leave_days',
        'gross_pay',
        'advances_deducted',
        'net_pay',
    ];

    protected function casts(): array
    {
        return [
            'full_days' => 'integer',
            'half_days' => 'integer',
            'leave_days' => 'integer',
            'gross_pay' => 'decimal:2',
            'advances_deducted' => 'decimal:2',
            'net_pay' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('A payroll line is immutable.'));
        static::deleting(fn () => throw new RuntimeException('A payroll line is permanent.'));
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

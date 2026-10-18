<?php

namespace App\Models;

use App\Concerns\HasUppercaseCode;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone the factory pays by the day.
 *
 * A master record: deactivated, never deleted, and holding no balance. What an employee earned,
 * was advanced and still owes is derived from attendance, salary advances and paid payrolls —
 * see App\Services\Payroll\PayrollCalculator. `daily_rate` applies to days recorded from now
 * on; each attendance day keeps the rate it was recorded at.
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    use HasUppercaseCode;

    protected $fillable = [
        'code',
        'name',
        'nic',
        'phone',
        'daily_rate',
        'joined_on',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            // decimal:2 keeps the value a precise string, never a float.
            'daily_rate' => 'decimal:2',
            'joined_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<EmployeeAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(EmployeeAttendance::class);
    }

    /**
     * @return HasMany<SalaryAdvance, $this>
     */
    public function salaryAdvances(): HasMany
    {
        return $this->hasMany(SalaryAdvance::class);
    }

    /**
     * @return HasMany<PayrollLine, $this>
     */
    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /**
     * @param  Builder<Employee>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('code', 'like', '%'.$term.'%')
                ->orWhere('name', 'like', '%'.$term.'%')
                ->orWhere('nic', 'like', '%'.$term.'%')
                ->orWhere('phone', 'like', '%'.$term.'%');
        });
    }
}

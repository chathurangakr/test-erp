<?php

namespace App\Http\Resources\V1\Payroll;

use App\Http\Resources\V1\ApiResource;
use App\Models\Employee;
use Illuminate\Http\Request;

/**
 * An employee paid by the day. There is no balance on an employee: `advances_owed` is derived
 * (PayrollCalculator) and only present when the controller worked it out.
 *
 * @mixin Employee
 */
class EmployeeResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'nic' => $this->nic,
            'phone' => $this->phone,
            'daily_rate' => $this->money($this->daily_rate),
            'joined_on' => $this->date($this->joined_on),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'advances_owed' => $this->when(isset($this->resource->advances_owed), fn () => $this->money($this->resource->advances_owed)),
            'created_at' => $this->timestamp($this->created_at),
            'updated_at' => $this->timestamp($this->updated_at),
        ];
    }
}

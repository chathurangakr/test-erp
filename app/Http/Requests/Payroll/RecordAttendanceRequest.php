<?php

namespace App\Http\Requests\Payroll;

use App\Enums\AttendanceStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One day's attendance register. A blank status clears a day recorded by mistake.
 */
class RecordAttendanceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'work_date' => ['required', 'date'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.employee_id' => ['required', 'integer', 'distinct', Rule::exists('employees', 'id')],
            'entries.*.status' => ['nullable', Rule::enum(AttendanceStatus::class)],
            'entries.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}

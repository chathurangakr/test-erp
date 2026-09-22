<?php

namespace App\Http\Requests\Accounting;

use App\Models\FiscalPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a fiscal period.
 *
 * Periods must not overlap: a journal date that fell inside two of them would have no single
 * answer to "which period does this belong to", and every period-based report would depend
 * on which one happened to be found first.
 */
class StoreFiscalPeriodRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('fiscal_periods', 'name')],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $start = $this->date('start_date');
            $end = $this->date('end_date');

            if ($start === null || $end === null) {
                return;
            }

            $overlapping = FiscalPeriod::query()
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->first();

            if ($overlapping !== null) {
                $validator->errors()->add(
                    'start_date',
                    __('These dates overlap :period (:start to :end).', [
                        'period' => $overlapping->name,
                        'start' => $overlapping->start_date->toDateString(),
                        'end' => $overlapping->end_date->toDateString(),
                    ]),
                );
            }
        });
    }
}

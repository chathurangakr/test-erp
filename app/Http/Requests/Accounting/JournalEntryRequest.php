<?php

namespace App\Http\Requests\Accounting;

use App\Support\DecimalMath;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared rules for creating and editing a DRAFT manual journal entry.
 *
 * A draft is allowed to be incomplete — that is what makes it a draft — but it is not
 * allowed to be nonsense. Lines that are both a debit and a credit, or neither, or negative,
 * are refused here and again at posting.
 *
 * The balance rule itself is NOT enforced here: a half-written draft may legitimately be
 * out of balance while someone works on it. It is enforced by PostJournalEntry, which is the
 * only thing that puts an entry into the books.
 */
abstract class JournalEntryRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'journal_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_active', true)],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.min' => __('A journal entry needs at least two lines: something debited and something credited.'),
            'lines.*.account_id.exists' => __('The selected account does not exist or is inactive.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array<string, mixed>> $lines */
            $lines = $this->input('lines', []);

            if (! is_array($lines)) {
                return;
            }

            foreach ($lines as $index => $line) {
                $debit = DecimalMath::of((string) ($line['debit'] ?? '0'));
                $credit = DecimalMath::of((string) ($line['credit'] ?? '0'));

                $hasDebit = DecimalMath::isPositive($debit);
                $hasCredit = DecimalMath::isPositive($credit);

                if ($hasDebit && $hasCredit) {
                    $validator->errors()->add(
                        "lines.{$index}.debit",
                        __('A line is either a debit or a credit, never both.'),
                    );

                    continue;
                }

                if (! $hasDebit && ! $hasCredit) {
                    $validator->errors()->add(
                        "lines.{$index}.debit",
                        __('Enter a debit or a credit amount.'),
                    );
                }
            }
        });
    }
}

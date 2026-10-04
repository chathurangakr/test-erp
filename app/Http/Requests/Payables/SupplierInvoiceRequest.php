<?php

namespace App\Http\Requests\Payables;

use App\Enums\SupplierInvoiceLineType;
use App\Models\SupplierInvoice;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared rules for creating and editing a DRAFT supplier invoice.
 *
 * Note what is absent: any credit account. Trade Payables comes from configuration, so a
 * user says what is being charged and never where the credit lands.
 *
 * The GRNI remaining-balance check lives in PostSupplierInvoice, not here — a draft may be
 * saved while someone works out the figures, and the balance it is checked against is only
 * meaningful under the lock taken at posting time.
 */
abstract class SupplierInvoiceRequest extends FormRequest
{
    abstract protected function invoiceId(): ?int;

    /**
     * Compare the number in the same shape the database stores it.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('supplier_invoice_number')) {
            $this->merge([
                'supplier_invoice_number' => SupplierInvoice::normaliseNumber(
                    (string) $this->input('supplier_invoice_number')
                ),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'supplier_invoice_number' => [
                'required', 'string', 'max:100',
                // Compared in its normalised form, exactly as the unique index stores it —
                // otherwise " inv-001 " would pass validation and then hit the constraint.
                Rule::unique('supplier_invoices', 'supplier_invoice_number_normalised')
                    ->where('supplier_id', $this->integer('supplier_id'))
                    ->ignore($this->invoiceId()),
            ],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'description' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_type' => ['required', Rule::enum(SupplierInvoiceLineType::class)],
            'lines.*.goods_receipt_id' => ['nullable', 'integer', Rule::exists('goods_receipts', 'id')],
            'lines.*.debit_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_active', true)],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.amount' => ['required', 'numeric', new Money, 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_invoice_number.unique' => __('This supplier has already sent an invoice with that number.'),
            'due_date.after_or_equal' => __('The due date cannot fall before the invoice date.'),
            'lines.min' => __('A supplier invoice needs at least one line.'),
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
                $type = SupplierInvoiceLineType::tryFrom((string) ($line['line_type'] ?? ''));

                if ($type === null) {
                    continue;
                }

                if ($type->isGrniMatch()) {
                    if (($line['goods_receipt_id'] ?? null) === null) {
                        $validator->errors()->add(
                            "lines.{$index}.goods_receipt_id",
                            __('Select the goods receipt this line is invoicing.'),
                        );
                    }

                    if (($line['debit_account_id'] ?? null) !== null) {
                        $validator->errors()->add(
                            "lines.{$index}.debit_account_id",
                            __('A goods-receipt line posts to Goods Received Not Invoiced; it takes no account.'),
                        );
                    }

                    continue;
                }

                if (($line['debit_account_id'] ?? null) === null) {
                    $validator->errors()->add(
                        "lines.{$index}.debit_account_id",
                        __('Select the expense account for this line.'),
                    );
                }

                if (($line['goods_receipt_id'] ?? null) !== null) {
                    $validator->errors()->add(
                        "lines.{$index}.goods_receipt_id",
                        __('A direct expense line is not tied to a goods receipt.'),
                    );
                }
            }

            unset($validator);
        });
    }
}

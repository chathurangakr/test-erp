<?php

namespace App\Actions\Payables;

use App\Enums\SupplierInvoiceLineType;
use App\Enums\SupplierInvoiceStatus;
use App\Models\SupplierInvoice;
use App\Support\DecimalMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft supplier invoice, or replaces the contents of an existing draft.
 *
 * The one implementation behind both the website and the API. Input is expected to have
 * passed StoreSupplierInvoiceRequest / UpdateSupplierInvoiceRequest. A draft owes nothing and
 * reaches no ledger: GRNI matching caps and journal rules are applied by PostSupplierInvoice.
 * Only a DRAFT may be edited.
 */
class SaveSupplierInvoice
{
    public function __construct(private readonly GeneratePayableReference $references) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?SupplierInvoice $invoice, array $data, ?int $userId = null): SupplierInvoice
    {
        if ($invoice !== null) {
            $this->assertEditable($invoice);
        }

        return DB::transaction(function () use ($invoice, $data, $userId): SupplierInvoice {
            $header = [
                'supplier_id' => $data['supplier_id'],
                'supplier_invoice_number' => $data['supplier_invoice_number'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'description' => $data['description'] ?? null,
            ];

            if ($invoice === null) {
                $invoice = SupplierInvoice::create($header + [
                    'internal_invoice_number' => $this->references->forInvoice(Carbon::parse($data['invoice_date'])),
                    'status' => SupplierInvoiceStatus::Draft,
                    'created_by' => $userId ?? auth()->id(),
                ]);
            } else {
                $invoice->update($header);

                // Safe only because the invoice is still a draft — nothing has reached the books.
                $invoice->lines()->delete();
            }

            foreach ($data['lines'] as $line) {
                $type = SupplierInvoiceLineType::from($line['line_type']);

                $invoice->lines()->create([
                    'line_type' => $type,
                    'goods_receipt_id' => $type->isGrniMatch() ? $line['goods_receipt_id'] : null,
                    'debit_account_id' => $type->isGrniMatch() ? null : $line['debit_account_id'],
                    'description' => $line['description'] ?? null,
                    'amount' => DecimalMath::cost(DecimalMath::of((string) $line['amount'])),
                ]);
            }

            return $invoice->refresh();
        });
    }

    public function assertEditable(SupplierInvoice $invoice): void
    {
        if (! $invoice->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft supplier invoice can be edited. :reference is :status.', [
                    'reference' => $invoice->internal_invoice_number,
                    'status' => $invoice->status->label(),
                ]),
            ]);
        }
    }
}

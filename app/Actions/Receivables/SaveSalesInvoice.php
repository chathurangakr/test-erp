<?php

namespace App\Actions\Receivables;

use App\Enums\SalesInvoiceStatus;
use App\Models\DeliveryLine;
use App\Models\SalesInvoice;
use App\Support\DecimalMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft sales invoice, or replaces the contents of an existing draft.
 *
 * The one implementation behind both the website and the API. Every line bills a delivery
 * line; the product is copied from it for reporting, never chosen. Billable caps, revenue
 * account resolution and the journal are applied by PostSalesInvoice. Only a DRAFT may be
 * edited.
 */
class SaveSalesInvoice
{
    public function __construct(private readonly GenerateReceivableReference $references) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?SalesInvoice $invoice, array $data, ?int $userId = null): SalesInvoice
    {
        if ($invoice !== null) {
            $this->assertEditable($invoice);
        }

        return DB::transaction(function () use ($invoice, $data, $userId): SalesInvoice {
            $header = [
                'customer_id' => $data['customer_id'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'customer_reference' => $data['customer_reference'] ?? null,
                'description' => $data['description'] ?? null,
            ];

            if ($invoice === null) {
                $invoice = SalesInvoice::create($header + [
                    'invoice_number' => $this->references->forInvoice(Carbon::parse($data['invoice_date'])),
                    'status' => SalesInvoiceStatus::Draft,
                    'created_by' => $userId ?? auth()->id(),
                ]);
            } else {
                $invoice->update($header);

                // Safe only because the invoice is still a draft — nothing has reached the books.
                $invoice->lines()->delete();
            }

            foreach ($data['lines'] as $line) {
                $deliveryLine = DeliveryLine::find($line['delivery_line_id']);

                if ($deliveryLine === null) {
                    throw ValidationException::withMessages([
                        'lines' => __('One of the selected delivery lines no longer exists.'),
                    ]);
                }

                $invoice->lines()->create([
                    'delivery_line_id' => $deliveryLine->id,
                    'product_id' => $deliveryLine->product_id,
                    'description' => $line['description'] ?? null,
                    'amount' => DecimalMath::cost(DecimalMath::of((string) $line['amount'])),
                ]);
            }

            return $invoice->refresh();
        });
    }

    public function assertEditable(SalesInvoice $invoice): void
    {
        if (! $invoice->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft sales invoice can be edited. :reference is :status.', [
                    'reference' => $invoice->invoice_number,
                    'status' => $invoice->status->label(),
                ]),
            ]);
        }
    }
}

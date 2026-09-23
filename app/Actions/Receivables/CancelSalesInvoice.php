<?php

namespace App\Actions\Receivables;

use App\Enums\SalesInvoiceStatus;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a DRAFT sales invoice — one that never reached the books.
 */
class CancelSalesInvoice
{
    public function handle(SalesInvoice $invoice, ?int $userId = null): SalesInvoice
    {
        return DB::transaction(function () use ($invoice): SalesInvoice {
            $locked = SalesInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a draft sales invoice can be cancelled. :reference is :status. A posted invoice must be reversed.', [
                        'reference' => $locked->invoice_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            $locked->update(['status' => SalesInvoiceStatus::Cancelled]);

            return $locked->refresh();
        });
    }
}

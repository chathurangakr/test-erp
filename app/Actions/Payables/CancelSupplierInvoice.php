<?php

namespace App\Actions\Payables;

use App\Enums\SupplierInvoiceStatus;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a DRAFT supplier invoice.
 *
 * CANCELLED and REVERSED are different, as everywhere in this system: a cancelled invoice
 * never reached the books, a reversed one did and was undone. Neither deletes anything.
 */
class CancelSupplierInvoice
{
    public function handle(SupplierInvoice $invoice, ?int $userId = null): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice): SupplierInvoice {
            $locked = SupplierInvoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canCancel()) {
                throw ValidationException::withMessages([
                    'status' => __('Only a draft supplier invoice can be cancelled. :reference is :status. A posted invoice must be reversed.', [
                        'reference' => $locked->internal_invoice_number,
                        'status' => $locked->status->label(),
                    ]),
                ]);
            }

            $locked->update(['status' => SupplierInvoiceStatus::Cancelled]);

            return $locked->refresh();
        });
    }
}

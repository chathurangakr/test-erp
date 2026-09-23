<?php

namespace App\Http\Requests\Payables;

class StoreSupplierInvoiceRequest extends SupplierInvoiceRequest
{
    protected function invoiceId(): ?int
    {
        return null;
    }
}

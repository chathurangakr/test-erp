<?php

namespace App\Http\Requests\Payables;

class UpdateSupplierInvoiceRequest extends SupplierInvoiceRequest
{
    protected function invoiceId(): ?int
    {
        return $this->route('supplier_invoice')?->id;
    }
}

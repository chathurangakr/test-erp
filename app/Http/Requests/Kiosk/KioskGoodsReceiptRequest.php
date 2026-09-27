<?php

namespace App\Http\Requests\Kiosk;

use App\Http\Requests\Purchasing\StoreGoodsReceiptRequest;
use App\Support\BusinessClock;

/**
 * The full ERP's own validation, with one difference: the date is always today in the
 * factory's timezone. Simple mode records work as it happens; back-dated entries belong in
 * the full ERP. Whatever date a client sends is overwritten, not trusted.
 */
class KioskGoodsReceiptRequest extends StoreGoodsReceiptRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['receipt_date' => BusinessClock::todayString()]);

        // Optional on the kiosk; the full ERP always sends them.
        foreach (['transport_cost', 'loading_cost', 'other_cost'] as $cost) {
            if (blank($this->input($cost))) {
                $this->merge([$cost => '0']);
            }
        }
    }
}

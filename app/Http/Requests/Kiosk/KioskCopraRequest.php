<?php

namespace App\Http\Requests\Kiosk;

use App\Http\Requests\Production\StoreCopraRequest;
use App\Support\BusinessClock;

/**
 * The full ERP's own validation, with one difference: the date is always today in the
 * factory's timezone. Simple mode records work as it happens; back-dated entries belong in
 * the full ERP. Whatever date a client sends is overwritten, not trusted.
 */
class KioskCopraRequest extends StoreCopraRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['production_date' => BusinessClock::todayString()]);
    }
}

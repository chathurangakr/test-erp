<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base currency
    |--------------------------------------------------------------------------
    |
    | Version 1 keeps every book in one currency. It lives here rather than as a
    | literal in business logic, so introducing a second currency later is a
    | schema and configuration change rather than a hunt through the codebase.
    |
    | Multi-currency is TBD — see docs/ACCOUNTING_RULES.md §9.
    |
    */

    'base_currency' => env('ACCOUNTING_BASE_CURRENCY', 'LKR'),

    /*
    |--------------------------------------------------------------------------
    | Monetary scale
    |--------------------------------------------------------------------------
    |
    | Decimal places stored for every accounting amount. It matches the inventory
    | ledger's cost scale on purpose: when Finance later posts inventory values,
    | the authoritative `total_cost` must transfer without rounding.
    |
    */

    'scale' => 6,

    /*
    |--------------------------------------------------------------------------
    | Money people type, pay and are billed
    |--------------------------------------------------------------------------
    |
    | Rupees and cents: prices, payments, invoice amounts and the labour rate are typed
    | to at most this many decimals, and amounts owed between the factory and others —
    | a delivery's sales value, a goods receipt's material cost — are rounded to it
    | (half up), so every bill can be settled exactly. Inventory COSTS keep the full
    | `scale` above; screens show every amount to this many places.
    |
    */

    'money_decimals' => 2,

    /*
    |--------------------------------------------------------------------------
    | Journal numbering
    |--------------------------------------------------------------------------
    */

    'journal_prefix' => 'JV',

    /*
    |--------------------------------------------------------------------------
    | Retained earnings account
    |--------------------------------------------------------------------------
    |
    | The balance sheet shows this account's ACTUAL ledger balance beside the
    | computed Current Earnings line, so the two are never confused. Nothing
    | posts to it automatically: profit is moved into retained earnings by a
    | year-end closing entry, which is not implemented.
    |
    | Configuration rather than a literal, because a factory may renumber its
    | chart. See docs/ACCOUNTING_RULES.md §24.
    |
    */

    'retained_earnings_code' => env('ACCOUNTING_RETAINED_EARNINGS_CODE', '3200'),

];

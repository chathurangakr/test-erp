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
    | Journal numbering
    |--------------------------------------------------------------------------
    */

    'journal_prefix' => 'JV',

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic master codes
    |--------------------------------------------------------------------------
    |
    | New suppliers and customers are numbered automatically: the prefix, a
    | dash and the next number after the highest one in use, zero-padded to
    | `code_digits` (SUP-001 … SUP-029 … SUP-1000). Existing codes are kept;
    | a code can still be corrected on the edit screen. See
    | App\Support\SequentialCode.
    |
    */

    'supplier_code_prefix' => env('SUPPLIER_CODE_PREFIX', 'SUP'),

    'customer_code_prefix' => env('CUSTOMER_CODE_PREFIX', 'CUS'),

    /*
    | The warehouse that counts returnable bags while customers hold them. Created on first use.
    */
    'customer_custody_warehouse_code' => env('CUSTOMER_CUSTODY_WAREHOUSE_CODE', 'WITH-CUSTOMERS'),

    'code_digits' => 3,

];

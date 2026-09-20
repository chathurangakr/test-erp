<?php

namespace App\Http\Requests\Production;

/**
 * Creating a draft husk chipping order.
 *
 * @see HuskChippingRequest for the rules and why output quantities are accepted here but
 *      costs never are.
 */
class StoreHuskChippingRequest extends HuskChippingRequest {}

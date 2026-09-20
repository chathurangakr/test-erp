<?php

namespace App\Http\Requests\Production;

/**
 * Editing a draft husk chipping order.
 *
 * Only a DRAFT reaches here — the controller refuses anything else — so rewriting the
 * measured weights moves no stock.
 *
 * @see HuskChippingRequest
 */
class UpdateHuskChippingRequest extends HuskChippingRequest {}

<?php

namespace App\Http\Requests\Production;

/**
 * Editing a draft copra production order.
 *
 * Only a DRAFT reaches here — the controller refuses anything else — so re-entering the
 * measured weights moves no stock.
 *
 * @see CopraRequest
 */
class UpdateCopraRequest extends CopraRequest {}

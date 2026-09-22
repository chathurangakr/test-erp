<?php

namespace App\Http\Requests\Production;

/**
 * Creating a draft copra production order.
 *
 * @see CopraRequest for why the copra weight is submitted, the shell count is refused, and
 *      the shell weight is required.
 */
class StoreCopraRequest extends CopraRequest {}

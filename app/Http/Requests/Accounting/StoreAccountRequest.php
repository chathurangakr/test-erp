<?php

namespace App\Http\Requests\Accounting;

class StoreAccountRequest extends AccountRequest
{
    protected function accountId(): ?int
    {
        return null;
    }
}

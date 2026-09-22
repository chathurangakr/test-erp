<?php

namespace App\Http\Requests\Accounting;

class UpdateAccountRequest extends AccountRequest
{
    protected function accountId(): ?int
    {
        return $this->route('account')?->id;
    }
}

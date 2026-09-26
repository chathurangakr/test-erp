<?php

namespace App\Actions\Cash;

use App\Enums\FundTransferStatus;
use App\Models\FundTransfer;
use App\Support\DecimalMath;
use Illuminate\Support\Carbon;

/**
 * Creates a draft fund transfer.
 *
 * The one implementation behind both the website and the API. There is no update: a transfer
 * is three facts — from, to, how much — so a wrong draft is cancelled and replaced, and a
 * wrong posted one is reversed. Input is expected to have passed StoreFundTransferRequest.
 */
class SaveFundTransfer
{
    public function __construct(private readonly GenerateCashReference $references) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?int $userId = null): FundTransfer
    {
        return FundTransfer::create([
            'transfer_number' => $this->references->forFundTransfer(Carbon::parse($data['transfer_date'])),
            'transfer_date' => $data['transfer_date'],
            'from_cash_bank_account_id' => $data['from_cash_bank_account_id'],
            'to_cash_bank_account_id' => $data['to_cash_bank_account_id'],
            'amount' => DecimalMath::cost(DecimalMath::of((string) $data['amount'])),
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => FundTransferStatus::Draft,
            'created_by' => $userId ?? auth()->id(),
        ])->refresh();
    }
}

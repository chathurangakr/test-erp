<?php

namespace App\Actions\Cash;

use App\Enums\ExpenseVoucherStatus;
use App\Models\ExpenseVoucher;
use App\Support\DecimalMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft expense voucher, or replaces the contents of an existing draft.
 *
 * The one implementation behind both the website and the API. Input is expected to have
 * passed StoreExpenseVoucherRequest / UpdateExpenseVoucherRequest. A draft writes no journal;
 * only a DRAFT may be edited — a posted voucher is corrected by reversal.
 */
class SaveExpenseVoucher
{
    public function __construct(private readonly GenerateCashReference $references) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?ExpenseVoucher $voucher, array $data, ?int $userId = null): ExpenseVoucher
    {
        if ($voucher !== null) {
            $this->assertEditable($voucher);
        }

        return DB::transaction(function () use ($voucher, $data, $userId): ExpenseVoucher {
            $header = [
                'voucher_date' => $data['voucher_date'],
                'cash_bank_account_id' => $data['cash_bank_account_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'payee_name' => $data['payee_name'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
            ];

            if ($voucher === null) {
                $voucher = ExpenseVoucher::create($header + [
                    'voucher_number' => $this->references->forExpenseVoucher(Carbon::parse($data['voucher_date'])),
                    'status' => ExpenseVoucherStatus::Draft,
                    'created_by' => $userId ?? auth()->id(),
                ]);
            } else {
                $voucher->update($header);

                // A draft's lines are replaced wholesale; nothing has been posted from them.
                $voucher->lines()->delete();
            }

            foreach ($data['lines'] as $line) {
                $voucher->lines()->create([
                    'expense_account_id' => $line['expense_account_id'],
                    'description' => $line['description'] ?? null,
                    'amount' => DecimalMath::cost(DecimalMath::of((string) $line['amount'])),
                ]);
            }

            return $voucher->refresh();
        });
    }

    public function assertEditable(ExpenseVoucher $voucher): void
    {
        if (! $voucher->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft expense voucher can be edited. :reference is :status — reverse it instead.', [
                    'reference' => $voucher->voucher_number,
                    'status' => $voucher->status->label(),
                ]),
            ]);
        }
    }
}

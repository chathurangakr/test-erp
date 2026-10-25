<?php

namespace App\Actions\Cash;

use App\Models\BankReconciliation;
use App\Services\Accounting\BankReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes the latest reconciliation of a bank account: it is kept, marked reversed, and its lines
 * are free to be ticked again. Only the latest — statements are reconciled in order.
 * See docs/ACCOUNTING_RULES.md §36.
 */
class ReverseBankReconciliation
{
    public function __construct(private readonly BankReconciliationService $service) {}

    public function handle(BankReconciliation $reconciliation, ?int $userId = null): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation, $userId): BankReconciliation {
            $locked = BankReconciliation::query()->whereKey($reconciliation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== BankReconciliation::COMPLETED || $this->service->latest($locked->cashBankAccount)?->isNot($locked)) {
                throw ValidationException::withMessages(['status' => __('Only the latest reconciliation of an account can be undone.')]);
            }

            $locked->update(['status' => BankReconciliation::REVERSED, 'reversed_at' => now(), 'reversed_by' => $userId ?? auth()->id()]);

            return $locked->refresh();
        });
    }
}

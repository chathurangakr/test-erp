<?php

namespace App\Http\Controllers\Cash;

use App\Actions\Cash\CancelExpenseVoucher;
use App\Actions\Cash\PostExpenseVoucher;
use App\Actions\Cash\ReverseExpenseVoucher;
use App\Http\Controllers\Controller;
use App\Models\ExpenseVoucher;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The expense voucher lifecycle: post, reverse, cancel.
 */
class ExpenseVoucherActionController extends Controller
{
    public function post(ExpenseVoucher $expenseVoucher, PostExpenseVoucher $action): RedirectResponse
    {
        $action->handle($expenseVoucher, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Expense voucher posted.')]);

        return to_route('expense-vouchers.show', $expenseVoucher);
    }

    public function reverse(ExpenseVoucher $expenseVoucher, ReverseExpenseVoucher $action): RedirectResponse
    {
        $action->handle($expenseVoucher, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Expense voucher reversed.')]);

        return to_route('expense-vouchers.show', $expenseVoucher);
    }

    public function cancel(ExpenseVoucher $expenseVoucher, CancelExpenseVoucher $action): RedirectResponse
    {
        $action->handle($expenseVoucher, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft expense voucher cancelled.')]);

        return to_route('expense-vouchers.show', $expenseVoucher);
    }
}

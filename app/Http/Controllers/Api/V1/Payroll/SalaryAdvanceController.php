<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Actions\Payroll\RecordSalaryAdvance;
use App\Actions\Payroll\ReverseSalaryAdvance;
use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Api\V1\Concerns\RecordsOfflineEntries;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\StoreSalaryAdvanceRequest;
use App\Http\Resources\V1\Payroll\SalaryAdvanceResource;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Services\Accounting\CashDocumentOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Salary advances over the API.
 *
 * Paying one moves money in a single step, so `store` requires an Idempotency-Key: a request
 * the phone sends twice pays the advance ONCE and replays it the second time (docs/API_V1.md
 * §17 and §19). Corrected only by reversal.
 */
class SalaryAdvanceController extends Controller
{
    use PaginatesResults;
    use RecordsOfflineEntries;

    private const DETAIL = [
        'employee:id,code,name',
        'cashBankAccount:id,display_name',
        'journalEntry.lines.account:id,code,name',
        'reversalJournalEntry:id,journal_number',
        'createdBy:id,name',
        'reversedBy:id,name',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $advances = SalaryAdvance::query()
            ->with(['employee:id,code,name', 'cashBankAccount:id,display_name'])
            ->when($request->integer('employee_id'), fn (Builder $q, int $id) => $q->where('employee_id', $id))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->date('from'), fn (Builder $q, $d) => $q->whereDate('advance_date', '>=', $d))
            ->when($request->date('to'), fn (Builder $q, $d) => $q->whereDate('advance_date', '<=', $d))
            ->orderByDesc('advance_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return SalaryAdvanceResource::collection($advances);
    }

    /**
     * What a new advance may use — exactly what the web form offers.
     */
    public function options(CashDocumentOptions $options): JsonResponse
    {
        return response()->json(['data' => [
            'employees' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'cash_bank_accounts' => $options->cashBankAccounts(),
            'payment_methods' => StoreSalaryAdvanceRequest::paymentMethodOptions(),
        ]]);
    }

    public function show(SalaryAdvance $salaryAdvance): SalaryAdvanceResource
    {
        return new SalaryAdvanceResource($salaryAdvance->load(self::DETAIL));
    }

    public function store(Request $request, RecordSalaryAdvance $action): JsonResponse
    {
        return $this->recordOnce(
            $request,
            'salary-advances',
            Permission::PayrollAdvancePay,
            StoreSalaryAdvanceRequest::class,
            fn (array $data, int $userId): SalaryAdvance => $action->handle($data, $userId),
            fn (SalaryAdvance $advance): SalaryAdvanceResource => new SalaryAdvanceResource($advance->load(self::DETAIL)),
        );
    }

    public function reverse(Request $request, SalaryAdvance $salaryAdvance, ReverseSalaryAdvance $action): SalaryAdvanceResource
    {
        return new SalaryAdvanceResource($action->handle($salaryAdvance, $request->user()->id)->load(self::DETAIL));
    }
}

<?php

namespace App\Http\Controllers\Cash;

use App\Actions\Cash\BounceCheque;
use App\Actions\Cash\MarkChequeCleared;
use App\Actions\Cash\UndoChequeCleared;
use App\Enums\ChequeStatus;
use App\Http\Controllers\Controller;
use App\Services\Accounting\ChequeRegister;
use App\Support\BusinessClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance → Cheques: every cheque received or issued, with where it stands, and the buttons to
 * mark one cleared or bounced. A cheque is named by its document as "Kind:id" (CustomerReceipt,
 * CustomerAdvance, SupplierPayment, SupplierAdvance). See docs/ACCOUNTING_RULES.md §35.
 */
class ChequeController extends Controller
{
    public function __construct(private readonly ChequeRegister $register) {}

    public function index(Request $request): Response
    {
        $direction = in_array($request->string('direction')->toString(), ['RECEIVED', 'ISSUED'], true) ? $request->string('direction')->toString() : null;
        $status = ChequeStatus::tryFrom($request->string('status')->toString());

        return Inertia::render('finance/cheques/Index', [
            'cheques' => $this->register->cheques($direction, $status),
            'filters' => ['direction' => $direction, 'status' => $status?->value],
            'statuses' => array_map(fn (ChequeStatus $case): array => ['value' => $case->value, 'label' => $case->label()], ChequeStatus::cases()),
            'today' => BusinessClock::todayString(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function clear(Request $request, MarkChequeCleared $action): RedirectResponse
    {
        [$type, $id] = $this->cheque($request);
        $action->handle($type, $id, $request->validate(['event_date' => ['required', 'date_format:Y-m-d']])['event_date'], auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cheque marked cleared.')]);

        return back();
    }

    public function undoClear(Request $request, UndoChequeCleared $action): RedirectResponse
    {
        [$type, $id] = $this->cheque($request);
        $action->handle($type, $id, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The cheque is waiting again.')]);

        return back();
    }

    public function bounce(Request $request, BounceCheque $action): RedirectResponse
    {
        [$type, $id] = $this->cheque($request);
        $data = $request->validate(['event_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:255']]);
        $action->handle($type, $id, $data['event_date'], $data['reason'], auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cheque marked bounced. Its document was reversed.')]);

        return back();
    }

    /**
     * @return array{0: class-string, 1: int}
     */
    private function cheque(Request $request): array
    {
        $kinds = collect(array_keys(ChequeRegister::SOURCES))->mapWithKeys(fn (string $class): array => [class_basename($class) => $class]);
        $key = $request->validate(['cheque' => ['required', 'string', 'regex:/^('.$kinds->keys()->implode('|').'):\d+$/']])['cheque'];
        [$kind, $id] = explode(':', $key);

        return [$kinds[$kind], (int) $id];
    }
}

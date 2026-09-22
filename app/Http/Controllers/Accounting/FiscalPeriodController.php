<?php

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\CloseFiscalPeriod;
use App\Enums\FiscalPeriodStatus;
use App\Enums\JournalEntryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreFiscalPeriodRequest;
use App\Models\FiscalPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fiscal periods.
 *
 * Periods are created and closed; they are never deleted, and reopening is deliberately not
 * offered — a closed period is the record of something already reported on.
 */
class FiscalPeriodController extends Controller
{
    public function index(Request $request): Response
    {
        $periods = FiscalPeriod::query()
            ->withCount([
                'journalEntries',
                'journalEntries as draft_entries_count' => fn ($query) => $query->where('status', JournalEntryStatus::Draft),
            ])
            ->with('closedBy:id,name')
            ->search((string) $request->string('search'))
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (FiscalPeriod $period): array => [
                'id' => $period->id,
                'name' => $period->name,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
                'status' => $period->status->value,
                'status_label' => $period->status->label(),
                'closed_at' => $period->closed_at?->toDateTimeString(),
                'closed_by' => $period->closedBy?->name,
                'entries_count' => $period->journal_entries_count,
                'draft_entries_count' => $period->draft_entries_count,
                'can_close' => $period->isOpen() && $period->draft_entries_count === 0,
            ]);

        return Inertia::render('finance/fiscal-periods/Index', [
            'periods' => $periods,
            'statuses' => FiscalPeriodStatus::options(),
            'filters' => ['search' => (string) $request->string('search')],
        ]);
    }

    public function store(StoreFiscalPeriodRequest $request): RedirectResponse
    {
        FiscalPeriod::create(array_merge($request->validated(), [
            'status' => FiscalPeriodStatus::Open,
        ]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fiscal period opened.')]);

        return to_route('fiscal-periods.index');
    }

    public function close(FiscalPeriod $fiscalPeriod, CloseFiscalPeriod $action): RedirectResponse
    {
        $action->handle($fiscalPeriod, auth()->id());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Fiscal period :period closed.', ['period' => $fiscalPeriod->name]),
        ]);

        return to_route('fiscal-periods.index');
    }
}

<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Accounting\CashBankBookService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the money is, as at a date — read only.
 *
 * Every balance is derived from posted journal lines by the same service the cash book uses,
 * so the position and the book can never disagree.
 */
class CashBankPositionController extends Controller
{
    public function __construct(private readonly CashBankBookService $cashBank) {}

    public function index(Request $request): Response
    {
        $asOf = $request->date('as_of');
        $asOfDate = $asOf === null ? null : Carbon::parse($asOf);

        return Inertia::render('finance/reports/CashBankPosition', [
            'summary' => $this->cashBank->summary($asOfDate, activeOnly: ! $request->boolean('show_inactive')),
            'filters' => [
                'as_of' => $asOfDate?->toDateString(),
                'show_inactive' => $request->boolean('show_inactive'),
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}

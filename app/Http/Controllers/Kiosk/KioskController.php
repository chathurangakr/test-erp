<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetKioskLocale;
use App\Services\Inventory\StockQueryService;
use App\Services\Kiosk\KioskOptions;
use App\Services\Kiosk\KioskTasks;
use App\Support\DecimalMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Simple mode — the home tiles, the stock screen and the language switch.
 *
 * See docs/KIOSK.md. The job screens live in KioskEntryController.
 */
class KioskController extends Controller
{
    /**
     * Settings every simple-mode screen needs: language, idle sign-out, today's date.
     *
     * @return array<string, mixed>
     */
    public static function shared(): array
    {
        return [
            'kiosk' => [
                'locale' => App::getLocale(),
                'locales' => config('kiosk.locales'),
                'idle_minutes' => (int) config('kiosk.idle_minutes'),
            ],
        ];
    }

    public function home(Request $request, KioskTasks $tasks): Response
    {
        return Inertia::render('kiosk/Home', self::shared() + [
            'tasks' => $tasks->availableTo($request->user()),
        ]);
    }

    public function stock(KioskOptions $options, StockQueryService $stock): Response
    {
        $warehouses = collect($options->warehouses())->keyBy('id');

        $products = collect($options->dispatchableProducts())->map(fn (array $product): array => [
            'id' => $product['id'],
            'code' => $product['code'],
            'name' => $product['name'],
            'uom' => $product['uom'],
            'secondary_uom' => $product['secondary_uom'],
            'total' => DecimalMath::quantity($stock->quantityForProduct($product['id'])),
            'secondary_total' => $product['secondary_uom'] === null
                ? null
                : DecimalMath::quantity($stock->secondaryQuantityForProduct($product['id'])),
            'stores' => collect($product['stock'])->map(fn (array $row): array => [
                'name' => $warehouses->get($row['warehouse_id'])['name'] ?? '—',
                'quantity' => $row['quantity'],
            ])->values()->all(),
        ])->values();

        return Inertia::render('kiosk/Stock', self::shared() + ['products' => $products]);
    }

    public function language(Request $request): RedirectResponse
    {
        $validated = $request->validate(['locale' => ['required', Rule::in(config('kiosk.locales'))]]);

        // A year: each shared tablet keeps its language until someone changes it.
        Cookie::queue(SetKioskLocale::COOKIE, $validated['locale'], 60 * 24 * 365);

        return back();
    }
}

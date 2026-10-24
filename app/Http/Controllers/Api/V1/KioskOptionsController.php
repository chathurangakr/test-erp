<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductionType;
use App\Http\Controllers\Controller;
use App\Models\ProductionRecipe;
use App\Services\Kiosk\KioskOptions;
use App\Services\Kiosk\KioskTasks;
use App\Support\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the offline phone app needs to draw simple mode's screens, downloaded at each
 * sync and kept on the phone: the jobs this person may do, the choices each job offers, and
 * what the recipes say (which outputs the ERP derives, which dispatches need raw husks used,
 * the default labour rate) — so the app never hard-codes a product code. See docs/API_V1.md §18.
 *
 * Read-only, and the same data simple mode's own pages are built from (KioskOptions,
 * KioskTasks). Like /dashboard it declares no single permission: each section is included only
 * when the person may do a job that needs it, decided on the server.
 */
class KioskOptionsController extends Controller
{
    private const RECIPES = [
        'dehusking' => ProductionType::Dehusking,
        'husk-chipping' => ProductionType::HuskChipping,
        'copra' => ProductionType::Copra,
    ];

    public function __invoke(Request $request, KioskTasks $kioskTasks, KioskOptions $options): JsonResponse
    {
        $tasks = $kioskTasks->availableTo($request->user());
        $may = fn (string $task): bool => in_array($task, $tasks, true);
        $anyJob = $tasks !== [];

        $recipes = [];
        $variants = [];

        foreach (self::RECIPES as $task => $type) {
            $recipe = $may($task) ? ProductionRecipe::forType($type) : null;
            // The original input's recipe, as before sizes existed…
            $recipes[$task] = $recipe === null ? null : $options->production($recipe);
            // …and every size's, one per input product. Send the chosen one's input id as
            // `input_product_id` when recording.
            $variants[$task] = $recipe === null ? null : $options->productionVariants($type);
        }

        return response()->json([
            'data' => [
                'as_of' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'business_today' => BusinessClock::todayString(),
                'tasks' => $tasks,
                'suppliers' => $may('receive') ? $options->suppliers() : null,
                'customers' => $may('dispatch') ? $options->customers() : null,
                'warehouses' => $anyJob ? $options->warehouses() : null,
                'products' => $anyJob ? $options->catalogue() : null,
                'recipes' => $recipes,
                'recipe_variants' => $variants,
            ],
        ]);
    }
}

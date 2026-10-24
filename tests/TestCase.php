<?php

namespace Tests;

use App\Enums\ProductionType;
use App\Models\Product;
use App\Models\ProductionRecipe;
use App\Models\UnitOfMeasure;
use Database\Seeders\ProductionRecipeSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * The moment every test runs at, unless it travels elsewhere itself.
     *
     * The scenarios are written around September 2026 — that is when their fiscal periods,
     * invoice due dates and receipts fall. Pinning the clock keeps the suite's answers the same
     * on every day it is run; otherwise "today" drifts out of those periods and reversals, overdue
     * checks and aging change meaning. 10:00 UTC is mid-afternoon in Sri Lanka, safely clear of
     * the midnight boundary that BusinessClockTest examines on purpose.
     */
    public const FIXED_NOW = '2026-09-20 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::FIXED_NOW, 'UTC'));
    }

    /**
     * Seed the production recipes, first creating any recipe product a test did not.
     *
     * Tests create the products they examine with factories (lot-tracked, unless they say
     * otherwise). The recipes name more products than most tests care about — Cocopeat and
     * the litre stock items among them — so those are created here from the seeded master
     * data, with its units and lot policy, rather than in every test.
     */
    protected function seedProductionRecipes(): void
    {
        $units = [
            'NOS' => ['name' => 'Numbers', 'decimal_places' => 0],
            'KG' => ['name' => 'Kilogram', 'decimal_places' => 3],
            'L' => ['name' => 'Litre', 'decimal_places' => 3],
        ];

        foreach (ProductSeeder::PRODUCTS as $definition) {
            if (Product::query()->where('code', $definition['code'])->exists()) {
                continue;
            }

            $uom = fn (string $code): UnitOfMeasure => UnitOfMeasure::query()->firstOrCreate(
                ['code' => $code],
                $units[$code] + ['is_active' => true],
            );

            Product::factory()->create([
                'code' => $definition['code'],
                'name' => $definition['name'],
                'primary_uom_id' => $uom($definition['primary'])->id,
                'secondary_uom_id' => $definition['secondary'] === null ? null : $uom($definition['secondary'])->id,
                'tracks_lots' => $definition['lots'],
            ]);
        }

        $this->seed(ProductionRecipeSeeder::class);
    }

    /**
     * Give the active copra recipe a value split between its outputs, in sort order.
     *
     * The real recipe gives the copra everything and the shell nothing (version 3). Tests of
     * the allocation mechanics — rounding, per-count shell costing, journals with two debit
     * lines — need a recipe that actually splits, so they configure one here, exactly as the
     * factory could by seeding a new version.
     */
    protected function splitCopraValue(string ...$percents): void
    {
        $recipe = ProductionRecipe::forType(ProductionType::Copra);

        foreach ($recipe->outputs()->orderBy('sort_order')->get() as $index => $output) {
            $output->update(['allocation_percent' => $percents[$index]]);
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copra recipe version 2: the shell weight becomes optional (the factory has no scale for shell).
 *
 * Recipes are versioned, never edited: on a database that already has the active copra recipe,
 * this copies it as the next version with no output requiring a weight, makes the copy the one
 * active copra recipe, and leaves the old version — and every run that used it — untouched. A
 * fresh database has no recipe yet; ProductionRecipeSeeder seeds version 2 directly. No product
 * code is named here: the copy is made from whatever the active recipe holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        $current = DB::table('production_recipes')
            ->where('production_type', 'COPRA')
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        $needsWeight = $current !== null && DB::table('production_recipe_outputs')
            ->where('production_recipe_id', $current->id)
            ->where('requires_secondary_quantity', true)
            ->exists();

        if (! $needsWeight) {
            return;
        }

        DB::transaction(function () use ($current): void {
            $now = now();
            $copy = (array) $current;
            unset($copy['id']);
            $copy['version'] = $current->version + 1;
            $copy['notes'] = trim(($current->notes ?? '').' Shell weight optional from version '.$copy['version'].'.');
            $copy['created_at'] = $now;
            $copy['updated_at'] = $now;

            $newId = DB::table('production_recipes')->insertGetId($copy);

            foreach (DB::table('production_recipe_outputs')->where('production_recipe_id', $current->id)->get() as $output) {
                $row = (array) $output;
                unset($row['id']);
                $row['production_recipe_id'] = $newId;
                $row['requires_secondary_quantity'] = false;
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
                DB::table('production_recipe_outputs')->insert($row);
            }

            DB::table('production_recipes')->where('id', $current->id)->update(['is_active' => false, 'updated_at' => $now]);
        });
    }

    public function down(): void
    {
        // Recipe versions are permanent history; the change is undone by seeding a new version.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copra recipe version 3: the copra carries the whole coconut value and the shell none of it
 * (confirmed 2026-10-24 — the factory buys coconuts, not shell, so the shell comes free).
 *
 * Recipes are versioned, never edited: on a database whose active copra recipe still gives the
 * shell a share, this copies it as the next version with the largest share (the copra) raised to
 * 100% and every other output at 0%, makes the copy the one active copra recipe, and leaves the
 * old version — and every run that used it — untouched. A fresh database has no recipe yet;
 * ProductionRecipeSeeder seeds version 3 directly. No product code is named here.
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

        if ($current === null) {
            return;
        }

        $outputs = DB::table('production_recipe_outputs')
            ->where('production_recipe_id', $current->id)
            ->orderByDesc('allocation_percent')
            ->orderBy('sort_order')
            ->get();

        $alreadyWhole = $outputs->isNotEmpty() && bccomp((string) $outputs->first()->allocation_percent, '100', 6) === 0;

        if ($outputs->isEmpty() || $alreadyWhole) {
            return;
        }

        DB::transaction(function () use ($current, $outputs): void {
            $now = now();
            $copy = (array) $current;
            unset($copy['id']);
            $copy['version'] = $current->version + 1;
            $copy['notes'] = 'Copra carries the coconut value; the shell comes free.';
            $copy['created_at'] = $now;
            $copy['updated_at'] = $now;

            $newId = DB::table('production_recipes')->insertGetId($copy);

            foreach ($outputs as $index => $output) {
                $row = (array) $output;
                unset($row['id']);
                $row['production_recipe_id'] = $newId;
                $row['allocation_percent'] = $index === 0 ? '100.000000' : '0.000000';
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

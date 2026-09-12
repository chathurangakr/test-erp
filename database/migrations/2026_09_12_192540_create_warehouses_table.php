<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Warehouse master.
     *
     * Inventory movements will require a warehouse reference, so the ERP is multi-warehouse
     * from the start even though Version 1 operates a single default warehouse.
     *
     * The "only one active default warehouse" rule is enforced transactionally in
     * App\Actions\Warehouses\SetDefaultWarehouse rather than by a database constraint:
     * MariaDB has no partial unique index and SQLite's syntax differs, so a database-level
     * rule would make the test schema weaker than production. See docs/DATA_MODEL.md D-2.
     */
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('is_active');
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};

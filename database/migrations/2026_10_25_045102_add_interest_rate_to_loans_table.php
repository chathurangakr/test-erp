<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The loan's interest rate, % a year on what is still owed (2026-10-25, the owner's choice). It only
 * SUGGESTS each payment's interest (still owed × rate ÷ 12); the interest typed from the bank slip
 * is what is recorded. Editable, because banks change rates. See docs/LOANS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('interest_rate', 7, 4)->nullable()->after('has_interest');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('interest_rate');
        });
    }
};

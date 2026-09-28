<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each person's screen language for the full ERP (en or si).
 *
 * NULL means "the system default" (config('app.locale')), so nobody's screens change until
 * they choose. It affects only what is displayed — saved records are always English
 * (App\Support\RecordText). See docs/LOCALISATION.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('start_in_simple_mode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};

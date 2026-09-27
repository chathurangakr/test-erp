<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a user lands in simple mode (the big-button kiosk screens) after signing in.
 *
 * A preference, set by an administrator on the Users screen. It grants nothing: what the user
 * may do is still decided by their roles. Defaults to false, so nobody's start page changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('start_in_simple_mode')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('start_in_simple_mode');
        });
    }
};

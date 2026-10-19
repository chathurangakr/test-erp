<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single row naming the business: the name, address, contact details and logo printed on
 * invoices and reports.
 *
 * Data, not code, so each installation carries its own customer's identity. The logo is kept
 * in the row itself (a small data URI), so the nightly database backup and a restore carry it
 * with everything else — no separate file to lose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('address', 500)->nullable();
            $table->string('phone', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->mediumText('logo')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_profiles');
    }
};

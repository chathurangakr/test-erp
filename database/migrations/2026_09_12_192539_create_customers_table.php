<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer master.
     *
     * A single customer table covers every product the factory sells. `credit_limit` is a
     * decimal terms field, not a balance — outstanding receivables are derived from posted
     * transactions in a later module and must never be stored here.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('contact_person', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('secondary_phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address_line_1', 200)->nullable();
            $table->string('address_line_2', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('tax_number', 100)->nullable();
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->unsignedSmallInteger('credit_days')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

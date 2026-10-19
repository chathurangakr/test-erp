<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single row holding the outgoing email account (Administration → Email settings).
 *
 * The password is encrypted with the application key before it is stored, never shown back on
 * a screen, and travels with the database backup (restore.sh restores the key too). While
 * `is_enabled` is false the .env mail settings apply as before. See docs/WEEKLY_UPDATE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->string('host', 150);
            $table->unsignedSmallInteger('port');
            $table->string('encryption', 10);
            $table->string('username', 150)->nullable();
            $table->text('password')->nullable();
            $table->string('from_address', 150)->nullable();
            $table->string('from_name', 150)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};

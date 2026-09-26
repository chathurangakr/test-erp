<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based access control.
 *
 * A permission is an application capability — "post a goods receipt" — and the catalogue of
 * them lives in code (`App\Enums\Permission`). This table mirrors that catalogue so roles can
 * reference it with foreign keys; no screen may invent a permission code that no route
 * understands.
 *
 * A role is nothing more than a named bundle of permissions, and a user may hold several.
 * Authorization always asks about permissions, never about role names.
 * See docs/AUTHORIZATION_RULES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 150);
            $table->string('module', 50)->index();
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            // The same permission twice on one role is meaningless.
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            // Restrict, not cascade: a role still held by someone must never vanish silently.
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->primary(['role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};

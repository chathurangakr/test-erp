<?php

use App\Enums\Permission;
use App\Http\Controllers\Administration\RoleController;
use App\Http\Controllers\Administration\UserController;
use Illuminate\Support\Facades\Route;

/*
 * Administration — users and roles.
 *
 * Users have no create or destroy route: people join by registering (and hold no access until
 * an administrator grants a role), and a user with history is deactivated, never deleted.
 * A role may be deleted only while nobody holds it; DeleteRole enforces that.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('administration/users', UserController::class)
        ->names('users')
        ->only(['index', 'show', 'edit', 'update'])
        ->middlewareFor(['index', 'show'], Permission::UsersView->middleware())
        ->middlewareFor(['edit', 'update'], Permission::UsersManage->middleware());

    Route::resource('administration/roles', RoleController::class)
        ->names('roles')
        ->middlewareFor(['index', 'show'], Permission::RolesView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], Permission::RolesManage->middleware());
});

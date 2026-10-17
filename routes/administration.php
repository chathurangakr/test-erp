<?php

use App\Enums\Permission;
use App\Http\Controllers\Administration\RoleController;
use App\Http\Controllers\Administration\UserController;
use Illuminate\Support\Facades\Route;

/*
 * Administration — users and roles.
 *
 * Users have no destroy route: a user with history is deactivated, never deleted. People join
 * by registering (holding no access until an administrator grants a role) or are added by an
 * administrator here — the only way in when self-registration is closed.
 * A role may be deleted only while nobody holds it; DeleteRole enforces that.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('administration/users', UserController::class)
        ->names('users')
        ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
        ->middlewareFor(['index', 'show'], Permission::UsersView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update'], Permission::UsersManage->middleware());

    Route::resource('administration/roles', RoleController::class)
        ->names('roles')
        ->middlewareFor(['index', 'show'], Permission::RolesView->middleware())
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], Permission::RolesManage->middleware());
});

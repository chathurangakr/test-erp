<?php

use App\Enums\Permission;
use App\Http\Controllers\Administration\CompanyProfileController;
use App\Http\Controllers\Administration\MailSettingsController;
use App\Http\Controllers\Administration\RoleController;
use App\Http\Controllers\Administration\TelegramSettingsController;
use App\Http\Controllers\Administration\UserController;
use Illuminate\Support\Facades\Route;

/*
 * Administration — users, roles and the company details printed on documents.
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

    Route::get('administration/company', [CompanyProfileController::class, 'edit'])
        ->name('company.edit')
        ->can(Permission::CompanyManage);
    Route::put('administration/company', [CompanyProfileController::class, 'update'])
        ->name('company.update')
        ->can(Permission::CompanyManage);

    Route::get('administration/email', [MailSettingsController::class, 'edit'])
        ->name('mail-settings.edit')
        ->can(Permission::MailManage);
    Route::put('administration/email', [MailSettingsController::class, 'update'])
        ->name('mail-settings.update')
        ->can(Permission::MailManage);
    Route::post('administration/email/test', [MailSettingsController::class, 'test'])
        ->middleware('throttle:5,1,mail-test')
        ->name('mail-settings.test')
        ->can(Permission::MailManage);

    Route::get('administration/telegram', [TelegramSettingsController::class, 'edit'])
        ->name('telegram-settings.edit')
        ->can(Permission::TelegramManage);
    Route::put('administration/telegram', [TelegramSettingsController::class, 'update'])
        ->name('telegram-settings.update')
        ->can(Permission::TelegramManage);
    Route::post('administration/telegram/find-chats', [TelegramSettingsController::class, 'findChats'])
        ->middleware('throttle:10,1,telegram-find-chats')
        ->name('telegram-settings.find-chats')
        ->can(Permission::TelegramManage);
    Route::post('administration/telegram/test', [TelegramSettingsController::class, 'test'])
        ->middleware('throttle:5,1,telegram-test')
        ->name('telegram-settings.test')
        ->can(Permission::TelegramManage);
    Route::post('administration/telegram/monthly', [TelegramSettingsController::class, 'monthly'])
        ->middleware('throttle:5,1,telegram-monthly')
        ->name('telegram-settings.monthly')
        ->can(Permission::TelegramManage);
});

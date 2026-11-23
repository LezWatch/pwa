<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\SyncController;
use App\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;

// Loaded under the secret admin path, with the "admin" middleware group,
// only when ADMIN_PATH is set. See bootstrap/app.php.

Route::name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::get('login/google', [AuthController::class, 'redirect'])->name('google');
    Route::get('callback', [AuthController::class, 'callback'])->name('callback');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware(RequireAdmin::class)->group(function () {
        Route::get('/', [SyncController::class, 'index'])->name('dashboard');
        Route::post('sync', [SyncController::class, 'sync'])->name('sync');
    });
});

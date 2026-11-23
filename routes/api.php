<?php

use App\Http\Controllers\ShowController;
use Illuminate\Support\Facades\Route;

// Read-only catalogue for the PWA. No sessions or cookies (api middleware group).
Route::get('/shows', [ShowController::class, 'index']);

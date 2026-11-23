<?php

use App\Http\Controllers\ShowController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ShowController::class, 'page']);

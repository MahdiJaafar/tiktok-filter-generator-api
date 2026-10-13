<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FilterController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('filters', FilterController::class);
    Route::post('/filters/{filter}/publish', [FilterController::class, 'publish']);
});

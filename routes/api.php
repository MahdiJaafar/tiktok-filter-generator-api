<?php

use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\FilterController;
use App\Http\Controllers\Api\GalleryController;
use App\Http\Controllers\Api\LikeController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::get('/gallery', [GalleryController::class, 'index']);
Route::get('/gallery/{filter}', [GalleryController::class, 'show'])->whereNumber('filter');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/assets', [AssetController::class, 'store']);

    Route::apiResource('collections', CollectionController::class);
    Route::post('/collections/{collection}/filters/{filter}', [CollectionController::class, 'addFilter']);
    Route::delete('/collections/{collection}/filters/{filter}', [CollectionController::class, 'removeFilter']);

    Route::apiResource('filters', FilterController::class);
    Route::post('/filters/{filter}/publish', [FilterController::class, 'publish']);
    Route::post('/filters/{filter}/like', [LikeController::class, 'store']);
    Route::delete('/filters/{filter}/like', [LikeController::class, 'destroy']);
});

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProjetController;
use App\Http\Controllers\Api\DocumentController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Documents & Projets (tout utilisateur connecté)
    Route::apiResource('projets', ProjetController::class);
    Route::apiResource('documents', DocumentController::class);

    // Chef Supérieur valide et partage
    Route::post('/documents/{document}/validate-share', [DocumentController::class, 'validateAndShare'])
        ->middleware('check.superchef');

    // Admin + Responsable Admin → gestion utilisateurs
    Route::middleware('check.admin')->group(function () {
        Route::apiResource('users', UserController::class);
    });

    // Super Chef Terrain → gestion chefs terrain
    Route::middleware('check.superchef')->group(function () {
        Route::post('/users-chef', [UserController::class, 'storeChefTerrain']);
        Route::get('/users-chefs', [UserController::class, 'listChefs']);
    });
});

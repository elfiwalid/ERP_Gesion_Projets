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
    Route::get('/roles', [UserController::class, 'getRoles']);
    

    // Documents & Projets (tout utilisateur connecté)
    Route::apiResource('projets', ProjetController::class);
     // Documents (toutes les méthodes explicites)
    Route::get   ('/documents',                    [DocumentController::class, 'index']);
    Route::post  ('/documents',                    [DocumentController::class, 'store']);
    Route::get   ('/documents/{document}',         [DocumentController::class, 'show']);
    Route::put   ('/documents/{document}',         [DocumentController::class, 'update']);
    Route::delete('/documents/{document}',         [DocumentController::class, 'destroy']);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download']);

    // Décisions
    Route::post  ('/documents/{document}/admin-review', [DocumentController::class, 'adminReview']);
    Route::post  ('/documents/{document}/cts-review',   [DocumentController::class, 'ctsReview']);
/*

    // Chef Supérieur valide et partage
    Route::post('/documents/{document}/validate-share', [DocumentController::class, 'validateAndShare'])
        ->middleware('check.superchef');
*/
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

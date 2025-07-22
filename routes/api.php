<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProjetController;
use App\Http\Controllers\Api\DocumentController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Toutes les routes API sécurisées avec Sanctum.
|--------------------------------------------------------------------------
*/

// 🔑 Authentification
Route::post('/register', [AuthController::class, 'register']); // Création compte (optionnel, ou réservé admin)
Route::post('/login', [AuthController::class, 'login']);       // Connexion utilisateur

// 🔒 Routes protégées (token requis)
Route::middleware(['auth:sanctum'])->group(function () {

    // 👤 Profil connecté
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']); 

    // 🛡️ Admin Général + Responsable Administratif : Gestion utilisateurs
    Route::middleware('check.admin')->group(function () {
        Route::apiResource('users', UserController::class);
    });

    // 🛡️ Admin Général + Responsable Admin + Super Chef Terrain : Gestion projets/documents
    Route::middleware('check.superchef')->group(function () {
        Route::apiResource('projets', ProjetController::class);
        Route::apiResource('documents', DocumentController::class);

        // 🔧 Super Chef Terrain : Gestion spécifique des Chefs Terrain
        Route::post('/users-chef', [UserController::class, 'storeChefTerrain']);
        Route::get('/users-chefs', [UserController::class, 'listChefs']);
    });
});

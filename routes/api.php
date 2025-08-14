<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\DemandeController;
use App\Http\Controllers\Api\DemandeDocumentController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ProjetController;
use App\Http\Controllers\Api\PieceController;




Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/roles', [UserController::class, 'getRoles']);
    
    
    Route::post('/demandes', [DemandeController::class, 'store']);
    Route::get('/demandes',  [DemandeController::class, 'index']);
    Route::get('/demandes/{id}', [DemandeController::class, 'show']);

      // Documents de demande
    Route::post('/demandes/{demande}/documents', [DemandeDocumentController::class, 'store']);
    Route::post('/demande-documents/{id}/upload',  [DemandeDocumentController::class, 'upload']);
    Route::post('/demande-documents/{id}/valider', [DemandeDocumentController::class, 'valider']);
    Route::post('/demande-documents/{id}/refuser', [DemandeDocumentController::class, 'refuser']);
    Route::get('/demande-documents/mes-taches',     [DemandeDocumentController::class, 'mesTaches']);
    Route::get('/demandes/eligibles', [DemandeController::class, 'eligibles']);

    // alias pratique: éligibles pour UN client
    Route::get('/clients/{client}/demandes/eligibles', [DemandeController::class, 'eligiblesByClient']);


    

    // Clients
    Route::get('/clients',        [ClientController::class, 'index']);
    Route::post('/clients',       [ClientController::class, 'store']);
    Route::get('/clients/{id}',   [ClientController::class, 'show']);
    Route::match(['put','patch'], '/clients/{id}', [ClientController::class, 'update']);
    Route::delete('/clients/{id}',[ClientController::class, 'destroy']);

        // projets
    Route::get('/projets',      [ProjetController::class, 'index']);
    Route::get('/projets/{id}', [ProjetController::class, 'show']);
    Route::post('/projets',     [ProjetController::class, 'store']);


    
     // Pièces par projet
    Route::get('/projets/{projet}/pieces',  [PieceController::class, 'indexByProject']);
    Route::post('/projets/{projet}/pieces', [PieceController::class, 'storeForProject']);
    // Pièces (unitaires)
    Route::get('/pieces/{piece}',           [PieceController::class, 'show']);
    Route::patch('/pieces/{piece}/assign',  [PieceController::class, 'assign']);   // AdminG
    Route::post('/pieces/{piece}/upload',   [PieceController::class, 'upload']);   // Assigné
    Route::post('/pieces/{piece}/valider',  [PieceController::class, 'valider']);  // AdminG
    Route::post('/pieces/{piece}/refuser',  [PieceController::class, 'refuser']);  // AdminG
    Route::get('/pieces/{piece}/download',  [PieceController::class, 'download']); // AdminG ou Assigné
    Route::delete('/pieces/{piece}',        [PieceController::class, 'destroy']);  // AdminG (optionnel)

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

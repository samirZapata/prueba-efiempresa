<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\QueryController;

// Aplicar middleware de internacionalización a todas las rutas
Route::middleware(['setlocale'])->group(function () {
    
    // Rutas públicas (sin autenticación)
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    // Rutas protegidas (requieren autenticación JWT)
    Route::middleware(['auth:api'])->group(function () {
        
        // Autenticación
        Route::prefix('auth')->group(function () {
            Route::get('profile', [AuthController::class, 'profile']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('refresh', [AuthController::class, 'refresh']);
        });

        // Documentos
        Route::prefix('documents')->group(function () {
            Route::get('/', [DocumentController::class, 'index']);           // GET /api/documents
            Route::post('upload', [DocumentController::class, 'store']);     // POST /api/documents/upload
            Route::get('{id}', [DocumentController::class, 'show']);         // GET /api/documents/{id}
            Route::delete('{id}', [DocumentController::class, 'destroy']);   // DELETE /api/documents/{id}
            
            // Contenido de páginas específicas
            Route::get('{id}/pages/{page}', [DocumentController::class, 'getPageContent']); // GET /api/documents/{id}/pages/{page}
        });

        // Consultas semánticas
        Route::prefix('query')->group(function () {
            Route::post('/', [QueryController::class, 'search']);            // POST /api/query
            Route::get('stats', [QueryController::class, 'stats']);          // GET /api/query/stats
        });
    });

    // Ruta de salud de la API (pública)
    Route::get('health', function () {
        return response()->json([
            'success' => true,
            'message' => 'API funcionando correctamente',
            'data' => [
                'version' => '1.0.0',
                'timestamp' => now()->toISOString(),
                'locale' => app()->getLocale()
            ]
        ]);
    });
});
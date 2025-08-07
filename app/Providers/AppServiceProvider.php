<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use App\Services\PdfExtractionService;
use App\Services\EmbeddingService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Registrar servicios en el contenedor
     */
    public function register(): void
    {
        // Registrar servicio de extracción de PDFs
        $this->app->singleton(PdfExtractionService::class, function ($app) {
            return new PdfExtractionService();
        });

        // Registrar servicio de embeddings
        $this->app->singleton(EmbeddingService::class, function ($app) {
            return new EmbeddingService();
        });
    }

    /**
     * Bootstrap de servicios
     */
    public function boot(): void
    {
        //
    }
}

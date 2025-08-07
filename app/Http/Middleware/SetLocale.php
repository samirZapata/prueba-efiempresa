<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Idiomas soportados por la aplicación
     */
    private array $supportedLocales = ['es', 'en', 'fr', 'de', 'pt'];

    /**
     * Manejar request entrante
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Obtener idioma desde header Accept-Language
        $locale = $this->getLocaleFromAcceptLanguage($request);

        // Establecer locale en la aplicación
        App::setLocale($locale);

        // Agregar header de respuesta con el idioma usado
        $response = $next($request);
        
        if (method_exists($response, 'header')) {
            $response->header('Content-Language', $locale);
        }

        return $response;
    }

    /**
     * Extraer idioma preferido del header Accept-Language
     */
    private function getLocaleFromAcceptLanguage(Request $request): string
    {
        $acceptLanguage = $request->header('Accept-Language');

        if (!$acceptLanguage) {
            return config('app.locale', 'es'); // Default español
        }

        // Parsear header Accept-Language
        $languages = [];
        $parts = explode(',', $acceptLanguage);

        foreach ($parts as $part) {
            $part = trim($part);
            $quality = 1.0;

            // Verificar si hay valor de calidad (q=0.8)
            if (strpos($part, ';q=') !== false) {
                [$lang, $q] = explode(';q=', $part);
                $quality = floatval($q);
                $part = $lang;
            }

            // Extraer código de idioma (es-ES -> es)
            $langCode = strtolower(substr($part, 0, 2));

            if (in_array($langCode, $this->supportedLocales)) {
                $languages[$langCode] = $quality;
            }
        }

        if (empty($languages)) {
            return config('app.locale', 'es');
        }

        // Ordenar por calidad (mayor primero)
        arsort($languages);

        // Retornar el idioma con mayor calidad
        return array_key_first($languages);
    }
}
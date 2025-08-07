<?php

namespace App\Services;

use Spatie\PdfToText\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PdfExtractionService
{
    /**
     * Extraer texto completo de un PDF
     */
    public function extractTextFromPdf(string $filePath): array
    {
        try {
            // Verificar que el archivo existe
            if (!file_exists($filePath)) {
                throw new \Exception("Archivo PDF no encontrado: {$filePath}");
            }

            // Extraer texto completo usando spatie/pdf-to-text
            $fullText = Pdf::getText($filePath);

            // Dividir el texto en páginas (aproximadamente)
            // Nota: spatie/pdf-to-text no separa por páginas automáticamente
            // Para separación real por páginas, necesitaríamos smalot/pdfparser
            $pages = $this->splitTextIntoPages($fullText);

            Log::info("PDF procesado exitosamente", [
                'file' => $filePath,
                'total_pages' => count($pages),
                'total_characters' => strlen($fullText)
            ]);

            return [
                'success' => true,
                'pages' => $pages,
                'total_pages' => count($pages),
                'total_characters' => strlen($fullText)
            ];

        } catch (\Exception $e) {
            Log::error("Error extrayendo texto del PDF", [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'pages' => [],
                'total_pages' => 0
            ];
        }
    }

    /**
     * Dividir texto en páginas aproximadas
     * Cada página tendrá máximo 2000 caracteres para optimizar embeddings
     */
    private function splitTextIntoPages(string $text): array
    {
        // Limpiar el texto
        $text = $this->cleanText($text);

        // Si el texto es muy corto, una sola página
        if (strlen($text) <= 2000) {
            return [$text];
        }

        $pages = [];
        $maxCharsPerPage = 2000;

        // Dividir por saltos de página si existen
        $possiblePages = preg_split('/\n\s*\n\s*\n/', $text);

        foreach ($possiblePages as $chunk) {
            if (strlen($chunk) <= $maxCharsPerPage) {
                $pages[] = trim($chunk);
            } else {
                // Si el chunk es muy grande, dividirlo por párrafos
                $paragraphs = explode("\n\n", $chunk);
                $currentPage = "";

                foreach ($paragraphs as $paragraph) {
                    if (strlen($currentPage . $paragraph) <= $maxCharsPerPage) {
                        $currentPage .= ($currentPage ? "\n\n" : "") . $paragraph;
                    } else {
                        if ($currentPage) {
                            $pages[] = trim($currentPage);
                        }
                        $currentPage = $paragraph;
                    }
                }

                if ($currentPage) {
                    $pages[] = trim($currentPage);
                }
            }
        }

        // Filtrar páginas vacías
        return array_filter($pages, function ($page) {
            return strlen(trim($page)) > 10; // Mínimo 10 caracteres
        });
    }

    /**
     * Limpiar texto extraído del PDF
     */
    private function cleanText(string $text): string
    {
        // Remover caracteres problemáticos específicos del PDF
        $text = str_replace(['<E2>', '<C3>', '<A9>', '<AA>', '<BB>'], '', $text);

        // Remover cualquier carácter no UTF-8 válido
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Remover caracteres de control problemáticos
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Convertir caracteres problemáticos comunes
        $replacements = [
            'Ãras' => 'érase',
            'Ã±' => 'ñ',
            'Ã©' => 'é',
            'Ã¡' => 'á',
            'Ãº' => 'ú',
            'Ã³' => 'ó',
            'Ã­' => 'í',
        ];
        $text = str_replace(array_keys($replacements), array_values($replacements), $text);

        // Normalizar espacios en blanco
        $text = preg_replace('/\s+/', ' ', $text);

        // Normalizar saltos de línea
        $text = preg_replace('/\n+/', "\n", $text);

        return trim($text);
    }

    /**
     * Extraer keywords básicas del texto (para búsqueda híbrida)
     */
    public function extractKeywords(string $text): array
    {
        // Convertir a minúsculas y remover puntuación
        $text = strtolower($text);
        $text = preg_replace('/[^\w\s]/', ' ', $text);

        // Dividir en palabras
        $words = array_filter(explode(' ', $text));

        // Remover palabras muy cortas y comunes (stopwords básicas)
        $stopwords = [
            'el',
            'la',
            'de',
            'que',
            'y',
            'a',
            'en',
            'un',
            'es',
            'se',
            'no',
            'te',
            'lo',
            'le',
            'da',
            'su',
            'por',
            'son',
            'con',
            'para',
            'al',
            'the',
            'and',
            'or',
            'but',
            'in',
            'on',
            'at',
            'to',
            'for',
            'of',
            'with',
            'by'
        ];

        $keywords = array_filter($words, function ($word) use ($stopwords) {
            return strlen($word) > 3 && !in_array($word, $stopwords);
        });

        // Contar frecuencia y tomar las más comunes
        $wordCount = array_count_values($keywords);
        arsort($wordCount);

        return array_keys(array_slice($wordCount, 0, 20)); // Top 20 keywords
    }

    /**
     * Validar que el archivo es un PDF válido
     */
    public function isValidPdf(string $filePath): bool
    {
        try {
            $fileType = mime_content_type($filePath);
            return $fileType === 'application/pdf';
        } catch (\Exception $e) {
            return false;
        }
    }
}
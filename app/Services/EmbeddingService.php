<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class EmbeddingService
{
    /**
     * Modelo de embedding a usar (OpenAI)
     */
    private const EMBEDDING_MODEL = 'text-embedding-3-small';
    
    /**
     * Dimensiones del embedding (1536 para text-embedding-3-small)
     */
    private const EMBEDDING_DIMENSIONS = 1536;

    /**
     * Generar embedding para un texto usando OpenAI
     */
    public function generateEmbedding(string $text): array
    {
        try {
            // Limpiar y preparar el texto
            $cleanText = $this->prepareTextForEmbedding($text);
            
            // Verificar que el texto no esté vacío
            if (empty($cleanText)) {
                throw new \Exception("Texto vacío después de limpieza");
            }

            // Verificar longitud (OpenAI tiene límite de tokens)
            if (strlen($cleanText) > 8000) {
                $cleanText = substr($cleanText, 0, 8000);
                Log::warning("Texto truncado para embedding", ['original_length' => strlen($text)]);
            }

            // Generar embedding usando OpenAI
            $response = OpenAI::embeddings()->create([
                'model' => self::EMBEDDING_MODEL,
                'input' => $cleanText,
            ]);

            $embedding = $response->embeddings[0]->embedding;

            // Verificar que el embedding tenga las dimensiones correctas
            if (count($embedding) !== self::EMBEDDING_DIMENSIONS) {
                throw new \Exception("Embedding con dimensiones incorrectas: " . count($embedding));
            }

            Log::info("Embedding generado exitosamente", [
                'text_length' => strlen($cleanText),
                'embedding_dimensions' => count($embedding)
            ]);

            return [
                'success' => true,
                'embedding' => $embedding,
                'dimensions' => count($embedding),
                'model_used' => self::EMBEDDING_MODEL
            ];

        } catch (\Exception $e) {
            Log::error("Error generando embedding", [
                'error' => $e->getMessage(),
                'text_preview' => substr($text, 0, 100)
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'embedding' => [],
                'dimensions' => 0
            ];
        }
    }

    /**
     * Generar múltiples embeddings en batch (para mejorar eficiencia)
     */
    public function generateBatchEmbeddings(array $texts): array
    {
        try {
            // Preparar todos los textos
            $cleanTexts = array_map([$this, 'prepareTextForEmbedding'], $texts);
            
            // Filtrar textos vacíos
            $validTexts = array_filter($cleanTexts, function($text) {
                return !empty($text);
            });

            if (empty($validTexts)) {
                throw new \Exception("No hay textos válidos para procesar");
            }

            // Generar embeddings en batch
            $response = OpenAI::embeddings()->create([
                'model' => self::EMBEDDING_MODEL,
                'input' => array_values($validTexts),
            ]);

            $embeddings = [];
            foreach ($response->embeddings as $index => $embeddingData) {
                $embeddings[] = [
                    'success' => true,
                    'embedding' => $embeddingData->embedding,
                    'dimensions' => count($embeddingData->embedding),
                    'index' => $index
                ];
            }

            Log::info("Batch de embeddings generado", [
                'total_texts' => count($validTexts),
                'embeddings_generated' => count($embeddings)
            ]);

            return $embeddings;

        } catch (\Exception $e) {
            Log::error("Error en batch de embeddings", [
                'error' => $e->getMessage(),
                'texts_count' => count($texts)
            ]);

            return [];
        }
    }

    /**
     * Preparar texto para generar embedding
     */
    private function prepareTextForEmbedding(string $text): string
    {
        // Remover saltos de línea excesivos
        $text = preg_replace('/\n+/', ' ', $text);
        
        // Remover espacios múltiples
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Trim general
        return trim($text);
    }

    /**
     * Calcular similitud coseno entre dos vectores
     */
    public function cosineSimilarity(array $vector1, array $vector2): float
    {
        if (count($vector1) !== count($vector2)) {
            throw new \InvalidArgumentException("Los vectores deben tener la misma dimensión");
        }

        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] * $vector1[$i];
            $magnitude2 += $vector2[$i] * $vector2[$i];
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }

    /**
     * Convertir embedding para almacenamiento en PostgreSQL
     */
    public function embeddingToPostgresArray(array $embedding): string
    {
        return '[' . implode(',', $embedding) . ']';
    }

    /**
     * Convertir desde formato PostgreSQL a array PHP
     */
    public function postgresArrayToEmbedding(string $postgresArray): array
    {
        // Remover corchetes y convertir a array
        $cleaned = trim($postgresArray, '[]');
        return array_map('floatval', explode(',', $cleaned));
    }
}
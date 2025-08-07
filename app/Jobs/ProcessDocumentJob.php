<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentPage;
use App\Services\PdfExtractionService;
use App\Services\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $documentId;
    public $timeout = 600; // 10 minutos para documentos grandes
    public $tries = 3;

    public function __construct(Document $document)
    {
        $this->documentId = $document->id;
    }

    public function handle(PdfExtractionService $pdfService, EmbeddingService $embeddingService)
    {
        Log::info("=== INICIANDO PROCESAMIENTO COMPLETO ===", ['document_id' => $this->documentId]);

        try {
            // Obtener documento
            $document = Document::findOrFail($this->documentId);
            Log::info("Documento encontrado: " . $document->title);

            // Verificar que el archivo existe
            if (!file_exists($document->file_path)) {
                throw new \Exception("Archivo no encontrado: " . $document->file_path);
            }

            // PASO 1: Extraer texto del PDF
            Log::info("Iniciando extracción de texto");
            $result = $pdfService->extractTextFromPdf($document->file_path);
            
            if (!$result['success']) {
                throw new \Exception("Error extrayendo texto: " . $result['error']);
            }

            Log::info("Texto extraído exitosamente", [
                'total_pages' => count($result['pages']),
                'total_chars' => array_sum(array_map('strlen', $result['pages']))
            ]);

            // PASO 2: Procesar cada página
            $pageCount = 0;
            $embeddingErrors = 0;

            foreach ($result['pages'] as $index => $content) {
                $pageNumber = $index + 1;
                
                try {
                    // Crear o actualizar página
                    $page = DocumentPage::updateOrCreate(
                        [
                            'document_id' => $document->id,
                            'page_number' => $pageNumber
                        ],
                        [
                            'content' => $content,
                            'content_preview' => substr($content, 0, 200),
                            'word_count' => str_word_count($content),
                            'keywords' => $pdfService->extractKeywords($content),
                            'has_embedding' => false
                        ]
                    );
                    
                    Log::info("Página {$pageNumber} guardada, generando embedding...");

                    // PASO 3: Generar embedding para la página
                    $embeddingResult = $embeddingService->generateEmbedding($content);
                    
                    if ($embeddingResult['success']) {
                        // Convertir embedding a formato PostgreSQL
                        $pgVector = $embeddingService->embeddingToPostgresArray($embeddingResult['embedding']);
                        
                        // Actualizar con embedding usando SQL raw
                        DB::statement(
                            'UPDATE document_pages SET embedding = ?, has_embedding = true, embedding_generated_at = NOW() WHERE id = ?',
                            [$pgVector, $page->id]
                        );
                        
                        Log::info("Embedding generado para página {$pageNumber}", [
                            'dimensions' => $embeddingResult['dimensions']
                        ]);
                    } else {
                        Log::warning("No se pudo generar embedding para página {$pageNumber}", [
                            'error' => $embeddingResult['error']
                        ]);
                        $embeddingErrors++;
                    }
                    
                    $pageCount++;
                    
                } catch (\Exception $e) {
                    Log::error("Error procesando página {$pageNumber}", [
                        'error' => $e->getMessage()
                    ]);
                    $embeddingErrors++;
                }
            }

            // PASO 4: Actualizar estado del documento
            $status = $embeddingErrors === 0 ? 'completed' : 
                     ($embeddingErrors < $pageCount ? 'partial' : 'failed');

            $document->update([
                'status' => $status,
                'total_pages' => $pageCount,
                'processing_error' => $embeddingErrors > 0 ? 
                    "Se generaron embeddings para " . ($pageCount - $embeddingErrors) . " de {$pageCount} páginas" : 
                    null
            ]);

            Log::info("=== PROCESAMIENTO COMPLETADO ===", [
                'document_id' => $document->id,
                'pages_processed' => $pageCount,
                'embeddings_generated' => $pageCount - $embeddingErrors,
                'status' => $status
            ]);

        } catch (\Exception $e) {
            Log::error("=== ERROR EN PROCESAMIENTO ===", [
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Marcar documento como fallido
            $document = Document::find($this->documentId);
            if ($document) {
                $document->update([
                    'status' => 'failed',
                    'processing_error' => $e->getMessage()
                ]);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception)
    {
        Log::error("Job failed completely after retries", [
            'document_id' => $this->documentId,
            'error' => $exception->getMessage()
        ]);

        $document = Document::find($this->documentId);
        if ($document) {
            $document->update([
                'status' => 'failed',
                'processing_error' => 'Procesamiento falló después de varios intentos: ' . $exception->getMessage()
            ]);
        }
    }
}
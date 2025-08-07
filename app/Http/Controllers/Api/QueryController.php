<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentPage;
use App\Services\EmbeddingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QueryController extends Controller
{
    protected EmbeddingService $embeddingService;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
    }

    /**
     * Realizar consulta semántica en los documentos
     */
    public function search(Request $request)
    {
        // Validar parámetros de entrada
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:3|max:500',
            'limit' => 'nullable|integer|min:1|max:20',
            'similarity_threshold' => 'nullable|numeric|min:0|max:1',
            'search_type' => 'nullable|in:semantic,hybrid,fulltext'
        ], [
            'query.required' => 'La consulta es obligatoria',
            'query.min' => 'La consulta debe tener mínimo 3 caracteres',
            'query.max' => 'La consulta no puede superar 500 caracteres',
            'limit.integer' => 'El límite debe ser un número entero',
            'limit.max' => 'El límite máximo es 20 resultados',
            'similarity_threshold.numeric' => 'El umbral debe ser un número',
            'search_type.in' => 'Tipo de búsqueda inválido'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $query = $request->input('query');
        $limit = $request->input('limit', 10);
        $threshold = $request->input('similarity_threshold', 0.7);
        $searchType = $request->input('search_type', 'hybrid');

        try {
            // Realizar búsqueda según el tipo seleccionado
            switch ($searchType) {
                case 'semantic':
                    $results = $this->performSemanticSearch($query, $limit, $threshold);
                    break;
                case 'fulltext':
                    $results = $this->performFullTextSearch($query, $limit);
                    break;
                case 'hybrid':
                default:
                    $results = $this->performHybridSearch($query, $limit, $threshold);
                    break;
            }

            return response()->json([
                'success' => true,
                'message' => 'Búsqueda completada exitosamente',
                'data' => [
                    'query' => $query,
                    'search_type' => $searchType,
                    'total_results' => count($results),
                    'results' => $results,
                    'metadata' => [
                        'limit' => $limit,
                        'threshold' => $threshold,
                        'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error en búsqueda semántica', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error realizando la búsqueda: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Búsqueda semántica pura usando embeddings
     */
    private function performSemanticSearch(string $query, int $limit, float $threshold): array
    {
        // Generar embedding para la consulta
        $embeddingResult = $this->embeddingService->generateEmbedding($query);

        if (!$embeddingResult['success']) {
            throw new \Exception('No se pudo generar embedding para la consulta: ' . $embeddingResult['error']);
        }

        $queryEmbedding = $this->embeddingService->embeddingToPostgresArray($embeddingResult['embedding']);

        // Buscar páginas similares usando similitud coseno
        $pages = DB::select("
            SELECT 
                dp.id,
                dp.document_id,
                dp.page_number,
                dp.content_preview,
                dp.content,
                dp.word_count,
                dp.keywords,
                d.title as document_title,
                d.original_filename,
                1 - (dp.embedding <=> ?) as similarity_score
            FROM document_pages dp
            JOIN documents d ON dp.document_id = d.id
            WHERE dp.has_embedding = true
            AND 1 - (dp.embedding <=> ?) > ?
            ORDER BY dp.embedding <=> ?
            LIMIT ?
        ", [$queryEmbedding, $queryEmbedding, $threshold, $queryEmbedding, $limit]);

        return $this->formatSearchResults($pages, 'semantic');
    }

    /**
     * Búsqueda de texto completo tradicional
     */
    private function performFullTextSearch(string $query, int $limit): array
    {
        $pages = DocumentPage::with(['document:id,title,original_filename'])
            ->where('content', 'ILIKE', '%' . $query . '%')
            ->orWhere('keywords', 'ILIKE', '%' . $query . '%')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();

        // Calcular score básico por relevancia de texto
        foreach ($pages as &$page) {
            $contentLower = strtolower($page['content']);
            $queryLower = strtolower($query);
            
            // Contar ocurrencias de la palabra en el contenido
            $occurrences = substr_count($contentLower, $queryLower);
            $page['similarity_score'] = min($occurrences / 10, 1); // Normalizar a 0-1
        }

        return $this->formatSearchResults($pages, 'fulltext');
    }

    /**
     * Búsqueda híbrida (semántica + texto completo)
     */
    private function performHybridSearch(string $query, int $limit, float $threshold): array
    {
        try {
            // Intentar búsqueda semántica primero
            $semanticResults = $this->performSemanticSearch($query, $limit, $threshold * 0.8);
        } catch (\Exception $e) {
            // Si falla la búsqueda semántica, usar solo texto completo
            Log::warning('Búsqueda semántica falló, usando texto completo', ['error' => $e->getMessage()]);
            return $this->performFullTextSearch($query, $limit);
        }

        // Obtener también resultados de texto completo
        $fulltextResults = $this->performFullTextSearch($query, $limit);

        // Combinar resultados y eliminar duplicados
        $combined = [];
        $seenIds = [];

        // Primero agregar resultados semánticos (mayor prioridad)
        foreach ($semanticResults as $result) {
            if (!in_array($result['id'], $seenIds)) {
                $result['search_method'] = 'semantic';
                $combined[] = $result;
                $seenIds[] = $result['id'];
            }
        }

        // Luego agregar resultados de texto completo que no estén duplicados
        foreach ($fulltextResults as $result) {
            if (!in_array($result['id'], $seenIds) && count($combined) < $limit) {
                $result['search_method'] = 'fulltext';
                $combined[] = $result;
                $seenIds[] = $result['id'];
            }
        }

        // Ordenar por score de similitud
        usort($combined, function($a, $b) {
            return $b['similarity_score'] <=> $a['similarity_score'];
        });

        return array_slice($combined, 0, $limit);
    }

    /**
     * Formatear resultados de búsqueda para respuesta consistente
     */
    private function formatSearchResults(array $pages, string $searchMethod): array
    {
        $results = [];

        foreach ($pages as $page) {
            // Convertir a array si es objeto
            $pageArray = is_object($page) ? (array) $page : $page;

            // Resaltar términos de búsqueda en el contenido
            $highlightedContent = $this->highlightSearchTerms(
                $pageArray['content_preview'] ?? $pageArray['content'] ?? '', 
                request('query')
            );

            $results[] = [
                'id' => $pageArray['id'],
                'document_id' => $pageArray['document_id'],
                'document_title' => $pageArray['document_title'] ?? $pageArray['document']['title'] ?? 'Sin título',
                'document_filename' => $pageArray['original_filename'] ?? $pageArray['document']['original_filename'] ?? '',
                'page_number' => $pageArray['page_number'],
                'content_preview' => $highlightedContent,
                'word_count' => $pageArray['word_count'],
                'keywords' => $pageArray['keywords'],
                'similarity_score' => round($pageArray['similarity_score'] ?? 0, 4),
                'search_method' => $searchMethod
            ];
        }

        return $results;
    }

    /**
     * Resaltar términos de búsqueda en el contenido
     */
    private function highlightSearchTerms(string $content, string $query): string
    {
        if (empty($query) || empty($content)) {
            return $content;
        }

        // Dividir query en palabras
        $words = array_filter(explode(' ', $query));
        
        foreach ($words as $word) {
            if (strlen($word) > 2) { // Solo resaltar palabras de más de 2 caracteres
                $pattern = '/\b(' . preg_quote($word, '/') . ')\b/i';
                $content = preg_replace($pattern, '<mark>$1</mark>', $content);
            }
        }

        return $content;
    }

    /**
     * Obtener estadísticas de la base de conocimientos
     */
    public function stats()
    {
        $stats = [
            'total_documents' => DB::table('documents')->count(),
            'total_pages' => DB::table('document_pages')->count(),
            'pages_with_embeddings' => DB::table('document_pages')->where('has_embedding', true)->count(),
            'processing_documents' => DB::table('documents')->where('status', 'processing')->count(),
            'failed_documents' => DB::table('documents')->where('status', 'failed')->count(),
            'avg_words_per_page' => DB::table('document_pages')->avg('word_count'),
            'total_words' => DB::table('document_pages')->sum('word_count')
        ];

        $stats['processing_progress'] = $stats['total_pages'] > 0 
            ? round(($stats['pages_with_embeddings'] / $stats['total_pages']) * 100, 2)
            : 0;

        return response()->json([
            'success' => true,
            'message' => 'Estadísticas obtenidas exitosamente',
            'data' => $stats
        ]);
    }
}
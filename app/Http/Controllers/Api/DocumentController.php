<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Document;
use App\Jobs\ProcessDocumentJob;
use App\Services\PdfExtractionService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    protected PdfExtractionService $pdfService;

    public function __construct(PdfExtractionService $pdfService)
    {
        $this->pdfService = $pdfService;
    }

    /**
     * Listar documentos del usuario autenticado
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        
        $documents = Document::with(['pages' => function($query) {
                $query->select('document_id', 'page_number', 'content_preview', 'has_embedding')
                      ->orderBy('page_number');
            }])
            ->select('id', 'title', 'original_filename', 'total_pages', 'status', 'created_at')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Documentos obtenidos exitosamente',
            'data' => $documents
        ]);
    }

    /**
     * Subir nuevo documento PDF
     */
    public function store(Request $request)
    {
        // Validar archivo PDF
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:pdf|max:10240', // Máximo 10MB
            'title' => 'nullable|string|max:255',
        ], [
            'file.required' => 'Debe seleccionar un archivo',
            'file.mimes' => 'El archivo debe ser un PDF',
            'file.max' => 'El archivo no puede superar los 10MB'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            
            // Generar nombre único para el archivo
            $filename = Str::uuid() . '.pdf';
            $filePath = $file->storeAs('documents', $filename);
            
            // Obtener ruta completa del archivo
            $fullPath = Storage::path($filePath);

            // Validar que es un PDF válido
            if (!$this->pdfService->isValidPdf($fullPath)) {
                // Eliminar archivo si no es válido
                Storage::delete($filePath);
                
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo no es un PDF válido'
                ], 422);
            }

            // Crear registro en base de datos
            $document = Document::create([
                'title' => $request->title ?: $file->getClientOriginalName(),
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $fullPath,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'status' => 'processing',
                'metadata' => [
                    'uploaded_by' => auth()->user()->name ?? 'Unknown',
                    'upload_ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]
            ]);

            // Despachar job para procesar el documento en background
            ProcessDocumentJob::dispatch($document);

            return response()->json([
                'success' => true,
                'message' => 'Documento subido exitosamente. Se está procesando en segundo plano.',
                'data' => [
                    'document' => [
                        'id' => $document->id,
                        'title' => $document->title,
                        'original_filename' => $document->original_filename,
                        'status' => $document->status,
                        'file_size' => $document->file_size,
                        'created_at' => $document->created_at
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error subiendo el documento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener detalles de un documento específico
     */
    public function show($id)
    {
        $document = Document::with(['pages' => function($query) {
            $query->select('id', 'document_id', 'page_number', 'content_preview', 
                          'word_count', 'has_embedding', 'embedding_generated_at')
                  ->orderBy('page_number');
        }])->find($id);

        if (!$document) {
            return response()->json([
                'success' => false,
                'message' => 'Documento no encontrado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Documento obtenido exitosamente',
            'data' => [
                'document' => $document,
                'processing_progress' => $document->getProcessingProgress()
            ]
        ]);
    }

    /**
     * Obtener el contenido completo de una página específica
     */
    public function getPageContent($documentId, $pageNumber)
    {
        $document = Document::find($documentId);
        
        if (!$document) {
            return response()->json([
                'success' => false,
                'message' => 'Documento no encontrado'
            ], 404);
        }

        $page = $document->pages()
            ->where('page_number', $pageNumber)
            ->first();

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'Página no encontrada'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Contenido de página obtenido exitosamente',
            'data' => [
                'page' => [
                    'id' => $page->id,
                    'document_id' => $page->document_id,
                    'page_number' => $page->page_number,
                    'content' => $page->content,
                    'word_count' => $page->word_count,
                    'keywords' => $page->keywords,
                    'has_embedding' => $page->has_embedding
                ]
            ]
        ]);
    }

    /**
     * Eliminar documento
     */
    public function destroy($id)
    {
        $document = Document::find($id);

        if (!$document) {
            return response()->json([
                'success' => false,
                'message' => 'Documento no encontrado'
            ], 404);
        }

        try {
            // Eliminar archivo físico
            if (file_exists($document->file_path)) {
                unlink($document->file_path);
            }

            // Eliminar registro (las páginas se eliminan por cascade)
            $document->delete();

            return response()->json([
                'success' => true,
                'message' => 'Documento eliminado exitosamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error eliminando documento: ' . $e->getMessage()
            ], 500);
        }
    }
}

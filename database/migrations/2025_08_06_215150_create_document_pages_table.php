<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->onDelete('cascade'); // Relación con documents
            $table->integer('page_number'); // Número de página (1, 2, 3...)
            $table->text('content'); // Contenido de texto extraído de la página
            $table->text('content_preview')->nullable(); // Preview del contenido (primeros 200 chars)
            $table->integer('word_count')->default(0); // Cantidad de palabras en la página
            $table->json('keywords')->nullable(); // Palabras clave extraídas (para búsqueda híbrida)
            $table->boolean('has_embedding')->default(false); // Si ya se generó el embedding
            $table->timestamp('embedding_generated_at')->nullable(); // Cuándo se generó el embedding
            $table->timestamps();
            
            // Índices para mejorar rendimiento de búsquedas
            $table->index(['document_id', 'page_number']); // Búsqueda por documento y página
            $table->index('has_embedding'); // Filtrar páginas con embeddings
            $table->unique(['document_id', 'page_number']); // Evitar páginas duplicadas
        });

        // Agregar la columna de embedding usando SQL crudo (vector de 1536 dimensiones como OpenAI)
        DB::statement('ALTER TABLE document_pages ADD COLUMN embedding VECTOR(1536)');

        // Crear índice HNSW para búsquedas vectoriales eficientes
        // HNSW es mejor que IVFFlat para datasets pequeños-medianos
        DB::statement('CREATE INDEX document_pages_embedding_idx ON document_pages USING hnsw (embedding vector_cosine_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_pages');
    }
};

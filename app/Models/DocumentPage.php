<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentPage extends Model

{
    use HasFactory;

    /**
     * Campos que pueden ser asignados masivamente
     */
    protected $fillable = [
        'document_id',
        'page_number',
        'content',
        'content_preview',
        'word_count',
        'keywords',
        'has_embedding',
        'embedding_generated_at',
    ];

    /**
     * Campos que deben ser tratados como tipos específicos
     */
    protected $casts = [
        'keywords' => 'array', // JSON se convierte automáticamente en array
        'word_count' => 'integer',
        'page_number' => 'integer',
        'has_embedding' => 'boolean',
        'embedding_generated_at' => 'datetime',
    ];

    /**
     * Relación: Una página pertenece a un documento
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Generar preview del contenido (primeros 200 caracteres)
     */
    public function generateContentPreview(): void
    {
        $this->content_preview = \Str::limit($this->content, 200);
    }

    /**
     * Contar palabras en el contenido
     */
    public function countWords(): void
    {
        $this->word_count = str_word_count(strip_tags($this->content));
    }

    /**
     * Marcar que el embedding fue generado
     */
    public function markEmbeddingGenerated(): void
    {
        $this->update([
            'has_embedding' => true,
            'embedding_generated_at' => now(),
        ]);
    }

    /**
     * Scope para páginas con embeddings
     */
    public function scopeWithEmbeddings($query)
    {
        return $query->where('has_embedding', true);
    }

    /**
     * Scope para páginas sin embeddings
     */
    public function scopeWithoutEmbeddings($query)
    {
        return $query->where('has_embedding', false);
    }

    /**
     * Búsqueda por similitud vectorial usando pgvector
     */
    public function scopeSimilarTo($query, array $embedding, int $limit = 10)
    {
        $embeddingStr = '[' . implode(',', $embedding) . ']';
        
        return $query
            ->select('*')
            ->selectRaw("embedding <=> ? as similarity_score", [$embeddingStr])
            ->where('has_embedding', true)
            ->orderByRaw('embedding <=> ?', [$embeddingStr])
            ->limit($limit);
    }
}

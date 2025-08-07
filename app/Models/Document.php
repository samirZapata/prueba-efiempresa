<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory;

    /**
     * Campos que pueden ser asignados masivamente
     */
    protected $fillable = [
        'title',
        'original_filename',
        'file_path',
        'mime_type',
        'file_size',
        'total_pages',
        'metadata',
        'status',
        'processing_error',
    ];

    /**
     * Campos que deben ser tratados como tipos específicos
     */
    protected $casts = [
        'metadata' => 'array', // JSON se convierte automáticamente en array
        'file_size' => 'integer',
        'total_pages' => 'integer',
    ];

    /**
     * Relación: Un documento tiene muchas páginas
     */
    public function pages(): HasMany
    {
        return $this->hasMany(DocumentPage::class)->orderBy('page_number');
    }

    /**
     * Obtener páginas que ya tienen embeddings generados
     */
    public function pagesWithEmbeddings(): HasMany
    {
        return $this->hasMany(DocumentPage::class)
            ->where('has_embedding', true)
            ->orderBy('page_number');
    }

    /**
     * Verificar si el documento está completamente procesado
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Obtener el progreso de procesamiento (porcentaje de páginas con embeddings)
     */
    public function getProcessingProgress(): float
    {
        if ($this->total_pages === 0) {
            return 0;
        }

        $pagesWithEmbeddings = $this->pages()->where('has_embedding', true)->count();
        return round(($pagesWithEmbeddings / $this->total_pages) * 100, 2);
    }

    /**
     * Scopes para filtrar documentos
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}

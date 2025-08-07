<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title'); // Nombre del archivo
            $table->string('original_filename'); // Nombre original del archivo
            $table->string('file_path'); // Ruta donde se guarda el archivo
            $table->string('mime_type')->default('application/pdf'); // Tipo MIME
            $table->integer('file_size'); // Tamaño del archivo en bytes
            $table->integer('total_pages')->default(0); // Total de páginas del PDF
            $table->json('metadata')->nullable(); // Metadata adicional del PDF
            $table->enum('status', ['processing', 'completed', 'failed'])->default('processing');
            $table->text('processing_error')->nullable(); // Errores de procesamiento si los hay
            $table->timestamps();

            // Índices para mejorar rendimiento
            $table->index('status');
            $table->index('created_at');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};

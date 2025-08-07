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
        Schema::create('test_vectors', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE test_vectors ADD COLUMN embedding VECTOR(1536)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('test_vectors');
    }
};

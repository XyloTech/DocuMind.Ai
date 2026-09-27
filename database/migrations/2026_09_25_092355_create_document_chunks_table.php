<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->mediumText('chunk_text');
            $table->json('embedding');
            $table->double('embedding_norm')->nullable();
            $table->unsignedSmallInteger('page_from')->default(0);
            $table->unsignedSmallInteger('page_to')->default(0);
            $table->unsignedInteger('char_start')->default(0);
            $table->unsignedInteger('char_end')->default(0);
            $table->unsignedSmallInteger('token_estimate')->default(0);
            $table->char('content_hash', 40);
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);

            // Keyword pre-filter for very large documents; SQLite (tests) has
            // no fulltext index support, so only create it where it exists.
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
                $table->fullText(['chunk_text']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};

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
        Schema::create('widget_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('widget_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content');
            $table->json('sources')->nullable();
            $table->string('model_used', 80)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('credit_cost')->default(0);
            $table->boolean('was_refused')->default(false);
            $table->boolean('was_helpful')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index(['widget_conversation_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('widget_messages');
    }
};

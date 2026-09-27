<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lightweight engagement beacon fired by the embedded widget: loads, launcher
     * opens/closes, messages sent. No IP, no fingerprint — only the already
     * one-way-hashed visitor id, so engagement can be counted without tracking
     * anybody across sites.
     */
    public function up(): void
    {
        Schema::create('widget_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('widget_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->char('visitor_id_hash', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['site_id', 'created_at']);
            $table->index(['site_id', 'type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('widget_events');
    }
};

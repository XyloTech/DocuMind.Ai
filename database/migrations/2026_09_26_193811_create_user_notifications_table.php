<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-app notifications: one row per user per event, with the columns
     * needed for category filtering, full-text-ish search, time-ordered
     * feeds, unread counts and burst deduplication.
     *
     * The unique (user_id, dedupe_key) pair collapses repeated events
     * (e.g. a flapping AI provider) into one row; NULL keys never collide
     * on MySQL or SQLite.
     */
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 64);
            $table->string('category', 32);
            $table->string('severity', 16);
            $table->string('title', 160);
            $table->string('body', 500)->nullable();
            $table->string('link', 500)->nullable();
            $table->string('link_label', 60)->nullable();
            $table->string('group_key', 120)->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'category']);
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};

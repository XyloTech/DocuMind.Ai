<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('user_workspace', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamps();

            $table->primary(['user_id', 'workspace_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->index(['workspace_id', 'status']);
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->index(['workspace_id', 'last_message_at']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->index(['workspace_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'enabled']);
            $table->dropConstrainedForeignId('workspace_id');
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'last_message_at']);
            $table->dropConstrainedForeignId('workspace_id');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'status']);
            $table->dropConstrainedForeignId('workspace_id');
        });

        Schema::dropIfExists('user_workspace');
        Schema::dropIfExists('workspaces');
    }
};

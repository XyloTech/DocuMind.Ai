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
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('support_conversations', function (Blueprint $table) {
            $table->foreignId('widget_conversation_id')->nullable()->constrained()->nullOnDelete();

            $table->index('widget_conversation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('widget_conversation_id');
        });

        Schema::table('support_conversations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};

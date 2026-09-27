<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Escalation ("a human needs to look at this") and the moment a conversation
     * was resolved feed the analytics funnel: active / escalated / completed,
     * plus the average time to resolve.
     */
    public function up(): void
    {
        Schema::table('widget_conversations', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('assigned_user_id');
            $table->string('escalation_reason', 64)->nullable()->after('escalated_at');
            $table->timestamp('resolved_at')->nullable()->after('escalation_reason');

            $table->index(['site_id', 'escalated_at']);
            $table->index(['site_id', 'resolved_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('widget_conversations', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'escalated_at']);
            $table->dropIndex(['site_id', 'resolved_at']);
            $table->dropColumn(['escalated_at', 'escalation_reason', 'resolved_at']);
        });
    }
};

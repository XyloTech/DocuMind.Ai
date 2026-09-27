<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every assistant answer is classified once it is terminal so the owner can
     * see successes, fallbacks (no knowledge hit) and failures with a reason,
     * instead of a single opaque message count.
     *
     * `status` also fixes a latent mismatch: WidgetMessage already casts it to
     * MessageStatus but the column never existed.
     */
    public function up(): void
    {
        Schema::table('widget_messages', function (Blueprint $table) {
            $table->string('status', 16)->default('complete')->after('role');
            $table->string('error_reason', 64)->nullable()->after('status');
            $table->boolean('was_fallback')->default(false)->after('was_refused');

            $table->index(['site_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('widget_messages', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'status']);
            $table->dropColumn(['status', 'error_reason', 'was_fallback']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user notification preferences: a master non-essential switch, a
     * browser-permission flag, an email opt-in, and per-category in-app /
     * email toggles. NULL means "all defaults" so existing accounts pick up
     * sane behaviour without a backfill.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('notification_preferences')->nullable()->after('chat_retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notification_preferences');
        });
    }
};

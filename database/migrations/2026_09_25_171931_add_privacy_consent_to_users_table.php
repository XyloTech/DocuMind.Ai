<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Privacy choices are stored per account and default to the safest value:
     * nothing is retained and nothing is used for training until the owner has
     * answered the consent prompt.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('store_chat_history')->default(false)->after('is_banned');
            $table->boolean('allow_model_training')->default(false)->after('store_chat_history');
            $table->timestamp('privacy_consent_at')->nullable()->after('allow_model_training');
            $table->string('privacy_consent_version', 40)->nullable()->after('privacy_consent_at');
            $table->unsignedSmallInteger('chat_retention_days')->nullable()->after('privacy_consent_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'store_chat_history',
                'allow_model_training',
                'privacy_consent_at',
                'privacy_consent_version',
                'chat_retention_days',
            ]);
        });
    }
};

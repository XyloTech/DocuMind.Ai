<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('widget_conversations', function (Blueprint $table): void {
            $table->string('visitor_id', 512)->change();
            $table->text('visitor_email')->nullable()->change();
        });

        Schema::table('widget_messages', function (Blueprint $table): void {
            $table->longText('content')->change();
        });

        Schema::table('widget_conversations', function (Blueprint $table) {
            $table->char('visitor_id_hash', 64)->nullable()->after('visitor_id');
            $table->char('visitor_email_hash', 64)->nullable()->after('visitor_email');
            $table->timestamp('visitor_email_consent_at')->nullable()->after('visitor_email_hash');
            $table->foreignId('workspace_id')->nullable()->after('site_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('open');
            $table->string('classification', 24)->default('support');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('follow_up_status', 24)->default('none');
            $table->index(['site_id', 'status', 'created_at'], 'widget_conversations_site_status_created_idx');
            $table->index(['workspace_id', 'created_at'], 'widget_conversations_workspace_created_idx');
            $table->index(['site_id', 'visitor_id_hash'], 'widget_conversations_site_session_hash_idx');
            $table->index('visitor_email_hash', 'widget_conversations_email_hash_idx');
        });

        DB::table('widget_conversations')
            ->whereNull('workspace_id')
            ->orderBy('id')
            ->chunkById(100, function ($conversations): void {
                foreach ($conversations as $conversation) {
                    $workspaceId = DB::table('sites')->where('id', $conversation->site_id)->value('workspace_id');

                    if ($workspaceId !== null) {
                        DB::table('widget_conversations')
                            ->where('id', $conversation->id)
                            ->update(['workspace_id' => $workspaceId]);
                    }
                }
            });

        DB::table('widget_conversations')
            ->whereNotNull('visitor_id')
            ->orderBy('id')
            ->chunkById(100, function ($conversations): void {
                foreach ($conversations as $conversation) {
                    $visitorId = (string) $conversation->visitor_id;

                    DB::table('widget_conversations')
                        ->where('id', $conversation->id)
                        ->update([
                            'visitor_id' => Crypt::encryptString($visitorId),
                            'visitor_id_hash' => hash('sha256', $visitorId),
                        ]);
                }
            });

        DB::table('widget_conversations')
            ->whereNotNull('visitor_email')
            ->orderBy('id')
            ->chunkById(100, function ($conversations): void {
                foreach ($conversations as $conversation) {
                    $email = trim((string) $conversation->visitor_email);

                    DB::table('widget_conversations')
                        ->where('id', $conversation->id)
                        ->update([
                            'visitor_email' => Crypt::encryptString($email),
                            'visitor_email_hash' => hash('sha256', mb_strtolower($email)),
                        ]);
                }
            });

        DB::table('widget_messages')
            ->whereNotNull('content')
            ->orderBy('id')
            ->chunkById(100, function ($messages): void {
                foreach ($messages as $message) {
                    DB::table('widget_messages')
                        ->where('id', $message->id)
                        ->update(['content' => Crypt::encryptString((string) $message->content)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('widget_messages')
            ->whereNotNull('content')
            ->orderBy('id')
            ->chunkById(100, function ($messages): void {
                foreach ($messages as $message) {
                    DB::table('widget_messages')
                        ->where('id', $message->id)
                        ->update(['content' => Crypt::decryptString($message->content)]);
                }
            });

        DB::table('widget_conversations')
            ->whereNotNull('visitor_email')
            ->orderBy('id')
            ->chunkById(100, function ($conversations): void {
                foreach ($conversations as $conversation) {
                    DB::table('widget_conversations')
                        ->where('id', $conversation->id)
                        ->update(['visitor_email' => Crypt::decryptString($conversation->visitor_email)]);
                }
            });

        DB::table('widget_conversations')
            ->whereNotNull('visitor_id')
            ->orderBy('id')
            ->chunkById(100, function ($conversations): void {
                foreach ($conversations as $conversation) {
                    DB::table('widget_conversations')
                        ->where('id', $conversation->id)
                        ->update(['visitor_id' => Crypt::decryptString($conversation->visitor_id)]);
                }
            });

        Schema::table('widget_conversations', function (Blueprint $table) {
            $table->dropIndex('widget_conversations_site_status_created_idx');
            $table->dropIndex('widget_conversations_workspace_created_idx');
            $table->dropIndex('widget_conversations_site_session_hash_idx');
            $table->dropIndex('widget_conversations_email_hash_idx');
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn([
                'visitor_id_hash',
                'visitor_email_hash',
                'visitor_email_consent_at',
                'status',
                'classification',
                'follow_up_status',
            ]);
            $table->string('visitor_id', 64)->change();
            $table->string('visitor_email', 190)->nullable()->change();
        });

        Schema::table('widget_messages', function (Blueprint $table): void {
            $table->text('content')->change();
        });
    }
};

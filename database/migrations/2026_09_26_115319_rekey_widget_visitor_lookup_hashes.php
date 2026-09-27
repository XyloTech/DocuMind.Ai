<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $key = (string) config('app.key');

        DB::table('widget_conversations')
            ->where(function ($query): void {
                $query->whereNotNull('visitor_id_hash')->orWhereNotNull('visitor_email_hash');
            })
            ->orderBy('id')
            ->chunkById(100, function ($conversations) use ($key): void {
                foreach ($conversations as $conversation) {
                    $visitorId = Crypt::decryptString($conversation->visitor_id);
                    $email = $conversation->visitor_email === null
                        ? null
                        : mb_strtolower(trim(Crypt::decryptString($conversation->visitor_email)));

                    DB::table('widget_conversations')
                        ->where('id', $conversation->id)
                        ->update([
                            'visitor_id_hash' => hash_hmac('sha256', $visitorId, $key),
                            'visitor_email_hash' => $email === null ? null : hash_hmac('sha256', $email, $key),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('widget_conversations')
            ->where(function ($query): void {
                $query->whereNotNull('visitor_id_hash')->orWhereNotNull('visitor_email_hash');
            })
            ->orderBy('id')
            ->chunkById(100, function ($conversations): void {
                foreach ($conversations as $conversation) {
                    $visitorId = Crypt::decryptString($conversation->visitor_id);
                    $email = $conversation->visitor_email === null
                        ? null
                        : mb_strtolower(trim(Crypt::decryptString($conversation->visitor_email)));

                    DB::table('widget_conversations')
                        ->where('id', $conversation->id)
                        ->update([
                            'visitor_id_hash' => hash('sha256', $visitorId),
                            'visitor_email_hash' => $email === null ? null : hash('sha256', $email),
                        ]);
                }
            });
    }
};

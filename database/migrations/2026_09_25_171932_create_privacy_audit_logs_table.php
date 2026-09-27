<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only trail of every privacy decision: consent granted or changed,
     * data exported, data deleted, retention runs. Holds metadata only — never
     * message content — and the `details` payload is encrypted at rest.
     */
    public function up(): void
    {
        Schema::create('privacy_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('action', 60);
            $table->string('summary', 255);
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_audit_logs');
    }
};

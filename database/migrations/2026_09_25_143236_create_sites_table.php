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
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('site_key', 40)->unique();
            $table->string('domain', 190)->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('bot_name', 60)->default('Assistant');
            $table->string('greeting', 255)->nullable();
            $table->string('accent_color', 7)->default('#4f46e5');
            $table->string('logo_url', 255)->nullable();
            // "position" is a reserved word in MySQL 8, hence the explicit name.
            $table->string('position', 16)->default('bottom-left');
            $table->boolean('collect_email')->default(false);
            $table->unsignedInteger('monthly_quota')->default(1000);
            $table->unsignedInteger('messages_used')->default(0);
            $table->string('quota_period', 7)->default('month');
            $table->timestamp('quota_started_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};

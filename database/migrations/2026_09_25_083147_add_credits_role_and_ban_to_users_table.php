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
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('credits')->default(0)->after('password');
            $table->string('role', 16)->default('user')->after('credits');
            $table->boolean('is_banned')->default(false)->after('role');
            $table->timestamp('last_login_at')->nullable()->after('is_banned');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['credits', 'role', 'is_banned', 'last_login_at']);
        });
    }
};

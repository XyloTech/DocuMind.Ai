<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The words beside the bubble ("Need help?") and how the bubble asks for
     * attention. Both are owner-editable from the widget builder.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('launcher_label', 40)->default('Need help?')->after('launcher_icon');
            $table->string('launcher_animation', 16)->default('pulse')->after('launcher_label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['launcher_label', 'launcher_animation']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The widget's avatar identity. A null seed/hue/tone means "derive it from
     * the assistant's name", so every site gets a stable creature for free and
     * an owner who wants control can pin each axis.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('blobatar_seed', 64)->nullable()->after('launcher_animation');
            $table->unsignedSmallInteger('blobatar_size')->default(40)->after('blobatar_seed');
            $table->unsignedSmallInteger('blobatar_hue')->nullable()->after('blobatar_size');
            $table->decimal('blobatar_tone', 4, 3)->nullable()->after('blobatar_hue');
            $table->string('blobatar_background', 16)->default('squircle')->after('blobatar_tone');
            $table->string('blobatar_expression', 24)->default('idle')->after('blobatar_background');
            $table->string('blobatar_animation', 16)->default('live')->after('blobatar_expression');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'blobatar_seed',
                'blobatar_size',
                'blobatar_hue',
                'blobatar_tone',
                'blobatar_background',
                'blobatar_expression',
                'blobatar_animation',
            ]);
        });
    }
};

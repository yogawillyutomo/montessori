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
        Schema::table('weekly_schedules', function (Blueprint $table) {
            $table->string('room')->nullable()->after('teacher_id');
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->string('room')->nullable()->after('teacher_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('room');
        });

        Schema::table('weekly_schedules', function (Blueprint $table) {
            $table->dropColumn('room');
        });
    }
};

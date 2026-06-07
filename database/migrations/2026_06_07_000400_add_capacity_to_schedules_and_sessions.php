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
            $table->unsignedSmallInteger('capacity')->nullable()->after('room');
        });

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->unsignedSmallInteger('capacity')->nullable()->after('room');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('capacity');
        });

        Schema::table('weekly_schedules', function (Blueprint $table) {
            $table->dropColumn('capacity');
        });
    }
};

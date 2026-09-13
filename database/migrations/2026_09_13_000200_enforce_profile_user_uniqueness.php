<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The database constraint intentionally fails the migration if legacy duplicate
        // links exist. No duplicate profile is silently deleted or reassigned.
        Schema::table('teachers', function (Blueprint $table): void {
            $table->unique('user_id', 'teachers_user_id_unique');
        });

        Schema::table('guardians', function (Blueprint $table): void {
            $table->unique('user_id', 'guardians_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table): void {
            $table->dropUnique('teachers_user_id_unique');
        });

        Schema::table('guardians', function (Blueprint $table): void {
            $table->dropUnique('guardians_user_id_unique');
        });
    }
};

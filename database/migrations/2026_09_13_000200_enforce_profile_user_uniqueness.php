<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateTeacherUser = DB::table('teachers')
            ->select('user_id')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        $duplicateGuardianUser = DB::table('guardians')
            ->select('user_id')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateTeacherUser || $duplicateGuardianUser) {
            throw new RuntimeException(
                'Cannot enforce one-to-one user profile links because duplicate teacher/guardian user_id values exist. Reconcile the data first.'
            );
        }

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

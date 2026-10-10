<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table): void {
            $table->foreignId('session_occurrence_id')
                ->nullable()
                ->after('class_session_id')
                ->constrained('session_occurrences')
                ->restrictOnDelete();

            $table->index(
                ['session_occurrence_id', 'student_id'],
                'observations_occurrence_student_index',
            );
        });

        DB::statement(<<<'SQL'
            UPDATE observations
            SET session_occurrence_id = (
                SELECT session_occurrences.id
                FROM session_occurrences
                WHERE session_occurrences.legacy_class_session_id = observations.class_session_id
                LIMIT 1
            )
            WHERE class_session_id IS NOT NULL
              AND session_occurrence_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table): void {
            $table->dropIndex('observations_occurrence_student_index');
            $table->dropConstrainedForeignId('session_occurrence_id');
        });
    }
};

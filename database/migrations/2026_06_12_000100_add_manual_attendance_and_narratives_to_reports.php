<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            if (! Schema::hasColumn('reports', 'manual_present_total')) {
                $table->unsignedInteger('manual_present_total')->default(0)->after('summary');
            }

            if (! Schema::hasColumn('reports', 'manual_sick_total')) {
                $table->unsignedInteger('manual_sick_total')->default(0)->after('manual_present_total');
            }

            if (! Schema::hasColumn('reports', 'manual_excused_total')) {
                $table->unsignedInteger('manual_excused_total')->default(0)->after('manual_sick_total');
            }

            if (! Schema::hasColumn('reports', 'manual_absent_total')) {
                $table->unsignedInteger('manual_absent_total')->default(0)->after('manual_excused_total');
            }

            if (! Schema::hasColumn('reports', 'manual_late_total')) {
                $table->unsignedInteger('manual_late_total')->default(0)->after('manual_absent_total');
            }

            if (! Schema::hasColumn('reports', 'manual_attendance_note')) {
                $table->text('manual_attendance_note')->nullable()->after('manual_late_total');
            }

            if (! Schema::hasColumn('reports', 'general_narrative')) {
                $table->text('general_narrative')->nullable()->after('teacher_narrative');
            }

            if (! Schema::hasColumn('reports', 'social_emotional_narrative')) {
                $table->text('social_emotional_narrative')->nullable()->after('general_narrative');
            }

            if (! Schema::hasColumn('reports', 'independence_narrative')) {
                $table->text('independence_narrative')->nullable()->after('social_emotional_narrative');
            }

            if (! Schema::hasColumn('reports', 'academic_narrative')) {
                $table->text('academic_narrative')->nullable()->after('independence_narrative');
            }

            if (! Schema::hasColumn('reports', 'principal_note')) {
                $table->text('principal_note')->nullable()->after('parent_meeting_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $columns = [
                'manual_present_total',
                'manual_sick_total',
                'manual_excused_total',
                'manual_absent_total',
                'manual_late_total',
                'manual_attendance_note',
                'general_narrative',
                'social_emotional_narrative',
                'independence_narrative',
                'academic_narrative',
                'principal_note',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('reports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendances', 'marked_by')) {
                $table->foreignId('marked_by')->nullable()->after('note')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('attendances', 'marked_at')) {
                $table->timestamp('marked_at')->nullable()->after('marked_by');
            }
        });

        Schema::table('class_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('class_sessions', 'class_note')) {
                $table->text('class_note')->nullable()->after('status');
            }

            if (! Schema::hasColumn('class_sessions', 'follow_up_recommendation')) {
                $table->text('follow_up_recommendation')->nullable()->after('class_note');
            }

            if (! Schema::hasColumn('class_sessions', 'closed_by')) {
                $table->foreignId('closed_by')->nullable()->after('follow_up_recommendation')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('class_sessions', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('closed_by');
            }
        });

        Schema::table('observations', function (Blueprint $table): void {
            $table->dropUnique('observations_daily_indicator_unique');
        });

        Schema::table('observations', function (Blueprint $table): void {
            if (! Schema::hasColumn('observations', 'observation_type')) {
                $table->string('observation_type')->default('scheduled')->after('teacher_id');
            }

            if (! Schema::hasColumn('observations', 'development_area_id')) {
                $table->foreignId('development_area_id')->nullable()->after('indicator_id')->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('observations', 'level')) {
                $table->string('level')->nullable()->after('observed_on');
            }

            if (! Schema::hasColumn('observations', 'needs_follow_up')) {
                $table->boolean('needs_follow_up')->default(false)->after('note');
            }

            if (! Schema::hasColumn('observations', 'include_in_report')) {
                $table->boolean('include_in_report')->default(false)->after('needs_follow_up');
            }

            $table->foreignId('indicator_id')->nullable()->change();
            $table->string('status')->default('saved')->change();
        });

        DB::statement(<<<'SQL'
            UPDATE observations
            SET development_area_id = (
                SELECT development_area_id
                FROM indicators
                WHERE indicators.id = observations.indicator_id
            )
            WHERE development_area_id IS NULL
        SQL);

        DB::table('observations')
            ->where('status', 'needs_support')
            ->update(['needs_follow_up' => true]);

        DB::table('observations')
            ->whereNull('level')
            ->update([
                'level' => DB::raw("case status when 'achieved' then 'independent' when 'emerging' then 'developing' when 'needs_support' then 'emerging' else 'developing' end"),
            ]);

        DB::table('observations')
            ->whereNull('status')
            ->orWhereIn('status', ['achieved', 'emerging', 'needs_support'])
            ->update([
                'include_in_report' => true,
                'status' => 'included_in_report',
            ]);
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table): void {
            $table->dropColumn([
                'observation_type',
                'development_area_id',
                'level',
                'needs_follow_up',
                'include_in_report',
            ]);
        });

        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['class_note', 'follow_up_recommendation', 'closed_at']);
        });

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('marked_by');
            $table->dropColumn('marked_at');
        });
    }
};

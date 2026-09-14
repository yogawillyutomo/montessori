<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropUnique(['student_id', 'term_id']);

            $table->foreignId('report_cycle_id')->nullable()->after('term_id')->constrained('report_cycles')->restrictOnDelete();
            $table->foreignId('child_enrollment_id')->nullable()->after('report_cycle_id')->constrained('child_enrollments')->restrictOnDelete();
            $table->unsignedInteger('working_revision')->default(1)->after('status');

            $table->foreignId('submitted_by')->nullable()->after('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->foreignId('review_started_by')->nullable()->after('submitted_at')->constrained('users')->restrictOnDelete();
            $table->timestamp('review_started_at')->nullable()->after('review_started_by');
            $table->foreignId('revision_requested_by')->nullable()->after('review_started_at')->constrained('users')->restrictOnDelete();
            $table->timestamp('revision_requested_at')->nullable()->after('revision_requested_by');
            $table->text('revision_request_note')->nullable()->after('revision_requested_at');
            $table->foreignId('approved_by')->nullable()->after('revision_request_note')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('revision_opened_by')->nullable()->after('approved_at')->constrained('users')->restrictOnDelete();
            $table->timestamp('revision_opened_at')->nullable()->after('revision_opened_by');
            $table->text('revision_open_reason')->nullable()->after('revision_opened_at');
            $table->foreignId('archived_by')->nullable()->after('revision_open_reason')->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable()->after('archived_by');

            $table->unique(['student_id', 'report_cycle_id'], 'reports_student_cycle_unique');
            $table->index(['student_id', 'term_id'], 'reports_student_term_lookup');
        });

        Schema::create('report_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['report_id', 'version_number'], 'report_version_number_unique');
        });

        Schema::create('report_workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('reports')->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['report_id', 'occurred_at']);
        });

        Schema::table('reports', function (Blueprint $table): void {
            $table->foreignId('published_version_id')->nullable()->after('published_at')->constrained('report_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_version_id');
        });

        Schema::dropIfExists('report_workflow_events');
        Schema::dropIfExists('report_versions');

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropUnique('reports_student_cycle_unique');
            $table->dropIndex('reports_student_term_lookup');

            $table->dropConstrainedForeignId('report_cycle_id');
            $table->dropConstrainedForeignId('child_enrollment_id');
            $table->dropColumn('working_revision');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn('submitted_at');
            $table->dropConstrainedForeignId('review_started_by');
            $table->dropColumn('review_started_at');
            $table->dropConstrainedForeignId('revision_requested_by');
            $table->dropColumn(['revision_requested_at', 'revision_request_note']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
            $table->dropConstrainedForeignId('revision_opened_by');
            $table->dropColumn(['revision_opened_at', 'revision_open_reason']);
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn('archived_at');

            $table->unique(['student_id', 'term_id']);
        });
    }
};

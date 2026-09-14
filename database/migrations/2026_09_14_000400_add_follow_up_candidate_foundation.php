<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_observation_id')->nullable()->constrained('observations')->nullOnDelete();
            $table->foreignId('indicator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('open');
            $table->text('reason_summary');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique('source_observation_id', 'follow_up_candidates_source_observation_unique');
            $table->index(['student_id', 'status'], 'follow_up_candidates_student_status_index');
        });

        Schema::table('ilp_plans', function (Blueprint $table): void {
            $table->foreignId('follow_up_candidate_id')
                ->nullable()
                ->after('trigger_observation_id')
                ->constrained('follow_up_candidates')
                ->nullOnDelete();

            $table->unique('follow_up_candidate_id', 'ilp_plans_follow_up_candidate_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ilp_plans', function (Blueprint $table): void {
            $table->dropUnique('ilp_plans_follow_up_candidate_unique');
            $table->dropConstrainedForeignId('follow_up_candidate_id');
        });

        Schema::dropIfExists('follow_up_candidates');
    }
};

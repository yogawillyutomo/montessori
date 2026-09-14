<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_conferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('conference_on');
            $table->foreignId('lead_teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->foreignId('report_version_id')->nullable()->constrained('report_versions')->nullOnDelete();
            $table->text('summary')->nullable();
            $table->text('strengths')->nullable();
            $table->text('areas_to_support')->nullable();
            $table->text('parent_observation')->nullable();
            $table->text('agreed_follow_up')->nullable();
            $table->date('next_review_on')->nullable();
            $table->boolean('share_with_parent')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'conference_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_conferences');
    }
};

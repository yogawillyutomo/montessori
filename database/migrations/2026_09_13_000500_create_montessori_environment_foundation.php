<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_level_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->string('age_range')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('environment_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['environment_id', 'student_id', 'valid_from'], 'environment_membership_period_unique');
            $table->index(['student_id', 'status', 'valid_from']);
        });

        Schema::create('environment_guide_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->string('assignment_role');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['environment_id', 'teacher_id', 'assignment_role', 'valid_from'],
                'environment_guide_assignment_period_unique'
            );
            $table->index(['teacher_id', 'is_active', 'valid_from']);
        });

        Schema::create('child_guide_responsibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->string('responsibility_type');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['student_id', 'teacher_id', 'responsibility_type', 'valid_from'],
                'child_guide_responsibility_period_unique'
            );
            $table->index(['teacher_id', 'is_active', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_guide_responsibilities');
        Schema::dropIfExists('environment_guide_assignments');
        Schema::dropIfExists('environment_memberships');
        Schema::dropIfExists('environments');
    }
};

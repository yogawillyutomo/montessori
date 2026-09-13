<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->foreignId('class_level_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('entitlement_quantity');
            $table->string('entitlement_period')->default('monthly');
            $table->unsignedTinyInteger('preferred_weekly_frequency')->nullable();
            $table->json('makeup_policy')->nullable();
            $table->json('rollover_policy')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['class_level_id', 'is_active']);
            $table->index(['class_level_id', 'is_default', 'is_active']);
        });

        Schema::create('child_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('previous_enrollment_id')->nullable()->constrained('child_enrollments')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status')->default('active');
            $table->date('first_session_on')->nullable();
            $table->date('suspended_from')->nullable();
            $table->date('suspended_until')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->text('ended_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'starts_on', 'ends_on']);
            $table->index(['student_id', 'status']);
            $table->index(['class_level_id', 'status']);
        });

        Schema::create('enrollment_plan_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('child_enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('session_plan_id')->constrained()->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('assignment_type')->default('default');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['child_enrollment_id', 'valid_from', 'valid_until'], 'enrollment_plan_effective_dates');
            $table->index(['session_plan_id', 'valid_from']);
            $table->index(['child_enrollment_id', 'cancelled_at']);
        });

        Schema::table('recurring_schedules', function (Blueprint $table): void {
            $table->foreignId('child_enrollment_id')
                ->nullable()
                ->after('student_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('child_session_bookings', function (Blueprint $table): void {
            $table->foreignId('child_enrollment_id')
                ->nullable()
                ->after('student_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('child_session_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('child_enrollment_id');
        });

        Schema::table('recurring_schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('child_enrollment_id');
        });

        Schema::dropIfExists('enrollment_plan_assignments');
        Schema::dropIfExists('child_enrollments');
        Schema::dropIfExists('session_plans');
    }
};

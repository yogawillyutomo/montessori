<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_eligibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_cycle_id')->constrained('report_cycles')->restrictOnDelete();
            $table->foreignId('child_enrollment_id')->constrained('child_enrollments')->restrictOnDelete();
            $table->string('status')->default('not_eligible');
            $table->json('reason_codes')->nullable();
            $table->unsignedInteger('observation_days')->default(0);
            $table->unsignedInteger('attended_sessions')->default(0);
            $table->unsignedInteger('covered_area_count')->default(0);
            $table->unsignedInteger('required_area_count')->default(0);
            $table->boolean('is_first_report_gate')->default(true);
            $table->foreignId('guide_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('guide_confirmed_at')->nullable();
            $table->text('guide_confirmation_note')->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('override_at')->nullable();
            $table->text('override_reason')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->unique(['report_cycle_id', 'child_enrollment_id'], 'report_eligibility_cycle_enrollment_unique');
            $table->index(['status', 'evaluated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_eligibilities');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_level_id')->constrained('class_levels')->restrictOnDelete();
            $table->string('name');
            $table->string('reporting_frequency');
            $table->unsignedInteger('minimum_observation_days')->nullable();
            $table->unsignedInteger('minimum_attended_sessions')->nullable();
            $table->boolean('require_guide_confirmation')->default(true);
            $table->string('area_coverage_mode')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['class_level_id', 'is_active'], 'report_policies_level_active_index');
        });

        Schema::create('report_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_policy_id')->constrained('report_policies')->restrictOnDelete();
            $table->string('name');
            $table->date('window_start');
            $table->date('window_end');
            $table->date('cutoff_date');
            $table->string('status')->default('open');
            $table->timestamps();

            $table->unique(
                ['report_policy_id', 'window_start', 'window_end'],
                'report_cycles_policy_window_unique'
            );
            $table->index(['report_policy_id', 'status'], 'report_cycles_policy_status_index');
            $table->index('cutoff_date', 'report_cycles_cutoff_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_cycles');
        Schema::dropIfExists('report_policies');
    }
};

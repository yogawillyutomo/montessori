<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('montessori_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('development_area_id')->constrained()->restrictOnDelete();
            $table->string('code')->nullable()->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('direct_aim')->nullable();
            $table->text('indirect_aim')->nullable();
            $table->unsignedSmallInteger('sequence_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['development_area_id', 'is_active', 'sequence_order'], 'montessori_activities_area_active_sequence_index');
        });

        Schema::create('presentations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained()->restrictOnDelete();
            $table->foreignId('montessori_activity_id')->constrained('montessori_activities')->restrictOnDelete();
            $table->foreignId('session_occurrence_id')->nullable()->constrained('session_occurrences')->restrictOnDelete();
            $table->date('presented_on');
            $table->string('presentation_type')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'presented_on']);
            $table->index(['student_id', 'montessori_activity_id', 'presented_on'], 'presentations_student_activity_date_index');
            $table->index(['teacher_id', 'presented_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentations');
        Schema::dropIfExists('montessori_activities');
    }
};

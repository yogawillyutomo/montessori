<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('development_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('indicator_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('montessori_activity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('progress_state', 64);
            $table->foreignId('judged_by')->nullable()->constrained('teachers')->nullOnDelete();
            $table->timestamp('judged_at');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'indicator_id', 'judged_at'], 'development_progress_indicator_history_idx');
            $table->index(['student_id', 'montessori_activity_id', 'judged_at'], 'development_progress_activity_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('development_progress');
    }
};

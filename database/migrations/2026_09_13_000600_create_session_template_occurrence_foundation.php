<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('code')->nullable()->unique();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('room')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('legacy_weekly_schedule_id')->nullable()->unique();
            $table->unsignedBigInteger('legacy_school_class_id')->nullable();
            $table->unsignedBigInteger('legacy_teacher_id')->nullable();
            $table->string('legacy_topic')->nullable();
            $table->timestamp('legacy_deleted_at')->nullable();
            $table->timestamps();

            $table->index(['day_of_week', 'is_active']);
        });

        Schema::create('session_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('occurs_on');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('room')->nullable();
            $table->string('status')->default('planned');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('legacy_class_session_id')->nullable()->unique();
            $table->unsignedBigInteger('legacy_weekly_schedule_id')->nullable();
            $table->unsignedBigInteger('legacy_school_class_id')->nullable();
            $table->unsignedBigInteger('legacy_teacher_id')->nullable();
            $table->string('legacy_topic')->nullable();
            $table->timestamp('legacy_deleted_at')->nullable();
            $table->timestamps();

            $table->index(['occurs_on', 'status']);
            $table->index(['session_template_id', 'occurs_on']);
        });

        $this->backfillLegacySchedules();
        $this->backfillLegacySessions();
    }

    public function down(): void
    {
        Schema::dropIfExists('session_occurrences');
        Schema::dropIfExists('session_templates');
    }

    private function backfillLegacySchedules(): void
    {
        DB::table('weekly_schedules')
            ->orderBy('id')
            ->eachById(function (object $schedule): void {
                DB::table('session_templates')->insert([
                    'environment_id' => null,
                    'name' => null,
                    'code' => null,
                    'day_of_week' => $schedule->day_of_week,
                    'starts_at' => $schedule->starts_at,
                    'ends_at' => $schedule->ends_at,
                    'capacity' => $schedule->capacity,
                    'room' => $schedule->room,
                    'valid_from' => null,
                    'valid_until' => null,
                    'is_active' => $schedule->is_active,
                    'legacy_weekly_schedule_id' => $schedule->id,
                    'legacy_school_class_id' => $schedule->school_class_id,
                    'legacy_teacher_id' => $schedule->teacher_id,
                    'legacy_topic' => $schedule->topic,
                    'legacy_deleted_at' => null,
                    'created_at' => $schedule->created_at,
                    'updated_at' => $schedule->updated_at,
                ]);
            });
    }

    private function backfillLegacySessions(): void
    {
        DB::table('class_sessions')
            ->orderBy('id')
            ->eachById(function (object $session): void {
                $templateId = $session->weekly_schedule_id
                    ? DB::table('session_templates')
                        ->where('legacy_weekly_schedule_id', $session->weekly_schedule_id)
                        ->value('id')
                    : null;

                DB::table('session_occurrences')->insert([
                    'session_template_id' => $templateId,
                    'environment_id' => null,
                    'occurs_on' => $session->session_date,
                    'starts_at' => $session->starts_at,
                    'ends_at' => $session->ends_at,
                    'capacity' => $session->capacity,
                    'room' => $session->room,
                    'status' => $session->status,
                    'opened_at' => null,
                    'completed_at' => $session->closed_at,
                    'completed_by' => $session->closed_by,
                    'cancellation_reason' => null,
                    'legacy_class_session_id' => $session->id,
                    'legacy_weekly_schedule_id' => $session->weekly_schedule_id,
                    'legacy_school_class_id' => $session->school_class_id,
                    'legacy_teacher_id' => $session->teacher_id,
                    'legacy_topic' => $session->topic,
                    'legacy_deleted_at' => null,
                    'created_at' => $session->created_at,
                    'updated_at' => $session->updated_at,
                ]);
            });
    }
};

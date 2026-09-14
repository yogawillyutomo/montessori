<?php

namespace Tests\Feature;

use App\Models\RecurringSchedule;
use App\Models\SchoolClass;
use App\Models\SessionTemplate;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TargetScheduleWriteCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_create_writes_target_first_class_domain_and_keeps_legacy_mirror_reconciled(): void
    {
        $this->seed();

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $class = SchoolClass::query()->firstOrFail();
        $teacher = Teacher::query()->firstOrFail();
        $student = Student::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('alpha.process.schedules.store'), [
                'school_class_id' => $class->id,
                'teacher_id' => $teacher->id,
                'room' => 'Cutover Room',
                'capacity' => 5,
                'day_of_week' => 6,
                'starts_at' => '13:00',
                'ends_at' => '14:00',
                'topic' => 'Target Write',
                'student_ids' => [$student->id],
            ])
            ->assertRedirect();

        $template = SessionTemplate::query()
            ->where('room', 'Cutover Room')
            ->where('legacy_topic', 'Target Write')
            ->firstOrFail();
        $legacy = WeeklySchedule::query()->findOrFail($template->legacy_weekly_schedule_id);
        $recurring = RecurringSchedule::query()
            ->where('session_template_id', $template->id)
            ->where('student_id', $student->id)
            ->firstOrFail();

        $this->assertSame($class->id, (int) $template->legacy_school_class_id);
        $this->assertSame($teacher->id, (int) $template->legacy_teacher_id);
        $this->assertSame('Cutover Room', $legacy->room);
        $this->assertSame('Target Write', $legacy->topic);
        $this->assertNotNull($recurring->legacy_student_weekly_schedule_id);
        $this->assertDatabaseHas('student_weekly_schedule', [
            'id' => $recurring->legacy_student_weekly_schedule_id,
            'weekly_schedule_id' => $legacy->id,
            'student_id' => $student->id,
        ]);

        $this->artisan('legacy:reconcile')->assertExitCode(0);
    }

    public function test_target_schedule_state_is_authoritative_even_when_legacy_mirror_was_stale_before_update(): void
    {
        $this->seed();

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $legacy = WeeklySchedule::query()->firstOrFail();
        $template = SessionTemplate::query()
            ->where('legacy_weekly_schedule_id', $legacy->id)
            ->firstOrFail();
        $studentIds = RecurringSchedule::query()
            ->where('session_template_id', $template->id)
            ->whereNull('legacy_deleted_at')
            ->pluck('student_id')
            ->all();

        DB::table('weekly_schedules')->where('id', $legacy->id)->update([
            'room' => 'STALE MIRROR',
            'topic' => 'STALE MIRROR',
        ]);

        $this->actingAs($admin)
            ->patch(route('alpha.process.schedules.update', $legacy), [
                'school_class_id' => $template->legacy_school_class_id,
                'teacher_id' => $template->legacy_teacher_id,
                'room' => 'Canonical Target Room',
                'capacity' => $template->capacity,
                'day_of_week' => $template->day_of_week,
                'starts_at' => $template->starts_at->format('H:i'),
                'ends_at' => $template->ends_at->format('H:i'),
                'topic' => 'Canonical Target Topic',
                'student_ids' => $studentIds,
            ])
            ->assertRedirect();

        $template->refresh();
        $legacy->refresh();

        $this->assertSame('Canonical Target Room', $template->room);
        $this->assertSame('Canonical Target Topic', $template->legacy_topic);
        $this->assertSame('Canonical Target Room', $legacy->room);
        $this->assertSame('Canonical Target Topic', $legacy->topic);
        $this->artisan('legacy:reconcile')->assertExitCode(0);
    }

    public function test_target_conflict_guard_cannot_be_bypassed_by_corrupting_legacy_mirror(): void
    {
        $this->seed();

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $existing = SessionTemplate::query()->where('is_active', true)->firstOrFail();
        $legacy = WeeklySchedule::query()->findOrFail($existing->legacy_weekly_schedule_id);
        $otherClass = SchoolClass::query()->whereKeyNot($existing->legacy_school_class_id)->firstOrFail();
        $otherTeacher = Teacher::query()->whereKeyNot($existing->legacy_teacher_id)->firstOrFail();

        DB::table('weekly_schedules')->where('id', $legacy->id)->update([
            'school_class_id' => $otherClass->id,
            'teacher_id' => $otherTeacher->id,
            'room' => 'Hidden Legacy Room',
        ]);

        $this->actingAs($admin)
            ->post(route('alpha.process.schedules.store'), [
                'school_class_id' => $existing->legacy_school_class_id,
                'teacher_id' => $existing->legacy_teacher_id,
                'room' => $existing->room,
                'capacity' => 5,
                'day_of_week' => $existing->day_of_week,
                'starts_at' => $existing->starts_at->format('H:i'),
                'ends_at' => $existing->ends_at->format('H:i'),
                'topic' => 'Must Be Rejected',
            ])
            ->assertSessionHasErrors('starts_at');

        $this->assertDatabaseMissing('session_templates', ['legacy_topic' => 'Must Be Rejected']);
    }

    public function test_write_source_can_roll_back_to_legacy_and_existing_bridge_still_populates_target(): void
    {
        $this->seed();
        config()->set('montessori.scheduling.write_source', 'legacy');

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $class = SchoolClass::query()->firstOrFail();
        $teacher = Teacher::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('alpha.process.schedules.store'), [
                'school_class_id' => $class->id,
                'teacher_id' => $teacher->id,
                'room' => 'Legacy Rollback Room',
                'capacity' => 5,
                'day_of_week' => 6,
                'starts_at' => '15:00',
                'ends_at' => '16:00',
                'topic' => 'Legacy Rollback',
            ])
            ->assertRedirect();

        $legacy = WeeklySchedule::query()->where('room', 'Legacy Rollback Room')->firstOrFail();
        $this->assertDatabaseHas('session_templates', [
            'legacy_weekly_schedule_id' => $legacy->id,
            'room' => 'Legacy Rollback Room',
            'legacy_topic' => 'Legacy Rollback',
        ]);
    }

    public function test_target_delete_keeps_tombstone_but_removes_legacy_compatibility_handle(): void
    {
        $this->seed();

        $admin = User::query()->where('role', 'admin')->firstOrFail();
        $class = SchoolClass::query()->firstOrFail();
        $teacher = Teacher::query()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('alpha.process.schedules.store'), [
                'school_class_id' => $class->id,
                'teacher_id' => $teacher->id,
                'room' => 'Delete Cutover Room',
                'capacity' => 5,
                'day_of_week' => 6,
                'starts_at' => '16:00',
                'ends_at' => '17:00',
                'topic' => 'Delete Cutover',
            ])
            ->assertRedirect();

        $template = SessionTemplate::query()->where('room', 'Delete Cutover Room')->firstOrFail();
        $legacyId = (int) $template->legacy_weekly_schedule_id;
        $legacy = WeeklySchedule::query()->findOrFail($legacyId);

        $this->actingAs($admin)
            ->delete(route('alpha.process.schedules.destroy', $legacy))
            ->assertRedirect();

        $this->assertDatabaseMissing('weekly_schedules', ['id' => $legacyId]);
        $template->refresh();
        $this->assertFalse($template->is_active);
        $this->assertNotNull($template->legacy_deleted_at);
    }
}

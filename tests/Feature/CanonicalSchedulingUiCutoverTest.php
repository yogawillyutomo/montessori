<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\SchoolClass;
use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CanonicalSchedulingUiCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutation_routes_bind_canonical_scheduling_models(): void
    {
        $this->assertSame(
            ['sessionTemplate'],
            Route::getRoutes()->getByName('alpha.process.schedules.update')->parameterNames(),
        );
        $this->assertSame(
            ['sessionTemplate'],
            Route::getRoutes()->getByName('alpha.process.schedules.destroy')->parameterNames(),
        );
        $this->assertSame(
            ['sessionOccurrence'],
            Route::getRoutes()->getByName('alpha.process.sessions.update')->parameterNames(),
        );
        $this->assertSame(
            ['sessionOccurrence'],
            Route::getRoutes()->getByName('alpha.process.sessions.attendance')->parameterNames(),
        );
        $this->assertSame(
            ['sessionOccurrence'],
            Route::getRoutes()->getByName('alpha.process.sessions.destroy')->parameterNames(),
        );
    }

    public function test_process_forms_emit_only_canonical_schedule_and_occurrence_ids(): void
    {
        $this->seed();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('alpha.process.attendance', ['date' => '2026-06-08']))
            ->assertOk()
            ->assertSee('name="session_template_id"', false)
            ->assertDontSee('name="weekly_schedule_id"', false);

        $this->actingAs($admin)
            ->get(route('alpha.process.observations'))
            ->assertOk()
            ->assertSee('name="session_occurrence_id"', false)
            ->assertDontSee('name="class_session_id"', false);
    }

    public function test_schedule_ui_uses_template_id_when_legacy_mirror_id_diverges(): void
    {
        $this->seed();
        $admin = $this->admin();
        $class = SchoolClass::query()->firstOrFail();
        $teacher = Teacher::query()->firstOrFail();
        $student = Student::query()->firstOrFail();

        DB::table('weekly_schedules')->insert([
            'school_class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => 6,
            'starts_at' => '05:00',
            'ends_at' => '05:30',
            'room' => 'Legacy ID Spacer',
            'capacity' => 1,
            'topic' => 'Compatibility spacer',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('alpha.process.schedules.store'), [
                'school_class_id' => $class->id,
                'teacher_id' => $teacher->id,
                'room' => 'Canonical ID Room',
                'capacity' => 4,
                'day_of_week' => 6,
                'starts_at' => '15:00',
                'ends_at' => '16:00',
                'topic' => 'Canonical ID Template',
                'student_ids' => [$student->id],
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $template = SessionTemplate::query()
            ->where('legacy_topic', 'Canonical ID Template')
            ->firstOrFail();

        $this->assertNotSame($template->id, (int) $template->legacy_weekly_schedule_id);

        $this->actingAs($admin)
            ->patch(route('alpha.process.schedules.update', $template), [
                'school_class_id' => $class->id,
                'teacher_id' => $teacher->id,
                'room' => 'Canonical ID Room Updated',
                'capacity' => 4,
                'day_of_week' => 6,
                'starts_at' => '15:00',
                'ends_at' => '16:00',
                'topic' => 'Canonical ID Template Updated',
                'student_ids' => [$student->id],
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $template->refresh();
        $this->assertSame('Canonical ID Room Updated', $template->room);
        $this->assertDatabaseHas('weekly_schedules', [
            'id' => $template->legacy_weekly_schedule_id,
            'room' => 'Canonical ID Room Updated',
            'topic' => 'Canonical ID Template Updated',
        ]);
    }

    public function test_disabling_template_preserves_default_roster_for_ui_display(): void
    {
        $this->seed();
        $admin = $this->admin();
        $template = SessionTemplate::query()
            ->whereNull('legacy_deleted_at')
            ->whereHas('students')
            ->with('students')
            ->firstOrFail();
        $studentIds = $template->students->pluck('id')->sort()->values()->all();

        $this->actingAs($admin)
            ->patch(route('alpha.process.schedules.toggle', $template))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $template->refresh()->load('students');

        $this->assertFalse($template->is_active);
        $this->assertSame($studentIds, $template->students->pluck('id')->sort()->values()->all());
    }

    public function test_process_attendance_page_reads_occurrence_when_legacy_session_is_stale(): void
    {
        $this->seed();
        $admin = $this->admin();

        $occurrence = SessionOccurrence::query()
            ->whereNotNull('legacy_class_session_id')
            ->whereNull('legacy_deleted_at')
            ->orderBy('id')
            ->firstOrFail();
        $legacyId = (int) $occurrence->legacy_class_session_id;

        DB::table('session_occurrences')
            ->where('id', $occurrence->id)
            ->update([
                'room' => 'Canonical Attendance Room',
                'legacy_topic' => 'Canonical Attendance Topic',
            ]);
        DB::table('class_sessions')
            ->where('id', $legacyId)
            ->update([
                'room' => 'STALE ATTENDANCE ROOM',
                'topic' => 'STALE ATTENDANCE TOPIC',
            ]);

        $this->actingAs($admin)
            ->get(route('alpha.process.attendance', [
                'date' => $occurrence->occurs_on->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Canonical Attendance Room')
            ->assertSee('Canonical Attendance Topic')
            ->assertDontSee('STALE ATTENDANCE ROOM')
            ->assertDontSee('STALE ATTENDANCE TOPIC');
    }

    public function test_session_ui_uses_occurrence_id_when_legacy_mirror_id_diverges(): void
    {
        $this->seed();
        $admin = $this->admin();
        $template = SessionTemplate::query()
            ->where('day_of_week', 1)
            ->where('room', 'Ruang Sunny')
            ->firstOrFail();

        DB::table('class_sessions')->insert([
            'weekly_schedule_id' => $template->legacy_weekly_schedule_id,
            'school_class_id' => $template->legacy_school_class_id,
            'teacher_id' => $template->legacy_teacher_id,
            'session_date' => '2026-06-07',
            'starts_at' => '05:00',
            'ends_at' => '05:30',
            'room' => 'Legacy Session Spacer',
            'capacity' => 1,
            'topic' => 'Compatibility spacer',
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('alpha.sessions.create-from-schedule'), [
                'session_template_id' => $template->id,
                'session_date' => '2026-06-08',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $occurrence = SessionOccurrence::query()
            ->where('session_template_id', $template->id)
            ->whereDate('occurs_on', '2026-06-08')
            ->firstOrFail();

        $this->assertNotSame($occurrence->id, (int) $occurrence->legacy_class_session_id);

        $studentIds = $occurrence->bookings()
            ->where('status', 'scheduled')
            ->pluck('student_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->actingAs($admin)
            ->patch(route('alpha.process.sessions.update', $occurrence), [
                'school_class_id' => $occurrence->legacy_school_class_id,
                'teacher_id' => $occurrence->legacy_teacher_id,
                'room' => 'Canonical Occurrence Updated',
                'capacity' => $occurrence->capacity,
                'session_date' => $occurrence->occurs_on->toDateString(),
                'starts_at' => $occurrence->starts_at->format('H:i'),
                'ends_at' => $occurrence->ends_at->format('H:i'),
                'topic' => 'Canonical Occurrence Topic',
                'status' => 'planned',
                'student_ids_present' => true,
                'student_ids' => $studentIds,
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $occurrence->refresh();
        $this->assertSame('Canonical Occurrence Updated', $occurrence->room);
        $this->assertDatabaseHas('class_sessions', [
            'id' => $occurrence->legacy_class_session_id,
            'room' => 'Canonical Occurrence Updated',
            'topic' => 'Canonical Occurrence Topic',
        ]);

        $this->actingAs($admin)
            ->get(route('alpha.process.attendance', ['date' => '2026-06-08']))
            ->assertOk()
            ->assertSee(route('alpha.process.sessions.destroy', $occurrence), false)
            ->assertDontSee('/process/sessions/'.$occurrence->legacy_class_session_id, false);
    }

    public function test_legacy_rollback_still_runs_behind_canonical_route_identity(): void
    {
        $this->seed();
        config()->set('montessori.session.write_source', 'legacy');

        $admin = $this->admin();
        $template = SessionTemplate::query()
            ->where('day_of_week', 1)
            ->where('room', 'Ruang Sunny')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('alpha.sessions.create-from-schedule'), [
                'session_template_id' => $template->id,
                'session_date' => '2026-06-15',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $occurrence = SessionOccurrence::query()
            ->where('session_template_id', $template->id)
            ->whereDate('occurs_on', '2026-06-15')
            ->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('alpha.process.sessions.close', $occurrence), [
                'class_note' => 'Canonical route, legacy rollback writer.',
                'follow_up_recommendation' => null,
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $occurrence->refresh();
        $legacy = ClassSession::query()->findOrFail($occurrence->legacy_class_session_id);

        $this->assertSame('completed', $occurrence->status);
        $this->assertSame('completed', $legacy->status);
        $this->assertSame('Canonical route, legacy rollback writer.', $legacy->class_note);
    }

    private function admin(): User
    {
        return User::query()->where('email', 'ops@montessori.test')->firstOrFail();
    }
}

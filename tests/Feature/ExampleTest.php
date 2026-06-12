<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\DevelopmentArea;
use App\Models\IlpPlan;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\WeeklySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_alpha_pages_return_successful_responses(): void
    {
        $this->seed();
        $this->signIn();

        $this->get('/')->assertStatus(200)->assertSee('Dashboard Monitoring');
        $this->get('/master')->assertStatus(200)->assertSee('Master Data');
        $this->get('/process')->assertStatus(200)->assertSee('Proses Harian');
        $this->get('/reports')
            ->assertStatus(200)
            ->assertSee('Rapor Siswa')
            ->assertSee('Daftar Siswa untuk Rapor')
            ->assertDontSee('Rekap Presensi');
        $this->get('/settings/users')->assertStatus(200)->assertSee('User &amp; Login', false);
    }

    public function test_user_can_login_and_logout(): void
    {
        $this->seed();

        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertStatus(200)->assertSee('Masuk ke sistem');

        $this->post('/login', [
            'email' => 'admin@montessori.test',
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_can_manage_users(): void
    {
        $this->seed();
        $this->signIn();

        $this->from('/settings/users')->post(route('alpha.settings.users.store'), [
            'name' => 'Admin Cabang',
            'email' => 'admin.cabang@montessori.test',
            'password' => 'password-baru',
            'password_confirmation' => 'password-baru',
            'role' => 'admin',
            'phone' => '0812-9999-0000',
            'is_active' => '1',
        ])->assertRedirect('/settings/users')
            ->assertSessionDoesntHaveErrors();

        $user = User::query()->where('email', 'admin.cabang@montessori.test')->firstOrFail();

        $this->from('/settings/users')->patch(route('alpha.settings.users.update', $user), [
            'name' => 'Admin Cabang Utama',
            'email' => 'admin.cabang@montessori.test',
            'role' => 'teacher',
            'phone' => '0812-9999-0001',
            'is_active' => '1',
        ])->assertRedirect('/settings/users')
            ->assertSessionDoesntHaveErrors();

        $user->refresh();
        $this->assertSame('Admin Cabang Utama', $user->name);
        $this->assertSame('teacher', $user->role);

        $this->delete(route('alpha.settings.users.destroy', $user))->assertRedirect();
        $this->assertDatabaseMissing('users', ['email' => 'admin.cabang@montessori.test']);
    }

    public function test_user_can_update_own_profile_and_password(): void
    {
        $this->seed();
        $user = $this->signIn();

        $this->from('/')->patch(route('alpha.profile.update'), [
            'name' => 'Admin Operasional Baru',
            'email' => 'admin.baru@montessori.test',
            'phone' => '0812-1234-5678',
            'current_password' => 'password',
            'password' => 'password-baru',
            'password_confirmation' => 'password-baru',
        ])->assertRedirect('/')
            ->assertSessionDoesntHaveErrors();

        $user->refresh();

        $this->assertSame('Admin Operasional Baru', $user->name);
        $this->assertSame('admin.baru@montessori.test', $user->email);
        $this->assertSame('0812-1234-5678', $user->phone);

        auth()->logout();

        $this->post('/login', [
            'email' => 'admin.baru@montessori.test',
            'password' => 'password-baru',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_teacher_reports_are_limited_to_scheduled_students(): void
    {
        $this->seed();
        $this->signIn('raras@montessori.test');

        $this->get('/reports')
            ->assertStatus(200)
            ->assertSee('Alya Pramesti')
            ->assertSee('Raka Mahendra')
            ->assertSee('Kirana Satya')
            ->assertDontSee('Arka Wijaya');

        $arkaReport = Report::query()
            ->whereHas('student', fn ($query) => $query->where('code', 'GLO02'))
            ->firstOrFail();

        $this->get(route('alpha.reports.show', $arkaReport))->assertForbidden();
    }

    public function test_admin_can_filter_reports_by_scheduled_teacher(): void
    {
        $this->seed();
        $this->signIn();

        $mira = Teacher::query()->where('code', 'TCH02')->firstOrFail();

        $this->get('/reports?teacher_id='.$mira->id)
            ->assertStatus(200)
            ->assertSee('Kirana Satya')
            ->assertSee('Arka Wijaya')
            ->assertDontSee('Alya Pramesti');
    }

    public function test_reports_are_student_first_and_store_manual_attendance(): void
    {
        $this->seed();
        $this->signIn();

        $student = Student::query()->where('code', 'SUN01')->firstOrFail();
        $term = \App\Models\Term::query()->where('is_current', true)->firstOrFail();

        Report::query()->where('student_id', $student->id)->delete();

        $this->get('/reports')
            ->assertStatus(200)
            ->assertSee('Alya Pramesti')
            ->assertSee('Belum Dibuat')
            ->assertDontSee('Rekap Presensi');

        $this->post(route('alpha.reports.students.draft', ['student' => $student, 'term_id' => $term->id]))
            ->assertRedirect();

        $report = Report::query()
            ->where('student_id', $student->id)
            ->firstOrFail();

        $this->assertSame('draft', $report->status);
        $this->assertSame('Alya Pramesti', $report->summary['biodata']['name']);
        $this->assertSame(0, $report->summary['attendance']['recorded']);
        $this->assertArrayHasKey('ilp_plans', $report->summary);

        $this->from(route('alpha.reports.student', ['student' => $student, 'term_id' => $term->id]))
            ->patch(route('alpha.reports.students.update', ['student' => $student, 'term_id' => $term->id]), [
                'term_id' => $term->id,
                'status' => 'ready',
                'manual_present_total' => 18,
                'manual_sick_total' => 1,
                'manual_excused_total' => 0,
                'manual_absent_total' => 0,
                'manual_late_total' => 2,
                'manual_attendance_note' => 'Kehadiran baik dan dua kali terlambat.',
                'teacher_narrative' => 'Alya menunjukkan rasa ingin tahu yang stabil.',
                'general_narrative' => 'Alya mengikuti ritme kelas dengan nyaman.',
            ])
            ->assertRedirect(route('alpha.reports.student', ['student' => $student, 'term_id' => $term->id]))
            ->assertSessionDoesntHaveErrors();

        $report->refresh();

        $this->assertSame('ready', $report->status);
        $this->assertSame(18, $report->manual_present_total);
        $this->assertSame(2, $report->manual_late_total);
        $this->assertSame(21, $report->summary['attendance']['recorded']);
        $this->assertEquals(95.2, $report->summary['attendance']['attendance_rate']);

        $this->from(route('alpha.reports.student', ['student' => $student, 'term_id' => $term->id]))
            ->patch(route('alpha.reports.students.update', ['student' => $student, 'term_id' => $term->id]), [
                'term_id' => $term->id,
                'status' => 'draft',
                'manual_present_total' => -1,
            ])
            ->assertRedirect(route('alpha.reports.student', ['student' => $student, 'term_id' => $term->id]))
            ->assertSessionHasErrors('manual_present_total');

        $this->get(route('alpha.reports.show', $report))
            ->assertStatus(200)
            ->assertSee('Detail Rapor Siswa')
            ->assertSee('Data Kehadiran Manual untuk Rapor')
            ->assertSee('Alya menunjukkan rasa ingin tahu yang stabil.');
    }

    public function test_main_navigation_hides_attendance_menu(): void
    {
        $this->seed();
        $this->signIn();

        $this->get('/reports')
            ->assertOk()
            ->assertDontSee('Sesi Belajar')
            ->assertDontSee('Panel Presensi')
            ->assertDontSee('Presensi Terakhir');
    }

    public function test_weekly_schedule_rejects_overlapping_room(): void
    {
        $this->seed();
        $this->signIn();

        $class = SchoolClass::query()->where('name', 'Sunny 2')->firstOrFail();
        $teacher = Teacher::query()->where('code', 'TCH02')->firstOrFail();

        $this->from('/process/schedules')->post(route('alpha.process.schedules.store'), [
            'school_class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'room' => 'Ruang Sunny',
            'day_of_week' => 1,
            'starts_at' => '08:30',
            'ends_at' => '09:00',
            'topic' => 'Tes bentrok ruangan',
        ])->assertRedirect('/process/schedules')
            ->assertSessionHasErrors('starts_at');
    }

    public function test_daily_monitoring_stores_multiple_observations_and_creates_ilp_follow_up(): void
    {
        $this->seed();
        $this->signIn();

        $student = Student::query()->where('code', 'SUN01')->firstOrFail();
        $session = ClassSession::query()
            ->whereHas('students', fn ($query) => $query->whereKey($student->id))
            ->firstOrFail();
        $teacher = Teacher::query()->where('code', 'TCH01')->firstOrFail();
        $indicators = Indicator::query()->whereIn('code', ['PMK01', 'PMK02'])->get()->keyBy('code');

        $this->from('/process/observations')->post(route('alpha.observations.store'), [
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'observed_on' => '2026-06-10',
            'note' => 'Monitoring harian dari form baru.',
            'observations' => [
                $indicators['PMK01']->id => ['status' => 'independent'],
                $indicators['PMK02']->id => ['status' => 'emerging'],
            ],
        ])->assertRedirect('/process/observations#monitoring-harian')
            ->assertSessionDoesntHaveErrors();

        $this->assertTrue(Observation::query()
            ->where('student_id', $student->id)
            ->where('indicator_id', $indicators['PMK01']->id)
            ->where('level', 'independent')
            ->where('status', 'included_in_report')
            ->exists());

        $this->assertTrue(IlpPlan::query()
            ->where('student_id', $student->id)
            ->where('indicator_id', $indicators['PMK02']->id)
            ->exists());

        $this->from('/process/observations')->post(route('alpha.observations.store'), [
            'class_session_id' => $session->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'observed_on' => '2026-06-10',
            'observations' => [
                $indicators['PMK01']->id => ['status' => 'developing'],
            ],
        ])->assertRedirect('/process/observations#monitoring-harian')
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(2, Observation::query()
            ->where('class_session_id', $session->id)
            ->where('student_id', $student->id)
            ->whereDate('observed_on', '2026-06-10')
            ->where('indicator_id', $indicators['PMK01']->id)
            ->count());

        $this->assertTrue(Observation::query()
            ->where('class_session_id', $session->id)
            ->where('student_id', $student->id)
            ->where('indicator_id', $indicators['PMK01']->id)
            ->whereDate('observed_on', '2026-06-10')
            ->where('level', 'developing')
            ->exists());
    }

    public function test_attendance_can_be_marked_all_present_and_reset_to_unmarked(): void
    {
        $this->seed();
        $this->signIn();

        $schedule = WeeklySchedule::query()->with('students')->where('day_of_week', 1)->firstOrFail();

        $this->from('/process/attendance')->post(route('alpha.sessions.create-from-schedule'), [
            'weekly_schedule_id' => $schedule->id,
            'session_date' => '2026-06-08',
        ])->assertRedirect('/process/attendance')
            ->assertSessionDoesntHaveErrors();

        $session = ClassSession::query()
            ->where('weekly_schedule_id', $schedule->id)
            ->whereDate('session_date', '2026-06-08')
            ->firstOrFail();

        $this->assertSame($session->students()->count(), $session->attendances()->whereNull('marked_at')->count());

        $payload = $session->students()
            ->pluck('students.id')
            ->mapWithKeys(fn ($studentId) => [$studentId => ['status' => 'unmarked', 'note' => null]])
            ->all();

        $this->patch(route('alpha.process.sessions.attendance', $session), [
            'attendance_action' => 'all_present',
            'attendance' => $payload,
        ])->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame($session->students()->count(), $session->attendances()->where('status', 'present')->whereNotNull('marked_at')->count());

        $this->patch(route('alpha.process.sessions.attendance', $session), [
            'attendance_action' => 'reset',
            'attendance' => $payload,
        ])->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame($session->students()->count(), $session->attendances()->whereNull('marked_at')->count());
    }

    public function test_teacher_can_store_spontaneous_observation_without_session(): void
    {
        $this->seed();
        $teacherUser = $this->signIn('raras@montessori.test');

        $teacher = Teacher::query()->where('user_id', $teacherUser->id)->firstOrFail();
        $student = Student::query()->where('code', 'SUN01')->firstOrFail();
        $area = DevelopmentArea::query()->where('slug', 'sosial-emosional')->firstOrFail();

        $this->from('/process/observations')->post(route('alpha.observations.store'), [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'development_area_id' => $area->id,
            'observed_on' => '2026-06-11',
            'level' => 'developing',
            'note' => 'Alya mulai mengajak teman bergantian memakai material saat kegiatan bebas.',
            'include_in_report' => '1',
        ])->assertRedirect('/process/observations#monitoring-harian')
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('observations', [
            'class_session_id' => null,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'development_area_id' => $area->id,
            'indicator_id' => null,
            'observation_type' => 'spontaneous',
            'level' => 'developing',
            'status' => 'included_in_report',
        ]);
    }

    public function test_ilp_plan_can_be_updated_from_process_page(): void
    {
        $this->seed();
        $this->signIn();

        $plan = IlpPlan::query()->firstOrFail();

        $this->from('/process/ilp')->patch(route('alpha.process.ilp.update', $plan), [
            'status' => 'in_progress',
            'analysis' => 'Anak perlu penguatan motorik kasar secara bertahap.',
            'target' => 'Mampu mencoba aktivitas dengan instruksi singkat dan bantuan minimal.',
            'follow_up' => 'Latihan singkat 10 menit setiap sesi dan komunikasi ke orangtua.',
            'starts_on' => '2026-06-11',
            'ends_on' => '2026-07-11',
        ])->assertRedirect("/process/ilp#ilp-plan-{$plan->id}")
            ->assertSessionDoesntHaveErrors();

        $plan->refresh();

        $this->assertSame('in_progress', $plan->status);
        $this->assertSame('Mampu mencoba aktivitas dengan instruksi singkat dan bantuan minimal.', $plan->target);
        $this->assertSame('2026-06-11', $plan->starts_on->toDateString());
        $this->assertSame('2026-07-11', $plan->ends_on->toDateString());
    }

    public function test_weekly_schedule_allows_cross_class_students_when_slot_is_available(): void
    {
        $this->seed();
        $this->signIn();

        $class = SchoolClass::query()->where('name', 'Sunny 2')->firstOrFail();
        $teacher = Teacher::query()->where('code', 'TCH02')->firstOrFail();
        $student = Student::query()->where('code', 'GLO01')->firstOrFail();

        $this->from('/process/schedules')->post(route('alpha.process.schedules.store'), [
            'school_class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'room' => 'Ruang Fleksibel',
            'capacity' => 2,
            'day_of_week' => 1,
            'starts_at' => '11:30',
            'ends_at' => '12:00',
            'topic' => 'Slot lintas kelas',
            'student_ids' => [$student->id],
        ])->assertRedirect('/process/schedules')
            ->assertSessionDoesntHaveErrors();

        $this->assertTrue(
            WeeklySchedule::query()
                ->where('room', 'Ruang Fleksibel')
                ->whereHas('students', fn ($query) => $query->whereKey($student->id))
                ->exists()
        );
    }

    public function test_weekly_schedule_rejects_student_capacity_overflow(): void
    {
        $this->seed();
        $this->signIn();

        $class = SchoolClass::query()->where('name', 'Sunny 2')->firstOrFail();
        $teacher = Teacher::query()->where('code', 'TCH02')->firstOrFail();
        $students = Student::query()->whereIn('code', ['GLO01', 'GLO02'])->pluck('id')->all();

        $this->from('/process/schedules')->post(route('alpha.process.schedules.store'), [
            'school_class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'room' => 'Ruang Kapasitas',
            'capacity' => 1,
            'day_of_week' => 1,
            'starts_at' => '12:30',
            'ends_at' => '13:00',
            'topic' => 'Slot penuh',
            'student_ids' => $students,
        ])->assertRedirect('/process/schedules')
            ->assertSessionHasErrors('student_ids');
    }

    public function test_weekly_schedule_rejects_overlapping_student(): void
    {
        $this->seed();
        $this->signIn();

        $class = SchoolClass::query()->where('name', 'Sunny 2')->firstOrFail();
        $teacher = Teacher::query()->where('code', 'TCH02')->firstOrFail();
        $student = Student::query()->where('code', 'SUN01')->firstOrFail();

        $this->from('/process/schedules')->post(route('alpha.process.schedules.store'), [
            'school_class_id' => $class->id,
            'teacher_id' => $teacher->id,
            'room' => 'Ruang Bebas',
            'capacity' => 7,
            'day_of_week' => 1,
            'starts_at' => '08:30',
            'ends_at' => '09:00',
            'topic' => 'Bentrok siswa',
            'student_ids' => [$student->id],
        ])->assertRedirect('/process/schedules')
            ->assertSessionHasErrors('student_ids');
    }

    public function test_class_session_rejects_overlapping_room(): void
    {
        $this->seed();
        $this->signIn();

        $sunny = SchoolClass::query()->where('name', 'Sunny 1')->firstOrFail();
        $glow = SchoolClass::query()->where('name', 'Glow 1')->firstOrFail();
        $raras = Teacher::query()->where('code', 'TCH01')->firstOrFail();
        $mira = Teacher::query()->where('code', 'TCH02')->firstOrFail();

        ClassSession::query()->create([
            'school_class_id' => $sunny->id,
            'teacher_id' => $raras->id,
            'room' => 'Ruang Tes',
            'session_date' => '2026-06-15',
            'starts_at' => '08:00',
            'ends_at' => '09:00',
            'topic' => 'Sesi pembanding',
            'status' => 'planned',
        ]);

        $session = ClassSession::query()->create([
            'school_class_id' => $glow->id,
            'teacher_id' => $mira->id,
            'room' => 'Ruang Lain',
            'session_date' => '2026-06-15',
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'topic' => 'Sesi kandidat',
            'status' => 'planned',
        ]);

        $this->from('/process/sessions')->patch(route('alpha.process.sessions.update', $session), [
            'school_class_id' => $glow->id,
            'teacher_id' => $mira->id,
            'room' => 'Ruang Tes',
            'session_date' => '2026-06-15',
            'starts_at' => '08:30',
            'ends_at' => '09:00',
            'topic' => 'Tes bentrok ruangan',
            'status' => 'planned',
        ])->assertRedirect('/process/sessions')
            ->assertSessionHasErrors('starts_at');
    }

    private function signIn(string $email = 'admin@montessori.test'): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();

        $this->actingAs($user);

        return $user;
    }
}

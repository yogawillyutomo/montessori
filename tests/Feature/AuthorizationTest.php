<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Indicator;
use App\Models\Report;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_protected_routes_are_enforced(): void
    {
        $this->seed();

        $this->actingAs($this->user('parent@montessori.test'))
            ->get('/master/students')
            ->assertForbidden();

        $this->actingAs($this->user('raras@montessori.test'))
            ->get('/settings/users')
            ->assertForbidden();

        $this->actingAs($this->user('ops@montessori.test'))
            ->get('/master/students')
            ->assertOk();

        $this->actingAs($this->user('admin@montessori.test'))
            ->get('/settings/users')
            ->assertOk();
    }

    public function test_parent_only_sees_own_published_report(): void
    {
        $this->seed();

        $alya = Student::query()->where('code', 'SUN01')->firstOrFail();
        $kirana = Student::query()->where('code', 'GLO01')->firstOrFail();

        $ownReport = Report::query()->where('student_id', $alya->id)->firstOrFail();
        $ownReport->update(['status' => 'published', 'published_at' => now()]);

        $otherReport = Report::query()->where('student_id', $kirana->id)->firstOrFail();
        $otherReport->update(['status' => 'published', 'published_at' => now()]);

        $this->actingAs($this->user('parent@montessori.test'))
            ->get('/reports')
            ->assertOk()
            ->assertSee('Alya Pramesti')
            ->assertDontSee('Kirana Satya');

        $this->get(route('alpha.reports.show', $ownReport))->assertOk();
        $this->get(route('alpha.reports.show', $otherReport))->assertForbidden();
    }

    public function test_parent_cannot_open_own_draft_report(): void
    {
        $this->seed();

        $alya = Student::query()->where('code', 'SUN01')->firstOrFail();
        $report = Report::query()->where('student_id', $alya->id)->firstOrFail();
        $report->update(['status' => 'draft']);

        $this->actingAs($this->user('parent@montessori.test'))
            ->get(route('alpha.reports.show', $report))
            ->assertForbidden();
    }

    public function test_teacher_can_observe_own_session_but_not_other_teacher_session(): void
    {
        $this->seed();

        $teacherUser = $this->user('raras@montessori.test');
        $teacher = Teacher::query()->where('user_id', $teacherUser->id)->firstOrFail();
        $indicator = Indicator::query()->where('code', 'BHS02')->firstOrFail();

        $ownSession = ClassSession::query()
            ->where('teacher_id', $teacher->id)
            ->whereHas('students', fn ($query) => $query->where('code', 'SUN01'))
            ->firstOrFail();
        $ownStudent = Student::query()->where('code', 'SUN01')->firstOrFail();

        $this->actingAs($teacherUser)
            ->from('/process/observations')
            ->post(route('alpha.observations.store'), [
                'class_session_id' => $ownSession->id,
                'student_id' => $ownStudent->id,
                'teacher_id' => $teacher->id,
                'observed_on' => $ownSession->session_date->toDateString(),
                'note' => 'Observasi scope guru.',
                'observations' => [
                    $indicator->id => ['status' => 'independent'],
                ],
            ])
            ->assertRedirect(route('alpha.process.observations').'#monitoring-harian')
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('observations', [
            'class_session_id' => $ownSession->id,
            'student_id' => $ownStudent->id,
            'indicator_id' => $indicator->id,
            'teacher_id' => $teacher->id,
        ]);

        $otherSession = ClassSession::query()
            ->where('teacher_id', '!=', $teacher->id)
            ->whereHas('students', fn ($query) => $query->where('code', 'GLO01'))
            ->firstOrFail();
        $otherStudent = Student::query()->where('code', 'GLO01')->firstOrFail();

        $this->post(route('alpha.observations.store'), [
            'class_session_id' => $otherSession->id,
            'student_id' => $otherStudent->id,
            'teacher_id' => $teacher->id,
            'observed_on' => $otherSession->session_date->toDateString(),
            'note' => 'Harus ditolak.',
            'observations' => [
                $indicator->id => ['status' => 'independent'],
            ],
        ])->assertForbidden();
    }

    public function test_generate_report_is_limited_to_allowed_roles(): void
    {
        $this->seed();

        $this->actingAs($this->user('principal@montessori.test'))
            ->post(route('alpha.reports.generate'))
            ->assertForbidden();

        $this->actingAs($this->user('raras@montessori.test'))
            ->post(route('alpha.reports.generate'))
            ->assertRedirect();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}

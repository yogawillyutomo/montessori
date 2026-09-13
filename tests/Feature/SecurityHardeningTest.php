<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Report;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Alpha\AccessScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_cannot_attach_out_of_scope_student_to_owned_session(): void
    {
        $this->seed();

        $teacherUser = $this->user('raras@montessori.test');
        $teacher = Teacher::query()->where('user_id', $teacherUser->id)->firstOrFail();
        $session = ClassSession::query()->where('teacher_id', $teacher->id)->with('students')->firstOrFail();

        $allowedStudentIds = app(AccessScopeService::class)->accessibleStudentIds($teacherUser);
        $outsideStudent = Student::query()->whereNotIn('id', $allowedStudentIds)->firstOrFail();

        $payload = $this->sessionPayload($session, [
            'student_ids' => [...$session->students->pluck('id')->all(), $outsideStudent->id],
        ]);

        $this->actingAs($teacherUser)
            ->patch(route('alpha.process.sessions.update', $session), $payload)
            ->assertForbidden();

        $this->assertFalse(
            $session->students()->whereKey($outsideStudent->id)->exists(),
            'Out-of-scope student must not be attached to the teacher session.'
        );
    }

    public function test_teacher_cannot_reassign_owned_session_to_another_teacher(): void
    {
        $this->seed();

        $teacherUser = $this->user('raras@montessori.test');
        $teacher = Teacher::query()->where('user_id', $teacherUser->id)->firstOrFail();
        $otherTeacher = Teacher::query()->whereKeyNot($teacher->id)->firstOrFail();
        $session = ClassSession::query()->where('teacher_id', $teacher->id)->with('students')->firstOrFail();

        $this->actingAs($teacherUser)
            ->patch(route('alpha.process.sessions.update', $session), $this->sessionPayload($session, [
                'teacher_id' => $otherTeacher->id,
            ]))
            ->assertForbidden();

        $this->assertSame($teacher->id, $session->fresh()->teacher_id);
    }

    public function test_teacher_cannot_reassign_owned_session_to_another_class(): void
    {
        $this->seed();

        $teacherUser = $this->user('raras@montessori.test');
        $teacher = Teacher::query()->where('user_id', $teacherUser->id)->firstOrFail();
        $session = ClassSession::query()->where('teacher_id', $teacher->id)->with('students')->firstOrFail();
        $otherClassId = Student::query()
            ->where('school_class_id', '!=', $session->school_class_id)
            ->value('school_class_id');

        $this->assertNotNull($otherClassId);

        $this->actingAs($teacherUser)
            ->patch(route('alpha.process.sessions.update', $session), $this->sessionPayload($session, [
                'school_class_id' => $otherClassId,
            ]))
            ->assertForbidden();

        $this->assertSame($session->school_class_id, $session->fresh()->school_class_id);
    }

    public function test_generic_report_save_cannot_publish_a_draft(): void
    {
        $this->seed();

        $teacherUser = $this->user('raras@montessori.test');
        $student = $this->firstAccessibleStudentFor($teacherUser);
        $report = Report::query()->where('student_id', $student->id)->firstOrFail();
        $report->forceFill(['status' => 'draft', 'published_at' => null])->save();

        $this->actingAs($teacherUser)
            ->from(route('alpha.reports.student', ['student' => $student, 'term_id' => $report->term_id]))
            ->patch(route('alpha.reports.students.update', $student), [
                'term_id' => $report->term_id,
                'status' => 'published',
                'teacher_narrative' => 'Should never be published through generic save.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $report->fresh()->status);
    }

    public function test_published_report_cannot_be_mutated_through_generic_save(): void
    {
        $this->seed();

        $admin = $this->user('ops@montessori.test');
        $report = Report::query()->firstOrFail();
        $report->forceFill([
            'status' => 'published',
            'teacher_narrative' => 'Immutable published narrative.',
            'published_at' => now(),
        ])->save();

        $student = $report->student;

        $this->actingAs($admin)
            ->from(route('alpha.reports.student', ['student' => $student, 'term_id' => $report->term_id]))
            ->patch(route('alpha.reports.students.update', $student), [
                'term_id' => $report->term_id,
                'status' => 'draft',
                'teacher_narrative' => 'MUTATED',
            ])
            ->assertSessionHasErrors('status');

        $report->refresh();
        $this->assertSame('published', $report->status);
        $this->assertSame('Immutable published narrative.', $report->teacher_narrative);
    }

    public function test_build_draft_does_not_mutate_published_report(): void
    {
        $this->seed();

        $teacherUser = $this->user('raras@montessori.test');
        $student = $this->firstAccessibleStudentFor($teacherUser);
        $report = Report::query()->where('student_id', $student->id)->firstOrFail();
        $originalGeneratedAt = now()->subDay()->startOfSecond();

        $report->forceFill([
            'status' => 'published',
            'teacher_narrative' => 'Final published copy.',
            'published_at' => now(),
            'generated_at' => $originalGeneratedAt,
        ])->save();

        $this->actingAs($teacherUser)
            ->post(route('alpha.reports.students.draft', $student), [
                'term_id' => $report->term_id,
            ])
            ->assertRedirect();

        $report->refresh();
        $this->assertSame('published', $report->status);
        $this->assertSame('Final published copy.', $report->teacher_narrative);
        $this->assertTrue($report->generated_at->equalTo($originalGeneratedAt));
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $this->seed();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'email' => 'admin@montessori.test',
                'password' => 'definitely-wrong',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/login', [
            'email' => 'admin@montessori.test',
            'password' => 'definitely-wrong',
        ])->assertTooManyRequests();
    }

    public function test_new_user_without_explicit_role_does_not_default_to_admin(): void
    {
        $user = User::query()->create([
            'name' => 'No Implicit Role',
            'email' => 'no-role@example.test',
            'password' => 'strong-password',
        ]);

        $this->assertNull($user->role);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function firstAccessibleStudentFor(User $user): Student
    {
        $studentId = app(AccessScopeService::class)->accessibleStudentIds($user)[0] ?? null;
        $this->assertNotNull($studentId, 'Expected seeded teacher to have at least one accessible student.');

        return Student::query()->findOrFail($studentId);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sessionPayload(ClassSession $session, array $overrides = []): array
    {
        $session->loadMissing('students');

        return array_replace([
            'school_class_id' => $session->school_class_id,
            'teacher_id' => $session->teacher_id,
            'room' => $session->room,
            'capacity' => max(1, (int) ($session->capacity ?: $session->students->count())),
            'session_date' => $session->session_date->toDateString(),
            'starts_at' => $session->starts_at->format('H:i'),
            'ends_at' => $session->ends_at->format('H:i'),
            'topic' => $session->topic,
            'status' => $session->status,
            'student_ids_present' => true,
            'student_ids' => $session->students->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ], $overrides);
    }
}

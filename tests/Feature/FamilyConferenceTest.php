<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassLevel;
use App\Models\FamilyConference;
use App\Models\Guardian;
use App\Models\Report;
use App\Models\ReportVersion;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Family\FamilyConferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FamilyConferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_only_sees_conferences_explicitly_shared_for_their_child(): void
    {
        $context = $this->context('M15-VISIBILITY');

        $this->actingAs($context['admin'])
            ->post(route('alpha.family-conferences.store', $context['student']), [
                'conference_on' => '2026-09-14',
                'summary' => 'Internal conference note.',
                'share_with_parent' => '0',
            ])
            ->assertRedirect(route('alpha.family-conferences.index', $context['student']));

        $conference = FamilyConference::query()->firstOrFail();
        $this->assertFalse($conference->share_with_parent);

        $this->actingAs($context['parent'])
            ->get(route('alpha.family-conferences.index', $context['student']))
            ->assertOk()
            ->assertDontSee('Internal conference note.');

        $this->actingAs($context['admin'])
            ->patch(route('alpha.family-conferences.update', $conference), [
                'conference_on' => '2026-09-14',
                'summary' => 'Shared conference summary.',
                'strengths' => 'Strong independence.',
                'agreed_follow_up' => 'Continue consistent home routine.',
                'share_with_parent' => '1',
            ])
            ->assertRedirect(route('alpha.family-conferences.index', $context['student']));

        $this->actingAs($context['parent'])
            ->get(route('alpha.family-conferences.index', $context['student']))
            ->assertOk()
            ->assertSee('Shared conference summary.')
            ->assertSee('Continue consistent home routine.')
            ->assertDontSee('Internal conference note.');
    }

    public function test_shared_conference_requires_summary_and_valid_review_date(): void
    {
        $context = $this->context('M15-VALIDATION');

        $this->actingAs($context['admin'])
            ->from(route('alpha.family-conferences.index', $context['student']))
            ->post(route('alpha.family-conferences.store', $context['student']), [
                'conference_on' => '2026-09-14',
                'next_review_on' => '2026-09-13',
                'share_with_parent' => '1',
            ])
            ->assertRedirect(route('alpha.family-conferences.index', $context['student']))
            ->assertSessionHasErrors(['summary', 'next_review_on']);

        $this->assertSame(0, FamilyConference::query()->count());
    }

    public function test_report_version_context_must_belong_to_same_student(): void
    {
        $first = $this->context('M15-FIRST');
        $second = $this->context('M15-SECOND');
        $version = $this->publishedVersion($second['student'], $second['term'], $second['admin']);

        $this->expectException(ValidationException::class);

        app(FamilyConferenceService::class)->create($first['student'], $first['admin'], [
            'conference_on' => '2026-09-14',
            'report_version_id' => $version->id,
            'summary' => 'Must not link another child report.',
            'share_with_parent' => false,
        ]);
    }

    public function test_principal_and_parent_cannot_mutate_family_conference(): void
    {
        $context = $this->context('M15-ROLES');

        $payload = [
            'conference_on' => '2026-09-14',
            'summary' => 'Unauthorized mutation.',
            'share_with_parent' => '0',
        ];

        $this->actingAs($context['principal'])
            ->post(route('alpha.family-conferences.store', $context['student']), $payload)
            ->assertForbidden();

        $this->actingAs($context['parent'])
            ->post(route('alpha.family-conferences.store', $context['student']), $payload)
            ->assertForbidden();

        $this->assertSame(0, FamilyConference::query()->count());
    }

    public function test_parent_cannot_open_another_child_family_conference_page(): void
    {
        $owner = $this->context('M15-OWNER');
        $other = $this->context('M15-OTHER');

        app(FamilyConferenceService::class)->create($other['student'], $other['admin'], [
            'conference_on' => '2026-09-14',
            'summary' => 'Other child shared note.',
            'share_with_parent' => true,
        ]);

        $this->actingAs($owner['parent'])
            ->get(route('alpha.family-conferences.index', $other['student']))
            ->assertForbidden();
    }

    /**
     * @return array{admin: User, principal: User, parent: User, student: Student, term: Term}
     */
    private function context(string $code): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        $principal = User::factory()->create([
            'role' => 'principal',
            'is_active' => true,
        ]);
        $parent = User::factory()->create([
            'role' => 'parent',
            'is_active' => true,
        ]);
        $guardian = Guardian::query()->create([
            'user_id' => $parent->id,
            'name' => $code.' Parent',
            'relationship' => 'Orangtua',
        ]);
        $level = ClassLevel::query()->create([
            'name' => $code,
            'slug' => strtolower($code),
            'sequence' => 800,
            'is_active' => true,
        ]);
        $schoolClass = SchoolClass::query()->create([
            'class_level_id' => $level->id,
            'name' => $code.' Class',
            'slug' => strtolower($code).'-class',
            'level' => $code,
            'capacity' => 12,
            'color' => 'sage',
            'is_active' => true,
        ]);
        $student = Student::query()->create([
            'school_class_id' => $schoolClass->id,
            'guardian_id' => $guardian->id,
            'code' => $code.'-STUDENT',
            'name' => $code.' Student',
            'status' => 'active',
        ]);
        $academicYear = AcademicYear::query()->create([
            'name' => $code.' Academic Year',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'is_active' => true,
        ]);
        $term = Term::query()->create([
            'academic_year_id' => $academicYear->id,
            'name' => $code.' Semester 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-12-31',
            'is_current' => true,
        ]);

        return compact('admin', 'principal', 'parent', 'student', 'term');
    }

    private function publishedVersion(Student $student, Term $term, User $publisher): ReportVersion
    {
        $report = Report::query()->create([
            'student_id' => $student->id,
            'term_id' => $term->id,
            'status' => 'published',
            'summary' => [],
            'published_at' => now(),
        ]);

        $version = ReportVersion::query()->create([
            'report_id' => $report->id,
            'version_number' => 1,
            'snapshot' => [
                'schema_version' => 1,
                'report' => [
                    'id' => $report->id,
                    'student_id' => $student->id,
                    'term_id' => $term->id,
                    'status' => 'published',
                ],
            ],
            'published_by' => $publisher->id,
            'published_at' => now(),
        ]);

        $report->forceFill(['published_version_id' => $version->id])->save();

        return $version;
    }
}

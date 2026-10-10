<?php

namespace Tests\Feature;

use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionAuthorizationFailClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_without_teacher_profile_cannot_mutate_sessions_or_create_from_schedule(): void
    {
        $this->seed();

        $orphanTeacher = User::query()->create([
            'name' => 'Orphan Guide',
            'email' => 'orphan-guide@montessori.test',
            'password' => 'password',
            'role' => 'teacher',
            'is_active' => true,
        ]);

        $template = SessionTemplate::query()->whereNull('legacy_deleted_at')->firstOrFail();
        $occurrence = SessionOccurrence::query()->whereNull('legacy_deleted_at')->firstOrFail();

        $this->actingAs($orphanTeacher)
            ->post(route('alpha.sessions.create-from-schedule'), [
                'session_template_id' => $template->id,
                'session_date' => '2026-06-08',
            ])
            ->assertForbidden();

        $this->actingAs($orphanTeacher)
            ->patch(route('alpha.process.sessions.note', $occurrence), [
                'class_note' => 'Unauthorized note',
                'follow_up_recommendation' => null,
            ])
            ->assertForbidden();

        $this->actingAs($orphanTeacher)
            ->patch(route('alpha.process.sessions.close', $occurrence), [
                'class_note' => null,
                'follow_up_recommendation' => null,
            ])
            ->assertForbidden();

        $this->actingAs($orphanTeacher)
            ->delete(route('alpha.process.sessions.destroy', $occurrence))
            ->assertForbidden();
    }
}

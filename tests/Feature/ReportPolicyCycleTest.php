<?php

namespace Tests\Feature;

use App\Models\ClassLevel;
use App\Models\ReportCycle;
use App\Models\ReportPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class ReportPolicyCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_m12_does_not_infer_policy_or_cycle_from_existing_program_levels(): void
    {
        $this->assertGreaterThanOrEqual(3, ClassLevel::query()->count());
        $this->assertSame(0, ReportPolicy::query()->count());
        $this->assertSame(0, ReportCycle::query()->count());
    }

    public function test_programs_can_define_distinct_configurable_report_policies(): void
    {
        $infant = ClassLevel::query()->where('slug', 'infant')->firstOrFail();
        $glow = ClassLevel::query()->where('slug', 'glow')->firstOrFail();

        $infantPolicy = $this->policy($infant, 'Infant Standard', 60, 8);
        $glowPolicy = $this->policy($glow, 'Glow Standard', 90, 8);

        $this->assertSame(60, $infantPolicy->minimum_observation_days);
        $this->assertSame(90, $glowPolicy->minimum_observation_days);
        $this->assertSame(8, $infantPolicy->minimum_attended_sessions);
        $this->assertSame('monthly', $infantPolicy->reporting_frequency);
        $this->assertTrue($infantPolicy->require_guide_confirmation);
        $this->assertSame('advisory', $infantPolicy->area_coverage_mode);
    }

    public function test_only_one_active_policy_per_level_and_old_policy_can_be_retained_as_history(): void
    {
        $level = ClassLevel::query()->where('slug', 'sunny')->firstOrFail();
        $first = $this->policy($level, 'Sunny Policy v1', 60, 6);

        try {
            $this->policy($level, 'Sunny Policy v2', 75, 8);
            $this->fail('A level must not have two active report policies.');
        } catch (ValidationException) {
            $this->assertSame(1, ReportPolicy::query()->where('class_level_id', $level->id)->count());
        }

        $first->update(['is_active' => false]);
        $second = $this->policy($level, 'Sunny Policy v2', 75, 8);

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->is_active);
        $this->assertSame(2, ReportPolicy::query()->where('class_level_id', $level->id)->count());
    }

    public function test_policy_core_is_immutable_after_it_has_report_cycles(): void
    {
        $level = ClassLevel::query()->where('slug', 'infant')->firstOrFail();
        $policy = $this->policy($level, 'Infant Historical Policy', 60, 8);
        $this->cycle($policy, 'September 2026', '2026-09-01', '2026-09-30', '2026-09-30');

        try {
            $policy->update(['minimum_observation_days' => 90]);
            $this->fail('Used report policy must not be rewritten retroactively.');
        } catch (ValidationException) {
            $this->assertSame(60, $policy->fresh()->minimum_observation_days);
        }

        $policy->update(['is_active' => false]);
        $this->assertFalse($policy->fresh()->is_active);
    }

    public function test_report_cycle_rejects_invalid_cutoff_and_overlapping_window(): void
    {
        $level = ClassLevel::query()->where('slug', 'glow')->firstOrFail();
        $policy = $this->policy($level, 'Glow Cycle Policy', 90, 8);
        $this->cycle($policy, 'September 2026', '2026-09-01', '2026-09-30', '2026-09-30');

        try {
            $this->cycle($policy, 'Bad Cutoff', '2026-10-01', '2026-10-31', '2026-11-01');
            $this->fail('Cutoff outside the evidence window must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(1, $policy->cycles()->count());
        }

        try {
            $this->cycle($policy, 'Overlap', '2026-09-15', '2026-10-15', '2026-10-15');
            $this->fail('Report cycles for the same policy must not overlap.');
        } catch (ValidationException) {
            $this->assertSame(1, $policy->cycles()->count());
        }

        $october = $this->cycle($policy, 'October 2026', '2026-10-01', '2026-10-31', '2026-10-31');
        $this->assertSame('2026-10-31', $october->cutoff_date->toDateString());
    }

    public function test_closed_report_cycle_is_immutable_and_cannot_be_deleted(): void
    {
        $level = ClassLevel::query()->where('slug', 'sunny')->firstOrFail();
        $policy = $this->policy($level, 'Sunny Closed Cycle Policy', 60, 8);
        $cycle = $this->cycle($policy, 'September 2026', '2026-09-01', '2026-09-30', '2026-09-30');

        $cycle->update(['status' => 'closed']);

        try {
            $cycle->update(['status' => 'open']);
            $this->fail('Closed cycle must not reopen through ordinary mutation.');
        } catch (ValidationException) {
            $this->assertSame('closed', $cycle->fresh()->status);
        }

        try {
            $cycle->update(['cutoff_date' => '2026-09-29']);
            $this->fail('Closed cycle dates must be immutable.');
        } catch (ValidationException) {
            $this->assertSame('2026-09-30', $cycle->fresh()->cutoff_date->toDateString());
        }

        $this->expectException(LogicException::class);
        $cycle->delete();
    }

    public function test_inactive_policy_cannot_receive_new_report_cycle(): void
    {
        $level = ClassLevel::query()->where('slug', 'infant')->firstOrFail();
        $policy = $this->policy($level, 'Inactive Policy', 60, 8);
        $policy->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->cycle($policy, 'September 2026', '2026-09-01', '2026-09-30', '2026-09-30');
    }

    public function test_report_policy_vocabulary_is_guarded(): void
    {
        $level = ClassLevel::query()->where('slug', 'glow')->firstOrFail();

        $this->expectException(ValidationException::class);

        ReportPolicy::query()->create([
            'class_level_id' => $level->id,
            'name' => 'Invalid Policy',
            'reporting_frequency' => 'weekly_magic',
            'minimum_observation_days' => 30,
            'minimum_attended_sessions' => 4,
            'require_guide_confirmation' => true,
            'area_coverage_mode' => 'scored',
            'is_active' => true,
        ]);
    }

    private function policy(
        ClassLevel $level,
        string $name,
        int $minimumObservationDays,
        int $minimumAttendedSessions,
    ): ReportPolicy {
        return ReportPolicy::query()->create([
            'class_level_id' => $level->id,
            'name' => $name,
            'reporting_frequency' => 'monthly',
            'minimum_observation_days' => $minimumObservationDays,
            'minimum_attended_sessions' => $minimumAttendedSessions,
            'require_guide_confirmation' => true,
            'area_coverage_mode' => 'advisory',
            'is_active' => true,
        ]);
    }

    private function cycle(
        ReportPolicy $policy,
        string $name,
        string $windowStart,
        string $windowEnd,
        string $cutoffDate,
    ): ReportCycle {
        return ReportCycle::query()->create([
            'report_policy_id' => $policy->id,
            'name' => $name,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'cutoff_date' => $cutoffDate,
            'status' => 'open',
        ]);
    }
}

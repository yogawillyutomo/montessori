<?php

namespace App\Services\Scheduling;

use App\Models\ClassSession;
use App\Models\SchoolClass;
use App\Models\SessionOccurrence;
use App\Models\SessionTemplate;
use App\Models\Teacher;
use App\Models\WeeklySchedule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use LogicException;

class SchedulingReadModelService
{
    /**
     * @param  array<int, int>  $classIds
     * @return Collection<int, WeeklySchedule>
     */
    public function schedules(array $classIds, ?Teacher $teacher): Collection
    {
        if (config('montessori.scheduling.read_source') === 'legacy') {
            return $this->legacySchedules($classIds, $teacher);
        }

        return $this->targetSchedules($classIds, $teacher);
    }

    /**
     * @param  array<int, int>  $classIds
     * @return Collection<int, ClassSession>
     */
    public function sessions(array $classIds, ?Teacher $teacher): Collection
    {
        if (config('montessori.scheduling.read_source') === 'legacy') {
            return $this->legacySessions($classIds, $teacher);
        }

        return $this->targetSessions($classIds, $teacher);
    }

    /**
     * @param  array<int, int>  $classIds
     * @return Collection<int, WeeklySchedule>
     */
    private function legacySchedules(array $classIds, ?Teacher $teacher): Collection
    {
        return WeeklySchedule::query()
            ->with(['schoolClass.classLevel', 'teacher', 'students.guardian', 'students.schoolClass'])
            ->whereIn('school_class_id', $classIds)
            ->when($teacher, fn ($query) => $query->where('teacher_id', $teacher->id))
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @param  array<int, int>  $classIds
     * @return Collection<int, ClassSession>
     */
    private function legacySessions(array $classIds, ?Teacher $teacher): Collection
    {
        return ClassSession::query()
            ->with(['weeklySchedule', 'schoolClass.classLevel', 'teacher', 'students.guardian', 'students.schoolClass', 'attendances.student'])
            ->withCount('observations')
            ->whereIn('school_class_id', $classIds)
            ->when($teacher, fn ($query) => $query->where('teacher_id', $teacher->id))
            ->orderByDesc('session_date')
            ->orderBy('starts_at')
            ->limit(80)
            ->get();
    }

    /**
     * @param  array<int, int>  $classIds
     * @return Collection<int, WeeklySchedule>
     */
    private function targetSchedules(array $classIds, ?Teacher $teacher): Collection
    {
        $templates = SessionTemplate::query()
            ->with(['recurringSchedules.student.guardian', 'recurringSchedules.student.schoolClass.classLevel'])
            ->whereNotNull('legacy_weekly_schedule_id')
            ->whereNull('legacy_deleted_at')
            ->whereIn('legacy_school_class_id', $classIds)
            ->when($teacher, fn ($query) => $query->where('legacy_teacher_id', $teacher->id))
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get();

        if ($templates->isEmpty()) {
            return collect();
        }

        $legacyRows = WeeklySchedule::query()
            ->whereIn('id', $templates->pluck('legacy_weekly_schedule_id')->filter())
            ->get()
            ->keyBy('id');
        $classes = SchoolClass::query()
            ->with('classLevel')
            ->whereIn('id', $templates->pluck('legacy_school_class_id')->filter())
            ->get()
            ->keyBy('id');
        $teachers = Teacher::query()
            ->whereIn('id', $templates->pluck('legacy_teacher_id')->filter())
            ->get()
            ->keyBy('id');

        return $templates->map(function (SessionTemplate $template) use ($legacyRows, $classes, $teachers): WeeklySchedule {
            $legacy = $legacyRows->get((int) $template->legacy_weekly_schedule_id);
            if (! $legacy) {
                throw new LogicException("Target session template {$template->id} has no legacy compatibility handle.");
            }

            $schoolClass = $classes->get((int) $template->legacy_school_class_id);
            $teacher = $teachers->get((int) $template->legacy_teacher_id);
            if (! $schoolClass || ! $teacher) {
                throw new LogicException("Target session template {$template->id} points to missing class/teacher metadata.");
            }

            $students = $template->recurringSchedules
                ->filter(fn ($schedule) => $schedule->legacy_deleted_at === null)
                ->map->student
                ->filter()
                ->unique('id')
                ->values();

            $legacy->forceFill([
                'school_class_id' => $template->legacy_school_class_id,
                'teacher_id' => $template->legacy_teacher_id,
                'room' => $template->room,
                'capacity' => $template->capacity,
                'day_of_week' => $template->day_of_week,
                'starts_at' => $template->starts_at,
                'ends_at' => $template->ends_at,
                'topic' => $template->legacy_topic,
                'is_active' => $template->is_active,
            ]);
            $legacy->syncOriginal();
            $legacy->setRelation('schoolClass', $schoolClass);
            $legacy->setRelation('teacher', $teacher);
            $legacy->setRelation('students', new EloquentCollection($students->all()));

            return $legacy;
        })->values();
    }

    /**
     * @param  array<int, int>  $classIds
     * @return Collection<int, ClassSession>
     */
    private function targetSessions(array $classIds, ?Teacher $teacher): Collection
    {
        $occurrences = SessionOccurrence::query()
            ->with(['bookings.student.guardian', 'bookings.student.schoolClass.classLevel'])
            ->whereNotNull('legacy_class_session_id')
            ->whereNull('legacy_deleted_at')
            ->whereIn('legacy_school_class_id', $classIds)
            ->when($teacher, fn ($query) => $query->where('legacy_teacher_id', $teacher->id))
            ->orderByDesc('occurs_on')
            ->orderBy('starts_at')
            ->limit(80)
            ->get();

        if ($occurrences->isEmpty()) {
            return collect();
        }

        $legacyRows = ClassSession::query()
            ->with(['attendances.student'])
            ->withCount('observations')
            ->whereIn('id', $occurrences->pluck('legacy_class_session_id')->filter())
            ->get()
            ->keyBy('id');
        $classes = SchoolClass::query()
            ->with('classLevel')
            ->whereIn('id', $occurrences->pluck('legacy_school_class_id')->filter())
            ->get()
            ->keyBy('id');
        $teachers = Teacher::query()
            ->whereIn('id', $occurrences->pluck('legacy_teacher_id')->filter())
            ->get()
            ->keyBy('id');

        return $occurrences->map(function (SessionOccurrence $occurrence) use ($legacyRows, $classes, $teachers): ClassSession {
            $legacy = $legacyRows->get((int) $occurrence->legacy_class_session_id);
            if (! $legacy) {
                throw new LogicException("Target session occurrence {$occurrence->id} has no legacy compatibility handle.");
            }

            $schoolClass = $classes->get((int) $occurrence->legacy_school_class_id);
            $teacher = $teachers->get((int) $occurrence->legacy_teacher_id);
            if (! $schoolClass || ! $teacher) {
                throw new LogicException("Target session occurrence {$occurrence->id} points to missing class/teacher metadata.");
            }

            $students = $occurrence->bookings
                ->filter(fn ($booking) => $booking->legacy_deleted_at === null)
                ->filter(fn ($booking) => in_array($booking->status, ['scheduled', 'session_cancelled'], true))
                ->map->student
                ->filter()
                ->unique('id')
                ->values();

            $legacy->forceFill([
                'weekly_schedule_id' => $occurrence->legacy_weekly_schedule_id,
                'school_class_id' => $occurrence->legacy_school_class_id,
                'teacher_id' => $occurrence->legacy_teacher_id,
                'room' => $occurrence->room,
                'capacity' => $occurrence->capacity,
                'session_date' => $occurrence->occurs_on,
                'starts_at' => $occurrence->starts_at,
                'ends_at' => $occurrence->ends_at,
                'topic' => $occurrence->legacy_topic,
                'status' => $occurrence->status,
                'closed_by' => $occurrence->completed_by,
                'closed_at' => $occurrence->completed_at,
            ]);
            $legacy->syncOriginal();
            $legacy->setRelation('schoolClass', $schoolClass);
            $legacy->setRelation('teacher', $teacher);
            $legacy->setRelation('students', new EloquentCollection($students->all()));

            return $legacy;
        })->values();
    }
}

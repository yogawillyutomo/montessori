<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Alpha\Concerns\ProvidesAlphaShell;
use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\IlpPlan;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\WeeklySchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProcessController extends Controller
{
    use ProvidesAlphaShell;

    public function schedules(Request $request): View
    {
        return $this->processView($request, 'schedules', 'process.schedules');
    }

    public function sessions(Request $request): View
    {
        return $this->processView($request, 'sessions', 'process.sessions');
    }

    public function observations(Request $request): View
    {
        return $this->processView($request, 'observations', 'process.observations');
    }

    public function ilp(Request $request): View
    {
        return $this->processView($request, 'ilp', 'process.ilp');
    }

    public function storeSchedule(Request $request): RedirectResponse
    {
        $validated = $this->scheduleRules($request);
        $validated['capacity'] = $this->resolveCapacity($validated['capacity'] ?? null, (int) $validated['school_class_id']);
        $this->validateScheduleTime($validated);
        $this->validateScheduleStudents($validated);

        $schedule = WeeklySchedule::create([
            'school_class_id' => $validated['school_class_id'],
            'teacher_id' => $validated['teacher_id'],
            'room' => $this->normalizeRoom($validated['room'] ?? null),
            'capacity' => $validated['capacity'],
            'day_of_week' => $validated['day_of_week'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'topic' => $validated['topic'] ?? null,
            'is_active' => true,
        ]);
        $schedule->students()->sync($validated['student_ids'] ?? []);

        return back()->with('status', 'Jadwal mingguan berhasil ditambahkan.');
    }

    public function updateSchedule(Request $request, WeeklySchedule $weeklySchedule): RedirectResponse
    {
        $validated = $this->scheduleRules($request);
        $validated['capacity'] = $this->resolveCapacity($validated['capacity'] ?? null, (int) $validated['school_class_id']);
        $this->validateScheduleTime($validated, $weeklySchedule);
        $this->validateScheduleStudents($validated, $weeklySchedule);

        $weeklySchedule->update([
            'school_class_id' => $validated['school_class_id'],
            'teacher_id' => $validated['teacher_id'],
            'room' => $this->normalizeRoom($validated['room'] ?? null),
            'capacity' => $validated['capacity'],
            'day_of_week' => $validated['day_of_week'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'topic' => $validated['topic'] ?? null,
        ]);
        $weeklySchedule->students()->sync($validated['student_ids'] ?? []);

        return back()->with('status', 'Jadwal mingguan berhasil diperbarui.');
    }

    public function toggleSchedule(WeeklySchedule $weeklySchedule): RedirectResponse
    {
        $weeklySchedule->update(['is_active' => ! $weeklySchedule->is_active]);

        return back()->with('status', 'Status jadwal mingguan berhasil diperbarui.');
    }

    public function destroySchedule(WeeklySchedule $weeklySchedule): RedirectResponse
    {
        if ($weeklySchedule->classSessions()->exists()) {
            return back()->withErrors('Jadwal tidak bisa dihapus karena sudah pernah dibuat menjadi presensi.');
        }

        $weeklySchedule->delete();

        return back()->with('status', 'Jadwal mingguan berhasil dihapus.');
    }

    public function createSession(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'weekly_schedule_id' => ['required', 'exists:weekly_schedules,id'],
            'session_date' => ['required', 'date'],
        ]);

        $schedule = WeeklySchedule::query()->with('students')->findOrFail($validated['weekly_schedule_id']);
        $sessionDate = Carbon::parse($validated['session_date']);

        if (! $schedule->is_active) {
            return back()->withErrors('Jadwal nonaktif tidak bisa dibuat menjadi presensi.');
        }

        if ((int) $sessionDate->dayOfWeekIso !== $schedule->day_of_week) {
            return back()->withErrors("Tanggal presensi harus sesuai hari jadwal, yaitu {$this->dayLabels()[$schedule->day_of_week]}.");
        }

        $sessionPayload = [
            'school_class_id' => $schedule->school_class_id,
            'teacher_id' => $schedule->teacher_id,
            'room' => $this->normalizeRoom($schedule->room),
            'capacity' => $this->scheduleCapacity($schedule),
            'session_date' => $sessionDate->toDateString(),
            'starts_at' => Carbon::parse($schedule->starts_at)->format('H:i'),
            'ends_at' => Carbon::parse($schedule->ends_at)->format('H:i'),
            'topic' => $schedule->topic,
            'status' => 'planned',
        ];
        $existingSession = ClassSession::query()
            ->where('weekly_schedule_id', $schedule->id)
            ->whereDate('session_date', $sessionPayload['session_date'])
            ->first();

        if (! $existingSession) {
            $this->validateSessionTime($sessionPayload);
            $this->validateSessionStudents($sessionPayload, $schedule->students->pluck('id')->all());
        }

        $session = ClassSession::query()->firstOrCreate(
            [
                'weekly_schedule_id' => $schedule->id,
                'session_date' => $sessionPayload['session_date'],
            ],
            $sessionPayload
        );

        if ($session->wasRecentlyCreated) {
            $studentIds = $schedule->students->pluck('id')->all();
            $session->students()->sync($studentIds);

            foreach ($studentIds as $studentId) {
                $session->attendances()->firstOrCreate([
                    'student_id' => $studentId,
                ], [
                    'status' => 'present',
                ]);
            }
        }

        return back()->with('status', 'Presensi berhasil dibuat dari jadwal mingguan.');
    }

    public function updateSession(Request $request, ClassSession $classSession): RedirectResponse
    {
        $validated = $this->sessionRules($request);
        $validated['room'] = $this->normalizeRoom($validated['room'] ?? null);
        $validated['capacity'] = $this->resolveCapacity($validated['capacity'] ?? null, (int) $validated['school_class_id']);
        $studentIds = $request->boolean('student_ids_present')
            ? $this->selectedStudentIds($validated)
            : $classSession->students()->pluck('students.id')->all();

        $this->validateSessionTime($validated, $classSession);
        $this->validateSessionStudents($validated, $studentIds, $classSession);

        $observedStudentIds = $classSession->observations()->pluck('student_id')->map(fn ($id) => (int) $id)->all();
        $removedObservedStudents = array_diff($observedStudentIds, $studentIds);

        if ($removedObservedStudents !== []) {
            return back()->withErrors('Siswa yang sudah memiliki observasi pada presensi ini tidak bisa dikeluarkan dari presensi.');
        }

        $classSession->update(collect($validated)->except(['student_ids', 'student_ids_present'])->all());
        $classSession->students()->sync($studentIds);
        $classSession->attendances()->whereNotIn('student_id', $studentIds)->delete();

        foreach ($studentIds as $studentId) {
            $classSession->attendances()->firstOrCreate([
                'student_id' => $studentId,
            ], [
                'status' => 'present',
            ]);
        }

        return back()->with('status', 'Presensi kelas berhasil diperbarui.');
    }

    public function updateSessionAttendance(Request $request, ClassSession $classSession): RedirectResponse
    {
        $validated = $request->validate([
            'attendance' => ['required', 'array'],
            'attendance.*.status' => ['required', 'in:present,absent,late,sick,excused'],
            'attendance.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $sessionStudentIds = $classSession->students()->pluck('students.id')->map(fn ($id) => (int) $id)->all();

        foreach (array_keys($validated['attendance']) as $studentId) {
            if (! in_array((int) $studentId, $sessionStudentIds, true)) {
                throw ValidationException::withMessages([
                    'attendance' => 'Presensi hanya boleh diisi untuk siswa yang terdaftar di jadwal ini.',
                ]);
            }
        }

        foreach ($validated['attendance'] as $studentId => $row) {
            $classSession->attendances()->updateOrCreate(
                ['student_id' => (int) $studentId],
                [
                    'status' => $row['status'],
                    'note' => $row['note'] ?? null,
                ]
            );
        }

        $classSession->update(['status' => 'completed']);

        return back()->with('status', 'Presensi berhasil diperbarui.');
    }

    public function destroySession(ClassSession $classSession): RedirectResponse
    {
        if ($classSession->observations()->exists()) {
            return back()->withErrors('Presensi tidak bisa dihapus karena sudah memiliki observasi.');
        }

        $classSession->attendances()->delete();
        $classSession->students()->detach();
        $classSession->delete();

        return back()->with('status', 'Presensi kelas berhasil dihapus.');
    }

    public function storeObservation(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'class_session_id' => ['required', 'exists:class_sessions,id'],
            'student_id' => ['required', 'exists:students,id'],
            'teacher_id' => ['required', 'exists:teachers,id'],
            'observed_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'observations' => ['required', 'array', 'min:1'],
            'observations.*.status' => ['required', 'in:achieved,emerging,needs_support'],
        ]);

        $indicatorIds = collect(array_keys($validated['observations']))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $validIndicatorIds = Indicator::query()
            ->whereIn('id', $indicatorIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($indicatorIds->diff($validIndicatorIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'observations' => 'Ada indikator observasi yang tidak valid.',
            ]);
        }

        $sessionStudentExists = ClassSession::query()
            ->whereKey($validated['class_session_id'])
            ->whereHas('students', fn ($query) => $query->whereKey($validated['student_id']))
            ->exists();

        if (! $sessionStudentExists) {
            throw ValidationException::withMessages([
                'student_id' => 'Siswa harus terdaftar pada presensi yang dipilih.',
            ]);
        }

        $observedOn = Carbon::parse($validated['observed_on'])->toDateString();
        $createdCount = 0;
        foreach ($validated['observations'] as $indicatorId => $row) {
            $status = $row['status'];
            $observation = Observation::query()
                ->where('class_session_id', $validated['class_session_id'])
                ->where('student_id', $validated['student_id'])
                ->where('indicator_id', (int) $indicatorId)
                ->whereDate('observed_on', $observedOn)
                ->first();

            if (! $observation) {
                $observation = new Observation([
                    'class_session_id' => $validated['class_session_id'],
                    'student_id' => $validated['student_id'],
                    'indicator_id' => (int) $indicatorId,
                    'observed_on' => $observedOn,
                ]);
            }

            $observation->fill([
                'teacher_id' => $validated['teacher_id'],
                'status' => $status,
                'score' => Observation::STATUS_SCORES[$status],
                'note' => $validated['note'] ?? null,
            ])->save();
            $createdCount++;

            if ($observation->status === 'needs_support') {
                $this->createIlpFromObservation($observation);
            }
        }

        return redirect(route('alpha.process.observations') . '#monitoring-harian')
            ->with('status', "{$createdCount} observasi tersimpan. Status SD otomatis masuk draft ILP.");
    }

    public function updateIlp(Request $request, IlpPlan $ilpPlan): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:draft,in_progress,completed,cancelled'],
            'analysis' => ['nullable', 'string', 'max:3000'],
            'target' => ['required', 'string', 'max:3000'],
            'follow_up' => ['nullable', 'string', 'max:3000'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        $ilpPlan->update($validated);

        return redirect(route('alpha.process.ilp') . "#ilp-plan-{$ilpPlan->id}")
            ->with('status', 'Rencana ILP berhasil diperbarui.');
    }

    private function createIlpFromObservation(Observation $observation): void
    {
        $term = Term::query()->where('is_current', true)->first();

        IlpPlan::query()->firstOrCreate(
            [
                'student_id' => $observation->student_id,
                'indicator_id' => $observation->indicator_id,
                'term_id' => $term?->id,
                'status' => 'draft',
            ],
            [
                'trigger_observation_id' => $observation->id,
                'analysis' => 'Anak masih memerlukan stimulasi dan pendampingan terarah pada indikator ini.',
                'target' => 'Mampu melakukan indikator dengan bantuan minimal dalam 2-4 minggu.',
                'follow_up' => 'Pendampingan melalui kegiatan bermain terarah dan komunikasi dengan orangtua.',
                'starts_on' => Carbon::parse($observation->observed_on)->addDay(),
                'ends_on' => Carbon::parse($observation->observed_on)->addWeeks(4),
            ]
        );
    }

    private function processView(Request $request, string $processSection, string $activeMenu): View
    {
        $selectedSessionDate = $request->date('date')?->toDateString() ?? now()->toDateString();
        $sessions = ClassSession::query()
            ->with(['weeklySchedule', 'schoolClass.classLevel', 'teacher', 'students.guardian', 'students.schoolClass', 'attendances.student'])
            ->withCount('observations')
            ->orderByDesc('session_date')
            ->orderBy('starts_at')
            ->limit(80)
            ->get();
        $indicators = Indicator::query()
            ->with('developmentArea')
            ->where('is_active', true)
            ->get()
            ->sort(function (Indicator $a, Indicator $b): int {
                return (($a->developmentArea?->sort_order ?? 999) <=> ($b->developmentArea?->sort_order ?? 999))
                    ?: strcmp((string) $a->sub_area, (string) $b->sub_area)
                    ?: strnatcasecmp($a->code, $b->code);
            })
            ->values();
        $monitoringSnapshots = Observation::query()
            ->whereIn('class_session_id', $sessions->pluck('id'))
            ->whereIn('indicator_id', $indicators->pluck('id'))
            ->get()
            ->groupBy(fn (Observation $observation): string => implode('|', [
                $observation->class_session_id,
                $observation->student_id,
                $observation->observed_on->toDateString(),
            ]))
            ->map(fn ($rows) => [
                'note' => $rows->firstWhere('note', '!=', null)?->note ?? '',
                'items' => $rows
                    ->mapWithKeys(fn (Observation $observation) => [
                        (string) $observation->indicator_id => [
                            'status' => $observation->status,
                            'note' => $observation->note,
                        ],
                    ])
                    ->all(),
            ])
            ->all();

        return view('alpha.process', [
            ...$this->shell($request, $activeMenu),
            'processSection' => $processSection,
            'schedules' => WeeklySchedule::query()
                ->with(['schoolClass.classLevel', 'teacher', 'students.guardian', 'students.schoolClass'])
                ->orderBy('day_of_week')
                ->orderBy('starts_at')
                ->get(),
            'sessions' => $sessions,
            'selectedSessionDate' => $selectedSessionDate,
            'observations' => Observation::query()
                ->with(['student.schoolClass', 'indicator.developmentArea', 'teacher', 'classSession'])
                ->orderByDesc('observed_on')
                ->orderByDesc('id')
                ->get(),
            'ilpPlans' => IlpPlan::query()
                ->with(['student.schoolClass.classLevel', 'indicator.developmentArea', 'term', 'triggerObservation.teacher'])
                ->orderByRaw("case status when 'draft' then 1 when 'in_progress' then 2 when 'completed' then 3 else 4 end")
                ->latest('updated_at')
                ->get(),
            'classes' => SchoolClass::naturalSort(SchoolClass::query()->with('classLevel')->get()),
            'students' => Student::query()->with(['schoolClass.classLevel', 'guardian'])->orderBy('name')->get(),
            'teachers' => Teacher::query()->orderBy('name')->get(),
            'indicators' => $indicators,
            'monitoringSnapshots' => $monitoringSnapshots,
        ]);
    }

    private function scheduleRules(Request $request): array
    {
        return $request->validate([
            'school_class_id' => ['required', 'exists:school_classes,id'],
            'teacher_id' => ['required', 'exists:teachers,id'],
            'room' => ['nullable', 'string', 'max:120'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:60'],
            'day_of_week' => ['required', 'integer', 'min:1', 'max:6'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'topic' => ['nullable', 'string', 'max:160'],
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
        ]);
    }

    private function validateScheduleTime(array $validated, ?WeeklySchedule $ignore = null): void
    {
        if ($validated['ends_at'] <= $validated['starts_at']) {
            throw ValidationException::withMessages([
                'ends_at' => 'Jam selesai harus setelah jam mulai.',
            ]);
        }

        $overlap = WeeklySchedule::query()
            ->where('day_of_week', $validated['day_of_week'])
            ->where('starts_at', '<', $validated['ends_at'])
            ->where('ends_at', '>', $validated['starts_at'])
            ->where(function ($query) use ($validated): void {
                $query->where('school_class_id', $validated['school_class_id'])
                    ->orWhere('teacher_id', $validated['teacher_id']);

                if ($this->normalizeRoom($validated['room'] ?? null) !== null) {
                    $query->orWhere('room', $this->normalizeRoom($validated['room']));
                }
            })
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'starts_at' => 'Jadwal bentrok dengan kelas, guru, atau ruangan pada hari dan jam yang sama.',
            ]);
        }
    }

    private function validateScheduleStudents(array $validated, ?WeeklySchedule $ignore = null): void
    {
        $studentIds = $this->selectedStudentIds($validated);
        if ($studentIds === []) {
            return;
        }

        if (count($studentIds) > $validated['capacity']) {
            throw ValidationException::withMessages([
                'student_ids' => "Jumlah peserta melebihi kapasitas slot ({$validated['capacity']} siswa).",
            ]);
        }

        $conflictExists = WeeklySchedule::query()
            ->where('day_of_week', $validated['day_of_week'])
            ->where('starts_at', '<', $validated['ends_at'])
            ->where('ends_at', '>', $validated['starts_at'])
            ->whereHas('students', fn ($query) => $query->whereIn('students.id', $studentIds))
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($conflictExists) {
            throw ValidationException::withMessages([
                'student_ids' => 'Ada siswa yang sudah punya slot mingguan lain pada hari dan jam yang sama.',
            ]);
        }
    }

    private function sessionRules(Request $request): array
    {
        return $request->validate([
            'school_class_id' => ['required', 'exists:school_classes,id'],
            'teacher_id' => ['required', 'exists:teachers,id'],
            'room' => ['nullable', 'string', 'max:120'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:60'],
            'session_date' => ['required', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'topic' => ['nullable', 'string', 'max:160'],
            'status' => ['required', 'in:planned,completed,cancelled'],
            'student_ids_present' => ['nullable', 'boolean'],
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
        ]);
    }

    private function validateSessionTime(array $validated, ?ClassSession $ignore = null): void
    {
        if ($validated['ends_at'] <= $validated['starts_at']) {
            throw ValidationException::withMessages([
                'ends_at' => 'Jam selesai presensi harus setelah jam mulai.',
            ]);
        }

        $overlap = ClassSession::query()
            ->whereDate('session_date', $validated['session_date'])
            ->where('starts_at', '<', $validated['ends_at'])
            ->where('ends_at', '>', $validated['starts_at'])
            ->where(function ($query) use ($validated): void {
                $query->where('school_class_id', $validated['school_class_id'])
                    ->orWhere('teacher_id', $validated['teacher_id']);

                if ($this->normalizeRoom($validated['room'] ?? null) !== null) {
                    $query->orWhere('room', $this->normalizeRoom($validated['room']));
                }
            })
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'starts_at' => 'Presensi bentrok dengan kelas, guru, atau ruangan pada tanggal dan jam yang sama.',
            ]);
        }
    }

    private function normalizeRoom(?string $room): ?string
    {
        $room = trim((string) $room);

        return $room === '' ? null : $room;
    }

    /**
     * @return array<int, int>
     */
    private function selectedStudentIds(array $validated): array
    {
        return collect($validated['student_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function resolveCapacity(null|int|string $capacity, int $schoolClassId): int
    {
        if ($capacity !== null && (int) $capacity > 0) {
            return (int) $capacity;
        }

        return (int) SchoolClass::query()->whereKey($schoolClassId)->value('capacity') ?: 1;
    }

    private function scheduleCapacity(WeeklySchedule $schedule): int
    {
        return (int) ($schedule->capacity ?: $schedule->schoolClass?->capacity ?: 1);
    }

    /**
     * @param  array<int, int>  $studentIds
     */
    private function validateSessionStudents(array $validated, array $studentIds, ?ClassSession $ignore = null): void
    {
        $studentIds = collect($studentIds)->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($studentIds === []) {
            return;
        }

        if (count($studentIds) > $validated['capacity']) {
            throw ValidationException::withMessages([
                'student_ids' => "Jumlah peserta presensi melebihi kapasitas ({$validated['capacity']} siswa).",
            ]);
        }

        $conflictExists = ClassSession::query()
            ->whereDate('session_date', $validated['session_date'])
            ->where('starts_at', '<', $validated['ends_at'])
            ->where('ends_at', '>', $validated['starts_at'])
            ->whereHas('students', fn ($query) => $query->whereIn('students.id', $studentIds))
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($conflictExists) {
            throw ValidationException::withMessages([
                'student_ids' => 'Ada siswa yang sudah terdaftar pada presensi lain di tanggal dan jam yang sama.',
            ]);
        }
    }
}

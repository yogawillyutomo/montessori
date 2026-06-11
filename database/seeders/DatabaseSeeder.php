<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassLevel;
use App\Models\ClassSession;
use App\Models\DevelopmentArea;
use App\Models\Guardian;
use App\Models\IlpPlan;
use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Term;
use App\Models\User;
use App\Models\WeeklySchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $users = [
            'super_admin' => User::query()->updateOrCreate(['email' => 'admin@montessori.test'], [
                'name' => 'Super Admin Montessori',
                'password' => 'password',
                'role' => 'super_admin',
                'is_active' => true,
            ]),
            'admin' => User::query()->updateOrCreate(['email' => 'ops@montessori.test'], [
                'name' => 'Admin Operasional',
                'password' => 'password',
                'role' => 'admin',
                'is_active' => true,
            ]),
            'principal' => User::query()->updateOrCreate(['email' => 'principal@montessori.test'], [
                'name' => 'Kepala Sekolah',
                'password' => 'password',
                'role' => 'principal',
                'is_active' => true,
            ]),
            'teacher_raras' => User::query()->updateOrCreate(['email' => 'raras@montessori.test'], [
                'name' => 'Bu Raras',
                'password' => 'password',
                'role' => 'teacher',
                'is_active' => true,
            ]),
            'teacher_mira' => User::query()->updateOrCreate(['email' => 'mira@montessori.test'], [
                'name' => 'Bu Mira',
                'password' => 'password',
                'role' => 'teacher',
                'is_active' => true,
            ]),
            'parent_alya' => User::query()->updateOrCreate(['email' => 'parent@montessori.test'], [
                'name' => 'Orangtua Alya',
                'password' => 'password',
                'role' => 'parent',
                'is_active' => true,
            ]),
        ];

        $classLevels = [
            'infant' => ClassLevel::query()->updateOrCreate(['slug' => 'infant'], [
                'name' => 'Infant',
                'sequence' => 0,
                'min_age_months' => 6,
                'max_age_months' => 24,
                'color' => 'blue',
            ]),
            'sunny' => ClassLevel::query()->updateOrCreate(['slug' => 'sunny'], [
                'name' => 'Sunny',
                'sequence' => 1,
                'min_age_months' => 24,
                'max_age_months' => 48,
                'color' => 'sage',
            ]),
            'glow' => ClassLevel::query()->updateOrCreate(['slug' => 'glow'], [
                'name' => 'Glow',
                'sequence' => 2,
                'min_age_months' => 48,
                'max_age_months' => 72,
                'color' => 'coral',
            ]),
        ];

        $classes = [
            'sunny' => SchoolClass::query()->updateOrCreate(['slug' => 'sunny-1'], [
                'class_level_id' => $classLevels['sunny']->id,
                'name' => 'Sunny 1',
                'level' => 'Sunny',
                'age_range' => $classLevels['sunny']->age_range_label,
                'capacity' => 18,
                'color' => $classLevels['sunny']->color,
            ]),
            'sunny2' => SchoolClass::query()->updateOrCreate(['slug' => 'sunny-2'], [
                'class_level_id' => $classLevels['sunny']->id,
                'name' => 'Sunny 2',
                'level' => 'Sunny',
                'age_range' => $classLevels['sunny']->age_range_label,
                'capacity' => 18,
                'color' => $classLevels['sunny']->color,
            ]),
            'glow' => SchoolClass::query()->updateOrCreate(['slug' => 'glow-1'], [
                'class_level_id' => $classLevels['glow']->id,
                'name' => 'Glow 1',
                'level' => 'Glow',
                'age_range' => $classLevels['glow']->age_range_label,
                'capacity' => 16,
                'color' => $classLevels['glow']->color,
            ]),
            'infant' => SchoolClass::query()->updateOrCreate(['slug' => 'infant-1'], [
                'class_level_id' => $classLevels['infant']->id,
                'name' => 'Infant 1',
                'level' => 'Infant',
                'age_range' => $classLevels['infant']->age_range_label,
                'capacity' => 10,
                'color' => $classLevels['infant']->color,
            ]),
        ];

        $teachers = [
            'raras' => Teacher::query()->updateOrCreate(['code' => 'TCH01'], [
                'user_id' => $users['teacher_raras']->id,
                'name' => 'Bu Raras',
                'focus_area' => 'Practical Life dan Bahasa',
                'phone' => '0812-0000-0001',
            ]),
            'mira' => Teacher::query()->updateOrCreate(['code' => 'TCH02'], [
                'user_id' => $users['teacher_mira']->id,
                'name' => 'Bu Mira',
                'focus_area' => 'Sensorial dan Kognitif',
                'phone' => '0812-0000-0002',
            ]),
            'dimas' => Teacher::query()->updateOrCreate(['code' => 'TCH03'], [
                'name' => 'Pak Dimas',
                'focus_area' => 'Motorik Kasar',
                'phone' => '0812-0000-0003',
            ]),
            'hana' => Teacher::query()->updateOrCreate(['code' => 'TCH04'], [
                'name' => 'Bu Hana',
                'focus_area' => 'Infant Care',
                'phone' => '0812-0000-0004',
            ]),
        ];

        $guardians = [
            'alya' => Guardian::query()->updateOrCreate(['phone' => '0812-1111-0001'], [
                'user_id' => $users['parent_alya']->id,
                'name' => 'Orangtua Alya',
                'relationship' => 'Ibu',
                'email' => 'parent@montessori.test',
                'address' => 'Purwokerto',
            ]),
            'raka' => Guardian::query()->updateOrCreate(['phone' => '0812-1111-0002'], ['name' => 'Orangtua Raka', 'relationship' => 'Ayah', 'address' => 'Banyumas']),
            'nala' => Guardian::query()->updateOrCreate(['phone' => '0812-1111-0003'], ['name' => 'Orangtua Nala', 'relationship' => 'Ibu', 'address' => 'Kembaran']),
            'kirana' => Guardian::query()->updateOrCreate(['phone' => '0812-1111-0004'], ['name' => 'Orangtua Kirana', 'relationship' => 'Ibu', 'address' => 'Purwokerto Utara']),
            'arka' => Guardian::query()->updateOrCreate(['phone' => '0812-1111-0005'], ['name' => 'Orangtua Arka', 'relationship' => 'Ayah', 'address' => 'Sokaraja']),
            'maya' => Guardian::query()->updateOrCreate(['phone' => '0812-1111-0006'], ['name' => 'Orangtua Maya', 'relationship' => 'Ibu', 'address' => 'Purbalingga']),
            'rumi' => Guardian::query()->updateOrCreate(['phone' => '0812-1111-0007'], ['name' => 'Orangtua Rumi', 'relationship' => 'Ibu', 'address' => 'Kroya']),
            'shaka' => Guardian::query()->updateOrCreate(['phone' => '0812-1111-0008'], ['name' => 'Orangtua Shaka', 'relationship' => 'Ayah', 'address' => 'Purwokerto']),
        ];

        $students = [
            'alya' => Student::query()->updateOrCreate(['code' => 'SUN01'], [
                'school_class_id' => $classes['sunny']->id,
                'guardian_id' => $guardians['alya']->id,
                'name' => 'Alya Pramesti',
                'gender' => 'Perempuan',
                'birth_place' => 'Banyumas',
                'birth_date' => '2022-09-22',
            ]),
            'raka' => Student::query()->updateOrCreate(['code' => 'SUN02'], [
                'school_class_id' => $classes['sunny']->id,
                'guardian_id' => $guardians['raka']->id,
                'name' => 'Raka Mahendra',
                'gender' => 'Laki-laki',
                'birth_place' => 'Purwokerto',
                'birth_date' => '2023-07-16',
            ]),
            'nala' => Student::query()->updateOrCreate(['code' => 'SUN03'], [
                'school_class_id' => $classes['sunny']->id,
                'guardian_id' => $guardians['nala']->id,
                'name' => 'Nala Putri',
                'gender' => 'Perempuan',
                'birth_place' => 'Banyumas',
                'birth_date' => '2024-01-15',
            ]),
            'kirana' => Student::query()->updateOrCreate(['code' => 'GLO01'], [
                'school_class_id' => $classes['glow']->id,
                'guardian_id' => $guardians['kirana']->id,
                'name' => 'Kirana Satya',
                'gender' => 'Perempuan',
                'birth_place' => 'Purwokerto',
                'birth_date' => '2020-11-05',
            ]),
            'arka' => Student::query()->updateOrCreate(['code' => 'GLO02'], [
                'school_class_id' => $classes['glow']->id,
                'guardian_id' => $guardians['arka']->id,
                'name' => 'Arka Wijaya',
                'gender' => 'Laki-laki',
                'birth_place' => 'Banyumas',
                'birth_date' => '2021-02-18',
            ]),
            'maya' => Student::query()->updateOrCreate(['code' => 'GLO03'], [
                'school_class_id' => $classes['glow']->id,
                'guardian_id' => $guardians['maya']->id,
                'name' => 'Maya Salsabila',
                'gender' => 'Perempuan',
                'birth_place' => 'Purbalingga',
                'birth_date' => '2021-08-12',
            ]),
            'rumi' => Student::query()->updateOrCreate(['code' => 'INF01'], [
                'school_class_id' => $classes['infant']->id,
                'guardian_id' => $guardians['rumi']->id,
                'name' => 'Rumi Adikara',
                'gender' => 'Laki-laki',
                'birth_place' => 'Kroya',
                'birth_date' => '2024-04-29',
            ]),
            'shaka' => Student::query()->updateOrCreate(['code' => 'INF02'], [
                'school_class_id' => $classes['infant']->id,
                'guardian_id' => $guardians['shaka']->id,
                'name' => 'Shaka Nawasena',
                'gender' => 'Laki-laki',
                'birth_place' => 'Purwokerto',
                'birth_date' => '2024-03-16',
            ]),
        ];

        $academicYear = AcademicYear::query()->updateOrCreate(['name' => '2025/2026'], [
            'starts_on' => '2025-07-01',
            'ends_on' => '2026-06-30',
            'is_active' => true,
        ]);

        $term = Term::query()->updateOrCreate([
            'academic_year_id' => $academicYear->id,
            'name' => 'Semester 2',
        ], [
            'academic_year_id' => $academicYear->id,
            'name' => 'Semester 2',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-06-30',
            'is_current' => true,
        ]);

        $areaSeeds = [
            'psikomotorik' => ['Psikomotorik', 'sage'],
            'bahasa' => ['Bahasa', 'teal'],
            'kognitif' => ['Kognitif', 'blue'],
            'sensorial' => ['Sensorial', 'gold'],
            'sosial-emosional' => ['Sosial Emosional', 'coral'],
            'kecakapan-hidup' => ['Kecakapan Hidup', 'plum'],
        ];

        $areas = [];
        $sort = 1;
        foreach ($areaSeeds as $slug => [$name, $color]) {
            $areas[$slug] = DevelopmentArea::query()->updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'color' => $color,
                'sort_order' => $sort++,
            ]);
        }

        $indicatorRows = [
            ['PMK01', 'psikomotorik', 'Keseimbangan dan Motorik Kasar', 'Memanjat dan turun dengan kontrol tubuh', 'Sunny'],
            ['PMK02', 'psikomotorik', 'Koordinasi Tubuh', 'Melompat dengan dua kaki dan mendarat stabil', 'Sunny'],
            ['PMH01', 'psikomotorik', 'Motorik Halus', 'Memegang alat tulis dengan koordinasi sesuai tahap usia', 'Glow'],
            ['BHS01', 'bahasa', 'Pemahaman', 'Mengikuti dua instruksi sederhana', 'Sunny'],
            ['BHS02', 'bahasa', 'Ekspresi', 'Menyampaikan kebutuhan dengan kalimat sederhana', 'Sunny'],
            ['BHS03', 'bahasa', 'Cerita', 'Menceritakan kembali gambar atau pengalaman pendek', 'Glow'],
            ['KOG01', 'kognitif', 'Klasifikasi', 'Mengelompokkan benda berdasarkan warna atau bentuk', 'Sunny'],
            ['KOG02', 'kognitif', 'Pola', 'Melanjutkan pola sederhana dengan material konkret', 'Glow'],
            ['SEN01', 'sensorial', 'Visual', 'Mencocokkan bentuk geometri', 'Sunny'],
            ['SEM01', 'sosial-emosional', 'Interaksi', 'Menunggu giliran dan berbagi material', 'Sunny'],
            ['SEM02', 'sosial-emosional', 'Regulasi Emosi', 'Menenangkan diri dengan bantuan guru', 'Sunny'],
            ['LKH01', 'kecakapan-hidup', 'Perawatan Diri', 'Merapikan perlengkapan setelah kegiatan', 'Sunny'],
            ['LKH02', 'kecakapan-hidup', 'Perawatan Lingkungan', 'Mengembalikan material ke rak yang tepat', 'Glow'],
        ];

        $indicators = [];
        foreach ($indicatorRows as [$code, $areaSlug, $subArea, $description, $level]) {
            $indicators[$code] = Indicator::query()->updateOrCreate(['code' => $code], [
                'development_area_id' => $areas[$areaSlug]->id,
                'sub_area' => $subArea,
                'description' => $description,
                'level' => $level,
                'is_active' => true,
            ]);
        }

        $schedules = [
            'sunny_monday' => [
                'class' => 'sunny',
                'teacher' => 'raras',
                'day' => 1,
                'start' => '08:00',
                'end' => '09:30',
                'room' => 'Ruang Sunny',
                'capacity' => 7,
                'topic' => 'Practical Life dan Bahasa',
                'students' => ['alya', 'raka'],
            ],
            'infant_monday' => [
                'class' => 'infant',
                'teacher' => 'hana',
                'day' => 1,
                'start' => '10:00',
                'end' => '11:00',
                'room' => 'Ruang Infant',
                'capacity' => 6,
                'topic' => 'Sensorial Play',
                'students' => ['rumi', 'shaka'],
            ],
            'glow_tuesday' => [
                'class' => 'glow',
                'teacher' => 'mira',
                'day' => 2,
                'start' => '08:00',
                'end' => '09:30',
                'room' => 'Ruang Sensorial',
                'capacity' => 8,
                'topic' => 'Sensorial dan Klasifikasi',
                'students' => ['kirana', 'arka', 'maya'],
            ],
            'sunny_wednesday' => [
                'class' => 'sunny',
                'teacher' => 'dimas',
                'day' => 3,
                'start' => '09:00',
                'end' => '10:30',
                'room' => 'Ruang Motorik',
                'capacity' => 7,
                'topic' => 'Motorik Kasar',
                'students' => ['alya', 'nala'],
            ],
            'glow_thursday' => [
                'class' => 'glow',
                'teacher' => 'raras',
                'day' => 4,
                'start' => '08:30',
                'end' => '10:00',
                'room' => 'Ruang Bahasa',
                'capacity' => 8,
                'topic' => 'Bahasa dan Kelompok Kecil',
                'students' => ['kirana', 'maya'],
            ],
            'infant_friday' => [
                'class' => 'infant',
                'teacher' => 'hana',
                'day' => 5,
                'start' => '08:00',
                'end' => '09:00',
                'room' => 'Ruang Infant',
                'capacity' => 6,
                'topic' => 'Rutinitas dan Bonding',
                'students' => ['rumi'],
            ],
        ];

        foreach ($schedules as $key => $row) {
            $schedule = WeeklySchedule::query()->updateOrCreate([
                'school_class_id' => $classes[$row['class']]->id,
                'day_of_week' => $row['day'],
                'starts_at' => $row['start'],
                'ends_at' => $row['end'],
                'room' => $row['room'],
            ], [
                'school_class_id' => $classes[$row['class']]->id,
                'teacher_id' => $teachers[$row['teacher']]->id,
                'room' => $row['room'],
                'capacity' => $row['capacity'],
                'day_of_week' => $row['day'],
                'starts_at' => $row['start'],
                'ends_at' => $row['end'],
                'topic' => $row['topic'],
                'is_active' => true,
            ]);

            $schedule->students()->sync(
                collect($row['students'])->map(fn (string $key) => $students[$key]->id)->all()
            );

            $schedules[$key]['model'] = $schedule;
        }

        $sessionDates = [
            'sunny_monday' => '2026-06-01',
            'infant_monday' => '2026-06-01',
            'glow_tuesday' => '2026-06-02',
            'sunny_wednesday' => '2026-06-03',
            'glow_thursday' => '2026-06-04',
            'infant_friday' => '2026-06-05',
        ];

        $sessions = [];
        foreach ($sessionDates as $key => $date) {
            $schedule = $schedules[$key]['model'];
            $session = ClassSession::query()->updateOrCreate([
                'school_class_id' => $schedule->school_class_id,
                'session_date' => $date,
                'starts_at' => $schedule->starts_at,
                'ends_at' => $schedule->ends_at,
                'room' => $schedule->room,
            ], [
                'weekly_schedule_id' => $schedule->id,
                'school_class_id' => $schedule->school_class_id,
                'teacher_id' => $schedule->teacher_id,
                'room' => $schedule->room,
                'capacity' => $schedule->capacity ?: $schedule->schoolClass->capacity,
                'session_date' => $date,
                'starts_at' => $schedule->starts_at,
                'ends_at' => $schedule->ends_at,
                'topic' => $schedule->topic,
                'status' => 'completed',
            ]);
            $studentIds = $schedule->students()->pluck('students.id')->all();
            $session->students()->sync($studentIds);

            foreach ($studentIds as $studentId) {
                Attendance::query()->updateOrCreate([
                    'class_session_id' => $session->id,
                    'student_id' => $studentId,
                ], [
                    'status' => 'present',
                    'marked_by' => $schedule->teacher?->user_id ?? $users['admin']->id,
                    'marked_at' => now(),
                ]);
            }

            $sessions[$key] = $session;
        }

        $observationRows = [
            ['sunny_monday', 'alya', 'LKH01', 'raras', 'independent', false, 'Merapikan tray setelah diberi pengingat satu kali.'],
            ['sunny_monday', 'raka', 'BHS01', 'raras', 'developing', false, 'Mengikuti instruksi pertama, instruksi kedua masih perlu diulang.'],
            ['glow_tuesday', 'kirana', 'KOG01', 'mira', 'independent', false, 'Mandiri mengelompokkan warna primer dan sekunder.'],
            ['sunny_wednesday', 'nala', 'PMK01', 'dimas', 'emerging', true, 'Masih ragu naik turun tangga kecil, perlu pendampingan dekat.'],
            ['glow_thursday', 'maya', 'SEM01', 'raras', 'developing', false, 'Mulai menunggu giliran saat permainan kelompok.'],
            ['infant_friday', 'rumi', 'SEN01', 'hana', 'independent', false, 'Merespons material visual dengan fokus stabil.'],
            ['sunny_wednesday', 'alya', 'PMK02', 'dimas', 'developing', false, 'Melompat dengan antusias, pendaratan masih perlu diarahkan.'],
            ['sunny_wednesday', 'nala', 'SEM02', 'dimas', 'emerging', true, 'Membutuhkan bantuan guru untuk kembali tenang setelah transisi.'],
        ];

        $createdObservations = [];
        foreach ($observationRows as [$sessionKey, $studentKey, $indicatorCode, $teacherKey, $level, $needsFollowUp, $note]) {
            $indicator = $indicators[$indicatorCode];
            $observation = Observation::query()->updateOrCreate([
                'class_session_id' => $sessions[$sessionKey]->id,
                'student_id' => $students[$studentKey]->id,
                'indicator_id' => $indicator->id,
                'observed_on' => $sessions[$sessionKey]->session_date,
            ], [
                'teacher_id' => $teachers[$teacherKey]->id,
                'development_area_id' => $indicator->development_area_id,
                'observation_type' => 'scheduled',
                'level' => $level,
                'status' => 'included_in_report',
                'score' => Observation::scoreForLevel($level),
                'note' => $note,
                'needs_follow_up' => $needsFollowUp,
                'include_in_report' => true,
            ]);

            $createdObservations[] = $observation;
        }

        foreach (collect($createdObservations)->where('needs_follow_up', true) as $observation) {
            IlpPlan::query()->updateOrCreate([
                'student_id' => $observation->student_id,
                'indicator_id' => $observation->indicator_id,
                'term_id' => $term->id,
            ], [
                'student_id' => $observation->student_id,
                'indicator_id' => $observation->indicator_id,
                'term_id' => $term->id,
                'trigger_observation_id' => $observation->id,
                'status' => 'draft',
                'analysis' => 'Anak masih memerlukan stimulasi dan pendampingan terarah pada indikator ini.',
                'target' => 'Mampu melakukan indikator dengan bantuan minimal dalam 2-4 minggu.',
                'follow_up' => 'Pendampingan melalui kegiatan bermain terarah, pengulangan singkat, dan komunikasi dengan orangtua.',
                'starts_on' => Carbon::parse($observation->observed_on)->addDay(),
                'ends_on' => Carbon::parse($observation->observed_on)->addWeeks(4),
            ]);
        }

        foreach (Student::query()->with('schoolClass')->get() as $student) {
            $summary = $this->buildReportSummary($student);
            $bestArea = collect($summary['areas'])->sortByDesc('score')->first();

            Report::query()->updateOrCreate([
                'student_id' => $student->id,
                'term_id' => $term->id,
            ], [
                'student_id' => $student->id,
                'term_id' => $term->id,
                'homeroom_teacher_id' => $teachers['raras']->id,
                'status' => $student->observations()->exists() ? 'draft' : 'empty',
                'summary' => $summary,
                'teacher_narrative' => $student->observations()->exists()
                    ? "{$student->name} menunjukkan perkembangan yang paling menonjol pada area {$bestArea['name']}. Draft ini masih perlu disunting guru sebelum diserahkan ke orangtua."
                    : 'Belum ada observasi pada periode ini.',
                'generated_at' => now(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReportSummary(Student $student): array
    {
        $areas = DevelopmentArea::query()
            ->with(['indicators.observations' => fn ($query) => $query->where('student_id', $student->id)])
            ->orderBy('sort_order')
            ->get()
            ->map(function (DevelopmentArea $area) {
                $observations = $area->indicators->flatMap->observations;
                $score = $observations->count() > 0 ? round($observations->avg('score')) : 0;

                return [
                    'name' => $area->name,
                    'score' => $score,
                    'observed' => $observations->count(),
                    'needs_support' => $observations->where('needs_follow_up', true)->count(),
                ];
            })
            ->values()
            ->all();

        return [
            'areas' => $areas,
            'observation_count' => $student->observations()->count(),
            'needs_support_count' => $student->observations()->where('needs_follow_up', true)->count(),
            'generated_from' => 'observations',
        ];
    }
}

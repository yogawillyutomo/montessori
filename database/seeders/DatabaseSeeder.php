<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Attendance;
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
            'super_admin' => User::create([
                'name' => 'Direktur Sekolah',
                'email' => 'super@montessori.test',
                'password' => 'password',
                'role' => 'super_admin',
            ]),
            'admin' => User::create([
                'name' => 'Admin Operasional',
                'email' => 'admin@montessori.test',
                'password' => 'password',
                'role' => 'admin',
            ]),
            'teacher_raras' => User::create([
                'name' => 'Bu Raras',
                'email' => 'raras@montessori.test',
                'password' => 'password',
                'role' => 'teacher',
            ]),
            'teacher_mira' => User::create([
                'name' => 'Bu Mira',
                'email' => 'mira@montessori.test',
                'password' => 'password',
                'role' => 'teacher',
            ]),
            'parent_alya' => User::create([
                'name' => 'Orangtua Alya',
                'email' => 'parent@montessori.test',
                'password' => 'password',
                'role' => 'parent',
            ]),
        ];

        $classes = [
            'sunny' => SchoolClass::create([
                'name' => 'Sunny',
                'slug' => 'sunny',
                'level' => 'Toddler',
                'age_range' => '2-4 tahun',
                'capacity' => 18,
                'color' => 'sage',
            ]),
            'glow' => SchoolClass::create([
                'name' => 'Glow',
                'slug' => 'glow',
                'level' => 'Preschool',
                'age_range' => '4-6 tahun',
                'capacity' => 16,
                'color' => 'coral',
            ]),
            'infant' => SchoolClass::create([
                'name' => 'Infant',
                'slug' => 'infant',
                'level' => 'Infant',
                'age_range' => '6-24 bulan',
                'capacity' => 10,
                'color' => 'blue',
            ]),
        ];

        $teachers = [
            'raras' => Teacher::create([
                'user_id' => $users['teacher_raras']->id,
                'name' => 'Bu Raras',
                'code' => 'TCH01',
                'focus_area' => 'Practical Life dan Bahasa',
                'phone' => '0812-0000-0001',
            ]),
            'mira' => Teacher::create([
                'user_id' => $users['teacher_mira']->id,
                'name' => 'Bu Mira',
                'code' => 'TCH02',
                'focus_area' => 'Sensorial dan Kognitif',
                'phone' => '0812-0000-0002',
            ]),
            'dimas' => Teacher::create([
                'name' => 'Pak Dimas',
                'code' => 'TCH03',
                'focus_area' => 'Motorik Kasar',
                'phone' => '0812-0000-0003',
            ]),
            'hana' => Teacher::create([
                'name' => 'Bu Hana',
                'code' => 'TCH04',
                'focus_area' => 'Infant Care',
                'phone' => '0812-0000-0004',
            ]),
        ];

        $guardians = [
            'alya' => Guardian::create([
                'user_id' => $users['parent_alya']->id,
                'name' => 'Orangtua Alya',
                'relationship' => 'Ibu',
                'phone' => '0812-1111-0001',
                'email' => 'parent@montessori.test',
                'address' => 'Purwokerto',
            ]),
            'raka' => Guardian::create(['name' => 'Orangtua Raka', 'relationship' => 'Ayah', 'phone' => '0812-1111-0002', 'address' => 'Banyumas']),
            'nala' => Guardian::create(['name' => 'Orangtua Nala', 'relationship' => 'Ibu', 'phone' => '0812-1111-0003', 'address' => 'Kembaran']),
            'kirana' => Guardian::create(['name' => 'Orangtua Kirana', 'relationship' => 'Ibu', 'phone' => '0812-1111-0004', 'address' => 'Purwokerto Utara']),
            'arka' => Guardian::create(['name' => 'Orangtua Arka', 'relationship' => 'Ayah', 'phone' => '0812-1111-0005', 'address' => 'Sokaraja']),
            'maya' => Guardian::create(['name' => 'Orangtua Maya', 'relationship' => 'Ibu', 'phone' => '0812-1111-0006', 'address' => 'Purbalingga']),
            'rumi' => Guardian::create(['name' => 'Orangtua Rumi', 'relationship' => 'Ibu', 'phone' => '0812-1111-0007', 'address' => 'Kroya']),
            'shaka' => Guardian::create(['name' => 'Orangtua Shaka', 'relationship' => 'Ayah', 'phone' => '0812-1111-0008', 'address' => 'Purwokerto']),
        ];

        $students = [
            'alya' => Student::create([
                'school_class_id' => $classes['sunny']->id,
                'guardian_id' => $guardians['alya']->id,
                'code' => 'SUN01',
                'name' => 'Alya Pramesti',
                'gender' => 'Perempuan',
                'birth_place' => 'Banyumas',
                'birth_date' => '2022-09-22',
            ]),
            'raka' => Student::create([
                'school_class_id' => $classes['sunny']->id,
                'guardian_id' => $guardians['raka']->id,
                'code' => 'SUN02',
                'name' => 'Raka Mahendra',
                'gender' => 'Laki-laki',
                'birth_place' => 'Purwokerto',
                'birth_date' => '2023-07-16',
            ]),
            'nala' => Student::create([
                'school_class_id' => $classes['sunny']->id,
                'guardian_id' => $guardians['nala']->id,
                'code' => 'SUN03',
                'name' => 'Nala Putri',
                'gender' => 'Perempuan',
                'birth_place' => 'Banyumas',
                'birth_date' => '2024-01-15',
            ]),
            'kirana' => Student::create([
                'school_class_id' => $classes['glow']->id,
                'guardian_id' => $guardians['kirana']->id,
                'code' => 'GLO01',
                'name' => 'Kirana Satya',
                'gender' => 'Perempuan',
                'birth_place' => 'Purwokerto',
                'birth_date' => '2020-11-05',
            ]),
            'arka' => Student::create([
                'school_class_id' => $classes['glow']->id,
                'guardian_id' => $guardians['arka']->id,
                'code' => 'GLO02',
                'name' => 'Arka Wijaya',
                'gender' => 'Laki-laki',
                'birth_place' => 'Banyumas',
                'birth_date' => '2021-02-18',
            ]),
            'maya' => Student::create([
                'school_class_id' => $classes['glow']->id,
                'guardian_id' => $guardians['maya']->id,
                'code' => 'GLO03',
                'name' => 'Maya Salsabila',
                'gender' => 'Perempuan',
                'birth_place' => 'Purbalingga',
                'birth_date' => '2021-08-12',
            ]),
            'rumi' => Student::create([
                'school_class_id' => $classes['infant']->id,
                'guardian_id' => $guardians['rumi']->id,
                'code' => 'INF01',
                'name' => 'Rumi Adikara',
                'gender' => 'Laki-laki',
                'birth_place' => 'Kroya',
                'birth_date' => '2024-04-29',
            ]),
            'shaka' => Student::create([
                'school_class_id' => $classes['infant']->id,
                'guardian_id' => $guardians['shaka']->id,
                'code' => 'INF02',
                'name' => 'Shaka Nawasena',
                'gender' => 'Laki-laki',
                'birth_place' => 'Purwokerto',
                'birth_date' => '2024-03-16',
            ]),
        ];

        $academicYear = AcademicYear::create([
            'name' => '2025/2026',
            'starts_on' => '2025-07-01',
            'ends_on' => '2026-06-30',
            'is_active' => true,
        ]);

        $term = Term::create([
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
            $areas[$slug] = DevelopmentArea::create([
                'name' => $name,
                'slug' => $slug,
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
            $indicators[$code] = Indicator::create([
                'development_area_id' => $areas[$areaSlug]->id,
                'code' => $code,
                'sub_area' => $subArea,
                'description' => $description,
                'level' => $level,
            ]);
        }

        $schedules = [
            'sunny_monday' => [
                'class' => 'sunny',
                'teacher' => 'raras',
                'day' => 1,
                'start' => '08:00',
                'end' => '09:30',
                'topic' => 'Practical Life dan Bahasa',
                'students' => ['alya', 'raka'],
            ],
            'infant_monday' => [
                'class' => 'infant',
                'teacher' => 'hana',
                'day' => 1,
                'start' => '10:00',
                'end' => '11:00',
                'topic' => 'Sensorial Play',
                'students' => ['rumi', 'shaka'],
            ],
            'glow_tuesday' => [
                'class' => 'glow',
                'teacher' => 'mira',
                'day' => 2,
                'start' => '08:00',
                'end' => '09:30',
                'topic' => 'Sensorial dan Klasifikasi',
                'students' => ['kirana', 'arka', 'maya'],
            ],
            'sunny_wednesday' => [
                'class' => 'sunny',
                'teacher' => 'dimas',
                'day' => 3,
                'start' => '09:00',
                'end' => '10:30',
                'topic' => 'Motorik Kasar',
                'students' => ['alya', 'nala'],
            ],
            'glow_thursday' => [
                'class' => 'glow',
                'teacher' => 'raras',
                'day' => 4,
                'start' => '08:30',
                'end' => '10:00',
                'topic' => 'Bahasa dan Kelompok Kecil',
                'students' => ['kirana', 'maya'],
            ],
            'infant_friday' => [
                'class' => 'infant',
                'teacher' => 'hana',
                'day' => 5,
                'start' => '08:00',
                'end' => '09:00',
                'topic' => 'Rutinitas dan Bonding',
                'students' => ['rumi'],
            ],
        ];

        foreach ($schedules as $key => $row) {
            $schedule = \App\Models\WeeklySchedule::create([
                'school_class_id' => $classes[$row['class']]->id,
                'teacher_id' => $teachers[$row['teacher']]->id,
                'day_of_week' => $row['day'],
                'starts_at' => $row['start'],
                'ends_at' => $row['end'],
                'topic' => $row['topic'],
            ]);

            $schedule->students()->attach(
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
            $session = ClassSession::create([
                'weekly_schedule_id' => $schedule->id,
                'school_class_id' => $schedule->school_class_id,
                'teacher_id' => $schedule->teacher_id,
                'session_date' => $date,
                'starts_at' => $schedule->starts_at,
                'ends_at' => $schedule->ends_at,
                'topic' => $schedule->topic,
                'status' => 'completed',
            ]);
            $studentIds = $schedule->students()->pluck('students.id')->all();
            $session->students()->attach($studentIds);

            foreach ($studentIds as $studentId) {
                Attendance::create([
                    'class_session_id' => $session->id,
                    'student_id' => $studentId,
                    'status' => 'present',
                ]);
            }

            $sessions[$key] = $session;
        }

        $observationRows = [
            ['sunny_monday', 'alya', 'LKH01', 'raras', 'achieved', 'Merapikan tray setelah diberi pengingat satu kali.'],
            ['sunny_monday', 'raka', 'BHS01', 'raras', 'emerging', 'Mengikuti instruksi pertama, instruksi kedua masih perlu diulang.'],
            ['glow_tuesday', 'kirana', 'KOG01', 'mira', 'achieved', 'Mandiri mengelompokkan warna primer dan sekunder.'],
            ['sunny_wednesday', 'nala', 'PMK01', 'dimas', 'needs_support', 'Masih ragu naik turun tangga kecil, perlu pendampingan dekat.'],
            ['glow_thursday', 'maya', 'SEM01', 'raras', 'emerging', 'Mulai menunggu giliran saat permainan kelompok.'],
            ['infant_friday', 'rumi', 'SEN01', 'hana', 'achieved', 'Merespons material visual dengan fokus stabil.'],
            ['sunny_wednesday', 'alya', 'PMK02', 'dimas', 'emerging', 'Melompat dengan antusias, pendaratan masih perlu diarahkan.'],
            ['sunny_wednesday', 'nala', 'SEM02', 'dimas', 'needs_support', 'Membutuhkan bantuan guru untuk kembali tenang setelah transisi.'],
        ];

        $createdObservations = [];
        foreach ($observationRows as [$sessionKey, $studentKey, $indicatorCode, $teacherKey, $status, $note]) {
            $observation = Observation::create([
                'class_session_id' => $sessions[$sessionKey]->id,
                'student_id' => $students[$studentKey]->id,
                'indicator_id' => $indicators[$indicatorCode]->id,
                'teacher_id' => $teachers[$teacherKey]->id,
                'observed_on' => $sessions[$sessionKey]->session_date,
                'status' => $status,
                'score' => Observation::STATUS_SCORES[$status],
                'note' => $note,
            ]);

            $createdObservations[] = $observation;
        }

        foreach (collect($createdObservations)->where('status', 'needs_support') as $observation) {
            IlpPlan::create([
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

            Report::create([
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
                    'needs_support' => $observations->where('status', 'needs_support')->count(),
                ];
            })
            ->values()
            ->all();

        return [
            'areas' => $areas,
            'observation_count' => $student->observations()->count(),
            'needs_support_count' => $student->observations()->where('status', 'needs_support')->count(),
            'generated_from' => 'observations',
        ];
    }
}

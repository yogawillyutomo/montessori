@extends('alpha.layout')

@section('title', 'Proses - Montessori Bloom')
@section('page_title', 'Proses Harian')
@section('page_subtitle', 'Jadwal mingguan, presensi, observasi, dan ILP/remedial.')

@section('content')
    <div class="section-head">
        <div>
            <h2>Alur Harian</h2>
            <div class="meta">Atur jadwal mingguan, buat presensi harian, catat observasi, lalu tindak lanjuti lewat ILP.</div>
        </div>
    </div>

    @if ($processSection === 'schedules')
        <section class="panel">
            <div class="line-head">
                <div>
                    <h3>Slot Mingguan</h3>
                    <div class="meta">Template slot berulang untuk jam, ruangan, level, dan peserta. Perubahan jadwal dilakukan dari sini.</div>
                </div>
                @if ($canManageSchedules)
                    <button class="btn primary" type="button" data-modal-target="modal-create-schedule">Tambah Jadwal</button>
                @endif
            </div>
            <div class="schedule-grid" style="margin-top: 14px">
                @foreach ([1, 2, 3, 4, 5, 6] as $day)
                    <div class="day-column">
                        @php
                            $daySchedules = $schedules->where('day_of_week', $day);
                            $dayLevelCount = $daySchedules
                                ->pluck('schoolClass.classLevel.name')
                                ->filter()
                                ->unique()
                                ->count();
                        @endphp
                        <div class="day-title">
                            <strong>{{ $dayLabels[$day] }}</strong>
                            <span>{{ $daySchedules->count() }} slot | {{ $dayLevelCount }} level</span>
                        </div>
                        <div class="card-list day-schedule-list" data-card-list data-card-page-size="5" data-card-search-placeholder="Cari jam, level, kelas, guru">
                            @forelse ($schedules->where('day_of_week', $day) as $schedule)
                                @php
                                    $start = \Illuminate\Support\Carbon::parse($schedule->starts_at)->format('H:i');
                                    $end = \Illuminate\Support\Carbon::parse($schedule->ends_at)->format('H:i');
                                    $slotCapacity = $schedule->capacity ?: $schedule->schoolClass->capacity;
                                    $studentModalId = "modal-schedule-students-{$schedule->id}";
                                    $levelName = $schedule->schoolClass->classLevel?->name ?? $schedule->schoolClass->level;
                                @endphp
                                <div class="line-card schedule-slot-card" data-card-item>
                                <div class="line-head">
                                    <div>
                                        <div class="schedule-time">{{ $start }}-{{ $end }}</div>
                                        <div class="meta">{{ $levelName }} | {{ $schedule->schoolClass->name }}</div>
                                    </div>
                                    <span class="status {{ $schedule->is_active ? 'status-achieved' : 'status-empty' }}">{{ $schedule->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                                </div>
                                <div class="schedule-topic">{{ $schedule->topic ?: 'Topik belum diisi' }}</div>
                                <div class="schedule-badge-row">
                                    <span class="chip">{{ $schedule->teacher->name }}</span>
                                    <span class="chip">{{ $schedule->room ?: 'Ruangan belum diisi' }}</span>
                                </div>
                                <div class="mini-grid schedule-summary">
                                    <div>
                                        <span class="meta">Peserta</span>
                                        <strong>{{ $schedule->students->count() }} / {{ $slotCapacity }}</strong>
                                    </div>
                                    <div>
                                        <span class="meta">Ruangan</span>
                                        <strong>{{ $schedule->room ?: '-' }}</strong>
                                    </div>
                                </div>
                                <div class="chips compact-chips">
                                    @foreach ($schedule->students->take(4) as $student)
                                        <span class="chip">{{ $student->name }}</span>
                                    @endforeach
                                    @if ($schedule->students->count() > 4)
                                        <span class="chip">+{{ $schedule->students->count() - 4 }}</span>
                                    @endif
                                </div>
                                <dialog class="modal wide-modal" id="{{ $studentModalId }}">
                                    <div class="modal-body">
                                        <div class="modal-head">
                                            <div>
                                                <h3>Detail Peserta Slot</h3>
                                                <div class="meta">{{ $dayLabels[$schedule->day_of_week] }} | {{ $start }}-{{ $end }} | {{ $schedule->schoolClass->name }} | {{ $schedule->students->count() }}/{{ $slotCapacity }} siswa</div>
                                            </div>
                                            <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                                        </div>
                                        <div class="table-wrap compact-table">
                                            <table>
                                                <thead>
                                                <tr>
                                                    <th>Siswa</th>
                                                    <th>Kelas</th>
                                                    <th>Orangtua</th>
                                                    <th>Kontak</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                @forelse ($schedule->students as $student)
                                                    <tr>
                                                        <td><strong>{{ $student->name }}</strong><br><span class="meta">{{ $student->code }}</span></td>
                                                        <td>{{ $student->schoolClass?->name ?? '-' }}</td>
                                                        <td>{{ $student->guardian?->name ?? '-' }}</td>
                                                        <td>{{ $student->guardian?->phone ?? '-' }}</td>
                                                    </tr>
                                                @empty
                                                    <tr><td colspan="4">Belum ada siswa pada jadwal ini.</td></tr>
                                                @endforelse
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </dialog>
                                <div class="toolbar compact-actions">
                                    <button class="btn ghost" type="button" data-modal-target="{{ $studentModalId }}">Detail Siswa</button>
                                    @if ($canManageSchedules)
                                        <form method="post" action="{{ route('alpha.process.schedules.toggle', $schedule) }}">
                                            @csrf
                                            @method('patch')
                                            <button class="btn ghost" type="submit">{{ $schedule->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                        </form>
                                        <button class="btn ghost" type="button" data-modal-target="modal-edit-schedule-{{ $schedule->id }}">Edit</button>
                                        <dialog class="modal wide-modal" id="modal-edit-schedule-{{ $schedule->id }}">
                                            <form method="post" action="{{ route('alpha.process.schedules.update', $schedule) }}">
                                                @csrf
                                                @method('patch')
                                            <div class="modal-head">
                                                <div>
                                                    <h3>Edit Jadwal</h3>
                                                    <div class="meta">{{ $dayLabels[$schedule->day_of_week] }} | {{ $start }}-{{ $end }} | {{ $schedule->schoolClass->name }}</div>
                                                </div>
                                                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                                            </div>
                                            <div class="form-grid">
                                                <div class="field">
                                                    <label>Hari</label>
                                                    <select name="day_of_week">
                                                        @foreach ([1, 2, 3, 4, 5, 6] as $optionDay)
                                                            <option value="{{ $optionDay }}" @selected($schedule->day_of_week === $optionDay)>{{ $dayLabels[$optionDay] }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="field">
                                                    <label>Kelompok acuan</label>
                                                    <select name="school_class_id" data-schedule-class-select="schedule-{{ $schedule->id }}">
                                                        @foreach ($classes as $class)
                                                            <option value="{{ $class->id }}" @selected($schedule->school_class_id === $class->id)>{{ $class->name }} - {{ $class->classLevel?->name ?? $class->level }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="field">
                                                    <label>Guru</label>
                                                    <select name="teacher_id">
                                                        @foreach ($teachers as $teacher)
                                                            <option value="{{ $teacher->id }}" @selected($schedule->teacher_id === $teacher->id)>{{ $teacher->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="field">
                                                    <label>Ruangan</label>
                                                    <input name="room" value="{{ old('room', $schedule->room) }}" placeholder="Ruang Sunny">
                                                </div>
                                                <div class="field">
                                                    <label>Kapasitas slot</label>
                                                    <input type="number" min="1" max="60" name="capacity" value="{{ old('capacity', $slotCapacity) }}">
                                                </div>
                                                <div class="date-grid wide">
                                                    <div class="field">
                                                        <label>Mulai</label>
                                                        <input type="time" name="starts_at" value="{{ old('starts_at', $start) }}">
                                                    </div>
                                                    <div class="field">
                                                        <label>Selesai</label>
                                                        <input type="time" name="ends_at" value="{{ old('ends_at', $end) }}">
                                                    </div>
                                                </div>
                                                <div class="field wide">
                                                    <label>Topik</label>
                                                    <input name="topic" value="{{ old('topic', $schedule->topic) }}">
                                                </div>
                                                <div class="field wide">
                                                    <label>Peserta default</label>
                                                    <select name="student_ids[]" multiple size="8" data-schedule-student-select="schedule-{{ $schedule->id }}" data-student-multiselect data-student-picker-placeholder="Cari nama, kode, kelas, atau orangtua">
                                                        @foreach ($students as $student)
                                                            <option
                                                                value="{{ $student->id }}"
                                                                data-class-id="{{ $student->school_class_id }}"
                                                                data-class-name="{{ $student->schoolClass?->name ?? '-' }}"
                                                                data-level-id="{{ $student->schoolClass?->class_level_id ?? 'none' }}"
                                                                data-level-name="{{ $student->schoolClass?->classLevel?->name ?? $student->schoolClass?->level ?? 'Tanpa level' }}"
                                                                data-code="{{ $student->code }}"
                                                                data-guardian="{{ $student->guardian?->name ?? '' }}"
                                                                @selected($schedule->students->contains($student->id))
                                                            >{{ $student->name }} - {{ $student->schoolClass?->name ?? '-' }}</option>
                                                        @endforeach
                                                    </select>
                                                    <div class="toolbar compact-actions">
                                                        <button class="btn ghost" type="button" data-schedule-select-class-students="schedule-{{ $schedule->id }}">Pilih siswa kelompok acuan</button>
                                                    </div>
                                                    <div class="meta">Peserta boleh dari kelas lain selama tidak bentrok dan kapasitas cukup.</div>
                                                </div>
                                            </div>
                                            <div class="toolbar modal-actions">
                                                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                                <button class="btn primary" type="submit">Update Jadwal</button>
                                            </div>
                                            </form>
                                        </dialog>
                                        <button class="btn danger" type="button" data-delete-action="{{ route('alpha.process.schedules.destroy', $schedule) }}" data-delete-label="Hapus jadwal {{ $dayLabels[$schedule->day_of_week] }} {{ $start }}-{{ $end }}? Jadwal tidak bisa dihapus jika sudah pernah dibuat menjadi presensi.">Hapus</button>
                                    @endif
                                </div>
                                </div>
                            @empty
                                <div class="line-card meta">Belum ada jadwal.</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
        @if ($canManageSchedules)
            <dialog class="modal wide-modal" id="modal-create-schedule">
                <form method="post" action="{{ route('alpha.process.schedules.store') }}">
                @csrf
                <div class="modal-head">
                    <div>
                        <h3>Tambah Slot Mingguan</h3>
                        <div class="meta">Buat template slot berulang per minggu. Presensi harian akan menyalin peserta default dari sini.</div>
                    </div>
                    <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="schedule-day">Hari</label>
                        <select id="schedule-day" name="day_of_week">
                            @foreach ([1, 2, 3, 4, 5, 6] as $day)
                                <option value="{{ $day }}" @selected((int) old('day_of_week', 1) === $day)>{{ $dayLabels[$day] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="schedule-class">Kelompok acuan</label>
                        <select id="schedule-class" name="school_class_id" data-schedule-class-select="schedule-create">
                            @foreach ($classes as $class)
                                <option value="{{ $class->id }}" @selected((int) old('school_class_id') === $class->id)>{{ $class->name }} - {{ $class->classLevel?->name ?? $class->level }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="schedule-teacher">Guru</label>
                        <select id="schedule-teacher" name="teacher_id">
                            @foreach ($teachers as $teacher)
                                <option value="{{ $teacher->id }}" @selected((int) old('teacher_id') === $teacher->id)>{{ $teacher->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="schedule-room">Ruangan</label>
                        <input id="schedule-room" name="room" value="{{ old('room') }}" placeholder="Ruang Sunny">
                    </div>
                    <div class="field">
                        <label for="schedule-capacity">Kapasitas slot</label>
                        <input id="schedule-capacity" type="number" min="1" max="60" name="capacity" value="{{ old('capacity') }}" placeholder="Ikuti kapasitas kelompok">
                    </div>
                    <div class="date-grid wide">
                        <div class="field">
                            <label for="schedule-start">Mulai</label>
                            <input id="schedule-start" type="time" name="starts_at" value="{{ old('starts_at', '08:00') }}">
                        </div>
                        <div class="field">
                            <label for="schedule-end">Selesai</label>
                            <input id="schedule-end" type="time" name="ends_at" value="{{ old('ends_at', '09:00') }}">
                        </div>
                    </div>
                    <div class="field wide">
                        <label for="schedule-topic">Topik</label>
                        <input id="schedule-topic" name="topic" value="{{ old('topic') }}" placeholder="Practical Life">
                    </div>
                    <div class="field wide">
                        <label for="schedule-students">Peserta default</label>
                        <select id="schedule-students" name="student_ids[]" multiple size="8" data-schedule-student-select="schedule-create" data-student-multiselect data-student-picker-placeholder="Cari nama, kode, kelas, atau orangtua">
                            @foreach ($students as $student)
                                <option
                                    value="{{ $student->id }}"
                                    data-class-id="{{ $student->school_class_id }}"
                                    data-class-name="{{ $student->schoolClass?->name ?? '-' }}"
                                    data-level-id="{{ $student->schoolClass?->class_level_id ?? 'none' }}"
                                    data-level-name="{{ $student->schoolClass?->classLevel?->name ?? $student->schoolClass?->level ?? 'Tanpa level' }}"
                                    data-code="{{ $student->code }}"
                                    data-guardian="{{ $student->guardian?->name ?? '' }}"
                                    @selected(in_array($student->id, old('student_ids', [])))
                                >{{ $student->name }} - {{ $student->schoolClass?->name ?? '-' }}</option>
                            @endforeach
                        </select>
                        <div class="toolbar compact-actions">
                            <button class="btn ghost" type="button" data-schedule-select-class-students="schedule-create">Pilih siswa kelompok acuan</button>
                        </div>
                        <div class="meta">Boleh pilih lintas kelas selama siswa tidak bentrok dan kapasitas slot cukup.</div>
                    </div>
                </div>
                <div class="toolbar modal-actions">
                    <button class="btn ghost" type="button" data-modal-close>Batal</button>
                    <button class="btn primary" type="submit">Simpan Slot</button>
                </div>
                </form>
            </dialog>
        @endif
    @endif

    @if ($processSection === 'sessions')
        @php
            $attendanceOptions = ['present' => 'Hadir', 'absent' => 'Tidak hadir', 'late' => 'Terlambat', 'sick' => 'Sakit', 'excused' => 'Izin'];
            $selectedDate = \Illuminate\Support\Carbon::parse($selectedSessionDate);
            $prevDate = $selectedDate->copy()->subDay()->toDateString();
            $nextDate = $selectedDate->copy()->addDay()->toDateString();
            $attendanceSessions = $sessions
                ->filter(fn ($session) => $session->session_date->toDateString() === $selectedDate->toDateString())
                ->sortBy(fn ($session) => $session->starts_at)
                ->values();
            $defaultSessionDate = old('session_date', $selectedDate->toDateString());
            $todayText = $selectedDate->isToday() ? ' (Hari ini)' : '';
        @endphp

        <section class="attendance-shell">
            <div class="attendance-head">
                <div>
                    <div class="attendance-location">Purwokerto</div>
                    <h3>Class Sessions</h3>
                    <div class="meta">Presensi harian berdasarkan jadwal mingguan dan slot siswa.</div>
                </div>
                <button class="icon-btn" type="button" aria-label="Opsi presensi"><i data-lucide="more-vertical" class="nav-icon"></i></button>
            </div>

            <div class="attendance-date-card" id="attendance-date-card">
                {{-- Three.js particle canvas --}}
                <canvas id="attendance-canvas"></canvas>

                {{-- Navigation row: prev / date info / next --}}
                <div class="attendance-date-nav">
                    <a class="attendance-date-arrow" href="{{ route('alpha.process.attendance', ['date' => $prevDate]) }}" aria-label="Tanggal sebelumnya">
                        <i data-lucide="chevron-left" class="nav-icon"></i>
                    </a>

                    <div class="attendance-date-info">
                        <div class="date-day">
                            {{ strtoupper($dayLabels[$selectedDate->dayOfWeekIso] ?? $selectedDate->format('l')) }}
                            @if($selectedDate->isToday())
                                <span class="date-today-badge">Hari ini</span>
                            @endif
                        </div>
                        <div class="date-main">{{ $selectedDate->translatedFormat('d M Y') }}</div>
                    </div>

                    <a class="attendance-date-arrow" href="{{ route('alpha.process.attendance', ['date' => $nextDate]) }}" aria-label="Tanggal berikutnya">
                        <i data-lucide="chevron-right" class="nav-icon"></i>
                    </a>
                </div>

                {{-- Date picker form bar --}}
                <form class="attendance-date-form" method="get" action="{{ route('alpha.process.attendance') }}">
                    <label for="attendance-date-picker">Pilih tanggal</label>
                    <input id="attendance-date-picker" type="date" name="date" value="{{ $selectedDate->toDateString() }}" data-attendance-date-picker>
                    <button class="btn ghost" type="submit">Tampilkan</button>
                </form>
            </div>

            <div class="attendance-session-list">
                @forelse ($attendanceSessions as $session)
                    @php
                        $start = \Illuminate\Support\Carbon::parse($session->starts_at)->format('H:i');
                        $end = \Illuminate\Support\Carbon::parse($session->ends_at)->format('H:i');
                        $sessionCapacity = $session->capacity ?: $session->schoolClass->capacity;
                        $attendanceByStudent = $session->attendances->keyBy('student_id');
                        $presentCount = $session->attendances->where('status', 'present')->count();
                        $studentCount = $session->students->count();
                        $absentCount = $session->attendances->whereIn('status', ['absent', 'sick', 'excused'])->count();
                        $sessionDetailModal = "modal-session-detail-{$session->id}";
                        $sessionAttendanceModal = "modal-session-attendance-{$session->id}";
                    @endphp
                    <article class="attendance-session-card">
                        <div class="attendance-session-title">
                            <div>
                                <h4>{{ strtoupper($session->topic ?: 'Presensi') }} - {{ strtoupper($session->schoolClass->name) }} ({{ $start }})</h4>
                                <div class="attendance-time">
                                    <span class="status status-{{ str_replace('_', '-', $session->status) }}">{{ strtoupper($statusLabels[$session->status] ?? $session->status) }}</span>
                                    {{ $start }} - {{ $end }} (+07)
                                </div>
                                <div class="meta">{{ $session->room ?: 'Ruangan belum diisi' }} | {{ $session->teacher->name }}</div>
                            </div>
                            @if ($canWriteProcess)
                                <button class="btn primary" type="button" data-modal-target="{{ $sessionAttendanceModal }}">Isi Presensi</button>
                            @endif
                        </div>

                        <div class="attendance-session-actions">
                            <button class="attendance-info-tile" type="button" data-modal-target="{{ $sessionDetailModal }}">
                                <span>
                                    <i data-lucide="user-check" class="nav-icon"></i>
                                    <strong>{{ $presentCount }}</strong>
                                </span>
                                <span>
                                    <i data-lucide="users" class="nav-icon"></i>
                                    <strong>{{ $studentCount }} / {{ $sessionCapacity }}</strong>
                                </span>
                                <small>class info</small>
                            </button>
                            <div class="attendance-feed-tile">
                                <span>
                                    <i data-lucide="message-square" class="nav-icon"></i>
                                    <strong>{{ $session->observations_count }}</strong>
                                </span>
                                <small>observasi</small>
                            </div>
                            <div class="attendance-feed-tile muted">
                                <span>
                                    <i data-lucide="user-x" class="nav-icon"></i>
                                    <strong>{{ $absentCount }}</strong>
                                </span>
                                <small>tidak hadir</small>
                            </div>
                        </div>

                        <div class="toolbar compact-actions attendance-card-footer">
                            <button class="btn ghost" type="button" data-modal-target="{{ $sessionDetailModal }}">Detail</button>
                            @if ($canWriteProcess)
                                <button class="btn ghost" type="button" data-modal-target="{{ $sessionAttendanceModal }}">Edit Presensi</button>
                                <button class="btn danger" type="button" data-delete-action="{{ route('alpha.process.sessions.destroy', $session) }}" data-delete-label="Hapus presensi {{ $session->schoolClass->name }} tanggal {{ $session->session_date->format('d M Y') }}? Presensi tidak bisa dihapus jika sudah punya observasi.">Hapus</button>
                            @endif
                        </div>
                    </article>

                    <dialog class="modal wide-modal" id="{{ $sessionDetailModal }}">
                        <div class="modal-body">
                            <div class="modal-head">
                                <div>
                                    <h3>Detail Presensi</h3>
                                    <div class="meta">{{ $session->session_date->format('d M Y') }} | {{ $start }}-{{ $end }} | {{ $session->schoolClass->name }}</div>
                                </div>
                                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                            </div>
                            <div class="mini-grid session-summary-grid">
                                <div>
                                    <span class="meta">Guru</span>
                                    <strong>{{ $session->teacher->name }}</strong>
                                </div>
                                <div>
                                    <span class="meta">Ruangan</span>
                                    <strong>{{ $session->room ?: '-' }}</strong>
                                </div>
                                <div>
                                    <span class="meta">Peserta</span>
                                    <strong>{{ $studentCount }} / {{ $sessionCapacity }}</strong>
                                </div>
                                <div>
                                    <span class="meta">Topik</span>
                                    <strong>{{ $session->topic ?: '-' }}</strong>
                                </div>
                            </div>
                            <div class="table-wrap compact-table session-detail-table">
                                <table>
                                    <thead>
                                    <tr>
                                        <th>Siswa</th>
                                        <th>Kelas asal</th>
                                        <th>Orangtua</th>
                                        <th>Kehadiran</th>
                                        <th>Catatan</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @forelse ($session->students as $student)
                                        @php
                                            $attendance = $attendanceByStudent->get($student->id);
                                        @endphp
                                        <tr>
                                            <td><strong>{{ $student->name }}</strong><br><span class="meta">{{ $student->code }}</span></td>
                                            <td>{{ $student->schoolClass?->name ?? '-' }}</td>
                                            <td>{{ $student->guardian?->name ?? '-' }}<br><span class="meta">{{ $student->guardian?->phone ?? '-' }}</span></td>
                                            <td><span class="status status-{{ $attendance?->status ?? 'empty' }}">{{ $attendanceOptions[$attendance?->status] ?? '-' }}</span></td>
                                            <td>{{ $attendance?->note ?: '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5">Belum ada siswa pada presensi ini.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </dialog>

                    @if ($canWriteProcess)
                        <dialog class="modal wide-modal" id="{{ $sessionAttendanceModal }}">
                            <form method="post" action="{{ route('alpha.process.sessions.attendance', $session) }}">
                            @csrf
                            @method('patch')
                            <div class="modal-head">
                                <div>
                                    <h3>Isi Presensi</h3>
                                    <div class="meta">{{ $session->session_date->format('d M Y') }} | {{ $session->schoolClass->name }} | {{ $start }}-{{ $end }}</div>
                                </div>
                                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                            </div>
                            <div class="attendance-list">
                                @forelse ($session->students as $student)
                                    @php
                                        $attendance = $attendanceByStudent->get($student->id);
                                    @endphp
                                    <div class="line-card soft attendance-row">
                                        <div>
                                            <strong>{{ $student->name }}</strong>
                                            <div class="meta">{{ $student->guardian?->name ?? 'Orangtua belum diisi' }} | {{ $student->guardian?->phone ?? '-' }}</div>
                                        </div>
                                        <div class="field">
                                            <label>Status</label>
                                            <select name="attendance[{{ $student->id }}][status]">
                                                @foreach ($attendanceOptions as $status => $label)
                                                    <option value="{{ $status }}" @selected(($attendance?->status ?? 'present') === $status)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="field">
                                            <label>Catatan</label>
                                            <input name="attendance[{{ $student->id }}][note]" value="{{ old("attendance.{$student->id}.note", $attendance?->note) }}" placeholder="Opsional">
                                        </div>
                                    </div>
                                @empty
                                    <div class="line-card muted">Belum ada siswa pada presensi ini.</div>
                                @endforelse
                            </div>
                            <div class="toolbar modal-actions">
                                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                <button class="btn primary" type="submit">Simpan Presensi</button>
                            </div>
                            </form>
                        </dialog>
                    @endif
                @empty
                    <div class="attendance-empty">
                        <div class="attendance-empty-icon">
                            <i data-lucide="calendar-x" class="nav-icon"></i>
                        </div>
                        <strong>Belum ada presensi pada tanggal ini</strong>
                        <span>Pilih tanggal lain atau buat presensi dari slot mingguan.</span>
                    </div>
                @endforelse
            </div>

            <div class="attendance-bottom-action">
                <a class="btn ghost calendar-button" href="{{ route('alpha.process.attendance', ['date' => now()->toDateString()]) }}" aria-label="Kembali ke hari ini">
                    <i data-lucide="calendar-days" class="nav-icon"></i>
                </a>
                @if ($canWriteProcess)
                    <button class="btn primary attendance-start-button" type="button" data-modal-target="modal-create-session">
                        <i data-lucide="plus-circle" class="nav-icon"></i>
                        Buat Presensi
                    </button>
                @endif
            </div>
        </section>

        @if ($canWriteProcess)
            <dialog class="modal wide-modal" id="modal-create-session">
                <form method="post" action="{{ route('alpha.sessions.create-from-schedule') }}">
                @csrf
                <div class="modal-head">
                    <div>
                        <h3>Buat Presensi dari Jadwal</h3>
                        <div class="meta">Presensi mengambil jam, ruangan, dan peserta dari jadwal mingguan.</div>
                    </div>
                    <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                </div>
                <div class="form-grid">
                    <div class="field wide">
                        <label for="weekly_schedule_id">Slot mingguan</label>
                        <select id="weekly_schedule_id" name="weekly_schedule_id" data-session-schedule-select required>
                            @foreach ($schedules as $schedule)
                                @php
                                    $start = \Illuminate\Support\Carbon::parse($schedule->starts_at)->format('H:i');
                                    $end = \Illuminate\Support\Carbon::parse($schedule->ends_at)->format('H:i');
                                @endphp
                                <option value="{{ $schedule->id }}" data-day="{{ $schedule->day_of_week }}">
                                    {{ $dayLabels[$schedule->day_of_week] }} | {{ $start }}-{{ $end }} | {{ $schedule->schoolClass->name }} | {{ $schedule->teacher->name }}@if ($schedule->room) | {{ $schedule->room }}@endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="session_date">Tanggal presensi</label>
                        <input id="session_date" name="session_date" type="date" value="{{ $defaultSessionDate }}" data-session-date-input required>
                    </div>
                </div>
                <div class="toolbar modal-actions">
                    <button class="btn ghost" type="button" data-modal-close>Batal</button>
                    <button class="btn primary" type="submit">Buat Presensi</button>
                </div>
                </form>
            </dialog>
        @endif
    @endif

    @if ($processSection === 'observations')
        @php
            $observationStatuses = [
                'needs_support' => ['code' => 'SD', 'label' => 'Sedang berkembang', 'symbol' => '❌', 'class' => 'sd'],
                'emerging' => ['code' => 'SB', 'label' => 'Sudah berkembang', 'symbol' => '⭕', 'class' => 'sb'],
                'achieved' => ['code' => 'SM', 'label' => 'Sudah maksimal', 'symbol' => '✔️', 'class' => 'sm'],
            ];
            $groupedIndicators = $indicators->groupBy(fn ($indicator) => $indicator->developmentArea?->name ?? 'Tanpa area');
            $observationStatuses = [
                'needs_support' => ['code' => 'SD', 'label' => 'Sedang berkembang', 'symbol' => '&#10060;', 'class' => 'sd'],
                'emerging' => ['code' => 'SB', 'label' => 'Sudah berkembang', 'symbol' => '&#11093;', 'class' => 'sb'],
                'achieved' => ['code' => 'SM', 'label' => 'Sudah maksimal', 'symbol' => '&#10004;&#65039;', 'class' => 'sm'],
            ];
            $areaCount = $groupedIndicators->count();
            $selectedStudent = $students->firstWhere('id', (int) old('student_id')) ?? $students->first();
        @endphp

        <section class="panel observation-panel" id="monitoring-harian">
            <div class="line-head">
                <div>
                    <h3>Monitoring Harian</h3>
                    <div class="meta">Format mengikuti sheet MONITORING HARIAN: SD, SB, dan SM per indikator perkembangan.</div>
                </div>
            </div>

            <script type="application/json" id="monitoring-snapshots-json">@json($monitoringSnapshots)</script>
            @if ($canWriteProcess)
                <form method="post" action="{{ route('alpha.observations.store') }}" style="margin-top: 14px" data-observation-monitoring-form>
                    @csrf
                <div class="observation-context-grid">
                    <div class="field">
                        <label for="class_session_id">Presensi</label>
                        <select id="class_session_id" name="class_session_id" data-observation-session-select required>
                            @foreach ($sessions as $session)
                                @php
                                    $start = \Illuminate\Support\Carbon::parse($session->starts_at)->format('H:i');
                                    $sessionStudentIds = $session->students->pluck('id')->implode(',');
                                @endphp
                                <option value="{{ $session->id }}" data-student-ids="{{ $sessionStudentIds }}" @selected((int) old('class_session_id') === $session->id)>
                                    {{ $session->session_date->format('d M') }} | {{ $start }} | {{ $session->schoolClass->name }} | {{ $session->topic }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="student_id">Siswa</label>
                        <select id="student_id" name="student_id" data-observation-student-select required>
                            @foreach ($students as $student)
                                <option
                                    value="{{ $student->id }}"
                                    data-name="{{ $student->name }}"
                                    data-code="{{ $student->code }}"
                                    data-initial="{{ mb_substr($student->name, 0, 1) }}"
                                    data-class-name="{{ $student->schoolClass?->name ?? '-' }}"
                                    data-level-name="{{ $student->schoolClass?->classLevel?->name ?? $student->schoolClass?->level ?? '-' }}"
                                    data-guardian="{{ $student->guardian?->name ?? 'Orangtua belum diisi' }}"
                                    @selected((int) old('student_id') === $student->id)
                                >{{ $student->name }} | {{ $student->schoolClass?->name ?? '-' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="teacher_id">Guru</label>
                        <select id="teacher_id" name="teacher_id" required>
                            @foreach ($teachers as $teacher)
                                <option value="{{ $teacher->id }}" @selected((int) old('teacher_id') === $teacher->id)>{{ $teacher->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="observed_on">Tanggal observasi</label>
                        <input id="observed_on" name="observed_on" type="date" value="{{ old('observed_on', now()->toDateString()) }}" required>
                    </div>
                </div>

                <div class="student-observation-card" data-observation-student-card>
                    <div class="student-avatar">{{ $selectedStudent ? mb_substr($selectedStudent->name, 0, 1) : '-' }}</div>
                    <div>
                        <strong>{{ $selectedStudent?->name ?? 'Pilih siswa' }}</strong>
                        <div class="meta">
                            {{ $selectedStudent?->code ?? '-' }} |
                            {{ $selectedStudent?->schoolClass?->name ?? '-' }} |
                            {{ $selectedStudent?->schoolClass?->classLevel?->name ?? $selectedStudent?->schoolClass?->level ?? '-' }}
                        </div>
                        <div class="meta">{{ $selectedStudent?->guardian?->name ?? 'Orangtua belum diisi' }}</div>
                    </div>
                </div>

                <div class="observation-legend">
                    @foreach ($observationStatuses as $status)
                        <span class="observation-legend-item observation-choice-{{ $status['class'] }}">
                            <span>{!! $status['symbol'] !!}</span>
                            <strong>{{ $status['code'] }}</strong>
                            {{ $status['label'] }}
                        </span>
                    @endforeach
                </div>

                <div class="observation-stepper" data-observation-stepper>
                    @foreach ($groupedIndicators as $areaName => $areaIndicators)
                        <button class="observation-step {{ $loop->first ? 'active' : '' }}" type="button" data-observation-step-target="{{ $loop->index }}">
                            <span>{{ $loop->iteration }}</span>
                            {{ $areaName }}
                        </button>
                    @endforeach
                </div>

                <div class="observation-area-grid" data-observation-wizard>
                    @foreach ($groupedIndicators as $areaName => $areaIndicators)
                        <article class="line-card observation-area-card" data-observation-area-step="{{ $loop->index }}" @if (! $loop->first) hidden @endif>
                            <div class="line-head">
                                <div>
                                    <strong>{{ $areaName }}</strong>
                                    <div class="meta">Area {{ $loop->iteration }} dari {{ $areaCount }} | {{ $areaIndicators->count() }} indikator</div>
                                </div>
                                <span class="status" data-observation-area-progress>0/{{ $areaIndicators->count() }} terisi</span>
                            </div>
                            <div class="observation-indicator-list">
                                @foreach ($areaIndicators as $indicator)
                                    <div class="observation-row">
                                        <div class="observation-copy">
                                            <strong>{{ $indicator->code }}</strong>
                                            <span>{{ $indicator->description }}</span>
                                            <small>{{ $indicator->sub_area ?: 'Sub area belum diisi' }}</small>
                                        </div>
                                        <div class="observation-rating" role="group" aria-label="Status {{ $indicator->code }}">
                                            @foreach ($observationStatuses as $statusValue => $status)
                                                @php
                                                    $inputId = "observation-{$indicator->id}-{$statusValue}";
                                                    $oldStatus = old("observations.{$indicator->id}.status");
                                                @endphp
                                                <input
                                                    id="{{ $inputId }}"
                                                    type="radio"
                                                    name="observations[{{ $indicator->id }}][status]"
                                                    value="{{ $statusValue }}"
                                                    @checked($oldStatus === $statusValue)
                                                >
                                                <button class="observation-choice observation-choice-{{ $status['class'] }}" type="button" data-observation-choice-for="{{ $inputId }}" title="{{ $status['label'] }}">
                                                    <span>{!! $status['symbol'] !!}</span>
                                                    <strong>{{ $status['code'] }}</strong>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="observation-wizard-footer">
                    <button class="btn ghost" type="button" data-observation-prev>Sebelumnya</button>
                    <div class="meta" data-observation-progress>Area 1 dari {{ $areaCount }}</div>
                    <button class="btn ghost" type="button" data-observation-next>Berikutnya</button>
                </div>

                <div class="field" style="margin-top: 14px">
                    <label for="note">Catatan guru</label>
                    <textarea id="note" name="note" placeholder="Opsional. Catatan ini disimpan pada observasi yang dipilih.">{{ old('note') }}</textarea>
                </div>

                <div class="toolbar" style="margin-top: 12px">
                    <button class="btn primary" type="submit">Simpan Monitoring</button>
                    <button class="btn ghost" type="reset">Reset pilihan</button>
                </div>
                </form>
            @else
                <div class="notice" style="margin-top: 14px">Mode monitoring aktif. Observasi harian hanya dapat diinput oleh guru atau admin.</div>
            @endif
        </section>

        <section class="panel">
            <div class="line-head">
                <div>
                    <h3>Observasi Terbaru</h3>
                    <div class="meta">Riwayat input monitoring harian dari guru.</div>
                </div>
            </div>
            <div class="table-wrap" style="margin-top: 14px">
                <table>
                    <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Siswa</th>
                        <th>Kelas</th>
                        <th>Area</th>
                        <th>Indikator</th>
                        <th>Status</th>
                        <th>Catatan</th>
                        <th>Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($observations as $observation)
                        @php
                            $statusMeta = $observationStatuses[$observation->status] ?? null;
                        @endphp
                        <tr>
                            <td>{{ $observation->observed_on->format('d M Y') }}</td>
                            <td><strong>{{ $observation->student->name }}</strong></td>
                            <td>{{ $observation->student->schoolClass->name }}</td>
                            <td>{{ $observation->indicator->developmentArea->name }}</td>
                            <td>
                                <strong>{{ $observation->indicator->code }}</strong><br>
                                <span class="meta">{{ $observation->indicator->description }}</span>
                            </td>
                            <td>
                                @if ($statusMeta)
                                    <span class="status status-{{ str_replace('_', '-', $observation->status) }}">
                                        {{ $statusMeta['code'] }} {!! $statusMeta['symbol'] !!}
                                    </span>
                                @else
                                    <span class="status status-{{ str_replace('_', '-', $observation->status) }}">{{ $statusLabels[$observation->status] ?? $observation->status }}</span>
                                @endif
                            </td>
                            <td>{{ $observation->note ?: '-' }}</td>
                            <td>
                                <button
                                    class="btn ghost"
                                    type="button"
                                    data-edit-monitoring
                                    data-edit-session-id="{{ $observation->class_session_id }}"
                                    data-edit-student-id="{{ $observation->student_id }}"
                                    data-edit-date="{{ $observation->observed_on->toDateString() }}"
                                >Edit</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8">Belum ada observasi.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($processSection === 'ilp')
        @php
            $ilpStatusOptions = [
                'draft' => 'Draft',
                'in_progress' => 'Berjalan',
                'completed' => 'Selesai',
                'cancelled' => 'Dibatalkan',
            ];
            $ilpCounts = collect($ilpStatusOptions)
                ->map(fn ($label, $status) => $ilpPlans->where('status', $status)->count());
        @endphp

        <section class="ilp-shell">
            <div class="section-head">
                <div>
                    <h2>ILP / Remedial</h2>
                    <div class="meta">Tindak lanjut otomatis dari observasi SD. Guru bisa merapikan analisis, target, dan follow up sebelum masuk rapor.</div>
                </div>
            </div>

            <div class="grid kpi ilp-kpi">
                <div class="metric"><span>Total rencana</span><strong>{{ $ilpPlans->count() }}</strong><span>semua status</span></div>
                <div class="metric"><span>Draft</span><strong>{{ $ilpCounts['draft'] ?? 0 }}</strong><span>perlu review guru</span></div>
                <div class="metric"><span>Berjalan</span><strong>{{ $ilpCounts['in_progress'] ?? 0 }}</strong><span>sedang ditindaklanjuti</span></div>
                <div class="metric"><span>Selesai</span><strong>{{ $ilpCounts['completed'] ?? 0 }}</strong><span>siap jadi catatan rapor</span></div>
            </div>

            <div class="ilp-board">
                @forelse ($ilpPlans as $plan)
                    @php
                        $editIlpModal = "modal-edit-ilp-{$plan->id}";
                        $trigger = $plan->triggerObservation;
                        $dateRange = trim(($plan->starts_on?->format('d M Y') ?? '-') . ' - ' . ($plan->ends_on?->format('d M Y') ?? '-'));
                    @endphp
                    <article class="line-card ilp-card" id="ilp-plan-{{ $plan->id }}">
                        <div class="ilp-card-head">
                            <div class="student-avatar small">{{ mb_substr($plan->student->name, 0, 1) }}</div>
                            <div>
                                <strong>{{ $plan->student->name }}</strong>
                                <div class="meta">{{ $plan->student->schoolClass?->name ?? '-' }} | {{ $plan->student->schoolClass?->classLevel?->name ?? $plan->student->schoolClass?->level ?? '-' }}</div>
                            </div>
                            <span class="status status-{{ str_replace('_', '-', $plan->status) }}">{{ $ilpStatusOptions[$plan->status] ?? $plan->status }}</span>
                        </div>

                        <div class="ilp-indicator">
                            <span>{{ $plan->indicator->developmentArea?->name ?? 'Tanpa area' }}</span>
                            <strong>{{ $plan->indicator->code }} - {{ $plan->indicator->description }}</strong>
                        </div>

                        <div class="ilp-copy">
                            <div>
                                <span class="meta">Analisis</span>
                                <p>{{ $plan->analysis ?: 'Belum ada analisis khusus.' }}</p>
                            </div>
                            <div>
                                <span class="meta">Target</span>
                                <p>{{ $plan->target ?: '-' }}</p>
                            </div>
                            <div>
                                <span class="meta">Follow up</span>
                                <p>{{ $plan->follow_up ?: 'Belum ada tindak lanjut detail.' }}</p>
                            </div>
                        </div>

                        <div class="ilp-foot">
                            <div>
                                <span class="meta">Periode</span>
                                <strong>{{ $dateRange }}</strong>
                            </div>
                            <div>
                                <span class="meta">Pemicu</span>
                                <strong>{{ $trigger ? $trigger->observed_on->format('d M Y') : '-' }}</strong>
                            </div>
                            <div>
                                <span class="meta">Guru</span>
                                <strong>{{ $trigger?->teacher?->name ?? '-' }}</strong>
                            </div>
                        </div>

                        @if ($canWriteProcess)
                            <div class="toolbar compact-actions">
                                <button class="btn primary" type="button" data-modal-target="{{ $editIlpModal }}">Edit ILP</button>
                            </div>
                        @endif
                    </article>

                    @if ($canWriteProcess)
                        <dialog class="modal wide-modal" id="{{ $editIlpModal }}">
                            <form method="post" action="{{ route('alpha.process.ilp.update', $plan) }}">
                            @csrf
                            @method('patch')
                            <input type="hidden" name="_modal" value="{{ $editIlpModal }}">
                            <div class="modal-head">
                                <div>
                                    <h3>Edit ILP</h3>
                                    <div class="meta">{{ $plan->student->name }} | {{ $plan->indicator->code }} | {{ $plan->indicator->developmentArea?->name ?? '-' }}</div>
                                </div>
                                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                            </div>
                            <div class="form-grid">
                                <div class="field">
                                    <label>Status</label>
                                    <select name="status" required>
                                        @foreach ($ilpStatusOptions as $status => $label)
                                            <option value="{{ $status }}" @selected(old('status', $plan->status) === $status)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="date-grid">
                                    <div class="field">
                                        <label>Mulai</label>
                                        <input type="date" name="starts_on" value="{{ old('starts_on', $plan->starts_on?->toDateString()) }}">
                                    </div>
                                    <div class="field">
                                        <label>Selesai</label>
                                        <input type="date" name="ends_on" value="{{ old('ends_on', $plan->ends_on?->toDateString()) }}">
                                    </div>
                                </div>
                                <div class="field wide">
                                    <label>Analisis</label>
                                    <textarea name="analysis" placeholder="Apa kebutuhan utama anak pada indikator ini?">{{ old('analysis', $plan->analysis) }}</textarea>
                                </div>
                                <div class="field wide">
                                    <label>Target</label>
                                    <textarea name="target" required placeholder="Target yang ingin dicapai dalam periode ILP.">{{ old('target', $plan->target) }}</textarea>
                                </div>
                                <div class="field wide">
                                    <label>Follow up</label>
                                    <textarea name="follow_up" placeholder="Aktivitas stimulasi, komunikasi orangtua, atau strategi kelas.">{{ old('follow_up', $plan->follow_up) }}</textarea>
                                </div>
                            </div>
                            <div class="toolbar modal-actions">
                                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                <button class="btn primary" type="submit">Simpan ILP</button>
                            </div>
                            </form>
                        </dialog>
                    @endif
                @empty
                    <div class="attendance-empty">
                        <div class="attendance-empty-icon">
                            <i data-lucide="target" class="nav-icon"></i>
                        </div>
                        <strong>Belum ada ILP</strong>
                        <span>ILP otomatis muncul saat observasi berstatus SD.</span>
                    </div>
                @endforelse
            </div>
        </section>
    @endif
@endsection

@push('scripts')
<script type="module">
(function () {
    const canvas = document.getElementById('attendance-canvas');
    if (!canvas) return;

    // --- Tiny WebGL particle renderer (no Three.js CDN needed) ---
    const container = document.getElementById('attendance-date-card');
    if (!container) return;

    const gl = canvas.getContext('webgl', { alpha: true, antialias: false });
    if (!gl) return;

    function resize() {
        const rect = container.getBoundingClientRect();
        canvas.width  = rect.width  * devicePixelRatio;
        canvas.height = rect.height * devicePixelRatio;
        gl.viewport(0, 0, canvas.width, canvas.height);
    }
    resize();
    new ResizeObserver(resize).observe(container);

    // Vertex shader
    const vsSource = `
        attribute vec2 a_pos;
        attribute float a_size;
        attribute float a_alpha;
        varying float v_alpha;
        void main() {
            gl_Position = vec4(a_pos, 0.0, 1.0);
            gl_PointSize = a_size;
            v_alpha = a_alpha;
        }
    `;
    // Fragment shader
    const fsSource = `
        precision mediump float;
        varying float v_alpha;
        void main() {
            float d = length(gl_PointCoord - vec2(0.5));
            if (d > 0.5) discard;
            float a = smoothstep(0.5, 0.1, d) * v_alpha;
            gl_FragColor = vec4(1.0, 1.0, 1.0, a);
        }
    `;

    function compile(type, src) {
        const s = gl.createShader(type);
        gl.shaderSource(s, src);
        gl.compileShader(s);
        return s;
    }
    const prog = gl.createProgram();
    gl.attachShader(prog, compile(gl.VERTEX_SHADER, vsSource));
    gl.attachShader(prog, compile(gl.FRAGMENT_SHADER, fsSource));
    gl.linkProgram(prog);
    gl.useProgram(prog);

    const COUNT = 60;
    const px  = new Float32Array(COUNT);
    const py  = new Float32Array(COUNT);
    const vx  = new Float32Array(COUNT);
    const vy  = new Float32Array(COUNT);
    const sz  = new Float32Array(COUNT);
    const al  = new Float32Array(COUNT);

    for (let i = 0; i < COUNT; i++) {
        px[i] = Math.random() * 2 - 1;
        py[i] = Math.random() * 2 - 1;
        vx[i] = (Math.random() - 0.5) * 0.003;
        vy[i] = (Math.random() - 0.5) * 0.003;
        sz[i] = 2 + Math.random() * 5;
        al[i] = 0.15 + Math.random() * 0.45;
    }

    const posData  = new Float32Array(COUNT * 2);
    const sizeData = new Float32Array(COUNT);
    const alphaData = new Float32Array(COUNT);

    const posBuf   = gl.createBuffer();
    const sizeBuf  = gl.createBuffer();
    const alphaBuf = gl.createBuffer();

    const aPos   = gl.getAttribLocation(prog, 'a_pos');
    const aSize  = gl.getAttribLocation(prog, 'a_size');
    const aAlpha = gl.getAttribLocation(prog, 'a_alpha');

    gl.enable(gl.BLEND);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);

    let raf;
    function frame() {
        for (let i = 0; i < COUNT; i++) {
            px[i] += vx[i];
            py[i] += vy[i];
            if (px[i] > 1.1)  px[i] = -1.1;
            if (px[i] < -1.1) px[i] =  1.1;
            if (py[i] > 1.1)  py[i] = -1.1;
            if (py[i] < -1.1) py[i] =  1.1;
            posData[i * 2]     = px[i];
            posData[i * 2 + 1] = py[i];
            sizeData[i]  = sz[i] * devicePixelRatio;
            alphaData[i] = al[i];
        }

        gl.clearColor(0, 0, 0, 0);
        gl.clear(gl.COLOR_BUFFER_BIT);

        gl.bindBuffer(gl.ARRAY_BUFFER, posBuf);
        gl.bufferData(gl.ARRAY_BUFFER, posData, gl.DYNAMIC_DRAW);
        gl.enableVertexAttribArray(aPos);
        gl.vertexAttribPointer(aPos, 2, gl.FLOAT, false, 0, 0);

        gl.bindBuffer(gl.ARRAY_BUFFER, sizeBuf);
        gl.bufferData(gl.ARRAY_BUFFER, sizeData, gl.DYNAMIC_DRAW);
        gl.enableVertexAttribArray(aSize);
        gl.vertexAttribPointer(aSize, 1, gl.FLOAT, false, 0, 0);

        gl.bindBuffer(gl.ARRAY_BUFFER, alphaBuf);
        gl.bufferData(gl.ARRAY_BUFFER, alphaData, gl.DYNAMIC_DRAW);
        gl.enableVertexAttribArray(aAlpha);
        gl.vertexAttribPointer(aAlpha, 1, gl.FLOAT, false, 0, 0);

        gl.drawArrays(gl.POINTS, 0, COUNT);
        raf = requestAnimationFrame(frame);
    }

    // Only animate when visible
    const obs = new IntersectionObserver(entries => {
        if (entries[0].isIntersecting) {
            raf = requestAnimationFrame(frame);
        } else {
            cancelAnimationFrame(raf);
        }
    }, { threshold: 0.1 });
    obs.observe(canvas);
})();
</script>
@endpush

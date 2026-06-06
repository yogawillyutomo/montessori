@extends('alpha.layout')

@section('title', 'Proses - Montessori Alpha')
@section('page_title', 'Proses Harian')
@section('page_subtitle', 'Jadwal mingguan, sesi kelas, observasi, dan ILP/remedial.')

@section('content')
    <div class="grid two">
        <section class="panel">
            <h3>Buat Sesi dari Jadwal Mingguan</h3>
            <div class="meta">Alpha flow: pilih jadwal dan tanggal, sistem membuat sesi serta daftar siswanya.</div>
            <form method="post" action="{{ route('alpha.sessions.create-from-schedule') }}" style="margin-top: 14px">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="weekly_schedule_id">Jadwal</label>
                        <select id="weekly_schedule_id" name="weekly_schedule_id" required>
                            @foreach ($schedules as $schedule)
                                @php
                                    $start = \Illuminate\Support\Carbon::parse($schedule->starts_at)->format('H:i');
                                    $end = \Illuminate\Support\Carbon::parse($schedule->ends_at)->format('H:i');
                                @endphp
                                <option value="{{ $schedule->id }}">
                                    {{ $dayLabels[$schedule->day_of_week] }} · {{ $start }}-{{ $end }} · {{ $schedule->schoolClass->name }} · {{ $schedule->teacher->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="session_date">Tanggal sesi</label>
                        <input id="session_date" name="session_date" type="date" value="{{ now()->toDateString() }}" required>
                    </div>
                </div>
                <div class="toolbar" style="margin-top: 12px">
                    <button class="btn primary" type="submit">Buat sesi</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <h3>Input Observasi</h3>
            <div class="meta">Kalau status “perlu stimulasi”, sistem otomatis membuat draft ILP.</div>
            <form method="post" action="{{ route('alpha.observations.store') }}" style="margin-top: 14px">
                @csrf
                <div class="form-grid">
                    <div class="field">
                        <label for="class_session_id">Sesi</label>
                        <select id="class_session_id" name="class_session_id" required>
                            @foreach ($sessions as $session)
                                @php
                                    $start = \Illuminate\Support\Carbon::parse($session->starts_at)->format('H:i');
                                @endphp
                                <option value="{{ $session->id }}">
                                    {{ $session->session_date->format('d M') }} · {{ $start }} · {{ $session->schoolClass->name }} · {{ $session->topic }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="student_id">Siswa</label>
                        <select id="student_id" name="student_id" required>
                            @foreach ($students as $student)
                                <option value="{{ $student->id }}">{{ $student->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="teacher_id">Guru</label>
                        <select id="teacher_id" name="teacher_id" required>
                            @foreach ($teachers as $teacher)
                                <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="observed_on">Tanggal observasi</label>
                        <input id="observed_on" name="observed_on" type="date" value="{{ now()->toDateString() }}" required>
                    </div>
                    <div class="field wide">
                        <label for="indicator_id">Indikator</label>
                        <select id="indicator_id" name="indicator_id" required>
                            @foreach ($indicators as $indicator)
                                <option value="{{ $indicator->id }}">{{ $indicator->code }} · {{ $indicator->developmentArea->name }} · {{ $indicator->description }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status" required>
                            <option value="achieved">Tercapai</option>
                            <option value="emerging">Berkembang</option>
                            <option value="needs_support">Perlu stimulasi</option>
                            <option value="not_observed">Belum diamati</option>
                        </select>
                    </div>
                    <div class="field wide">
                        <label for="note">Catatan guru</label>
                        <textarea id="note" name="note">Catatan observasi singkat.</textarea>
                    </div>
                </div>
                <div class="toolbar" style="margin-top: 12px">
                    <button class="btn primary" type="submit">Simpan observasi</button>
                </div>
            </form>
        </section>
    </div>

    <section class="panel">
        <div class="line-head">
            <div>
                <h3>Jadwal Mingguan</h3>
                <div class="meta">Satu sesi punya satu guru dan beberapa siswa. Jadwal ini bisa berubah tiap minggu.</div>
            </div>
        </div>
        <div class="schedule-grid" style="margin-top: 14px">
            @foreach ([1, 2, 3, 4, 5, 6] as $day)
                <div class="day-column">
                    <div class="day-title">{{ $dayLabels[$day] }}</div>
                    @forelse ($schedules->where('day_of_week', $day) as $schedule)
                        @php
                            $start = \Illuminate\Support\Carbon::parse($schedule->starts_at)->format('H:i');
                            $end = \Illuminate\Support\Carbon::parse($schedule->ends_at)->format('H:i');
                        @endphp
                        <div class="line-card">
                            <strong>{{ $start }}-{{ $end }}</strong>
                            <div class="meta">{{ $schedule->schoolClass->name }} · {{ $schedule->teacher->name }}</div>
                            <div>{{ $schedule->topic }}</div>
                            <div class="chips">
                                @foreach ($schedule->students as $student)
                                    <span class="chip">{{ $student->name }}</span>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="line-card meta">Belum ada jadwal.</div>
                    @endforelse
                </div>
            @endforeach
        </div>
    </section>

    <div class="grid two">
        <section class="panel">
            <h3>Sesi Kelas Terakhir</h3>
            <div class="table-wrap" style="margin-top: 14px">
                <table>
                    <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kelas</th>
                        <th>Guru</th>
                        <th>Topik</th>
                        <th>Siswa</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($sessions as $session)
                        <tr>
                            <td>{{ $session->session_date->format('d M Y') }}</td>
                            <td>{{ $session->schoolClass->name }}</td>
                            <td>{{ $session->teacher->name }}</td>
                            <td>{{ $session->topic }}</td>
                            <td>{{ $session->students->count() }}</td>
                            <td><span class="status status-{{ str_replace('_', '-', $session->status) }}">{{ $statusLabels[$session->status] ?? $session->status }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h3>Draft ILP / Remedial</h3>
            <div class="table-wrap" style="margin-top: 14px">
                <table>
                    <thead>
                    <tr>
                        <th>Siswa</th>
                        <th>Area</th>
                        <th>Target</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($ilpPlans as $plan)
                        <tr>
                            <td><strong>{{ $plan->student->name }}</strong><br><span class="meta">{{ $plan->student->schoolClass->name }}</span></td>
                            <td>{{ $plan->indicator->developmentArea->name }}<br><span class="meta">{{ $plan->indicator->code }}</span></td>
                            <td>{{ $plan->target }}</td>
                            <td><span class="status status-draft">{{ ucfirst($plan->status) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Belum ada ILP.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="panel">
        <h3>Observasi Terbaru</h3>
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
                </tr>
                </thead>
                <tbody>
                @foreach ($observations as $observation)
                    <tr>
                        <td>{{ $observation->observed_on->format('d M Y') }}</td>
                        <td><strong>{{ $observation->student->name }}</strong></td>
                        <td>{{ $observation->student->schoolClass->name }}</td>
                        <td>{{ $observation->indicator->developmentArea->name }}</td>
                        <td>{{ $observation->indicator->description }}</td>
                        <td><span class="status status-{{ str_replace('_', '-', $observation->status) }}">{{ $statusLabels[$observation->status] ?? $observation->status }}</span></td>
                        <td>{{ $observation->note }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection

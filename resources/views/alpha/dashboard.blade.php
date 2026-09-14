@extends('alpha.layout')

@section('title', 'Dashboard - Montessori Bloom')
@section('page_title', 'Dashboard Monitoring')
@section('page_subtitle', 'Ringkasan dari master data, observasi harian, review follow-up, dan draft laporan.')

@section('content')
    <div class="section-head">
        <div>
            <h2>Ringkasan Operasional</h2>
            <div class="meta">Observation adalah evidence. Follow-up perlu direview sebelum menjadi support plan.</div>
        </div>
        <div class="toolbar">
            @if (in_array($activeRole, ['super_admin', 'admin'], true))
                <a class="btn ghost" href="{{ route('alpha.master') }}">Buka Master</a>
            @endif
            @if (in_array($activeRole, ['super_admin', 'admin', 'teacher', 'principal'], true))
                <a class="btn teal" href="{{ route('alpha.process.observations') }}">{{ $activeRole === 'principal' ? 'Lihat Observasi' : 'Input Observasi' }}</a>
                <a class="btn ghost" href="{{ route('alpha.process.follow-up') }}">{{ $activeRole === 'principal' ? 'Lihat Follow-Up' : 'Review Follow-Up' }}</a>
            @endif
            <a class="btn ghost" href="{{ route('alpha.reports') }}">Lihat Rapor</a>
        </div>
    </div>

    <div class="grid kpi">
        <div class="metric"><span>Kelas aktif</span><strong>{{ $stats['classes'] }}</strong><span>Sunny, Glow, Infant</span></div>
        <div class="metric"><span>Siswa aktif</span><strong>{{ $stats['students'] }}</strong><span>terhubung ke orangtua</span></div>
        <div class="metric"><span>Jadwal mingguan</span><strong>{{ $stats['weekly_schedules'] }}</strong><span>fleksibel per minggu</span></div>
        <div class="metric"><span>Draft rapor</span><strong>{{ $stats['draft_reports'] }}</strong><span>menunggu penyelesaian</span></div>
        <div class="metric"><span>Follow-up terbuka</span><strong>{{ $stats['open_follow_up'] }}</strong><span>menunggu review guide</span></div>
    </div>

    <div class="grid two">
        <section class="panel panel-binder">
            <div class="line-head">
                <div>
                    <h3>Progress Area Perkembangan</h3>
                    <div class="meta">Rata-rata skor legacy dari observation tetap ditampilkan selama masa transisi domain.</div>
                </div>
            </div>
            <div class="progress-list" style="margin-top: 14px">
                @foreach ($areaScores as $area)
                    <div class="progress-row">
                        <div class="progress-meta">
                            <span>{{ $area['name'] }} | {{ $area['observed'] }} observasi</span>
                            <strong>{{ $area['score'] }}%</strong>
                        </div>
                        <div class="bar {{ strtolower(str_replace(' ', '-', $area['name'])) }}"><span style="width: {{ $area['score'] }}%"></span></div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="panel panel-tape">
            <h3>Alur Sistem</h3>
            <div class="card-list" style="margin-top: 14px">
                <div class="line-card">
                    <strong>1. Master Data</strong>
                    <div class="meta">Kelas, siswa, guide, orangtua, tahun ajaran, dan indikator.</div>
                </div>
                <div class="line-card">
                    <strong>2. Evidence & Review</strong>
                    <div class="meta">Presentation dan observation dicatat, lalu follow-up candidate ditinjau guide.</div>
                </div>
                <div class="line-card">
                    <strong>3. Support & Laporan</strong>
                    <div class="meta">Support plan hanya dibuat setelah konfirmasi manusia; rapor tetap melalui review.</div>
                </div>
            </div>
        </section>
    </div>

    <div class="grid two">
        <section class="panel panel-binder">
            <h3>Status Kelas</h3>
            <div class="table-wrap" style="margin-top: 14px">
                <table>
                    <thead>
                    <tr>
                        <th>Kelas</th>
                        <th>Level</th>
                        <th>Siswa</th>
                        <th>Jadwal</th>
                        <th>Kapasitas</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($classes as $class)
                        <tr>
                            <td><strong>{{ $class->name }}</strong></td>
                            <td>{{ $class->classLevel?->name ?? $class->level }}</td>
                            <td>{{ $class->students_count }}</td>
                            <td>{{ $class->weekly_schedules_count }}</td>
                            <td>{{ $class->capacity }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel panel-binder">
            <h3>Sesi Belajar Terakhir</h3>
            <div class="card-list" style="margin-top: 14px">
                @forelse ($sessions as $session)
                    @php
                        $start = \Illuminate\Support\Carbon::parse($session->starts_at)->format('H:i');
                        $end = \Illuminate\Support\Carbon::parse($session->ends_at)->format('H:i');
                    @endphp
                    <div class="line-card">
                        <div class="line-head">
                            <div>
                                <strong>{{ $session->schoolClass->name }} | {{ $session->topic }}</strong>
                                <div class="meta">{{ $session->session_date->format('d M Y') }} | {{ $start }}-{{ $end }} | {{ $session->teacher->name }}</div>
                            </div>
                            <span class="status status-{{ str_replace('_', '-', $session->status) }}">{{ $statusLabels[$session->status] ?? $session->status }}</span>
                        </div>
                        <div class="chips">
                            @foreach ($session->students as $student)
                                <span class="chip">{{ $student->name }}</span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="line-card">Belum ada sesi belajar.</div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="panel panel-binder">
        <div class="line-head">
            <div>
                <h3>Follow-Up Menunggu Review</h3>
                <div class="meta">Candidate terbuka belum menjadi support plan sampai guide/admin mengonfirmasi.</div>
            </div>
            <a class="btn ghost" href="{{ route('alpha.process.follow-up') }}">Buka Review Queue</a>
        </div>
        <div class="table-wrap" style="margin-top: 14px">
            <table>
                <thead>
                <tr>
                    <th>Tanggal Evidence</th>
                    <th>Siswa</th>
                    <th>Kelas</th>
                    <th>Area</th>
                    <th>Indikator / Alasan</th>
                    <th>Guide</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($followUpCandidates as $candidate)
                    <tr>
                        <td>{{ $candidate->sourceObservation?->observed_on?->format('d M Y') ?? '-' }}</td>
                        <td><strong>{{ $candidate->student->name }}</strong></td>
                        <td>{{ $candidate->student->schoolClass->name }}</td>
                        <td>{{ $candidate->indicator?->developmentArea?->name ?? $candidate->sourceObservation?->developmentArea?->name ?? '-' }}</td>
                        <td>
                            <strong>{{ $candidate->indicator?->description ?? 'Belum dipilih saat observation' }}</strong>
                            <div class="meta">{{ $candidate->reason_summary }}</div>
                        </td>
                        <td>{{ $candidate->sourceObservation?->teacher?->name ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">Tidak ada follow-up candidate terbuka.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

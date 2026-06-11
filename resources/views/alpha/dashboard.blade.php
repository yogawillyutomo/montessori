@extends('alpha.layout')

@section('title', 'Dashboard - Montessori Bloom')
@section('page_title', 'Dashboard Monitoring')
@section('page_subtitle', 'Ringkasan dari master data, proses harian, dan draft laporan.')

@section('content')
    <div class="section-head">
        <div>
            <h2>Ringkasan Operasional</h2>
            <div class="meta">Data dibuat dari migration dan seeder Laravel. Rapor masih draft otomatis, bukan final.</div>
        </div>
        <div class="toolbar">
            @if (in_array($activeRole, ['super_admin', 'admin'], true))
                <a class="btn ghost" href="{{ route('alpha.master') }}">Buka Master</a>
            @endif
            @if (in_array($activeRole, ['super_admin', 'admin', 'teacher', 'principal'], true))
                <a class="btn teal" href="{{ route('alpha.process') }}">{{ $activeRole === 'principal' ? 'Lihat Proses' : 'Input Proses' }}</a>
            @endif
            <a class="btn ghost" href="{{ route('alpha.reports') }}">Lihat Rapor</a>
        </div>
    </div>

    <div class="grid kpi">
        <div class="metric"><span>Kelas aktif</span><strong>{{ $stats['classes'] }}</strong><span>Sunny, Glow, Infant</span></div>
        <div class="metric"><span>Siswa aktif</span><strong>{{ $stats['students'] }}</strong><span>terhubung ke orangtua</span></div>
        <div class="metric"><span>Jadwal mingguan</span><strong>{{ $stats['weekly_schedules'] }}</strong><span>fleksibel per minggu</span></div>
        <div class="metric"><span>Draft rapor</span><strong>{{ $stats['draft_reports'] }}</strong><span>hasil generate otomatis</span></div>
        <div class="metric"><span>Perlu stimulasi</span><strong>{{ $stats['needs_support'] }}</strong><span>masuk ILP</span></div>
    </div>

    <div class="grid two">
        <section class="panel panel-binder">
            <div class="line-head">
                <div>
                    <h3>Progress Area Perkembangan</h3>
                    <div class="meta">Rata-rata skor dari observasi: tercapai 100, berkembang 65, perlu stimulasi 30.</div>
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
                    <div class="meta">Kelas, siswa, guru, orangtua, tahun ajaran, dan indikator.</div>
                </div>
                <div class="line-card">
                    <strong>2. Proses Harian</strong>
                    <div class="meta">Jadwal mingguan, presensi, observasi, dan ILP.</div>
                </div>
                <div class="line-card">
                    <strong>3. Laporan</strong>
                    <div class="meta">Draft rapor otomatis dari observasi, lalu direview guru/admin.</div>
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
            <h3>Presensi Terakhir</h3>
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
                    <div class="line-card">Belum ada presensi.</div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="panel panel-binder">
        <h3>Perlu Stimulasi / ILP</h3>
        <div class="table-wrap" style="margin-top: 14px">
            <table>
                <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Siswa</th>
                    <th>Kelas</th>
                    <th>Area</th>
                    <th>Indikator</th>
                    <th>Guru</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($needsSupport as $observation)
                    <tr>
                        <td>{{ $observation->observed_on->format('d M Y') }}</td>
                        <td><strong>{{ $observation->student->name }}</strong></td>
                        <td>{{ $observation->student->schoolClass->name }}</td>
                        <td>{{ $observation->indicator->developmentArea->name }}</td>
                        <td>{{ $observation->indicator->description }}</td>
                        <td>{{ $observation->teacher->name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6">Belum ada observasi yang perlu stimulasi.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

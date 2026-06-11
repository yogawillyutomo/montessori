@extends('alpha.layout')

@section('title', 'Laporan - Montessori Bloom')
@section('page_title', 'Laporan dan Rapor')
@section('page_subtitle', 'Draft rapor otomatis dari observasi dan presensi, lalu direview guru/admin sebelum publish.')

@section('content')
    @php
        $attendanceRows = collect($attendanceRecap ?? []);
        $totalStudents = $attendanceRows->count();
        $recordedAttendances = $attendanceRows->sum(fn ($row) => $row['summary']['recorded']);
        $averageAttendanceRate = $totalStudents > 0
            ? round($attendanceRows->avg(fn ($row) => $row['summary']['attendance_rate']), 1)
            : 0;
        $totalIssues = $attendanceRows->sum(fn ($row) => $row['summary']['sick'] + $row['summary']['excused'] + $row['summary']['absent']);
    @endphp

    <div class="section-head">
        <div>
            <h2>Draft Rapor Otomatis</h2>
            <div class="meta">
                Periode aktif:
                @if ($currentTerm)
                    {{ $currentTerm->academicYear->name }} | {{ $currentTerm->name }} | {{ $currentTerm->starts_on->format('d M Y') }} - {{ $currentTerm->ends_on->format('d M Y') }}
                @else
                    belum diset
                @endif
            </div>
        </div>
        @if ($canGenerateReport)
            <form method="post" action="{{ route('alpha.reports.generate') }}">
                @csrf
                <button class="btn primary" type="submit">Generate ulang draft rapor</button>
            </form>
        @endif
    </div>

    <section class="panel">
        <div class="section-head">
            <div>
                <h3>Rekap Presensi</h3>
                <div class="meta">Bahan laporan sebelum dimasukkan ke rapor siswa.</div>
            </div>
        </div>

        <form method="get" action="{{ route('alpha.reports') }}" class="form-grid" style="margin-top: 14px">
            <div class="date-grid wide">
                <div class="field">
                    <label for="attendance-starts-on">Mulai</label>
                    <input id="attendance-starts-on" type="date" name="starts_on" value="{{ $attendanceFilters['starts_on'] }}">
                </div>
                <div class="field">
                    <label for="attendance-ends-on">Selesai</label>
                    <input id="attendance-ends-on" type="date" name="ends_on" value="{{ $attendanceFilters['ends_on'] }}">
                </div>
            </div>
            <div class="field">
                <label for="attendance-class-id">Kelas</label>
                <select id="attendance-class-id" name="school_class_id">
                    <option value="">Semua kelas</option>
                    @foreach ($classes as $class)
                        <option value="{{ $class->id }}" @selected((int) $attendanceFilters['school_class_id'] === $class->id)>
                            {{ $class->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="toolbar" style="align-self: end">
                <button class="btn primary" type="submit">Terapkan</button>
                <a class="btn ghost" href="{{ route('alpha.reports') }}">Reset</a>
            </div>
        </form>

        <div class="grid kpi" style="margin-top: 16px">
            <div class="metric">
                <span>Siswa direkap</span>
                <strong>{{ $totalStudents }}</strong>
            </div>
            <div class="metric">
                <span>Presensi tercatat</span>
                <strong>{{ $recordedAttendances }}</strong>
            </div>
            <div class="metric">
                <span>Rata-rata hadir</span>
                <strong>{{ $averageAttendanceRate }}%</strong>
            </div>
            <div class="metric">
                <span>Sakit, izin, alpha</span>
                <strong>{{ $totalIssues }}</strong>
            </div>
        </div>

        <div class="table-wrap" style="margin-top: 14px">
            <table data-table data-table-page-size="10" data-table-search-placeholder="Cari siswa, kelas, atau orangtua">
                <thead>
                <tr>
                    <th>Siswa</th>
                    <th>Kelas</th>
                    <th>Orangtua</th>
                    <th>Tercatat</th>
                    <th>Hadir</th>
                    <th>Terlambat</th>
                    <th>Sakit</th>
                    <th>Izin</th>
                    <th>Alpha</th>
                    <th>% Hadir</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($attendanceRows as $row)
                    @php
                        $student = $row['student'];
                        $summary = $row['summary'];
                    @endphp
                    <tr>
                        <td><strong>{{ $student->name }}</strong><br><span class="meta">{{ $student->code }}</span></td>
                        <td>{{ $student->schoolClass?->name ?? '-' }}</td>
                        <td>{{ $student->guardian?->name ?? '-' }}</td>
                        <td>{{ $summary['recorded'] }}</td>
                        <td><span class="status status-present">{{ $summary['present'] }}</span></td>
                        <td><span class="status status-late">{{ $summary['late'] }}</span></td>
                        <td><span class="status status-sick">{{ $summary['sick'] }}</span></td>
                        <td><span class="status status-excused">{{ $summary['excused'] }}</span></td>
                        <td><span class="status status-absent">{{ $summary['absent'] }}</span></td>
                        <td><strong>{{ $summary['attendance_rate'] }}%</strong></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">Belum ada siswa untuk filter ini.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        @if ($isParentView)
            <h3>Rapor Anak</h3>
            <div class="line-card" style="margin-top: 14px">
                <strong>Rapor yang tampil sudah dipublish sekolah.</strong>
                <div class="meta">Jika rapor terbaru belum muncul, berarti masih dalam proses review guru dan sekolah.</div>
            </div>
        @else
            <h3>Workflow Rapor</h3>
            <div class="grid three" style="margin-top: 14px">
                <div class="line-card">
                    <strong>1. Sistem generate draft</strong>
                    <div class="meta">Biodata, area perkembangan, indikator prioritas, presensi, dan narasi awal diisi otomatis.</div>
                </div>
                <div class="line-card">
                    <strong>2. Guru edit narasi</strong>
                    <div class="meta">Guru merapikan bahasa rapor dan menambahkan konteks observasi.</div>
                </div>
                <div class="line-card">
                    <strong>3. Admin review & publish</strong>
                    <div class="meta">Setelah disetujui, rapor bisa dicetak PDF atau dibuka orangtua.</div>
                </div>
            </div>
        @endif
    </section>

    <section class="panel">
        <div class="section-head">
            <div>
                <h3>Daftar Rapor</h3>
                <div class="meta">Preview rapor mengikuti alur observasi, presensi, dan ILP/remedial.</div>
            </div>
            <div class="report-legend">
                <span><strong class="report-status-pill status-needs-support">SD</strong> Sedang berkembang</span>
                <span><strong class="report-status-pill status-emerging">SB</strong> Sudah berkembang</span>
                <span><strong class="report-status-pill status-achieved">SM</strong> Sudah maksimal</span>
            </div>
        </div>

        <form method="get" action="{{ route('alpha.reports') }}" class="form-grid report-filter-form">
            <div class="field">
                <label for="report-q">Cari siswa</label>
                <input id="report-q" type="search" name="q" value="{{ $reportFilters['q'] }}" placeholder="Nama atau kode siswa">
            </div>
            <div class="field">
                <label for="report-class-id">Kelas</label>
                <select id="report-class-id" name="school_class_id">
                    <option value="">Semua kelas</option>
                    @foreach ($classes as $class)
                        <option value="{{ $class->id }}" @selected((int) $reportFilters['school_class_id'] === $class->id)>{{ $class->name }}</option>
                    @endforeach
                </select>
            </div>
            @if ($canUseTeacherFilter)
                <div class="field">
                    <label for="report-teacher-id">Guru terjadwal</label>
                    <select id="report-teacher-id" name="teacher_id">
                        <option value="">Semua guru</option>
                        @foreach ($teachers as $teacher)
                            <option value="{{ $teacher->id }}" @selected((int) $reportFilters['teacher_id'] === $teacher->id)>{{ $teacher->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @unless ($isParentView)
                <div class="field">
                    <label for="report-status">Status</label>
                    <select id="report-status" name="status">
                        <option value="">Semua status</option>
                        @foreach (['draft', 'reviewed', 'approved', 'published', 'archived', 'empty'] as $status)
                            <option value="{{ $status }}" @selected($reportFilters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>
            @endunless
            <div class="toolbar" style="align-self: end">
                <button class="btn primary" type="submit">Terapkan</button>
                <a class="btn ghost" href="{{ route('alpha.reports') }}">Reset</a>
            </div>
        </form>

        @if ($isTeacherScoped)
            <div class="notice report-scope-note">Rapor yang tampil dibatasi ke siswa yang sudah diplot di jadwal atau presensi guru login.</div>
        @endif

        <div class="report-list">
            @forelse ($reports as $report)
                @php
                    $summary = $report->summary ?? [];
                    $biodata = $summary['biodata'] ?? [];
                    $attendance = $summary['attendance'] ?? [];
                    $rubric = collect($summary['rubric'] ?? []);
                    $priorityRubric = $rubric->where('observed', true)->sortBy('score')->take(6);
                    $displayRubric = $priorityRubric->isNotEmpty() ? $priorityRubric : $rubric->take(6);
                    $ilpPlans = collect($summary['ilp_plans'] ?? []);
                    $areas = collect($summary['areas'] ?? []);
                @endphp

                <article class="report-card">
                    <div class="report-card-head">
                        <div class="report-student">
                            <div class="avatar">{{ str($report->student->name)->substr(0, 1)->upper() }}</div>
                            <div>
                                <h4>{{ $report->student->name }}</h4>
                                <div class="meta">
                                    {{ $report->student->code }} | {{ $biodata['class'] ?? $report->student->schoolClass?->name ?? '-' }}
                                    @if (($biodata['age'] ?? null) && $biodata['age'] !== '-')
                                        | {{ $biodata['age'] }}
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="report-actions">
                            <span class="status status-{{ str_replace('_', '-', $report->status) }}">{{ $statusLabels[$report->status] ?? $report->status }}</span>
                            <a class="btn ghost" href="{{ route('alpha.reports.show', $report) }}">Detail siswa</a>
                            <button class="btn ghost" type="button" onclick="window.print()">Cetak</button>
                        </div>
                    </div>

                    <div class="report-grid">
                        <div class="report-box">
                            <span class="box-label">Identitas</span>
                            <dl class="report-dl">
                                <div><dt>Tempat lahir</dt><dd>{{ $biodata['birth_place'] ?? $report->student->birth_place ?? '-' }}</dd></div>
                                <div><dt>Tanggal lahir</dt><dd>{{ $report->student->birth_date?->format('d M Y') ?? '-' }}</dd></div>
                                <div><dt>Wali</dt><dd>{{ $biodata['guardian_name'] ?? $report->student->guardian?->name ?? '-' }}</dd></div>
                                <div><dt>Telepon</dt><dd>{{ $biodata['guardian_phone'] ?? $report->student->guardian?->phone ?? '-' }}</dd></div>
                            </dl>
                        </div>

                        <div class="report-box">
                            <span class="box-label">Presensi</span>
                            <div class="report-attendance">
                                <strong>{{ $attendance['attendance_rate'] ?? 0 }}%</strong>
                                <span>{{ $attendance['recorded'] ?? 0 }} presensi tercatat</span>
                            </div>
                            <div class="mini-stats">
                                <span>Hadir {{ $attendance['present'] ?? 0 }}</span>
                                <span>Terlambat {{ $attendance['late'] ?? 0 }}</span>
                                <span>Alpha {{ $attendance['absent'] ?? 0 }}</span>
                            </div>
                        </div>

                        <div class="report-box">
                            <span class="box-label">Observasi</span>
                            <div class="report-attendance">
                                <strong>{{ $summary['observation_count'] ?? 0 }}</strong>
                                <span>catatan observasi</span>
                            </div>
                            <div class="mini-stats">
                                <span>Perlu stimulasi {{ $summary['needs_support_count'] ?? 0 }}</span>
                                <span>ILP {{ $ilpPlans->count() }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="report-section">
                        <div class="report-section-title">
                            <strong>Capaian Perkembangan</strong>
                            <span class="meta">Format ringkas dari area/sub-area seperti file Excel referensi.</span>
                        </div>
                        <div class="report-rubric">
                            @forelse ($displayRubric as $row)
                                <div class="rubric-row">
                                    <div>
                                        <strong>{{ $row['header'] ?? (($row['area'] ?? '-') . ': ' . ($row['sub_area'] ?? '-')) }}</strong>
                                        <span>{{ $row['observed'] ?? 0 }} indikator diamati | {{ $row['needs_support'] ?? 0 }} perlu stimulasi</span>
                                    </div>
                                    <span class="report-status-pill status-{{ $row['tone'] ?? 'muted' }}">{{ $row['code'] ?? '-' }}</span>
                                </div>
                            @empty
                                <div class="empty-state compact">Belum ada rubrik aktif untuk level siswa ini.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="grid two report-detail-grid">
                        <div class="report-section">
                            <div class="report-section-title">
                                <strong>Ringkasan Area</strong>
                                <span class="meta">Skor otomatis dari observasi terakhir tiap indikator.</span>
                            </div>
                            <div class="progress-list">
                                @forelse ($areas as $area)
                                    <div class="progress-row">
                                        <div class="progress-meta">
                                            <span>{{ $area['name'] }} | {{ $area['observed'] ?? 0 }} diamati</span>
                                            <strong>{{ $area['score'] ?? 0 }}%</strong>
                                        </div>
                                        <div class="bar"><span style="width: {{ $area['score'] ?? 0 }}%"></span></div>
                                    </div>
                                @empty
                                    <div class="empty-state compact">Belum ada ringkasan area.</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="report-section">
                            <div class="report-section-title">
                                <strong>ILP / Remedial</strong>
                                <span class="meta">Tindak lanjut dari indikator SD.</span>
                            </div>
                            <div class="report-ilp-list">
                                @forelse ($ilpPlans->take(3) as $plan)
                                    <div class="report-ilp-item">
                                        <div>
                                            <strong>{{ $plan['indicator_code'] ?? '-' }} | {{ $plan['sub_area'] ?? '-' }}</strong>
                                            <span>{{ $plan['target'] ?? 'Target belum diisi.' }}</span>
                                        </div>
                                        <span class="status status-{{ str_replace('_', '-', $plan['status'] ?? 'draft') }}">{{ $plan['status_label'] ?? ($plan['status'] ?? 'Draft') }}</span>
                                    </div>
                                @empty
                                    <div class="empty-state compact">Belum ada ILP/remedial untuk periode ini.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="report-narrative">
                        <strong>Catatan keseluruhan</strong>
                        <p>{{ $report->teacher_narrative }}</p>
                        <div class="meta">
                            Guru: {{ $report->homeroomTeacher?->name ?? '-' }} |
                            Generated: {{ $report->generated_at?->format('d M Y H:i') ?? '-' }}
                        </div>
                    </div>
                </article>
            @empty
                <div class="empty-state">Belum ada draft rapor. Klik generate untuk membuat draft dari data observasi.</div>
            @endforelse
        </div>
    </section>
@endsection

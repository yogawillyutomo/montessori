@extends('alpha.layout')

@section('title', $report->student->name . ' - Rapor Montessori Bloom')
@section('page_title', 'Rapor Per Siswa')
@section('page_subtitle', 'Preview perkembangan, presensi, ILP, dan pesan guru untuk satu siswa.')

@section('content')
    @php
        $student = $report->student;
        $maxScore = max(100, (int) $areas->max('score'));
        $chartAreas = $areas->take(5)->values();
        $chartCount = max(3, $chartAreas->count());
        $center = 140;
        $radius = 98;
        $polygonPoints = [];
        $axisPoints = [];
        $colors = ['#f2b705', '#71b7d7', '#5f7f45', '#ec4f9a', '#8e5de8'];

        for ($i = 0; $i < $chartCount; $i++) {
            $angle = -pi() / 2 + (2 * pi() * $i / $chartCount);
            $score = (float) ($chartAreas[$i]['score'] ?? 0);
            $areaRadius = $radius * min($score, $maxScore) / $maxScore;
            $polygonPoints[] = round($center + cos($angle) * $areaRadius, 2) . ',' . round($center + sin($angle) * $areaRadius, 2);
            $axisPoints[] = [
                'x' => round($center + cos($angle) * $radius, 2),
                'y' => round($center + sin($angle) * $radius, 2),
                'label_x' => round($center + cos($angle) * ($radius + 24), 2),
                'label_y' => round($center + sin($angle) * ($radius + 24), 2),
                'area' => $chartAreas[$i] ?? ['name' => 'Area', 'score' => 0],
                'color' => $colors[$i % count($colors)],
            ];
        }

        $teacherName = $report->homeroomTeacher?->name ?? '-';
        $attendanceRate = $attendance['attendance_rate'] ?? 0;
        $termLabel = trim(($report->term?->academicYear?->name ?? '') . ' ' . ($report->term?->name ?? ''));
    @endphp

    <div class="section-head">
        <div>
            <a class="btn ghost" href="{{ route('alpha.reports') }}">Kembali ke daftar rapor</a>
        </div>
        <button class="btn primary" type="button" onclick="window.print()">Cetak Rapor</button>
    </div>

    <section class="student-report-hero">
        <div class="student-report-avatar-wrap">
            <div class="student-report-avatar">{{ str($student->name)->substr(0, 1)->upper() }}</div>
            <span class="student-level-pill">{{ $biodata['level'] ?? $student->schoolClass?->level ?? '-' }} Level</span>
        </div>
        <div class="student-report-profile">
            <h2>{{ $student->name }}</h2>
            <div class="student-info-grid">
                <div class="info-tile tile-blue">
                    <span>Tanggal lahir</span>
                    <strong>{{ $student->birth_date?->format('d M Y') ?? '-' }}</strong>
                </div>
                <div class="info-tile tile-yellow">
                    <span>Periode rapor</span>
                    <strong>{{ $termLabel ?: '-' }}</strong>
                </div>
                <div class="info-tile tile-green">
                    <span>Guru kelas</span>
                    <strong>{{ $teacherName }}</strong>
                </div>
                <div class="info-tile tile-gray">
                    <span>Attendance</span>
                    <strong>{{ $attendanceRate }}%</strong>
                </div>
            </div>
            <p class="student-report-quote">"Learning at their own pace with joy and curiosity."</p>
        </div>
    </section>

    <div class="student-report-grid">
        <section class="student-report-panel progress-panel">
            <h3><i data-lucide="bar-chart-3" class="nav-icon"></i> Development Progress ({{ $areas->count() }} Areas)</h3>
            <div class="radar-wrap">
                <svg class="radar-chart" viewBox="0 0 280 280" role="img" aria-label="Grafik radar perkembangan">
                    @for ($level = 1; $level <= 5; $level++)
                        @php
                            $ringRadius = $radius * $level / 5;
                            $ringPoints = [];
                            for ($i = 0; $i < $chartCount; $i++) {
                                $angle = -pi() / 2 + (2 * pi() * $i / $chartCount);
                                $ringPoints[] = round($center + cos($angle) * $ringRadius, 2) . ',' . round($center + sin($angle) * $ringRadius, 2);
                            }
                        @endphp
                        <polygon points="{{ implode(' ', $ringPoints) }}" fill="none" stroke="#e8e1d4" stroke-width="1" />
                    @endfor
                    @foreach ($axisPoints as $point)
                        <line x1="{{ $center }}" y1="{{ $center }}" x2="{{ $point['x'] }}" y2="{{ $point['y'] }}" stroke="#ece6da" />
                        <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3" fill="{{ $point['color'] }}" />
                        <text x="{{ $point['label_x'] }}" y="{{ $point['label_y'] }}" text-anchor="middle">{{ $point['area']['name'] }}</text>
                    @endforeach
                    <polygon points="{{ implode(' ', $polygonPoints) }}" fill="rgba(255, 217, 61, .24)" stroke="#f2b705" stroke-width="3" />
                    @foreach ($polygonPoints as $point)
                        @php [$x, $y] = explode(',', $point); @endphp
                        <circle cx="{{ $x }}" cy="{{ $y }}" r="4" fill="#f2b705" />
                    @endforeach
                    <text x="{{ $center }}" y="36" text-anchor="middle">100%</text>
                    <text x="{{ $center }}" y="{{ $center + 4 }}" text-anchor="middle">0%</text>
                </svg>
            </div>
        </section>

        <section class="student-report-panel">
            <h3><i data-lucide="trending-up" class="nav-icon"></i> Areas Overview</h3>
            <div class="area-overview-list">
                @forelse ($areas as $index => $area)
                    <div class="area-overview-row">
                        <div>
                            <strong>{{ $area['name'] }}</strong>
                            <span>{{ $area['observed'] ?? 0 }} indikator diamati</span>
                        </div>
                        <span>{{ $area['score'] ?? 0 }}%</span>
                    </div>
                    <div class="area-overview-bar"><span style="width: {{ $area['score'] ?? 0 }}%; background: {{ $colors[$index % count($colors)] }}"></span></div>
                @empty
                    <div class="empty-state compact">Belum ada area perkembangan.</div>
                @endforelse
            </div>
        </section>
    </div>

    <div class="student-report-grid bottom">
        <section class="student-report-panel download-panel">
            <div class="report-section-title">
                <h3><i data-lucide="file-down" class="nav-icon"></i> Download Report</h3>
                <i data-lucide="clipboard" class="nav-icon"></i>
            </div>
            <div class="meta">{{ $termLabel ?: 'Progress Report' }}</div>
            <div class="download-status">Status: {{ $statusLabels[$report->status] ?? $report->status }} - awaiting publication</div>
            <button class="btn ghost" type="button" disabled>Download PDF</button>
        </section>

        <section class="student-report-panel teacher-message">
            <h3><i data-lucide="message-circle" class="nav-icon"></i> Teacher's Message</h3>
            <blockquote>
                "{{ $report->teacher_narrative }}"
                <span>- {{ $teacherName }}, Class Teacher</span>
            </blockquote>
        </section>
    </div>

    <section class="student-report-panel">
        <div class="report-section-title">
            <h3>Capaian Perkembangan Detail</h3>
            <span class="meta">MB, B, M, dan MH mengikuti level perkembangan Montessori Bloom.</span>
        </div>
        <div class="report-rubric detail-rubric">
            @forelse ($rubric as $row)
                <div class="rubric-row">
                    <div>
                        <strong>{{ $row['header'] ?? (($row['area'] ?? '-') . ': ' . ($row['sub_area'] ?? '-')) }}</strong>
                        <span>{{ $row['observed'] ?? 0 }} indikator diamati | {{ $row['needs_support'] ?? 0 }} perlu stimulasi</span>
                    </div>
                    <span class="report-status-pill status-{{ $row['tone'] ?? 'muted' }}">{{ $row['code'] ?? '-' }}</span>
                </div>
            @empty
                <div class="empty-state compact">Belum ada rubrik untuk siswa ini.</div>
            @endforelse
        </div>
    </section>
@endsection

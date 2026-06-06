@extends('alpha.layout')

@section('title', 'Laporan - Montessori Alpha')
@section('page_title', 'Laporan dan Rapor')
@section('page_subtitle', 'Draft rapor otomatis dari observasi, lalu direview guru/admin sebelum publish.')

@section('content')
    <div class="section-head">
        <div>
            <h2>Draft Rapor Otomatis</h2>
            <div class="meta">
                Periode aktif:
                @if ($currentTerm)
                    {{ $currentTerm->academicYear->name }} · {{ $currentTerm->name }} · {{ $currentTerm->starts_on->format('d M Y') }} - {{ $currentTerm->ends_on->format('d M Y') }}
                @else
                    belum diset
                @endif
            </div>
        </div>
        <form method="post" action="{{ route('alpha.reports.generate') }}">
            @csrf
            <button class="btn primary" type="submit">Generate ulang draft rapor</button>
        </form>
    </div>

    <section class="panel">
        <h3>Workflow Rapor</h3>
        <div class="grid three" style="margin-top: 14px">
            <div class="line-card">
                <strong>1. Sistem generate draft</strong>
                <div class="meta">Biodata, area perkembangan, indikator prioritas, dan narasi awal diisi otomatis.</div>
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
    </section>

    <section class="panel">
        <h3>Daftar Rapor</h3>
        <div class="table-wrap" style="margin-top: 14px">
            <table>
                <thead>
                <tr>
                    <th>Siswa</th>
                    <th>Kelas</th>
                    <th>Orangtua</th>
                    <th>Observasi</th>
                    <th>Perlu stimulasi</th>
                    <th>Status</th>
                    <th>Generated</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($reports as $report)
                    <tr>
                        <td><strong>{{ $report->student->name }}</strong><br><span class="meta">{{ $report->student->code }}</span></td>
                        <td>{{ $report->student->schoolClass->name }}</td>
                        <td>{{ $report->student->guardian?->name ?? '-' }}</td>
                        <td>{{ $report->summary['observation_count'] ?? 0 }}</td>
                        <td>{{ $report->summary['needs_support_count'] ?? 0 }}</td>
                        <td><span class="status status-{{ str_replace('_', '-', $report->status) }}">{{ $statusLabels[$report->status] ?? $report->status }}</span></td>
                        <td>{{ $report->generated_at?->format('d M Y H:i') ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td colspan="7">
                            <div class="grid two">
                                <div>
                                    <strong>Narasi draft</strong>
                                    <p>{{ $report->teacher_narrative }}</p>
                                </div>
                                <div class="report-area">
                                    <strong>Ringkasan area</strong>
                                    <div class="progress-list" style="margin-top: 8px">
                                        @foreach (($report->summary['areas'] ?? []) as $area)
                                            <div class="progress-row">
                                                <div class="progress-meta">
                                                    <span>{{ $area['name'] }} · {{ $area['observed'] }} obs</span>
                                                    <strong>{{ $area['score'] }}%</strong>
                                                </div>
                                                <div class="bar"><span style="width: {{ $area['score'] }}%"></span></div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection

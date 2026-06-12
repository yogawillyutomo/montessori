@extends('alpha.layout')

@section('title', 'Rapor Siswa - Montessori Bloom')
@section('page_title', $isParentView ? 'Rapor Anak' : 'Rapor Siswa')
@section('page_subtitle', $isParentView ? 'Rapor yang tampil adalah rapor yang sudah dipublish sekolah.' : 'Pilih siswa terlebih dahulu, lalu susun draft, narasi, dan data kehadiran manual di detail rapor.')

@section('content')
    @php
        $statusTone = [
            'not_created' => 'not-created',
            'draft' => 'draft',
            'ready' => 'ready',
            'published' => 'published',
            'archived' => 'archived',
        ];
    @endphp

    <div class="section-head">
        <div>
            <h2>{{ $isParentView ? 'Daftar Rapor Anak' : 'Daftar Siswa untuk Rapor' }}</h2>
            <div class="meta">
                Periode:
                @if ($currentTerm)
                    {{ $currentTerm->academicYear?->name }} | {{ $currentTerm->name }} | {{ $currentTerm->starts_on?->format('d M Y') }} - {{ $currentTerm->ends_on?->format('d M Y') }}
                @else
                    belum diset
                @endif
            </div>
        </div>
        @if ($canGenerateReport && ! $isParentView)
            <form method="post" action="{{ route('alpha.reports.generate', ['term_id' => $currentTerm->id]) }}">
                @csrf
                <button class="btn primary" type="submit">Perbarui Draft Semua Siswa</button>
            </form>
        @endif
    </div>

    <section class="panel">
        @if ($isParentView)
            <div class="line-card soft">
                <strong>Rapor yang belum dipublish belum ditampilkan.</strong>
                <div class="meta">Sekolah akan membuka rapor setelah guru dan tim sekolah selesai mereview narasi perkembangan anak.</div>
            </div>
        @else
            <div class="grid four">
                <div class="line-card">
                    <strong>1. Pilih siswa</strong>
                    <div class="meta">Daftar ini tetap menampilkan siswa walau rapor belum dibuat.</div>
                </div>
                <div class="line-card">
                    <strong>2. Buat draft</strong>
                    <div class="meta">Draft mengambil bahan observasi yang ditandai masuk rapor.</div>
                </div>
                <div class="line-card">
                    <strong>3. Isi manual</strong>
                    <div class="meta">Kehadiran rapor dan narasi diisi di detail siswa.</div>
                </div>
                <div class="line-card">
                    <strong>4. Publish</strong>
                    <div class="meta">Orang tua hanya melihat rapor berstatus published.</div>
                </div>
            </div>
        @endif

        <form method="get" action="{{ route('alpha.reports') }}" class="form-grid report-filter-form" style="margin-top: 18px">
            <input type="hidden" name="term_id" value="{{ $currentTerm->id }}">
            <div class="field">
                <label for="report-q">Cari siswa</label>
                <input id="report-q" type="search" name="q" value="{{ $reportFilters['q'] }}" placeholder="Nama, kode, atau wali">
            </div>
            <div class="field">
                <label for="report-term-id">Term</label>
                <select id="report-term-id" name="term_id">
                    @foreach ($terms as $term)
                        <option value="{{ $term->id }}" @selected($currentTerm->id === $term->id)>
                            {{ $term->academicYear?->name }} - {{ $term->name }}
                        </option>
                    @endforeach
                </select>
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
            @if ($canUseTeacherFilter && ! $isParentView)
                <div class="field">
                    <label for="report-teacher-id">Guru</label>
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
                    <label for="report-status">Status rapor</label>
                    <select id="report-status" name="status">
                        <option value="">Semua status</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($reportFilters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endunless
            <div class="toolbar" style="align-self: end">
                <button class="btn primary" type="submit">Terapkan</button>
                <a class="btn ghost" href="{{ route('alpha.reports') }}">Reset</a>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="section-head">
            <div>
                <h3>{{ $isParentView ? 'Rapor Tersedia' : 'Siswa dan Status Rapor' }}</h3>
                <div class="meta">{{ $students->total() }} siswa sesuai filter.</div>
            </div>
            <div class="report-legend">
                @foreach ($statusOptions as $value => $label)
                    @continue($isParentView && $value !== 'published')
                    <span><strong class="status status-{{ $statusTone[$value] ?? $value }}">{{ $label }}</strong></span>
                @endforeach
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Siswa</th>
                    <th>Kelas</th>
                    <th>Wali</th>
                    <th>Bahan Observasi</th>
                    <th>Status Rapor</th>
                    <th>Aksi</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($students as $student)
                    @php
                        $report = $student->reports->first();
                        $status = $report?->status ?? 'not_created';
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $student->name }}</strong>
                            <br>
                            <span class="meta">{{ $student->code }}{{ $student->gender ? ' | ' . strtoupper($student->gender) : '' }}</span>
                        </td>
                        <td>{{ $student->schoolClass?->name ?? '-' }}</td>
                        <td>{{ $student->guardian?->name ?? '-' }}</td>
                        <td>
                            <strong>{{ $student->report_observations_count ?? 0 }}</strong>
                            <span class="meta">catatan</span>
                        </td>
                        <td>
                            <span class="status status-{{ $statusTone[$status] ?? str_replace('_', '-', $status) }}">
                                {{ $statusOptions[$status] ?? $report?->status_label ?? $status }}
                            </span>
                        </td>
                        <td>
                            <div class="toolbar compact-actions">
                                <a class="btn ghost" href="{{ route('alpha.reports.student', ['student' => $student, 'term_id' => $currentTerm->id]) }}">{{ $isParentView ? 'Buka Rapor' : 'Detail' }}</a>
                                @if (! $isParentView && $canGenerateReport)
                                    <form method="post" action="{{ route('alpha.reports.students.draft', ['student' => $student, 'term_id' => $currentTerm->id]) }}">
                                        @csrf
                                        <button class="btn ghost" type="submit">{{ $report ? 'Perbarui Draft' : 'Buat Draft' }}</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">{{ $isParentView ? 'Belum ada rapor yang dipublish.' : 'Belum ada siswa sesuai filter ini.' }}</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top: 16px">
            {{ $students->links() }}
        </div>
    </section>
@endsection

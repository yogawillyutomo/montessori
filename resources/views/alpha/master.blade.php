@extends('alpha.layout')

@section('title', 'Master Data - Montessori Alpha')
@section('page_title', 'Master Data')
@section('page_subtitle', 'Data dasar: kelas, siswa, orangtua, guru, dan indikator perkembangan.')

@section('content')
    <div class="section-head">
        <div>
            <h2>Fondasi Data</h2>
            <div class="meta">Bagian ini relatif stabil. Proses harian mengambil referensi dari master ini.</div>
        </div>
    </div>

    <div class="grid three">
        @foreach ($classes as $class)
            <section class="panel">
                <div class="line-head">
                    <div>
                        <h3>{{ $class->name }}</h3>
                        <div class="meta">{{ $class->level }} · {{ $class->age_range }}</div>
                    </div>
                    <span class="status status-achieved">Aktif</span>
                </div>
                <div class="grid two" style="margin-top: 14px">
                    <div class="line-card">
                        <span class="meta">Siswa</span>
                        <strong>{{ $class->students_count }} / {{ $class->capacity }}</strong>
                    </div>
                    <div class="line-card">
                        <span class="meta">Jadwal</span>
                        <strong>{{ $class->weekly_schedules_count }}</strong>
                    </div>
                </div>
            </section>
        @endforeach
    </div>

    <section class="panel">
        <div class="line-head">
            <div>
                <h3>Siswa dan Orangtua</h3>
                <div class="meta">Siswa punya kelas aktif dan guardian/orangtua utama.</div>
            </div>
        </div>
        <div class="table-wrap" style="margin-top: 14px">
            <table>
                <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama</th>
                    <th>Kelas</th>
                    <th>Usia</th>
                    <th>Orangtua</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($students as $student)
                    <tr>
                        <td>{{ $student->code }}</td>
                        <td><strong>{{ $student->name }}</strong></td>
                        <td>{{ $student->schoolClass->name }}</td>
                        <td>{{ $student->age_label }}</td>
                        <td>{{ $student->guardian?->name ?? '-' }}</td>
                        <td><span class="status status-achieved">{{ ucfirst($student->status) }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="line-head">
            <div>
                <h3>Guru</h3>
                <div class="meta">Guru menjadi penanggung jawab jadwal mingguan dan observasi.</div>
            </div>
        </div>
        <div class="table-wrap" style="margin-top: 14px">
            <table>
                <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama</th>
                    <th>Fokus</th>
                    <th>Jadwal</th>
                    <th>Sesi</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($teachers as $teacher)
                    <tr>
                        <td>{{ $teacher->code }}</td>
                        <td><strong>{{ $teacher->name }}</strong></td>
                        <td>{{ $teacher->focus_area }}</td>
                        <td>{{ $teacher->weekly_schedules_count }}</td>
                        <td>{{ $teacher->class_sessions_count }}</td>
                        <td><span class="status status-achieved">Aktif</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="line-head">
            <div>
                <h3>Area dan Indikator Perkembangan</h3>
                <div class="meta">Data ini nanti bisa diimport dari workbook indikator Sunny/Glow/Infant.</div>
            </div>
        </div>
        <div class="table-wrap" style="margin-top: 14px">
            <table>
                <thead>
                <tr>
                    <th>Area</th>
                    <th>Kode</th>
                    <th>Sub-area</th>
                    <th>Indikator</th>
                    <th>Level</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($areas as $area)
                    @foreach ($area->indicators as $indicator)
                        <tr>
                            <td><span class="dot {{ $area->color }}"></span> {{ $area->name }}</td>
                            <td>{{ $indicator->code }}</td>
                            <td>{{ $indicator->sub_area }}</td>
                            <td>{{ $indicator->description }}</td>
                            <td>{{ $indicator->level }}</td>
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rapor {{ $student->name }} - Montessori Bloom</title>
    <style>
        :root {
            color: #111827;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            margin: 0;
            background: #f8fafc;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: #fff;
            padding: 18mm;
            box-sizing: border-box;
        }

        .print-actions {
            width: 210mm;
            margin: 18px auto;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        button, a {
            border: 1px solid #d0d7de;
            background: #fff;
            border-radius: 8px;
            padding: 9px 14px;
            color: #0969da;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        h1, h2, h3, p {
            margin: 0;
        }

        h1 {
            font-size: 26px;
        }

        h2 {
            margin-top: 22px;
            font-size: 17px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 8px;
        }

        h3 {
            font-size: 14px;
            margin-bottom: 6px;
        }

        .muted {
            color: #6b7280;
            font-size: 13px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            border-bottom: 2px solid #111827;
            padding-bottom: 14px;
        }

        .badge {
            display: inline-flex;
            border-radius: 999px;
            border: 1px solid #bbf7d0;
            background: #ecfdf5;
            color: #166534;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 700;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 14px;
        }

        .box {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 12px;
        }

        .attendance {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-top: 12px;
        }

        .attendance .box {
            text-align: center;
        }

        .attendance strong {
            display: block;
            font-size: 20px;
        }

        .area {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .area-row {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 12px;
        }

        .narrative {
            display: grid;
            gap: 12px;
            margin-top: 12px;
        }

        .signature {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 36px;
            margin-top: 34px;
        }

        .signature div {
            min-height: 76px;
            border-top: 1px solid #111827;
            padding-top: 8px;
            text-align: center;
        }

        @media print {
            body {
                background: #fff;
            }

            .print-actions {
                display: none;
            }

            .page {
                width: auto;
                min-height: auto;
                padding: 0;
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <div class="print-actions">
        <a href="{{ route('alpha.reports.student', ['student' => $student, 'term_id' => $term->id]) }}">Kembali</a>
        <button type="button" onclick="window.print()">Cetak</button>
    </div>

    <main class="page">
        <header class="header">
            <div>
                <h1>Rapor Perkembangan Montessori</h1>
                <p class="muted">Montessori Bloom | {{ $term->academicYear?->name }} - {{ $term->name }}</p>
            </div>
            <span class="badge">{{ $report->status_label }}</span>
        </header>

        <section>
            <h2>Identitas Siswa</h2>
            <div class="grid">
                <div class="box"><h3>Nama</h3><p>{{ $student->name }}</p><p class="muted">{{ $student->code }}</p></div>
                <div class="box"><h3>Kelas</h3><p>{{ $student->schoolClass?->name ?? '-' }}</p><p class="muted">{{ $student->schoolClass?->classLevel?->name ?? '-' }}</p></div>
                <div class="box"><h3>Tanggal Lahir</h3><p>{{ $student->birth_date?->format('d M Y') ?? '-' }}</p><p class="muted">{{ $student->birth_place ?? '-' }}</p></div>
                <div class="box"><h3>Orang Tua/Wali</h3><p>{{ $student->guardian?->name ?? '-' }}</p><p class="muted">{{ $student->guardian?->phone ?? '-' }}</p></div>
            </div>
        </section>

        <section>
            <h2>Kehadiran</h2>
            <p class="muted">{{ $report->manual_attendance_note ?: 'Catatan kehadiran belum ditambahkan.' }}</p>
            <div class="attendance">
                <div class="box"><strong>{{ $attendance['present'] }}</strong><span>Hadir</span></div>
                <div class="box"><strong>{{ $attendance['sick'] }}</strong><span>Sakit</span></div>
                <div class="box"><strong>{{ $attendance['excused'] }}</strong><span>Izin</span></div>
                <div class="box"><strong>{{ $attendance['absent'] }}</strong><span>Alfa</span></div>
                <div class="box"><strong>{{ $attendance['late'] }}</strong><span>Terlambat</span></div>
            </div>
        </section>

        <section>
            <h2>Ringkasan Observasi</h2>
            <div class="area">
                @forelse ($observationSummary['areas'] as $area)
                    <div class="area-row">
                        <h3>{{ $area['name'] }}</h3>
                        <p class="muted">{{ $area['observed'] }} catatan | skor ringkas {{ $area['score'] }}% | {{ $area['needs_follow_up'] }} perlu tindak lanjut</p>
                    </div>
                @empty
                    <p class="muted">Belum ada observasi yang masuk bahan rapor.</p>
                @endforelse
            </div>
        </section>

        <section>
            <h2>Narasi Perkembangan</h2>
            <div class="narrative">
                <div class="box"><h3>Catatan Guru</h3><p>{{ $report->teacher_narrative ?: '-' }}</p></div>
                <div class="box"><h3>Umum</h3><p>{{ $report->general_narrative ?: '-' }}</p></div>
                <div class="box"><h3>Sosial Emosional</h3><p>{{ $report->social_emotional_narrative ?: '-' }}</p></div>
                <div class="box"><h3>Kemandirian</h3><p>{{ $report->independence_narrative ?: '-' }}</p></div>
                <div class="box"><h3>Akademik dan Kegiatan Montessori</h3><p>{{ $report->academic_narrative ?: '-' }}</p></div>
                <div class="box"><h3>Catatan Kepala Sekolah</h3><p>{{ $report->principal_note ?: '-' }}</p></div>
            </div>
        </section>

        <section class="signature">
            <div>Guru Kelas</div>
            <div>Kepala Sekolah</div>
        </section>
    </main>
</body>
</html>

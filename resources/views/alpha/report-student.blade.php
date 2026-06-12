@extends('alpha.layout')

@section('title', $student->name . ' - Rapor Montessori Bloom')
@section('page_title', $isParentView ? 'Rapor Anak' : 'Detail Rapor Siswa')
@section('page_subtitle', $isParentView ? 'Narasi perkembangan anak dari sekolah.' : 'Susun rapor dari bahan observasi, narasi guru, dan data kehadiran manual.')

@section('content')
    @php
        $statusOptions = [
            'draft' => 'Draft',
            'ready' => 'Siap Direview',
            'archived' => 'Diarsipkan',
        ];
        if ($report?->status === 'published') {
            $statusOptions['published'] = 'Dipublish';
        }
        $statusLabel = $report ? $report->status_label : 'Belum Dibuat';
        $statusClass = $report ? $report->status_badge_class : 'status-not-created';
        $birthDate = $student->birth_date?->format('d M Y') ?? '-';
    @endphp

    <div class="section-head">
        <div class="toolbar">
            <a class="btn ghost" href="{{ route('alpha.reports', ['term_id' => $term->id]) }}">Kembali</a>
        </div>
        <div class="toolbar">
            @if ($report)
                <a class="btn ghost" href="{{ route('alpha.reports.print', $report) }}" target="_blank">Cetak</a>
            @endif
            @if ($canBuildDraft && ! $isParentView)
                <form method="post" action="{{ route('alpha.reports.students.draft', ['student' => $student, 'term_id' => $term->id]) }}">
                    @csrf
                    <button class="btn ghost" type="submit">{{ $report ? 'Perbarui Draft' : 'Buat Draft' }}</button>
                </form>
            @endif
            @if ($canPublishReport && $report?->status !== 'published')
                <form method="post" action="{{ route('alpha.reports.publish', $report) }}">
                    @csrf
                    @method('PATCH')
                    <button class="btn primary" type="submit">Publish ke Orang Tua</button>
                </form>
            @endif
        </div>
    </div>

    <section class="panel">
        <div class="report-card-head">
            <div class="report-student">
                <div class="avatar">{{ str($student->name)->substr(0, 1)->upper() }}</div>
                <div>
                    <h2>{{ $student->name }}</h2>
                    <div class="meta">
                        {{ $student->code }} | {{ $student->schoolClass?->name ?? '-' }} |
                        {{ $term->academicYear?->name }} {{ $term->name }}
                    </div>
                </div>
            </div>
            <span class="status {{ $statusClass }}">{{ $statusLabel }}</span>
        </div>

        <div class="grid four" style="margin-top: 16px">
            <div class="line-card soft">
                <strong>Tanggal lahir</strong>
                <div class="meta">{{ $birthDate }}</div>
            </div>
            <div class="line-card soft">
                <strong>Wali</strong>
                <div class="meta">{{ $student->guardian?->name ?? '-' }}</div>
            </div>
            <div class="line-card soft">
                <strong>Bahan observasi</strong>
                <div class="meta">{{ $observationSummary['total'] }} catatan masuk rapor</div>
            </div>
            <div class="line-card soft">
                <strong>Kehadiran rapor</strong>
                <div class="meta">{{ $attendance['recorded'] }} hari tercatat manual</div>
            </div>
        </div>
    </section>

    @if (! $report && ! $isParentView)
        <section class="panel">
            <div class="empty-state">
                Belum ada rapor untuk siswa ini pada term terpilih. Guru atau admin dapat membuat draft dari bahan observasi, atau langsung mengisi data manual.
            </div>
        </section>
    @endif

    <div class="grid two">
        <section class="panel">
            <div class="section-head">
                <div>
                    <h3>Ringkasan Observasi</h3>
                    <div class="meta">Hanya catatan yang ditandai sebagai bahan rapor.</div>
                </div>
            </div>

            <div class="progress-list">
                @forelse ($observationSummary['areas'] as $area)
                    <div class="progress-row">
                        <div class="progress-meta">
                            <span>{{ $area['name'] }} | {{ $area['observed'] }} catatan</span>
                            <strong>{{ $area['score'] }}%</strong>
                        </div>
                        <div class="bar"><span style="width: {{ $area['score'] }}%"></span></div>
                        @if ($area['needs_follow_up'] > 0)
                            <div class="meta">{{ $area['needs_follow_up'] }} catatan perlu tindak lanjut.</div>
                        @endif
                    </div>
                @empty
                    <div class="empty-state compact">Belum ada observasi yang masuk bahan rapor.</div>
                @endforelse
            </div>
        </section>

        <section class="panel">
            <h3>Catatan Observasi Terakhir</h3>
            <div class="card-list" style="margin-top: 14px">
                @forelse ($observationSummary['latest'] as $observation)
                    <div class="line-card soft">
                        <div class="line-head">
                            <div>
                                <strong>{{ $observation->developmentArea?->name ?? $observation->indicator?->developmentArea?->name ?? 'Area belum dipilih' }}</strong>
                                <div class="meta">{{ $observation->observed_on?->format('d M Y') }} | {{ $observation->teacher?->name ?? '-' }}</div>
                            </div>
                            <span class="status {{ $observation->level_badge_class }}">{{ $observation->level_label }}</span>
                        </div>
                        <div class="meta" style="margin-top: 8px">{{ $observation->indicator?->description ?? 'Observasi spontan tanpa indikator spesifik.' }}</div>
                        <p>{{ $observation->note }}</p>
                    </div>
                @empty
                    <div class="empty-state compact">Belum ada catatan observasi untuk periode ini.</div>
                @endforelse
            </div>
        </section>
    </div>

    @if ($isParentView)
        <section class="panel">
            <h3>Narasi Perkembangan</h3>
            <div class="card-list" style="margin-top: 14px">
                <div class="line-card soft">
                    <strong>Catatan Guru</strong>
                    <p>{{ $report?->teacher_narrative ?: $report?->general_narrative ?: 'Belum ada narasi guru.' }}</p>
                </div>
                @if ($report?->social_emotional_narrative)
                    <div class="line-card soft"><strong>Sosial Emosional</strong><p>{{ $report->social_emotional_narrative }}</p></div>
                @endif
                @if ($report?->independence_narrative)
                    <div class="line-card soft"><strong>Kemandirian</strong><p>{{ $report->independence_narrative }}</p></div>
                @endif
                @if ($report?->academic_narrative)
                    <div class="line-card soft"><strong>Akademik dan Kegiatan Montessori</strong><p>{{ $report->academic_narrative }}</p></div>
                @endif
                @if ($report?->principal_note)
                    <div class="line-card soft"><strong>Catatan Kepala Sekolah</strong><p>{{ $report->principal_note }}</p></div>
                @endif
            </div>
        </section>
    @else
        <form method="post" action="{{ route('alpha.reports.students.update', ['student' => $student, 'term_id' => $term->id]) }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="term_id" value="{{ $term->id }}">

            <section class="panel">
                <div class="section-head">
                    <div>
                        <h3>Data Kehadiran Manual untuk Rapor</h3>
                        <div class="meta">Diisi sesuai data yang akan dicetak di rapor. Tidak bergantung pada panel presensi harian.</div>
                    </div>
                    <div class="report-attendance">
                        <strong>{{ $attendance['attendance_rate'] }}%</strong>
                        <span>{{ $attendance['recorded'] }} hari tercatat</span>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="manual_present_total">Hadir</label>
                        <input id="manual_present_total" type="number" min="0" name="manual_present_total" value="{{ old('manual_present_total', $report?->manual_present_total ?? 0) }}">
                        @error('manual_present_total')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="manual_sick_total">Sakit</label>
                        <input id="manual_sick_total" type="number" min="0" name="manual_sick_total" value="{{ old('manual_sick_total', $report?->manual_sick_total ?? 0) }}">
                        @error('manual_sick_total')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="manual_excused_total">Izin</label>
                        <input id="manual_excused_total" type="number" min="0" name="manual_excused_total" value="{{ old('manual_excused_total', $report?->manual_excused_total ?? 0) }}">
                        @error('manual_excused_total')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="manual_absent_total">Alfa</label>
                        <input id="manual_absent_total" type="number" min="0" name="manual_absent_total" value="{{ old('manual_absent_total', $report?->manual_absent_total ?? 0) }}">
                        @error('manual_absent_total')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="manual_late_total">Terlambat</label>
                        <input id="manual_late_total" type="number" min="0" name="manual_late_total" value="{{ old('manual_late_total', $report?->manual_late_total ?? 0) }}">
                        @error('manual_late_total')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="status">Status rapor</label>
                        <select id="status" name="status">
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', $report?->status ?? 'draft') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('status')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field wide">
                        <label for="manual_attendance_note">Catatan kehadiran</label>
                        <textarea id="manual_attendance_note" name="manual_attendance_note" rows="3" placeholder="Contoh: Kehadiran baik, beberapa keterlambatan sudah dikomunikasikan dengan orang tua.">{{ old('manual_attendance_note', $report?->manual_attendance_note) }}</textarea>
                        @error('manual_attendance_note')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </section>

            <section class="panel">
                <h3>Narasi Rapor</h3>
                <div class="form-grid" style="margin-top: 14px">
                    <div class="field wide">
                        <label for="teacher_narrative">Catatan guru</label>
                        <textarea id="teacher_narrative" name="teacher_narrative" rows="4">{{ old('teacher_narrative', $report?->teacher_narrative) }}</textarea>
                        @error('teacher_narrative')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field wide">
                        <label for="general_narrative">Narasi umum</label>
                        <textarea id="general_narrative" name="general_narrative" rows="4">{{ old('general_narrative', $report?->general_narrative) }}</textarea>
                        @error('general_narrative')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="social_emotional_narrative">Sosial emosional</label>
                        <textarea id="social_emotional_narrative" name="social_emotional_narrative" rows="5">{{ old('social_emotional_narrative', $report?->social_emotional_narrative) }}</textarea>
                        @error('social_emotional_narrative')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="independence_narrative">Kemandirian</label>
                        <textarea id="independence_narrative" name="independence_narrative" rows="5">{{ old('independence_narrative', $report?->independence_narrative) }}</textarea>
                        @error('independence_narrative')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="academic_narrative">Akademik dan Montessori</label>
                        <textarea id="academic_narrative" name="academic_narrative" rows="5">{{ old('academic_narrative', $report?->academic_narrative) }}</textarea>
                        @error('academic_narrative')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="principal_note">Catatan kepala sekolah</label>
                        <textarea id="principal_note" name="principal_note" rows="5">{{ old('principal_note', $report?->principal_note) }}</textarea>
                        @error('principal_note')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field wide">
                        <label for="parent_meeting_note">Catatan pertemuan orang tua</label>
                        <textarea id="parent_meeting_note" name="parent_meeting_note" rows="3">{{ old('parent_meeting_note', $report?->parent_meeting_note) }}</textarea>
                        @error('parent_meeting_note')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="toolbar" style="margin-top: 16px">
                    <button class="btn primary" type="submit">Simpan Rapor</button>
                </div>
            </section>
        </form>
    @endif
@endsection

@extends('alpha.layout')

@section('title', $student->name . ' - Rapor Montessori Bloom')
@section('page_title', $isParentView ? 'Rapor Anak' : 'Detail Rapor Siswa')
@section('page_subtitle', $isParentView ? 'Narasi perkembangan anak dari versi rapor yang sudah dipublish.' : 'Susun rapor dari evidence, narasi guru, dan workflow review yang terkontrol.')

@section('content')
    @php
        $legacyStatusOptions = [
            'draft' => 'Draft',
            'ready' => 'Siap Direview',
            'archived' => 'Diarsipkan',
        ];
        if (! $isCycleReport && $report?->status === 'published') {
            $legacyStatusOptions['published'] = 'Dipublish';
        }

        $visibleReport = $report;
        $statusSource = $isParentView ? $visibleReport : ($workingReport ?? $visibleReport);
        $statusLabel = $statusSource ? $statusSource->status_label : 'Belum Dibuat';
        $statusClass = $statusSource ? $statusSource->status_badge_class : 'status-not-created';
        $birthDate = $student->birth_date?->format('d M Y') ?? '-';
        $editAction = $isCycleReport && $workingReport
            ? route('alpha.cycle-reports.update', $workingReport)
            : route('alpha.reports.students.update', ['student' => $student, 'term_id' => $term->id]);
    @endphp

    <div class="section-head">
        <div class="toolbar">
            <a class="btn ghost" href="{{ route('alpha.reports', ['term_id' => $term->id]) }}">Kembali</a>
        </div>
        <div class="toolbar">
            @if ($visibleReport || $workingReport)
                <a class="btn ghost" href="{{ route('alpha.reports.print', $workingReport ?? $visibleReport) }}" target="_blank">Cetak</a>
            @endif
            @if ($canBuildDraft && ! $isParentView)
                <form method="post" action="{{ route('alpha.reports.students.draft', ['student' => $student, 'term_id' => $term->id]) }}">
                    @csrf
                    <button class="btn ghost" type="submit">{{ $visibleReport ? 'Perbarui Draft' : 'Buat Draft Legacy' }}</button>
                </form>
            @endif
            @if ($canPublishReport && $workingReport?->status !== 'published')
                <form method="post" action="{{ route('alpha.reports.publish', $workingReport) }}">
                    @csrf
                    @method('PATCH')
                    <button class="btn primary" type="submit">Publish Legacy ke Orang Tua</button>
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
                    @if ($isCycleReport && $reportCycle)
                        <div class="meta" style="margin-top: 4px">
                            Cycle: <strong>{{ $reportCycle->name }}</strong> |
                            Evidence {{ $reportCycle->window_start?->format('d M Y') }} - {{ $reportCycle->window_end?->format('d M Y') }} |
                            Cutoff {{ $reportCycle->cutoff_date?->format('d M Y') }}
                        </div>
                    @endif
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
                <div class="meta">{{ $observationSummary['total'] ?? 0 }} catatan masuk rapor</div>
            </div>
            <div class="line-card soft">
                <strong>Kehadiran rapor</strong>
                <div class="meta">{{ $attendance['recorded'] }} hari tercatat manual</div>
            </div>
        </div>
    </section>

    @if ($isCycleReport && ! $isParentView && $workingReport)
        <section class="panel">
            <div class="section-head">
                <div>
                    <h3>Workflow Cycle Report</h3>
                    <div class="meta">
                        Working revision {{ $workingReport->working_revision }} |
                        status <strong>{{ $workingReport->status_label }}</strong>.
                    </div>
                </div>
                @if ($workingReport->publishedVersion)
                    <span class="status status-published">
                        Public v{{ $workingReport->publishedVersion->version_number }}
                    </span>
                @endif
            </div>

            @if ($workingReport->publishedVersion && $workingReport->status !== 'published')
                <div class="line-card soft" style="margin-bottom: 14px">
                    <strong>Versi publik tetap aman.</strong>
                    <div class="meta">Orang tua masih melihat published version v{{ $workingReport->publishedVersion->version_number }}. Perubahan working revision tidak tampil sampai direview, disetujui, dan dipublish ulang.</div>
                </div>
            @endif

            @if ($workingReportStatus === 'revision_requested' && $workingReport->revision_request_note)
                <div class="line-card soft" style="margin-bottom: 14px">
                    <strong>Catatan revisi</strong>
                    <p>{{ $workingReport->revision_request_note }}</p>
                </div>
            @endif

            <div class="toolbar" style="align-items: flex-start; flex-wrap: wrap">
                @if ($canSubmitCycleReport)
                    <form method="post" action="{{ route('alpha.cycle-reports.submit', $workingReport) }}">
                        @csrf
                        <button class="btn primary" type="submit">Ajukan Review</button>
                    </form>
                @endif

                @if ($canStartCycleReview)
                    <form method="post" action="{{ route('alpha.cycle-reports.review', $workingReport) }}">
                        @csrf
                        <button class="btn primary" type="submit">Mulai Review</button>
                    </form>
                @endif

                @if ($canApproveCycleReport)
                    <form method="post" action="{{ route('alpha.cycle-reports.approve', $workingReport) }}">
                        @csrf
                        <button class="btn primary" type="submit">Setujui Rapor</button>
                    </form>
                @endif

                @if ($canPublishCycleReport)
                    <form method="post" action="{{ route('alpha.cycle-reports.publish', $workingReport) }}">
                        @csrf
                        <button class="btn primary" type="submit">Publish Immutable Version</button>
                    </form>
                @endif

                @if ($canRequestCycleRevision)
                    <form method="post" action="{{ route('alpha.cycle-reports.request-revision', $workingReport) }}" style="min-width: 280px">
                        @csrf
                        <div class="field">
                            <label for="revision-note">Alasan revisi</label>
                            <textarea id="revision-note" name="note" rows="2" required placeholder="Jelaskan bagian yang perlu diperbaiki."></textarea>
                        </div>
                        <button class="btn ghost" type="submit">Minta Revisi</button>
                    </form>
                @endif

                @if ($canBeginCycleRevision)
                    <form method="post" action="{{ route('alpha.cycle-reports.begin-revision', $workingReport) }}" style="min-width: 280px">
                        @csrf
                        <div class="field">
                            <label for="revision-reason">Alasan membuka revisi</label>
                            <textarea id="revision-reason" name="reason" rows="2" required placeholder="Contoh: koreksi narasi setelah konferensi keluarga."></textarea>
                        </div>
                        <button class="btn ghost" type="submit">Buka Working Revision</button>
                    </form>
                @endif

                @if ($canArchiveCycleReport)
                    <form method="post" action="{{ route('alpha.cycle-reports.archive', $workingReport) }}">
                        @csrf
                        <button class="btn ghost" type="submit">Arsipkan</button>
                    </form>
                @endif
            </div>
        </section>
    @endif

    @if (! $isParentView && ! empty($cycleRows))
        <section class="panel">
            <div class="section-head">
                <div>
                    <h3>Report Cycle & Eligibility</h3>
                    <div class="meta">Cycle report dibuat terpisah dari legacy term report. Eligibility harus ELIGIBLE sebelum draft cycle dapat dibuat.</div>
                </div>
            </div>

            <div class="card-list">
                @foreach ($cycleRows as $row)
                    @php
                        $cycle = $row['cycle'];
                        $cycleReport = $row['report'];
                        $eligibility = $row['eligibility'];
                        $eligibilityReasons = (array) ($eligibility?->reason_codes ?? []);
                        $onlyGuidePending = in_array('guide_confirmation_pending', $eligibilityReasons, true)
                            && count(array_diff($eligibilityReasons, ['guide_confirmation_pending'])) === 0;
                    @endphp
                    <div class="line-card soft">
                        <div class="line-head">
                            <div>
                                <strong>{{ $cycle->name }}</strong>
                                <div class="meta">
                                    {{ $cycle->reportPolicy?->name ?? 'Policy belum tersedia' }} |
                                    {{ $cycle->window_start?->format('d M Y') }} - {{ $cycle->window_end?->format('d M Y') }} |
                                    cutoff {{ $cycle->cutoff_date?->format('d M Y') }}
                                </div>
                            </div>
                            <div class="toolbar compact-actions">
                                <span class="status {{ $eligibility?->status === 'eligible' ? 'status-published' : 'status-draft' }}">
                                    {{ $eligibility ? strtoupper($eligibility->status) : 'BELUM DIEVALUASI' }}
                                </span>
                                @if ($cycleReport)
                                    <span class="status {{ $cycleReport->status_badge_class }}">{{ $cycleReport->status_label }}</span>
                                @endif
                            </div>
                        </div>

                        @if ($eligibility && $eligibilityReasons !== [])
                            <div class="meta" style="margin-top: 8px">
                                Blocking reason: {{ implode(', ', $eligibilityReasons) }}
                            </div>
                        @endif

                        <div class="toolbar compact-actions" style="margin-top: 12px">
                            @if ($cycleReport)
                                <a class="btn ghost" href="{{ route('alpha.reports.show', $cycleReport) }}">Buka Cycle Report</a>
                            @elseif ($canGenerateCycleReport && $eligibility?->status === 'eligible')
                                <form method="post" action="{{ route('alpha.cycle-reports.draft', ['reportCycle' => $cycle, 'student' => $student]) }}">
                                    @csrf
                                    <button class="btn primary" type="submit">Buat Cycle Draft</button>
                                </form>
                            @endif

                            @if ($canGenerateCycleReport)
                                <form method="post" action="{{ route('alpha.report-eligibility.evaluate', ['reportCycle' => $cycle, 'student' => $student]) }}">
                                    @csrf
                                    <button class="btn ghost" type="submit">{{ $eligibility ? 'Evaluasi Ulang Eligibility' : 'Evaluasi Eligibility' }}</button>
                                </form>
                            @endif

                            @if ($canGenerateCycleReport && $eligibility && $onlyGuidePending)
                                <form method="post" action="{{ route('alpha.report-eligibility.confirm', $eligibility) }}">
                                    @csrf
                                    <input type="hidden" name="note" value="Confirmed from report cycle workflow UI.">
                                    <button class="btn ghost" type="submit">Konfirmasi Guide Readiness</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if (! $visibleReport && ! $isParentView && ! $isCycleReport)
        <section class="panel">
            <div class="empty-state">
                Belum ada legacy term report untuk siswa ini. Anda dapat tetap menggunakan alur legacy selama transisi, atau gunakan report cycle di atas untuk workflow M14 yang versioned.
            </div>
        </section>
    @endif

    <div class="grid two">
        <section class="panel">
            <div class="section-head">
                <div>
                    <h3>Ringkasan Observasi</h3>
                    <div class="meta">{{ $isCycleReport ? 'Evidence dibatasi oleh window dan cutoff report cycle.' : 'Hanya catatan yang ditandai sebagai bahan rapor.' }}</div>
                </div>
            </div>

            <div class="progress-list">
                @forelse ($observationSummary['areas'] ?? [] as $area)
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
                @forelse ($observationSummary['latest'] ?? [] as $observation)
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
                    <p>{{ $visibleReport?->teacher_narrative ?: $visibleReport?->general_narrative ?: 'Belum ada narasi guru.' }}</p>
                </div>
                @if ($visibleReport?->social_emotional_narrative)
                    <div class="line-card soft"><strong>Sosial Emosional</strong><p>{{ $visibleReport->social_emotional_narrative }}</p></div>
                @endif
                @if ($visibleReport?->independence_narrative)
                    <div class="line-card soft"><strong>Kemandirian</strong><p>{{ $visibleReport->independence_narrative }}</p></div>
                @endif
                @if ($visibleReport?->academic_narrative)
                    <div class="line-card soft"><strong>Akademik dan Kegiatan Montessori</strong><p>{{ $visibleReport->academic_narrative }}</p></div>
                @endif
                @if ($visibleReport?->principal_note)
                    <div class="line-card soft"><strong>Catatan Kepala Sekolah</strong><p>{{ $visibleReport->principal_note }}</p></div>
                @endif
            </div>
        </section>
    @elseif ($canEditReport)
        <form method="post" action="{{ $editAction }}">
            @csrf
            @method('PATCH')
            @unless ($isCycleReport)
                <input type="hidden" name="term_id" value="{{ $term->id }}">
            @endunless

            <section class="panel">
                <div class="section-head">
                    <div>
                        <h3>Data Kehadiran Manual untuk Rapor</h3>
                        <div class="meta">Diisi sesuai data yang akan dicetak di rapor. Tidak mengubah presensi sesi harian.</div>
                    </div>
                    <div class="report-attendance">
                        <strong>{{ $attendance['attendance_rate'] }}%</strong>
                        <span>{{ $attendance['recorded'] }} hari tercatat</span>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="manual_present_total">Hadir</label>
                        <input id="manual_present_total" type="number" min="0" name="manual_present_total" value="{{ old('manual_present_total', $visibleReport?->manual_present_total ?? 0) }}">
                        @error('manual_present_total')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="manual_sick_total">Sakit</label>
                        <input id="manual_sick_total" type="number" min="0" name="manual_sick_total" value="{{ old('manual_sick_total', $visibleReport?->manual_sick_total ?? 0) }}">
                        @error('manual_sick_total')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="manual_excused_total">Izin</label>
                        <input id="manual_excused_total" type="number" min="0" name="manual_excused_total" value="{{ old('manual_excused_total', $visibleReport?->manual_excused_total ?? 0) }}">
                        @error('manual_excused_total')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="manual_absent_total">Alfa</label>
                        <input id="manual_absent_total" type="number" min="0" name="manual_absent_total" value="{{ old('manual_absent_total', $visibleReport?->manual_absent_total ?? 0) }}">
                        @error('manual_absent_total')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="manual_late_total">Terlambat</label>
                        <input id="manual_late_total" type="number" min="0" name="manual_late_total" value="{{ old('manual_late_total', $visibleReport?->manual_late_total ?? 0) }}">
                        @error('manual_late_total')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        @if ($isCycleReport)
                            <label>Workflow status</label>
                            <input type="text" value="{{ $workingReport?->status_label }}" disabled>
                            <div class="meta">Status cycle report hanya berubah melalui workflow action di atas.</div>
                        @else
                            <label for="status">Status rapor</label>
                            <select id="status" name="status">
                                @foreach ($legacyStatusOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('status', $visibleReport?->status ?? 'draft') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('status')<div class="field-error">{{ $message }}</div>@enderror
                        @endif
                    </div>
                    <div class="field wide">
                        <label for="manual_attendance_note">Catatan kehadiran</label>
                        <textarea id="manual_attendance_note" name="manual_attendance_note" rows="3" placeholder="Contoh: Kehadiran baik, beberapa keterlambatan sudah dikomunikasikan dengan orang tua.">{{ old('manual_attendance_note', $visibleReport?->manual_attendance_note) }}</textarea>
                        @error('manual_attendance_note')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </section>

            <section class="panel">
                <h3>Narasi Rapor</h3>
                <div class="form-grid" style="margin-top: 14px">
                    <div class="field wide">
                        <label for="teacher_narrative">Catatan guru</label>
                        <textarea id="teacher_narrative" name="teacher_narrative" rows="4">{{ old('teacher_narrative', $visibleReport?->teacher_narrative) }}</textarea>
                        @error('teacher_narrative')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field wide">
                        <label for="general_narrative">Narasi umum</label>
                        <textarea id="general_narrative" name="general_narrative" rows="4">{{ old('general_narrative', $visibleReport?->general_narrative) }}</textarea>
                        @error('general_narrative')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="social_emotional_narrative">Sosial emosional</label>
                        <textarea id="social_emotional_narrative" name="social_emotional_narrative" rows="5">{{ old('social_emotional_narrative', $visibleReport?->social_emotional_narrative) }}</textarea>
                        @error('social_emotional_narrative')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="independence_narrative">Kemandirian</label>
                        <textarea id="independence_narrative" name="independence_narrative" rows="5">{{ old('independence_narrative', $visibleReport?->independence_narrative) }}</textarea>
                        @error('independence_narrative')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="academic_narrative">Akademik dan Montessori</label>
                        <textarea id="academic_narrative" name="academic_narrative" rows="5">{{ old('academic_narrative', $visibleReport?->academic_narrative) }}</textarea>
                        @error('academic_narrative')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="principal_note">Catatan kepala sekolah</label>
                        <textarea id="principal_note" name="principal_note" rows="5">{{ old('principal_note', $visibleReport?->principal_note) }}</textarea>
                        @error('principal_note')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field wide">
                        <label for="parent_meeting_note">Catatan pertemuan orang tua</label>
                        <textarea id="parent_meeting_note" name="parent_meeting_note" rows="3">{{ old('parent_meeting_note', $visibleReport?->parent_meeting_note) }}</textarea>
                        @error('parent_meeting_note')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="toolbar" style="margin-top: 16px">
                    <button class="btn primary" type="submit">Simpan {{ $isCycleReport ? 'Working Draft' : 'Rapor' }}</button>
                </div>
            </section>
        </form>
    @elseif (! $isParentView && $isCycleReport)
        <section class="panel">
            <div class="line-card soft">
                <strong>Isi report sedang terkunci.</strong>
                <div class="meta">Working content hanya dapat diedit pada status DRAFT atau REVISION_REQUESTED. Gunakan workflow action untuk melanjutkan proses.</div>
            </div>
        </section>
    @endif
@endsection

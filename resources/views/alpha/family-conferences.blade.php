@extends('alpha.layout')

@section('title', $student->name . ' - Family Conference')
@section('page_title', $isParentView ? 'Family Conference' : 'Family Conference Siswa')
@section('page_subtitle', $isParentView ? 'Ringkasan kolaborasi keluarga yang dibagikan sekolah.' : 'Catat hasil konferensi keluarga secara terstruktur tanpa membuka raw observation feed.')

@section('content')
    <div class="section-head">
        <div>
            <h2>{{ $student->name }}</h2>
            <div class="meta">
                {{ $student->code }} | {{ $student->schoolClass?->name ?? '-' }} |
                Wali: {{ $student->guardian?->name ?? '-' }}
            </div>
        </div>
        <div class="toolbar">
            <a class="btn ghost" href="{{ route('alpha.reports.student', $student) }}">Kembali ke Rapor</a>
        </div>
    </div>

    @if ($errors->any())
        <section class="panel" style="border-color: rgba(180, 60, 60, .35)">
            <strong>Data belum dapat disimpan.</strong>
            <ul style="margin: 10px 0 0 20px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($canManage)
        <section class="panel">
            <div class="section-head">
                <div>
                    <h3>Catat Family Conference</h3>
                    <div class="meta">Gunakan untuk hasil pembicaraan keluarga yang relevan dan disepakati. Bukan chat bebas.</div>
                </div>
            </div>

            <form method="post" action="{{ route('alpha.family-conferences.store', $student) }}">
                @csrf
                <div class="grid two">
                    <label>
                        <span>Tanggal konferensi</span>
                        <input type="date" name="conference_on" value="{{ old('conference_on', now()->toDateString()) }}" required>
                    </label>

                    @if (auth()->user()?->role !== 'teacher')
                        <label>
                            <span>Lead teacher</span>
                            <select name="lead_teacher_id">
                                <option value="">- Belum ditentukan -</option>
                                @foreach ($teachers as $teacher)
                                    <option value="{{ $teacher->id }}" @selected((string) old('lead_teacher_id') === (string) $teacher->id)>{{ $teacher->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif

                    <label>
                        <span>Konteks published report (opsional)</span>
                        <select name="report_version_id">
                            <option value="">- Tidak terkait report tertentu -</option>
                            @foreach ($reportVersions as $version)
                                <option value="{{ $version->id }}" @selected((string) old('report_version_id') === (string) $version->id)>
                                    {{ $version->report?->reportCycle?->name ?? 'Legacy/Term Report' }} · v{{ $version->version_number }} · {{ $version->published_at?->format('d M Y') }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span>Review berikutnya (opsional)</span>
                        <input type="date" name="next_review_on" value="{{ old('next_review_on') }}">
                    </label>
                </div>

                <label style="display:block; margin-top: 14px">
                    <span>Ringkasan</span>
                    <textarea name="summary" rows="4" placeholder="Pokok pembicaraan dan kesimpulan utama...">{{ old('summary') }}</textarea>
                </label>

                <div class="grid two" style="margin-top: 14px">
                    <label>
                        <span>Kekuatan anak</span>
                        <textarea name="strengths" rows="4">{{ old('strengths') }}</textarea>
                    </label>
                    <label>
                        <span>Area yang perlu didukung</span>
                        <textarea name="areas_to_support" rows="4">{{ old('areas_to_support') }}</textarea>
                    </label>
                    <label>
                        <span>Observasi/masukan orang tua</span>
                        <textarea name="parent_observation" rows="4">{{ old('parent_observation') }}</textarea>
                    </label>
                    <label>
                        <span>Tindak lanjut yang disepakati</span>
                        <textarea name="agreed_follow_up" rows="4">{{ old('agreed_follow_up') }}</textarea>
                    </label>
                </div>

                <label style="display:flex; gap:10px; align-items:center; margin-top:16px">
                    <input type="hidden" name="share_with_parent" value="0">
                    <input type="checkbox" name="share_with_parent" value="1" @checked(old('share_with_parent'))>
                    <span>Bagikan ringkasan ini kepada orang tua</span>
                </label>

                <div class="toolbar" style="margin-top: 16px">
                    <button class="btn primary" type="submit">Simpan Family Conference</button>
                </div>
            </form>
        </section>
    @endif

    <section class="panel">
        <div class="section-head">
            <div>
                <h3>Riwayat Family Conference</h3>
                <div class="meta">
                    {{ $isParentView ? 'Hanya catatan yang secara eksplisit dibagikan kepada orang tua yang tampil.' : 'Riwayat kolaborasi keluarga untuk siswa ini.' }}
                </div>
            </div>
        </div>

        @forelse ($conferences as $conference)
            <article class="line-card" style="margin-bottom: 14px">
                <div class="section-head">
                    <div>
                        <strong>{{ $conference->conference_on?->format('d M Y') }}</strong>
                        <div class="meta">
                            Lead: {{ $conference->leadTeacher?->name ?? '-' }}
                            @if ($conference->reportVersion)
                                | {{ $conference->reportVersion->report?->reportCycle?->name ?? 'Published report' }} v{{ $conference->reportVersion->version_number }}
                            @endif
                        </div>
                    </div>
                    <span class="status {{ $conference->share_with_parent ? 'status-published' : 'status-draft' }}">
                        {{ $conference->share_with_parent ? 'Dibagikan ke orang tua' : 'Internal sekolah' }}
                    </span>
                </div>

                <div class="grid two" style="margin-top: 14px">
                    <div>
                        <strong>Ringkasan</strong>
                        <p>{{ $conference->summary ?: '-' }}</p>
                    </div>
                    <div>
                        <strong>Kekuatan</strong>
                        <p>{{ $conference->strengths ?: '-' }}</p>
                    </div>
                    <div>
                        <strong>Area dukungan</strong>
                        <p>{{ $conference->areas_to_support ?: '-' }}</p>
                    </div>
                    <div>
                        <strong>Masukan orang tua</strong>
                        <p>{{ $conference->parent_observation ?: '-' }}</p>
                    </div>
                    <div>
                        <strong>Tindak lanjut disepakati</strong>
                        <p>{{ $conference->agreed_follow_up ?: '-' }}</p>
                    </div>
                    <div>
                        <strong>Review berikutnya</strong>
                        <p>{{ $conference->next_review_on?->format('d M Y') ?? '-' }}</p>
                    </div>
                </div>

                @if ($canManage)
                    <details style="margin-top: 14px">
                        <summary style="cursor:pointer; font-weight:600">Edit catatan</summary>
                        <form method="post" action="{{ route('alpha.family-conferences.update', $conference) }}" style="margin-top: 14px">
                            @csrf
                            @method('PATCH')
                            <div class="grid two">
                                <label>
                                    <span>Tanggal konferensi</span>
                                    <input type="date" name="conference_on" value="{{ $conference->conference_on?->toDateString() }}" required>
                                </label>
                                @if (auth()->user()?->role !== 'teacher')
                                    <label>
                                        <span>Lead teacher</span>
                                        <select name="lead_teacher_id">
                                            <option value="">- Belum ditentukan -</option>
                                            @foreach ($teachers as $teacher)
                                                <option value="{{ $teacher->id }}" @selected((int) $conference->lead_teacher_id === (int) $teacher->id)>{{ $teacher->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                @endif
                                <label>
                                    <span>Konteks published report</span>
                                    <select name="report_version_id">
                                        <option value="">- Tidak terkait report tertentu -</option>
                                        @foreach ($reportVersions as $version)
                                            <option value="{{ $version->id }}" @selected((int) $conference->report_version_id === (int) $version->id)>
                                                {{ $version->report?->reportCycle?->name ?? 'Legacy/Term Report' }} · v{{ $version->version_number }} · {{ $version->published_at?->format('d M Y') }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>
                                    <span>Review berikutnya</span>
                                    <input type="date" name="next_review_on" value="{{ $conference->next_review_on?->toDateString() }}">
                                </label>
                            </div>

                            @foreach ([
                                'summary' => 'Ringkasan',
                                'strengths' => 'Kekuatan anak',
                                'areas_to_support' => 'Area yang perlu didukung',
                                'parent_observation' => 'Observasi/masukan orang tua',
                                'agreed_follow_up' => 'Tindak lanjut yang disepakati',
                            ] as $field => $label)
                                <label style="display:block; margin-top: 12px">
                                    <span>{{ $label }}</span>
                                    <textarea name="{{ $field }}" rows="3">{{ $conference->{$field} }}</textarea>
                                </label>
                            @endforeach

                            <label style="display:flex; gap:10px; align-items:center; margin-top:14px">
                                <input type="hidden" name="share_with_parent" value="0">
                                <input type="checkbox" name="share_with_parent" value="1" @checked($conference->share_with_parent)>
                                <span>Bagikan kepada orang tua</span>
                            </label>

                            <div class="toolbar" style="margin-top: 14px">
                                <button class="btn primary" type="submit">Simpan Perubahan</button>
                            </div>
                        </form>
                    </details>
                @endif
            </article>
        @empty
            <div class="meta">Belum ada family conference yang tersedia.</div>
        @endforelse
    </section>
@endsection

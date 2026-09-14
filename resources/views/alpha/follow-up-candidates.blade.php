@extends('alpha.layout')

@section('title', 'Review Follow-Up - Montessori Bloom')
@section('page_title', 'Review Follow-Up')
@section('page_subtitle', 'Tinjau kandidat tindak lanjut sebelum membuat support plan. Observation tetap evidence; keputusan support tetap milik manusia.')

@section('content')
    @php
        $statusLabel = [
            'open' => 'Menunggu Review',
            'confirmed' => 'Dikonfirmasi',
            'dismissed' => 'Tidak Dilanjutkan',
            'closed' => 'Selesai',
        ];
    @endphp

    <div class="section-head">
        <div>
            <h2>Follow-Up Candidate Queue</h2>
            <div class="meta">Candidate terbuka belum menjadi diagnosis, nilai, atau support plan. Guide/admin harus meninjaunya terlebih dahulu.</div>
        </div>
        <div class="toolbar">
            <a class="btn ghost" href="{{ route('alpha.process.observations') }}">Kembali ke Observasi</a>
            <a class="btn ghost" href="{{ route('alpha.process.ilp') }}">Lihat Support Plan</a>
        </div>
    </div>

    <section class="panel">
        <div class="toolbar" style="margin-bottom: 16px">
            @foreach ($statusOptions as $status)
                <a
                    class="btn {{ $selectedStatus === $status ? 'teal' : 'ghost' }}"
                    href="{{ route('alpha.process.follow-up', ['status' => $status]) }}"
                >
                    {{ $statusLabel[$status] ?? ucfirst($status) }}
                </a>
            @endforeach
        </div>

        @if ($errors->any())
            <div class="line-card" style="margin-bottom: 14px">
                <strong>Review belum dapat disimpan.</strong>
                <div class="meta">{{ $errors->first() }}</div>
            </div>
        @endif

        <div class="card-list">
            @forelse ($candidates as $candidate)
                @php
                    $source = $candidate->sourceObservation;
                    $sourceAreaId = $source?->development_area_id;
                    $candidateIndicators = $sourceAreaId
                        ? $indicators->where('development_area_id', $sourceAreaId)
                        : $indicators;
                    $areaName = $candidate->indicator?->developmentArea?->name
                        ?? $source?->developmentArea?->name
                        ?? '-';
                @endphp

                <article class="line-card" id="follow-up-candidate-{{ $candidate->id }}">
                    <div class="line-head">
                        <div>
                            <strong>{{ $candidate->student->name }}</strong>
                            <div class="meta">
                                {{ $candidate->student->schoolClass->name }}
                                | Evidence {{ $source?->observed_on?->format('d M Y') ?? '-' }}
                                | {{ $source?->teacher?->name ?? 'Guide tidak tercatat' }}
                            </div>
                        </div>
                        <span class="status status-{{ str_replace('_', '-', $candidate->status) }}">
                            {{ $statusLabel[$candidate->status] ?? $candidate->status }}
                        </span>
                    </div>

                    <div class="grid two" style="margin-top: 14px">
                        <div>
                            <div class="meta">Area perkembangan</div>
                            <strong>{{ $areaName }}</strong>
                        </div>
                        <div>
                            <div class="meta">Indikator</div>
                            <strong>{{ $candidate->indicator?->description ?? 'Belum dipilih saat observation' }}</strong>
                        </div>
                    </div>

                    <div style="margin-top: 14px">
                        <div class="meta">Alasan masuk review</div>
                        <div>{{ $candidate->reason_summary }}</div>
                    </div>

                    @if ($source?->note)
                        <div style="margin-top: 14px">
                            <div class="meta">Catatan observation sumber</div>
                            <div>{{ $source->note }}</div>
                        </div>
                    @endif

                    @if ($candidate->status === 'open')
                        @if (in_array($activeRole, ['super_admin', 'admin', 'teacher'], true))
                            <div class="grid two" style="margin-top: 18px">
                                <form method="post" action="{{ route('alpha.follow-up-candidates.confirm', $candidate) }}" class="form-grid">
                                    @csrf

                                    @if (! $candidate->indicator_id)
                                        <div class="field">
                                            <label for="candidate-{{ $candidate->id }}-indicator">Indikator untuk support plan</label>
                                            <select id="candidate-{{ $candidate->id }}-indicator" name="indicator_id" required>
                                                <option value="">Pilih indikator</option>
                                                @foreach ($candidateIndicators as $indicator)
                                                    <option value="{{ $indicator->id }}">
                                                        {{ $indicator->developmentArea?->name }} | {{ $indicator->code }} | {{ $indicator->description }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @if ($candidateIndicators->isEmpty())
                                                <div class="meta">Belum ada indikator aktif yang sesuai dengan area observation ini.</div>
                                            @endif
                                        </div>
                                    @endif

                                    <div class="field">
                                        <label for="candidate-{{ $candidate->id }}-confirm-note">Catatan review</label>
                                        <textarea id="candidate-{{ $candidate->id }}-confirm-note" name="review_note" rows="3" placeholder="Alasan profesional mengapa support plan diperlukan"></textarea>
                                    </div>

                                    <div>
                                        <button class="btn primary" type="submit" @disabled(! $candidate->indicator_id && $candidateIndicators->isEmpty())>
                                            Konfirmasi & Buat Draft Support Plan
                                        </button>
                                    </div>
                                </form>

                                <form method="post" action="{{ route('alpha.follow-up-candidates.dismiss', $candidate) }}" class="form-grid">
                                    @csrf
                                    <div class="field">
                                        <label for="candidate-{{ $candidate->id }}-dismiss-note">Alasan tidak dilanjutkan</label>
                                        <textarea id="candidate-{{ $candidate->id }}-dismiss-note" name="review_note" rows="3" placeholder="Contoh: evidence tunggal, lanjutkan observation biasa terlebih dahulu"></textarea>
                                    </div>
                                    <div>
                                        <button class="btn ghost" type="submit">Tidak Perlu Support Plan</button>
                                    </div>
                                </form>
                            </div>
                        @else
                            <div class="line-card soft" style="margin-top: 14px">
                                <strong>Mode lihat saja.</strong>
                                <div class="meta">Kepala sekolah dapat memantau queue, tetapi keputusan confirm/dismiss tetap dilakukan guide/admin.</div>
                            </div>
                        @endif
                    @else
                        <div class="grid two" style="margin-top: 14px">
                            <div>
                                <div class="meta">Direview oleh</div>
                                <strong>{{ $candidate->reviewedBy?->name ?? '-' }}</strong>
                                <div class="meta">{{ $candidate->reviewed_at?->format('d M Y H:i') ?? '-' }}</div>
                            </div>
                            <div>
                                <div class="meta">Catatan review</div>
                                <div>{{ $candidate->review_note ?: '-' }}</div>
                            </div>
                        </div>

                        @if ($candidate->supportPlan)
                            <div class="toolbar" style="margin-top: 14px">
                                <a class="btn ghost" href="{{ route('alpha.process.ilp') }}#ilp-plan-{{ $candidate->supportPlan->id }}">Buka Draft Support Plan</a>
                            </div>
                        @endif
                    @endif
                </article>
            @empty
                <div class="line-card">
                    <strong>Tidak ada candidate pada status ini.</strong>
                    <div class="meta">Follow-up hanya muncul ketika observation diberi sinyal tindak lanjut secara eksplisit.</div>
                </div>
            @endforelse
        </div>
    </section>
@endsection

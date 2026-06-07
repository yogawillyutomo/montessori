@extends('alpha.layout')

@section('title', 'Master Data - Montessori Bloom')
@section('page_title', 'Master Data')
@section('page_subtitle', 'Kelola periode akademik, kelas, siswa, orangtua, guru, dan indikator perkembangan.')

@section('content')
    <div class="section-head">
        <div>
            <h2>Fondasi Operasional</h2>
            <div class="meta">Data master menjadi referensi untuk jadwal, observasi, ILP, dan rapor.</div>
        </div>
    </div>

    <div class="grid kpi">
        <div class="metric">
            <span>Tahun ajaran</span>
            <strong>{{ $stats['academic_years'] }}</strong>
        </div>
        <div class="metric">
            <span>Kelas aktif</span>
            <strong>{{ $stats['classes'] }}</strong>
        </div>
        <div class="metric">
            <span>Level kelas</span>
            <strong>{{ $stats['levels'] }}</strong>
        </div>
        <div class="metric">
            <span>Siswa terdaftar</span>
            <strong>{{ $stats['students'] }}</strong>
        </div>
        <div class="metric">
            <span>Orangtua / wali</span>
            <strong>{{ $stats['guardians'] }}</strong>
        </div>
        <div class="metric">
            <span>Guru aktif</span>
            <strong>{{ $stats['teachers'] }}</strong>
        </div>
        <div class="metric">
            <span>Indikator</span>
            <strong>{{ $stats['indicators'] }}</strong>
        </div>
    </div>

    @if ($masterSection === 'academic-years')
    <section class="panel master-section" id="periode">
        <div class="line-head period-head">
            <div>
                <h3>Tahun Ajaran dan Periode</h3>
                <div class="meta">Periode ini dipakai untuk ILP, draft rapor, dan publikasi laporan perkembangan.</div>
                @if ($currentTerm)
                    <span class="status status-achieved period-status">{{ $currentTerm->academicYear->name }} - {{ $currentTerm->name }}</span>
                @else
                    <span class="status status-empty period-status">Belum ada periode aktif</span>
                @endif
            </div>
            <div class="action-pair">
                <button class="btn primary" type="button" data-modal-target="modal-create-academic-year">Tambah Tahun Ajaran</button>
                <button class="btn ghost" type="button" data-modal-target="modal-create-term">Tambah Periode</button>
            </div>
        </div>

        <div class="split-grid">
            <div class="card-list">
                @forelse ($academicYears as $year)
                    <article class="line-card">
                        <div class="line-head">
                            <div>
                                <strong>{{ $year->name }}</strong>
                                <div class="meta">{{ $year->starts_on->format('d M Y') }} - {{ $year->ends_on->format('d M Y') }}</div>
                            </div>
                            <span class="status {{ $year->is_active ? 'status-achieved' : 'status-empty' }}">{{ $year->is_active ? 'Aktif' : 'Arsip' }}</span>
                        </div>
                        <div class="toolbar compact-actions">
                            @unless ($year->is_active)
                                <form method="post" action="{{ route('alpha.master.academic-years.activate', $year) }}">
                                    @csrf
                                    @method('patch')
                                    <button class="btn ghost" type="submit">Aktifkan</button>
                                </form>
                            @endunless
                            <button class="btn ghost" type="button" data-modal-target="modal-edit-year-{{ $year->id }}">Edit</button>
                            <dialog class="modal" id="modal-edit-year-{{ $year->id }}">
                                <form method="post" action="{{ route('alpha.master.academic-years.update', $year) }}">
                                    @csrf
                                    @method('patch')
                                    <div class="modal-head">
                                        <div>
                                            <h3>Edit Tahun Ajaran</h3>
                                            <div class="meta">{{ $year->name }}</div>
                                        </div>
                                        <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                                    </div>
                                    <div class="form-grid">
                                        <div class="field wide">
                                            <label>Nama</label>
                                            <input name="name" value="{{ old('name', $year->name) }}">
                                        </div>
                                    </div>
                                    <div class="date-grid">
                                        <div class="field">
                                            <label>Mulai</label>
                                            <input type="date" name="starts_on" value="{{ old('starts_on', $year->starts_on->toDateString()) }}">
                                        </div>
                                        <div class="field">
                                            <label>Selesai</label>
                                            <input type="date" name="ends_on" value="{{ old('ends_on', $year->ends_on->toDateString()) }}">
                                        </div>
                                    </div>
                                    <label class="check-row">
                                        <input type="checkbox" name="is_active" value="1" @checked($year->is_active)>
                                        <span>Aktif</span>
                                    </label>
                                    <div class="toolbar modal-actions">
                                        <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                        <button class="btn primary" type="submit">Update</button>
                                    </div>
                                </form>
                            </dialog>
                            <button class="btn danger" type="button" data-delete-action="{{ route('alpha.master.academic-years.destroy', $year) }}" data-delete-label="Hapus tahun ajaran {{ $year->name }}? Data tidak bisa dihapus jika masih memiliki periode.">Hapus</button>
                        </div>
                        <div class="card-list" style="margin-top: 12px">
                            @forelse ($year->terms as $term)
                                <div class="line-card soft">
                                    <div class="line-head">
                                        <div>
                                            <strong>{{ $term->name }}</strong>
                                            <div class="meta">{{ $term->starts_on->format('d M Y') }} - {{ $term->ends_on->format('d M Y') }}</div>
                                        </div>
                                        <span class="status {{ $term->is_current ? 'status-achieved' : 'status-empty' }}">{{ $term->is_current ? 'Berjalan' : 'Arsip' }}</span>
                                    </div>
                                    <div class="toolbar compact-actions">
                                        @unless ($term->is_current)
                                            <form method="post" action="{{ route('alpha.master.terms.activate', $term) }}">
                                                @csrf
                                                @method('patch')
                                                <button class="btn ghost" type="submit">Jadikan berjalan</button>
                                            </form>
                                        @endunless
                                        <button class="btn ghost" type="button" data-modal-target="modal-edit-term-{{ $term->id }}">Edit</button>
                                        <dialog class="modal" id="modal-edit-term-{{ $term->id }}">
                                            <form method="post" action="{{ route('alpha.master.terms.update', $term) }}">
                                                @csrf
                                                @method('patch')
                                                <div class="modal-head">
                                                    <div>
                                                        <h3>Edit Periode</h3>
                                                        <div class="meta">{{ $term->name }} - {{ $year->name }}</div>
                                                    </div>
                                                    <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                                                </div>
                                                <div class="form-grid">
                                                    <div class="field wide">
                                                        <label>Tahun ajaran</label>
                                                        <select name="academic_year_id">
                                                            @foreach ($academicYears as $optionYear)
                                                                <option value="{{ $optionYear->id }}" @selected($optionYear->id === $term->academic_year_id)>{{ $optionYear->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="field wide">
                                                        <label>Nama periode</label>
                                                        <input name="name" value="{{ old('name', $term->name) }}">
                                                    </div>
                                                </div>
                                                <div class="date-grid">
                                                    <div class="field">
                                                        <label>Mulai</label>
                                                        <input type="date" name="starts_on" value="{{ old('starts_on', $term->starts_on->toDateString()) }}">
                                                    </div>
                                                    <div class="field">
                                                        <label>Selesai</label>
                                                        <input type="date" name="ends_on" value="{{ old('ends_on', $term->ends_on->toDateString()) }}">
                                                    </div>
                                                </div>
                                                <label class="check-row">
                                                    <input type="checkbox" name="is_current" value="1" @checked($term->is_current)>
                                                    <span>Periode berjalan</span>
                                                </label>
                                                <div class="toolbar modal-actions">
                                                    <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                                    <button class="btn primary" type="submit">Update</button>
                                                </div>
                                            </form>
                                        </dialog>
                                        <button class="btn danger" type="button" data-delete-action="{{ route('alpha.master.terms.destroy', $term) }}" data-delete-label="Hapus periode {{ $term->name }}? Data tidak bisa dihapus jika sudah dipakai rapor atau ILP.">Hapus</button>
                                    </div>
                                </div>
                            @empty
                                <div class="meta">Belum ada periode.</div>
                            @endforelse
                        </div>
                    </article>
                @empty
                    <div class="line-card muted">Belum ada tahun ajaran.</div>
                @endforelse
            </div>

            <aside class="panel inset-panel">
                <h4>Aturan Periode</h4>
                <div class="card-list">
                    <div class="line-card soft">Hanya satu tahun ajaran yang bisa aktif.</div>
                    <div class="line-card soft">Hanya satu periode yang bisa berjalan.</div>
                    <div class="line-card soft">Tanggal periode harus berada di dalam rentang tahun ajaran.</div>
                </div>
            </aside>
        </div>
    </section>
    <dialog class="modal" id="modal-create-academic-year">
        <form method="post" action="{{ route('alpha.master.academic-years.store') }}">
            @csrf
            <div class="modal-head">
                <div>
                    <h3>Tambah Tahun Ajaran</h3>
                    <div class="meta">Buat rentang akademik dasar untuk periode, ILP, dan rapor.</div>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
            </div>
            <div class="form-grid">
                <div class="field wide">
                    <label for="academic-year-name">Nama</label>
                    <input id="academic-year-name" name="name" value="{{ old('name') }}" placeholder="2026/2027">
                </div>
            </div>
            <div class="date-grid">
                <div class="field">
                    <label for="academic-year-start">Mulai</label>
                    <input id="academic-year-start" type="date" name="starts_on" value="{{ old('starts_on') }}">
                </div>
                <div class="field">
                    <label for="academic-year-end">Selesai</label>
                    <input id="academic-year-end" type="date" name="ends_on" value="{{ old('ends_on') }}">
                </div>
            </div>
            <label class="check-row">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active'))>
                <span>Jadikan tahun ajaran aktif</span>
            </label>
            <div class="toolbar modal-actions">
                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                <button class="btn primary" type="submit">Simpan Tahun Ajaran</button>
            </div>
        </form>
    </dialog>

    <dialog class="modal" id="modal-create-term">
        <form method="post" action="{{ route('alpha.master.terms.store') }}">
            @csrf
            <div class="modal-head">
                <div>
                    <h3>Tambah Periode</h3>
                    <div class="meta">Tanggal periode wajib berada di dalam rentang tahun ajaran.</div>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
            </div>
            <div class="form-grid">
                <div class="field wide">
                    <label for="term-year">Tahun ajaran</label>
                    <select id="term-year" name="academic_year_id">
                        @foreach ($academicYears as $year)
                            <option value="{{ $year->id }}" @selected((int) old('academic_year_id') === $year->id)>{{ $year->name }} ({{ $year->starts_on->format('d M Y') }} - {{ $year->ends_on->format('d M Y') }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="field wide">
                    <label for="term-name">Nama periode</label>
                    <input id="term-name" name="name" value="{{ old('name') }}" placeholder="Periode 1">
                </div>
            </div>
            <div class="date-grid">
                <div class="field">
                    <label for="term-start">Mulai</label>
                    <input id="term-start" type="date" name="starts_on" value="{{ old('starts_on') }}">
                </div>
                <div class="field">
                    <label for="term-end">Selesai</label>
                    <input id="term-end" type="date" name="ends_on" value="{{ old('ends_on') }}">
                </div>
            </div>
            <label class="check-row">
                <input type="checkbox" name="is_current" value="1" @checked(old('is_current'))>
                <span>Jadikan periode berjalan</span>
            </label>
            <div class="toolbar modal-actions">
                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                <button class="btn primary" type="submit">Simpan Periode</button>
            </div>
        </form>
    </dialog>
    @endif

    @if ($masterSection === 'levels')
    <section class="panel master-section" id="level">
        <div class="line-head">
            <div>
                <h3>Level Kelas</h3>
                <div class="meta">Level menyimpan kelompok usia, urutan level, dan warna visual untuk kelas seperti Sunny 1 atau Sunny 2.</div>
            </div>
            <button class="btn primary" type="button" data-modal-target="modal-create-level">Tambah Level</button>
        </div>

        <div class="grid two" data-card-list data-card-page-size="6" data-card-search-placeholder="Cari level">
            @forelse ($classLevels as $level)
                <article class="line-card" data-card-item>
                    <div class="line-head">
                        <div>
                            <span class="dot {{ $level->color }}"></span>
                            <strong>{{ $level->name }}</strong>
                            <div class="meta">Level {{ $level->sequence }} | {{ $level->age_range_years_label }} ({{ $level->age_range_label }})</div>
                        </div>
                        <span class="status {{ $level->is_active ? 'status-achieved' : 'status-empty' }}">{{ $level->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                    </div>
                    <div class="mini-grid">
                        <div>
                            <span class="meta">Kelas</span>
                            <strong>{{ $level->school_classes_count }}</strong>
                        </div>
                        <div>
                            <span class="meta">Warna</span>
                            <strong>{{ ucfirst($level->color) }}</strong>
                        </div>
                    </div>
                    <div class="toolbar compact-actions">
                        <form method="post" action="{{ route('alpha.master.levels.toggle', $level) }}">
                            @csrf
                            @method('patch')
                            <button class="btn ghost" type="submit">{{ $level->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                        </form>
                        <button class="btn ghost" type="button" data-modal-target="modal-edit-level-{{ $level->id }}">Edit</button>
                        <dialog class="modal" id="modal-edit-level-{{ $level->id }}">
                            <form method="post" action="{{ route('alpha.master.levels.update', $level) }}">
                                @csrf
                                @method('patch')
                                <div class="modal-head">
                                    <div>
                                        <h3>Edit Level</h3>
                                        <div class="meta">{{ $level->name }}</div>
                                    </div>
                                    <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                                </div>
                                <div class="form-grid">
                                    <div class="field">
                                        <label>Nama level</label>
                                        <input name="name" value="{{ old('name', $level->name) }}">
                                    </div>
                                    <div class="field">
                                        <label>Urutan level</label>
                                        <input type="number" min="0" name="sequence" value="{{ old('sequence', $level->sequence) }}">
                                    </div>
                                    <div class="field">
                                        <label>Usia minimum (tahun)</label>
                                        <input name="min_age_years" inputmode="decimal" value="{{ old('min_age_years', $level->min_age_years) }}" placeholder="2.5" data-age-years-input>
                                        <div class="meta" data-age-months-preview></div>
                                    </div>
                                    <div class="field">
                                        <label>Usia maksimum (tahun)</label>
                                        <input name="max_age_years" inputmode="decimal" value="{{ old('max_age_years', $level->max_age_years) }}" placeholder="4" data-age-years-input>
                                        <div class="meta" data-age-months-preview></div>
                                    </div>
                                    <div class="field">
                                        <label>Warna</label>
                                        <select name="color">
                                            @foreach (['sage', 'teal', 'coral', 'blue', 'gold', 'plum'] as $color)
                                                <option value="{{ $color }}" @selected($level->color === $color)>{{ ucfirst($color) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label>Slug opsional</label>
                                        <input name="slug" value="{{ old('slug', $level->slug) }}">
                                    </div>
                                </div>
                                <div class="toolbar modal-actions">
                                    <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                    <button class="btn primary" type="submit">Update</button>
                                </div>
                            </form>
                        </dialog>
                        <button class="btn danger" type="button" data-delete-action="{{ route('alpha.master.levels.destroy', $level) }}" data-delete-label="Hapus level {{ $level->name }}? Level tidak bisa dihapus jika masih dipakai kelas.">Hapus</button>
                    </div>
                </article>
            @empty
                <div class="line-card muted">Belum ada level kelas.</div>
            @endforelse
        </div>
    </section>
    <dialog class="modal" id="modal-create-level">
        <form method="post" action="{{ route('alpha.master.levels.store') }}">
            @csrf
            <div class="modal-head">
                <div>
                    <h3>Tambah Level</h3>
                    <div class="meta">Contoh: Sunny, urutan level 1, usia 2.5-4 tahun. Sistem otomatis menghitung bulan.</div>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="level-name">Nama level</label>
                    <input id="level-name" name="name" value="{{ old('name') }}" placeholder="Sunny">
                </div>
                <div class="field">
                    <label for="level-sequence">Urutan level</label>
                    <input id="level-sequence" type="number" min="0" name="sequence" value="{{ old('sequence', 1) }}">
                </div>
                <div class="field">
                    <label for="level-min-age">Usia minimum (tahun)</label>
                    <input id="level-min-age" name="min_age_years" inputmode="decimal" value="{{ old('min_age_years') }}" placeholder="2.5" data-age-years-input>
                    <div class="meta" data-age-months-preview></div>
                </div>
                <div class="field">
                    <label for="level-max-age">Usia maksimum (tahun)</label>
                    <input id="level-max-age" name="max_age_years" inputmode="decimal" value="{{ old('max_age_years') }}" placeholder="4" data-age-years-input>
                    <div class="meta" data-age-months-preview></div>
                </div>
                <div class="field">
                    <label for="level-color">Warna</label>
                    <select id="level-color" name="color">
                        @foreach (['sage', 'teal', 'coral', 'blue', 'gold', 'plum'] as $color)
                            <option value="{{ $color }}" @selected(old('color', 'sage') === $color)>{{ ucfirst($color) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="level-slug">Slug opsional</label>
                    <input id="level-slug" name="slug" value="{{ old('slug') }}" placeholder="auto">
                </div>
            </div>
            <div class="toolbar modal-actions">
                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                <button class="btn primary" type="submit">Simpan Level</button>
            </div>
        </form>
    </dialog>
    @endif

    @if ($masterSection === 'classes')
    <section class="panel master-section" id="kelas">
        <div class="line-head">
            <div>
                <h3>Kelas</h3>
                <div class="meta">Kelas adalah rombel seperti Sunny 1 atau Sunny 2 yang mengacu ke master level.</div>
            </div>
            <button class="btn primary" type="button" data-modal-target="modal-create-class">Tambah Kelas</button>
        </div>

        <div class="split-grid class-split">
            <div class="card-list-column">
                <div class="grid three class-grid" data-card-list data-card-page-size="6" data-card-search-placeholder="Cari kelas">
                    @forelse ($classes as $class)
                        @php
                            $level = $class->classLevel;
                        @endphp
                        <article class="line-card class-card" data-card-item>
                        <div class="line-head">
                            <div>
                                <span class="dot {{ $level?->color ?? $class->color }}"></span>
                                <strong>{{ $class->name }}</strong>
                            </div>
                            <span class="status {{ $class->is_active ? 'status-achieved' : 'status-empty' }}">{{ $class->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                        </div>
                        <div class="meta">{{ $level?->name ?? $class->level }} | {{ $level?->age_range_years_label ?? $class->age_range ?? 'Rentang usia belum diisi' }}</div>
                        <div class="mini-grid">
                            <div>
                                <span class="meta">Siswa</span>
                                <strong>{{ $class->students_count }} / {{ $class->capacity }}</strong>
                            </div>
                            <div>
                                <span class="meta">Jadwal</span>
                                <strong>{{ $class->weekly_schedules_count }}</strong>
                            </div>
                        </div>
                        <div class="toolbar compact-actions">
                            <form method="post" action="{{ route('alpha.master.classes.toggle', $class) }}">
                                @csrf
                                @method('patch')
                                <button class="btn ghost" type="submit">{{ $class->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                        </form>
                            <button class="btn ghost" type="button" data-modal-target="modal-edit-class-{{ $class->id }}">Edit</button>
                            <button class="btn ghost" type="button" data-modal-target="modal-copy-class-{{ $class->id }}">Salin</button>
                            <dialog class="modal" id="modal-copy-class-{{ $class->id }}">
                                <form method="post" action="{{ route('alpha.master.classes.copy', $class) }}">
                                    @csrf
                                    <div class="modal-head">
                                        <div>
                                            <h3>Salin Kelas</h3>
                                            <div class="meta">Menyalin level, kapasitas, warna, dan status dari {{ $class->name }}.</div>
                                        </div>
                                        <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                                    </div>
                                    <div class="form-grid">
                                        <div class="field">
                                            <label>Nama kelas baru</label>
                                            <input name="name" value="{{ old('name') }}" placeholder="{{ $level?->name ?? $class->level }} 2">
                                        </div>
                                        <div class="field">
                                            <label>Slug opsional</label>
                                            <input name="slug" value="{{ old('slug') }}" placeholder="auto">
                                        </div>
                                        <div class="field wide">
                                            <div class="meta">Setelah disalin, detail kelas tetap bisa diedit dari tombol Edit.</div>
                                        </div>
                                    </div>
                                    <div class="toolbar modal-actions">
                                        <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                        <button class="btn primary" type="submit">Salin Kelas</button>
                                    </div>
                                </form>
                            </dialog>
                            <dialog class="modal" id="modal-edit-class-{{ $class->id }}">
                                <form method="post" action="{{ route('alpha.master.classes.update', $class) }}">
                                    @csrf
                                    @method('patch')
                                    <div class="modal-head">
                                        <div>
                                            <h3>Edit Kelas</h3>
                                            <div class="meta">{{ $class->name }} - {{ $level?->name ?? $class->level }}</div>
                                        </div>
                                        <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                                    </div>
                                    <div class="form-grid">
                                        <div class="field">
                                            <label>Nama kelas</label>
                                            <input name="name" value="{{ old('name', $class->name) }}">
                                        </div>
                                        <div class="field">
                                            <label>Kapasitas</label>
                                            <input type="number" min="1" name="capacity" value="{{ old('capacity', $class->capacity) }}">
                                        </div>
                                        <div class="field">
                                            <label>Level</label>
                                            <select name="class_level_id">
                                                @foreach ($classLevels as $optionLevel)
                                                    <option value="{{ $optionLevel->id }}" @selected((int) old('class_level_id', $class->class_level_id) === $optionLevel->id)>{{ $optionLevel->name }} - {{ $optionLevel->age_range_years_label }} ({{ $optionLevel->age_range_label }})</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="field">
                                            <label>Slug</label>
                                            <input name="slug" value="{{ old('slug', $class->slug) }}">
                                        </div>
                                    </div>
                                    <div class="toolbar modal-actions">
                                        <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                        <button class="btn primary" type="submit">Update</button>
                                    </div>
                                </form>
                            </dialog>
                            <button class="btn danger" type="button" data-delete-action="{{ route('alpha.master.classes.destroy', $class) }}" data-delete-label="Hapus kelas {{ $class->name }}? Data tidak bisa dihapus jika sudah dipakai siswa, jadwal, atau presensi.">Hapus</button>
                        </div>
                        </article>
                    @empty
                        <div class="line-card muted">Belum ada kelas.</div>
                    @endforelse
                </div>
            </div>

            <aside class="panel inset-panel">
                <h4>Ringkasan Kelas</h4>
                <div class="card-list">
                    <div class="line-card soft">Gunakan kapasitas untuk memantau rasio siswa per kelas.</div>
                    <div class="line-card soft">Warna berasal dari master Level untuk membantu membedakan kelompok di kartu, jadwal, dan laporan.</div>
                </div>
            </aside>
        </div>
    </section>
    <dialog class="modal" id="modal-create-class">
        <form method="post" action="{{ route('alpha.master.classes.store') }}">
            @csrf
            <div class="modal-head">
                <div>
                    <h3>Tambah Kelas</h3>
                    <div class="meta">Buat ruang belajar dan kapasitas operasional kelas.</div>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="class-name">Nama kelas</label>
                    <input id="class-name" name="name" value="{{ old('name') }}" placeholder="Sunny 1">
                </div>
                <div class="field">
                    <label for="class-level">Level</label>
                    <select id="class-level" name="class_level_id">
                        @foreach ($classLevels as $level)
                            <option value="{{ $level->id }}" @selected((int) old('class_level_id') === $level->id)>{{ $level->name }} - {{ $level->age_range_years_label }} ({{ $level->age_range_label }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="class-capacity">Kapasitas</label>
                    <input id="class-capacity" type="number" min="1" name="capacity" value="{{ old('capacity', 12) }}">
                </div>
                <div class="field">
                    <label for="class-slug">Slug opsional</label>
                    <input id="class-slug" name="slug" value="{{ old('slug') }}" placeholder="auto">
                </div>
                <div class="field wide">
                    <div class="meta">Rentang usia dan warna mengikuti master Level yang dipilih.</div>
                </div>
            </div>
            <div class="toolbar modal-actions">
                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                <button class="btn primary" type="submit">Simpan Kelas</button>
            </div>
        </form>
    </dialog>
    @endif

    @if ($masterSection === 'students')
    <section class="panel master-section" id="siswa">
        <div class="line-head">
            <div>
                <h3>Siswa dan Orangtua</h3>
                <div class="meta">Saat siswa baru dibuat, wali baru juga bisa dibuat dari form yang sama.</div>
            </div>
            <div class="page-actions">
                <button class="btn ghost" type="button" data-modal-target="modal-import-students">Import Excel</button>
                <button class="btn primary" type="button" data-modal-target="modal-create-student">Tambah Siswa</button>
            </div>
        </div>

        <dialog class="modal wide-modal" id="modal-create-student">
        <form method="post" action="{{ route('alpha.master.students.store') }}">
            @csrf
            <div class="modal-head">
                <div>
                    <h3>Tambah Siswa</h3>
                    <div class="meta">Data wali baru dapat diisi sekaligus dari form ini.</div>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="student-code">Kode</label>
                    <input id="student-code" name="code" value="{{ old('code') }}" placeholder="SUN04">
                </div>
                <div class="field">
                    <label for="student-name">Nama siswa</label>
                    <input id="student-name" name="name" value="{{ old('name') }}" placeholder="Nama lengkap">
                </div>
                <div class="field">
                    <label for="student-class">Kelas</label>
                    <select id="student-class" name="school_class_id">
                        @foreach ($classes as $class)
                            <option value="{{ $class->id }}" @selected((int) old('school_class_id') === $class->id)>{{ $class->name }} - {{ $class->classLevel?->name ?? $class->level }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="student-status">Status</label>
                    <select id="student-status" name="status">
                        @foreach (['active' => 'Aktif', 'inactive' => 'Tidak aktif', 'graduated' => 'Lulus'] as $key => $label)
                            <option value="{{ $key }}" @selected(old('status', 'active') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="student-gender">Gender</label>
                    <input id="student-gender" name="gender" value="{{ old('gender') }}" placeholder="Perempuan / Laki-laki">
                </div>
                <div class="field">
                    <label for="student-birth-date">Tanggal lahir</label>
                    <input id="student-birth-date" type="date" name="birth_date" value="{{ old('birth_date') }}">
                </div>
                <div class="field">
                    <label for="student-birth-place">Tempat lahir</label>
                    <input id="student-birth-place" name="birth_place" value="{{ old('birth_place') }}" placeholder="Banyumas">
                </div>
                <div class="field">
                    <label for="student-guardian">Wali yang sudah ada</label>
                    <select id="student-guardian" name="guardian_id">
                        <option value="">Buat wali baru / tanpa wali</option>
                        @foreach ($guardians as $guardian)
                            <option value="{{ $guardian->id }}" @selected((int) old('guardian_id') === $guardian->id)>{{ $guardian->name }} ({{ $guardian->students_count }} siswa)</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="guardian-name">Nama wali baru</label>
                    <input id="guardian-name" name="guardian_name" value="{{ old('guardian_name') }}" placeholder="Orangtua / wali">
                </div>
                <div class="field">
                    <label for="guardian-relationship">Relasi</label>
                    <input id="guardian-relationship" name="guardian_relationship" value="{{ old('guardian_relationship') }}" placeholder="Ibu / Ayah / Wali">
                </div>
                <div class="field">
                    <label for="guardian-phone">Telepon wali</label>
                    <input id="guardian-phone" name="guardian_phone" value="{{ old('guardian_phone') }}" placeholder="08xx">
                </div>
                <div class="field">
                    <label for="guardian-email">Email wali</label>
                    <input id="guardian-email" type="email" name="guardian_email" value="{{ old('guardian_email') }}" placeholder="parent@email.test">
                </div>
                <div class="field wide">
                    <label for="guardian-address">Alamat wali</label>
                    <input id="guardian-address" name="guardian_address" value="{{ old('guardian_address') }}" placeholder="Alamat singkat">
                </div>
                <div class="field wide">
                    <label for="medical-note">Catatan kesehatan / kebutuhan khusus</label>
                    <textarea id="medical-note" name="medical_note" placeholder="Opsional">{{ old('medical_note') }}</textarea>
                </div>
            </div>
            <div class="toolbar modal-actions">
                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                <button class="btn primary" type="submit">Simpan Siswa</button>
            </div>
        </form>
        </dialog>

        <dialog class="modal" id="modal-import-students">
            <form method="post" action="{{ route('alpha.master.students.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="modal-head">
                    <div>
                        <h3>Import Siswa</h3>
                        <div class="meta">Gunakan file .xlsx dengan baris pertama sebagai header kolom.</div>
                    </div>
                    <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                </div>
                <div class="field">
                    <label for="student-import-file">File Excel</label>
                    <input id="student-import-file" type="file" name="file" accept=".xlsx">
                </div>
                <div class="import-notes meta">
                    <div>Kolom wajib: kode, nama, kelas.</div>
                    <div>Kolom opsional: status, gender, tempat_lahir, tanggal_lahir, nama_wali, relasi_wali, telepon_wali, email_wali, alamat_wali, catatan.</div>
                </div>
                <div class="toolbar modal-actions">
                    <a class="btn ghost" href="{{ route('alpha.master.import-template', 'students') }}">Download Format</a>
                    <button class="btn ghost" type="button" data-modal-close>Batal</button>
                    <button class="btn primary" type="submit">Import Siswa</button>
                </div>
            </form>
        </dialog>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama</th>
                    <th>Kelas</th>
                    <th>Usia</th>
                    <th>Orangtua</th>
                    <th>Kontak</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($students as $student)
                    <tr>
                        <td>{{ $student->code }}</td>
                        <td>
                            <strong>{{ $student->name }}</strong>
                            <div class="meta">{{ $student->gender ?? '-' }} | {{ $student->birth_place ?? '-' }}</div>
                        </td>
                        <td>{{ $student->schoolClass->name }}</td>
                        <td>{{ $student->age_label }}</td>
                        <td>
                            @if ($student->guardian)
                                <strong>{{ $student->guardian->name }}</strong>
                                <div class="meta">{{ $student->guardian->relationship }} untuk {{ $student->name }}</div>
                                @php
                                    $relatedStudents = $student->guardian->students->where('id', '!=', $student->id);
                                @endphp
                                @if ($relatedStudents->isNotEmpty())
                                    <div class="relation-chips">
                                        <span class="meta">Siswa lain:</span>
                                        @foreach ($relatedStudents as $relatedStudent)
                                            <span class="chip">{{ $relatedStudent->name }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            {{ $student->guardian?->phone ?? '-' }}
                            <div class="meta">{{ $student->guardian?->email ?? '' }}</div>
                        </td>
                        <td><span class="status {{ $student->status === 'active' ? 'status-achieved' : 'status-empty' }}">{{ ucfirst($student->status) }}</span></td>
                        <td>
                            <div class="toolbar compact-actions">
                                <form method="post" action="{{ route('alpha.master.students.toggle', $student) }}">
                                    @csrf
                                    @method('patch')
                                    <button class="btn ghost" type="submit">{{ $student->status === 'active' ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                </form>
                                <button class="btn ghost" type="button" data-modal-target="modal-edit-student-{{ $student->id }}">Edit</button>
                                <dialog class="modal wide-modal" id="modal-edit-student-{{ $student->id }}">
                                    <form method="post" action="{{ route('alpha.master.students.update', $student) }}">
                                        @csrf
                                        @method('patch')
                                        <div class="modal-head">
                                            <div>
                                                <h3>Edit Siswa</h3>
                                                <div class="meta">{{ $student->name }} - {{ $student->code }}</div>
                                            </div>
                                            <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                                        </div>
                                        <div class="form-grid">
                                            <div class="field">
                                                <label>Kode</label>
                                                <input name="code" value="{{ old('code', $student->code) }}">
                                            </div>
                                            <div class="field">
                                                <label>Nama siswa</label>
                                                <input name="name" value="{{ old('name', $student->name) }}">
                                            </div>
                                            <div class="field">
                                                <label>Kelas</label>
                                                <select name="school_class_id">
                                                    @foreach ($classes as $class)
                                                            <option value="{{ $class->id }}" @selected($student->school_class_id === $class->id)>{{ $class->name }} - {{ $class->classLevel?->name ?? $class->level }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="field">
                                                <label>Wali</label>
                                                <select name="guardian_id">
                                                    <option value="">Tanpa wali</option>
                                                    @foreach ($guardians as $guardian)
                                                        <option value="{{ $guardian->id }}" @selected($student->guardian_id === $guardian->id)>{{ $guardian->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="field">
                                                <label>Status</label>
                                                <select name="status">
                                                    @foreach (['active' => 'Aktif', 'inactive' => 'Tidak aktif', 'graduated' => 'Lulus'] as $key => $label)
                                                        <option value="{{ $key }}" @selected($student->status === $key)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="field">
                                                <label>Gender</label>
                                                <input name="gender" value="{{ old('gender', $student->gender) }}">
                                            </div>
                                            <div class="field">
                                                <label>Tempat lahir</label>
                                                <input name="birth_place" value="{{ old('birth_place', $student->birth_place) }}">
                                            </div>
                                            <div class="field">
                                                <label>Tanggal lahir</label>
                                                <input type="date" name="birth_date" value="{{ old('birth_date', $student->birth_date?->toDateString()) }}">
                                            </div>
                                            <div class="field wide">
                                                <label>Catatan kesehatan</label>
                                                <textarea name="medical_note">{{ old('medical_note', $student->medical_notes['note'] ?? '') }}</textarea>
                                            </div>
                                        </div>
                                        <div class="toolbar modal-actions">
                                            <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                            <button class="btn primary" type="submit">Update</button>
                                        </div>
                                    </form>
                                </dialog>
                                <button class="btn danger" type="button" data-delete-action="{{ route('alpha.master.students.destroy', $student) }}" data-delete-label="Hapus siswa {{ $student->name }}? Data tidak bisa dihapus jika sudah memiliki observasi, ILP, rapor, atau presensi.">Hapus</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">Belum ada siswa.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <section class="panel inset-panel">
            <h4>Orangtua / Wali</h4>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Relasi</th>
                        <th>Kontak</th>
                        <th>Siswa</th>
                        <th>Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($guardians as $guardian)
                        <tr>
                            <td><strong>{{ $guardian->name }}</strong><br><span class="meta">{{ $guardian->address ?? '-' }}</span></td>
                            <td>{{ $guardian->relationship }}</td>
                            <td>{{ $guardian->phone ?? '-' }}<br><span class="meta">{{ $guardian->email ?? '' }}</span></td>
                            <td>
                                @if ($guardian->students->isNotEmpty())
                                    <div class="relation-list">
                                        @foreach ($guardian->students as $guardianStudent)
                                            <div>
                                                <strong>{{ $guardianStudent->name }}</strong>
                                                <span class="meta">({{ $guardianStudent->schoolClass?->name ?? '-' }})</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="meta">Belum terhubung ke siswa.</span>
                                @endif
                            </td>
                            <td>
                                <div class="toolbar compact-actions">
                                    <button class="btn ghost" type="button" data-modal-target="modal-edit-guardian-{{ $guardian->id }}">Edit</button>
                                    <dialog class="modal" id="modal-edit-guardian-{{ $guardian->id }}">
                                        <form method="post" action="{{ route('alpha.master.guardians.update', $guardian) }}">
                                            @csrf
                                            @method('patch')
                                            <div class="modal-head">
                                                <div>
                                                    <h3>Edit Orangtua / Wali</h3>
                                                    <div class="meta">{{ $guardian->name }}</div>
                                                </div>
                                                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                                            </div>
                                            <div class="form-grid">
                                                <div class="field">
                                                    <label>Nama</label>
                                                    <input name="name" value="{{ old('name', $guardian->name) }}">
                                                </div>
                                                <div class="field">
                                                    <label>Relasi</label>
                                                    <input name="relationship" value="{{ old('relationship', $guardian->relationship) }}">
                                                </div>
                                                <div class="field">
                                                    <label>Telepon</label>
                                                    <input name="phone" value="{{ old('phone', $guardian->phone) }}">
                                                </div>
                                                <div class="field">
                                                    <label>Email</label>
                                                    <input type="email" name="email" value="{{ old('email', $guardian->email) }}">
                                                </div>
                                                <div class="field wide">
                                                    <label>Alamat</label>
                                                    <input name="address" value="{{ old('address', $guardian->address) }}">
                                                </div>
                                            </div>
                                            <div class="toolbar modal-actions">
                                                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                                <button class="btn primary" type="submit">Update</button>
                                            </div>
                                        </form>
                                    </dialog>
                                    <button class="btn danger" type="button" data-delete-action="{{ route('alpha.master.guardians.destroy', $guardian) }}" data-delete-label="Hapus orangtua/wali {{ $guardian->name }}? Data tidak bisa dihapus jika masih terhubung ke siswa.">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Belum ada orangtua/wali.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </section>
    @endif

    @if ($masterSection === 'teachers')
    <section class="panel master-section" id="guru">
        <div class="line-head">
            <div>
                <h3>Guru</h3>
                <div class="meta">Guru dipakai sebagai penanggung jawab jadwal, presensi, observasi, dan homeroom rapor.</div>
            </div>
            <div class="page-actions">
                <button class="btn ghost" type="button" data-modal-target="modal-import-teachers">Import Excel</button>
                <button class="btn primary" type="button" data-modal-target="modal-create-teacher">Tambah Guru</button>
            </div>
        </div>

        <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Fokus</th>
                        <th>Jadwal</th>
                        <th>Presensi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($teachers as $teacher)
                        <tr>
                            <td>{{ $teacher->code }}</td>
                            <td>
                                <strong>{{ $teacher->name }}</strong>
                                <div class="meta">{{ $teacher->phone ?? '-' }}</div>
                            </td>
                            <td>{{ $teacher->focus_area ?? '-' }}</td>
                            <td>{{ $teacher->weekly_schedules_count }}</td>
                            <td>{{ $teacher->class_sessions_count }}</td>
                            <td><span class="status {{ $teacher->is_active ? 'status-achieved' : 'status-empty' }}">{{ $teacher->is_active ? 'Aktif' : 'Tidak aktif' }}</span></td>
                            <td>
                                <div class="toolbar compact-actions">
                                    <form method="post" action="{{ route('alpha.master.teachers.toggle', $teacher) }}">
                                        @csrf
                                        @method('patch')
                                        <button class="btn ghost" type="submit">{{ $teacher->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                    </form>
                                    <button class="btn ghost" type="button" data-modal-target="modal-edit-teacher-{{ $teacher->id }}">Edit</button>
                                    <dialog class="modal" id="modal-edit-teacher-{{ $teacher->id }}">
                                        <form method="post" action="{{ route('alpha.master.teachers.update', $teacher) }}">
                                            @csrf
                                            @method('patch')
                                            <div class="modal-head">
                                                <div>
                                                    <h3>Edit Guru</h3>
                                                    <div class="meta">{{ $teacher->name }} - {{ $teacher->code }}</div>
                                                </div>
                                                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                                            </div>
                                            <div class="form-grid">
                                                <div class="field">
                                                    <label>Kode</label>
                                                    <input name="code" value="{{ old('code', $teacher->code) }}">
                                                </div>
                                                <div class="field">
                                                    <label>Nama</label>
                                                    <input name="name" value="{{ old('name', $teacher->name) }}">
                                                </div>
                                                <div class="field">
                                                    <label>Fokus area</label>
                                                    <input name="focus_area" value="{{ old('focus_area', $teacher->focus_area) }}">
                                                </div>
                                                <div class="field">
                                                    <label>Telepon</label>
                                                    <input name="phone" value="{{ old('phone', $teacher->phone) }}">
                                                </div>
                                            </div>
                                            <div class="toolbar modal-actions">
                                                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                                <button class="btn primary" type="submit">Update</button>
                                            </div>
                                        </form>
                                    </dialog>
                                    <button class="btn danger" type="button" data-delete-action="{{ route('alpha.master.teachers.destroy', $teacher) }}" data-delete-label="Hapus guru {{ $teacher->name }}? Data tidak bisa dihapus jika sudah dipakai jadwal, presensi, atau observasi.">Hapus</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">Belum ada guru.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
        </div>
    </section>
    <dialog class="modal" id="modal-create-teacher">
        <form method="post" action="{{ route('alpha.master.teachers.store') }}">
            @csrf
            <div class="modal-head">
                <div>
                    <h3>Tambah Guru</h3>
                    <div class="meta">Tambahkan guru untuk jadwal, presensi, observasi, dan rapor.</div>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="teacher-code">Kode</label>
                    <input id="teacher-code" name="code" value="{{ old('code') }}" placeholder="TCH05">
                </div>
                <div class="field">
                    <label for="teacher-name">Nama</label>
                    <input id="teacher-name" name="name" value="{{ old('name') }}" placeholder="Bu Sari">
                </div>
                <div class="field">
                    <label for="teacher-focus">Fokus area</label>
                    <input id="teacher-focus" name="focus_area" value="{{ old('focus_area') }}" placeholder="Bahasa dan Practical Life">
                </div>
                <div class="field">
                    <label for="teacher-phone">Telepon</label>
                    <input id="teacher-phone" name="phone" value="{{ old('phone') }}" placeholder="08xx">
                </div>
            </div>
            <div class="toolbar modal-actions">
                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                <button class="btn primary" type="submit">Simpan Guru</button>
            </div>
        </form>
    </dialog>
    <dialog class="modal" id="modal-import-teachers">
        <form method="post" action="{{ route('alpha.master.teachers.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-head">
                <div>
                    <h3>Import Guru</h3>
                    <div class="meta">Gunakan file .xlsx dengan baris pertama sebagai header kolom.</div>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
            </div>
            <div class="field">
                <label for="teacher-import-file">File Excel</label>
                <input id="teacher-import-file" type="file" name="file" accept=".xlsx">
            </div>
            <div class="import-notes meta">
                <div>Kolom wajib: kode, nama.</div>
                <div>Kolom opsional: fokus_area, telepon, status.</div>
            </div>
            <div class="toolbar modal-actions">
                <a class="btn ghost" href="{{ route('alpha.master.import-template', 'teachers') }}">Download Format</a>
                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                <button class="btn primary" type="submit">Import Guru</button>
            </div>
        </form>
    </dialog>
    @endif

    @if ($masterSection === 'curriculum')
    <section class="panel master-section" id="indikator">
        <div class="line-head">
            <div>
                <h3>Area dan Indikator Perkembangan</h3>
                <div class="meta">Indikator menjadi rubric observasi dan sumber draft rapor.</div>
            </div>
            <div class="page-actions">
                <button class="btn ghost" type="button" data-modal-target="modal-import-indicators">Import Excel</button>
                <button class="btn ghost" type="button" data-modal-target="modal-create-area">Tambah Area</button>
                <button class="btn primary" type="button" data-modal-target="modal-create-indicator">Tambah Indikator</button>
            </div>
        </div>

        <div class="grid two" data-card-list data-card-page-size="4" data-card-search-placeholder="Cari area atau indikator">
            @foreach ($areas as $area)
                <article class="line-card" data-card-item>
                    <div class="line-head">
                        <div>
                            <span class="dot {{ $area->color }}"></span>
                            <strong>{{ $area->name }}</strong>
                        </div>
                        <span class="chip">{{ $area->indicators->count() }} indikator</span>
                    </div>
                    <div class="meta">Urutan {{ $area->sort_order }} | Slug {{ $area->slug }}</div>
                    <div class="toolbar compact-actions">
                        <button class="btn ghost" type="button" data-modal-target="modal-edit-area-{{ $area->id }}">Edit Area</button>
                        <dialog class="modal" id="modal-edit-area-{{ $area->id }}">
                            <form method="post" action="{{ route('alpha.master.areas.update', $area) }}">
                                @csrf
                                @method('patch')
                                <div class="modal-head">
                                    <div>
                                        <h3>Edit Area</h3>
                                        <div class="meta">{{ $area->name }}</div>
                                    </div>
                                    <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                                </div>
                                <div class="form-grid">
                                    <div class="field">
                                        <label>Nama area</label>
                                        <input name="name" value="{{ old('name', $area->name) }}">
                                    </div>
                                    <div class="field">
                                        <label>Urutan</label>
                                        <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $area->sort_order) }}">
                                    </div>
                                    <div class="field">
                                        <label>Warna</label>
                                        <select name="color">
                                            @foreach (['sage', 'teal', 'coral', 'blue', 'gold', 'plum'] as $color)
                                                <option value="{{ $color }}" @selected($area->color === $color)>{{ ucfirst($color) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label>Slug opsional</label>
                                        <input name="slug" value="{{ old('slug', $area->slug) }}">
                                    </div>
                                </div>
                                <div class="toolbar modal-actions">
                                    <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                    <button class="btn primary" type="submit">Update Area</button>
                                </div>
                            </form>
                        </dialog>
                        <button class="btn danger" type="button" data-delete-action="{{ route('alpha.master.areas.destroy', $area) }}" data-delete-label="Hapus area {{ $area->name }}? Area tidak bisa dihapus jika masih memiliki indikator.">Hapus Area</button>
                    </div>
                    <div class="card-list" style="margin-top: 12px" data-card-list data-card-page-size="6" data-card-search-placeholder="Cari indikator {{ $area->name }}">
                        @forelse ($area->indicators as $indicator)
                            <div class="line-card soft" data-card-item>
                                <div class="line-head">
                                    <strong>{{ $indicator->code }}</strong>
                                    <span class="status {{ $indicator->is_active ? 'status-achieved' : 'status-empty' }}">{{ $indicator->is_active ? ($indicator->level ?? '-') : 'Nonaktif' }}</span>
                                </div>
                                <div class="meta">{{ $indicator->sub_area }}</div>
                                <div>{{ $indicator->description }}</div>
                                <div class="toolbar compact-actions">
                                    <form method="post" action="{{ route('alpha.master.indicators.toggle', $indicator) }}">
                                        @csrf
                                        @method('patch')
                                        <button class="btn ghost" type="submit">{{ $indicator->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                    </form>
                                    <button class="btn ghost" type="button" data-modal-target="modal-edit-indicator-{{ $indicator->id }}">Edit</button>
                                    <dialog class="modal" id="modal-edit-indicator-{{ $indicator->id }}">
                                        <form method="post" action="{{ route('alpha.master.indicators.update', $indicator) }}">
                                            @csrf
                                            @method('patch')
                                            <div class="modal-head">
                                                <div>
                                                    <h3>Edit Indikator</h3>
                                                    <div class="meta">{{ $indicator->code }} - {{ $area->name }}</div>
                                                </div>
                                                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
                                            </div>
                                            <div class="form-grid">
                                                <div class="field">
                                                    <label>Area</label>
                                                    <select name="development_area_id">
                                                        @foreach ($areas as $optionArea)
                                                            <option value="{{ $optionArea->id }}" @selected($indicator->development_area_id === $optionArea->id)>{{ $optionArea->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="field">
                                                    <label>Kode</label>
                                                    <input name="code" value="{{ old('code', $indicator->code) }}">
                                                </div>
                                                <div class="field">
                                                    <label>Sub-area</label>
                                                    <input name="sub_area" value="{{ old('sub_area', $indicator->sub_area) }}">
                                                </div>
                                                <div class="field">
                                                    <label>Level / kelas</label>
                                                    <input name="level" value="{{ old('level', $indicator->level) }}">
                                                </div>
                                                <div class="field wide">
                                                    <label>Indikator</label>
                                                    <textarea name="description">{{ old('description', $indicator->description) }}</textarea>
                                                </div>
                                            </div>
                                            <div class="toolbar modal-actions">
                                                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                                                <button class="btn primary" type="submit">Update</button>
                                            </div>
                                        </form>
                                    </dialog>
                                    <button class="btn danger" type="button" data-delete-action="{{ route('alpha.master.indicators.destroy', $indicator) }}" data-delete-label="Hapus indikator {{ $indicator->code }}? Data tidak bisa dihapus jika sudah dipakai observasi atau ILP.">Hapus</button>
                                </div>
                            </div>
                        @empty
                            <div class="meta">Belum ada indikator.</div>
                        @endforelse
                    </div>
                </article>
            @endforeach
        </div>
    </section>
    <dialog class="modal" id="modal-create-area">
        <form method="post" action="{{ route('alpha.master.areas.store') }}">
            @csrf
            <div class="modal-head">
                <div>
                    <h3>Tambah Area</h3>
                    <div class="meta">Area menjadi kelompok utama indikator perkembangan.</div>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="area-name">Nama area</label>
                    <input id="area-name" name="name" value="{{ old('name') }}" placeholder="Bahasa">
                </div>
                <div class="field">
                    <label for="area-sort-order">Urutan</label>
                    <input id="area-sort-order" type="number" min="0" name="sort_order" value="{{ old('sort_order', ($areas->max('sort_order') ?? 0) + 1) }}">
                </div>
                <div class="field">
                    <label for="area-color">Warna</label>
                    <select id="area-color" name="color">
                        @foreach (['sage', 'teal', 'coral', 'blue', 'gold', 'plum'] as $color)
                            <option value="{{ $color }}" @selected(old('color', 'sage') === $color)>{{ ucfirst($color) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="area-slug">Slug opsional</label>
                    <input id="area-slug" name="slug" value="{{ old('slug') }}" placeholder="auto">
                </div>
            </div>
            <div class="toolbar modal-actions">
                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                <button class="btn primary" type="submit">Simpan Area</button>
            </div>
        </form>
    </dialog>
    <dialog class="modal" id="modal-create-indicator">
        <form method="post" action="{{ route('alpha.master.indicators.store') }}">
            @csrf
            <div class="modal-head">
                <div>
                    <h3>Tambah Indikator</h3>
                    <div class="meta">Indikator menjadi rubric observasi dan sumber draft rapor.</div>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="indicator-area">Area</label>
                    <select id="indicator-area" name="development_area_id">
                        @foreach ($areas as $area)
                            <option value="{{ $area->id }}" @selected((int) old('development_area_id') === $area->id)>{{ $area->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="indicator-code">Kode</label>
                    <input id="indicator-code" name="code" value="{{ old('code') }}" placeholder="BHS04">
                </div>
                <div class="field">
                    <label for="indicator-sub-area">Sub-area</label>
                    <input id="indicator-sub-area" name="sub_area" value="{{ old('sub_area') }}" placeholder="Ekspresi">
                </div>
                <div class="field">
                    <label for="indicator-level">Level / kelas</label>
                    <input id="indicator-level" name="level" value="{{ old('level') }}" placeholder="Sunny / Glow">
                </div>
                <div class="field wide">
                    <label for="indicator-description">Indikator</label>
                    <textarea id="indicator-description" name="description" placeholder="Deskripsi indikator yang diamati">{{ old('description') }}</textarea>
                </div>
            </div>
            <div class="toolbar modal-actions">
                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                <button class="btn primary" type="submit">Simpan Indikator</button>
            </div>
        </form>
    </dialog>
    <dialog class="modal" id="modal-import-indicators">
        <form method="post" action="{{ route('alpha.master.indicators.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-head">
                <div>
                    <h3>Import Kurikulum</h3>
                    <div class="meta">Gunakan file .xlsx untuk menambah atau memperbarui indikator.</div>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup"><i data-lucide="x" class="nav-icon"></i></button>
            </div>
            <div class="field">
                <label for="indicator-import-file">File Excel</label>
                <input id="indicator-import-file" type="file" name="file" accept=".xlsx">
            </div>
            <div class="import-notes meta">
                <div>Kolom wajib: area, kode, sub_area, indikator.</div>
                <div>Kolom opsional: level, status. Area baru akan dibuat otomatis.</div>
            </div>
            <div class="toolbar modal-actions">
                <a class="btn ghost" href="{{ route('alpha.master.import-template', 'indicators') }}">Download Format</a>
                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                <button class="btn primary" type="submit">Import Kurikulum</button>
            </div>
        </form>
    </dialog>
    @endif
@endsection

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Montessori Bloom')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/alpha.css') }}?v={{ filemtime(public_path('css/alpha.css')) }}">
    <script src="https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js"></script>
</head>
<body>
@php
    $menus = [
        'Operasional' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => 'alpha.dashboard', 'dot' => 'sage', 'icon' => 'layout-dashboard'],
        ],
        'Master Data' => [
            ['key' => 'master.academic-years', 'label' => 'Tahun Ajaran', 'route' => 'alpha.master.academic-years', 'dot' => 'teal', 'icon' => 'calendar-days'],
            ['key' => 'master.levels', 'label' => 'Level', 'route' => 'alpha.master.levels', 'dot' => 'teal', 'icon' => 'list-ordered'],
            ['key' => 'master.classes', 'label' => 'Kelas', 'route' => 'alpha.master.classes', 'dot' => 'teal', 'icon' => 'school'],
            ['key' => 'master.students', 'label' => 'Siswa & Orangtua', 'route' => 'alpha.master.students', 'dot' => 'teal', 'icon' => 'users'],
            ['key' => 'master.teachers', 'label' => 'Guru', 'route' => 'alpha.master.teachers', 'dot' => 'teal', 'icon' => 'graduation-cap'],
            ['key' => 'master.curriculum', 'label' => 'Kurikulum', 'route' => 'alpha.master.curriculum', 'dot' => 'teal', 'icon' => 'book-open'],
        ],
        'Proses' => [
            ['key' => 'process.schedules', 'label' => 'Jadwal Mingguan', 'route' => 'alpha.process.schedules', 'dot' => 'coral', 'icon' => 'calendar'],
            ['key' => 'process.sessions', 'label' => 'Presensi', 'route' => 'alpha.process.attendance', 'dot' => 'coral', 'icon' => 'clipboard-list'],
            ['key' => 'process.observations', 'label' => 'Observasi', 'route' => 'alpha.process.observations', 'dot' => 'coral', 'icon' => 'clipboard-check'],
            ['key' => 'process.ilp', 'label' => 'ILP / Remedial', 'route' => 'alpha.process.ilp', 'dot' => 'coral', 'icon' => 'target'],
        ],
        'Laporan' => [
            ['key' => 'reports', 'label' => 'Draft rapor otomatis', 'route' => 'alpha.reports', 'dot' => 'blue', 'icon' => 'file-text'],
        ],
        'Setting' => [
            ['key' => 'settings.users', 'label' => 'User & Login', 'route' => 'alpha.settings.users', 'dot' => 'plum', 'icon' => 'settings'],
        ],
    ];
@endphp
<div class="app" id="app-shell">
    <aside class="sidebar" id="app-sidebar">
        <div class="brand">
            <button class="brand-mark" type="button" data-sidebar-toggle aria-label="Tampilkan atau sembunyikan sidebar">M</button>
            <div class="brand-copy">
                <strong>Montessori Bloom</strong>
                <span>Learning Management</span>
            </div>
            <button class="icon-btn sidebar-pin" type="button" data-sidebar-toggle aria-label="Tampilkan atau sembunyikan sidebar">
                <i data-lucide="panel-left-close" class="nav-icon"></i>
            </button>
        </div>

        @foreach ($menus as $group => $items)
            <nav class="nav-group" aria-label="{{ $group }}">
                <div class="nav-title">{{ $group }}</div>
                @foreach ($items as $item)
                    <a class="nav-link {{ $activeMenu === $item['key'] ? 'active' : '' }}" href="{{ route($item['route']) }}">
                        <i data-lucide="{{ $item['icon'] }}" class="nav-icon"></i>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        @endforeach

        <div class="user-box">
            <strong>{{ $currentUser?->name ?? $roleLabel }}</strong>
            <span>{{ $roleLabel }}{{ $currentUser?->email ? ' | ' . $currentUser->email : '' }}</span>
        </div>
    </aside>

    <main class="content">
        <header class="topbar">
            <div class="topbar-main">
                <div>
                    <h1>@yield('page_title')</h1>
                    <div class="meta">@yield('page_subtitle')</div>
                </div>
            </div>
            <details class="profile-menu">
                <summary class="role-pill">
                    <i data-lucide="user-check" class="nav-icon"></i>
                    <span>{{ $currentUser?->name ?? $roleLabel }}</span>
                    <i data-lucide="chevron-down" class="nav-icon"></i>
                </summary>
                <div class="profile-dropdown">
                    <div class="profile-dropdown-head">
                        <strong>{{ $currentUser?->name ?? $roleLabel }}</strong>
                        <span>{{ $roleLabel }}{{ $currentUser?->email ? ' | ' . $currentUser->email : '' }}</span>
                    </div>
                    <button class="btn ghost" type="button" data-modal-target="modal-profile">Profile</button>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn danger" type="submit">Logout</button>
                    </form>
                </div>
            </details>
        </header>

        <section class="page">
            @if (session('status'))
                <div class="notice">{{ session('status') }}</div>
            @endif

            @if ($errors->any() && ! old('_modal'))
                <div class="notice error">
                    <strong>Data belum bisa disimpan.</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </section>
    </main>
</div>
@auth
    <dialog class="modal" id="modal-profile">
        <form method="post" action="{{ route('alpha.profile.update') }}">
            @csrf
            @method('patch')
            <div class="modal-head">
                <div>
                    <h3>Edit Profile</h3>
                    <div class="meta">Perbarui data login dan password akun sendiri.</div>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup">
                    <i data-lucide="x" class="nav-icon"></i>
                </button>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="profile-name">Nama</label>
                    <input id="profile-name" type="text" name="name" value="{{ old('name', $currentUser?->name) }}" required>
                </div>
                <div class="field">
                    <label for="profile-email">Email</label>
                    <input id="profile-email" type="email" name="email" value="{{ old('email', $currentUser?->email) }}" required>
                </div>
                <div class="field wide">
                    <label for="profile-phone">Telepon</label>
                    <input id="profile-phone" type="text" name="phone" value="{{ old('phone', $currentUser?->phone) }}">
                </div>
                <div class="field">
                    <label for="profile-current-password">Password lama</label>
                    <input id="profile-current-password" type="password" name="current_password" autocomplete="current-password">
                </div>
                <div class="field">
                    <label for="profile-password">Password baru</label>
                    <input id="profile-password" type="password" name="password" autocomplete="new-password" minlength="8">
                </div>
                <div class="field wide">
                    <label for="profile-password-confirmation">Konfirmasi password baru</label>
                    <input id="profile-password-confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="8">
                </div>
            </div>
            <div class="toolbar modal-actions">
                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                <button class="btn primary" type="submit">Simpan Profile</button>
            </div>
        </form>
    </dialog>
@endauth
<dialog class="modal" id="confirm-delete-dialog">
    <form method="post" id="confirm-delete-form">
        @csrf
        @method('delete')
        <div class="modal-head">
            <div>
                <h3>Konfirmasi Hapus</h3>
                <div class="meta" id="confirm-delete-message">Data yang dihapus tidak selalu bisa dikembalikan.</div>
            </div>
            <button class="icon-btn" type="button" data-modal-close aria-label="Tutup">
                <i data-lucide="x" class="nav-icon"></i>
            </button>
        </div>
        <div class="toolbar modal-actions">
            <button class="btn ghost" type="button" data-modal-close>Batal</button>
            <button class="btn danger" type="submit">Ya, hapus</button>
        </div>
    </form>
</dialog>
@if ($errors->any() && old('_modal'))
    <template id="modal-error-template">
        <div class="notice error modal-error">
            <strong>Data belum bisa disimpan.</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </template>
@endif
<script>
    const appShell = document.getElementById('app-shell');
    const sidebarPreference = localStorage.getItem('montessori.sidebarCollapsed');
    const activeModal = @json(old('_modal'));

    if (sidebarPreference === '1') {
        appShell?.classList.add('sidebar-collapsed');
    }

    if (window.lucide) {
        window.lucide.createIcons();
    }

    document.querySelectorAll('[data-sidebar-toggle]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            appShell?.classList.toggle('sidebar-collapsed');
            localStorage.setItem('montessori.sidebarCollapsed', appShell?.classList.contains('sidebar-collapsed') ? '1' : '0');
        });
    });

    document.querySelectorAll('[data-modal-target]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const dialog = document.getElementById(trigger.dataset.modalTarget);
            if (dialog?.showModal) {
                dialog.showModal();
            }
        });
    });

    document.querySelectorAll('dialog form').forEach((form) => {
        form.addEventListener('submit', () => {
            const dialog = form.closest('dialog');
            if (!dialog?.id) {
                return;
            }

            let modalInput = form.querySelector('input[name="_modal"]');
            if (!modalInput) {
                modalInput = document.createElement('input');
                modalInput.type = 'hidden';
                modalInput.name = '_modal';
                form.appendChild(modalInput);
            }
            modalInput.value = dialog.id;
        });
    });

    document.querySelectorAll('[data-delete-action]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const dialog = document.getElementById('confirm-delete-dialog');
            const form = document.getElementById('confirm-delete-form');
            const message = document.getElementById('confirm-delete-message');

            form.action = trigger.dataset.deleteAction;
            message.textContent = trigger.dataset.deleteLabel || 'Data yang dihapus tidak selalu bisa dikembalikan.';
            dialog.showModal();
        });
    });

    document.querySelectorAll('[data-age-years-input]').forEach((input) => {
        const preview = input.parentElement?.querySelector('[data-age-months-preview]');
        const updatePreview = () => {
            if (!preview) {
                return;
            }

            const value = Number(String(input.value).replace(',', '.'));
            preview.textContent = Number.isFinite(value) && value >= 0
                ? `Terdeteksi ${Number((value * 12).toFixed(2))} bulan`
                : '';
        };

        input.addEventListener('input', updatePreview);
        updatePreview();
    });

    document.querySelectorAll('[data-schedule-class-select]').forEach((classSelect) => {
        const key = classSelect.dataset.scheduleClassSelect;
        const studentSelect = document.querySelector(`[data-schedule-student-select="${key}"]`);

        if (!studentSelect) {
            return;
        }

        classSelect.addEventListener('change', () => {
            studentSelect.dataset.activeClassId = classSelect.value;
        });
        studentSelect.dataset.activeClassId = classSelect.value;
    });

    document.querySelectorAll('select[data-student-multiselect]').forEach((select, pickerIndex) => {
        const options = Array.from(select.options);
        const levels = new Map();
        const picker = document.createElement('div');
        const searchId = `student-picker-search-${pickerIndex}`;
        const levelId = `student-picker-level-${pickerIndex}`;
        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
        const optionSearchText = (option) => [
            option.textContent,
            option.dataset.code,
            option.dataset.className,
            option.dataset.levelName,
            option.dataset.guardian,
        ].join(' ').toLowerCase();

        options.forEach((option) => {
            const key = option.dataset.levelId || 'none';
            if (!levels.has(key)) {
                levels.set(key, option.dataset.levelName || 'Tanpa level');
            }
        });

        picker.className = 'student-picker';
        picker.innerHTML = `
            <div class="student-picker-tools">
                <label class="field" for="${searchId}">
                    <span>Cari siswa</span>
                    <input id="${searchId}" type="search" placeholder="${escapeHtml(select.dataset.studentPickerPlaceholder || 'Cari siswa')}">
                </label>
                <label class="field" for="${levelId}">
                    <span>Level</span>
                    <select id="${levelId}">
                        <option value="">Semua level</option>
                        ${Array.from(levels, ([value, label]) => `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`).join('')}
                    </select>
                </label>
            </div>
            <div class="student-picker-summary">
                <strong data-picker-count>0 siswa dipilih</strong>
                <button class="btn ghost" type="button" data-picker-action="clear">Kosongkan</button>
            </div>
            <div class="student-picker-selected" data-picker-selected></div>
            <div class="student-picker-list" data-picker-list></div>
        `;

        select.classList.add('student-picker-source');
        select.after(picker);

        const searchInput = picker.querySelector('input[type="search"]');
        const levelSelect = picker.querySelector('select');
        const selectedContainer = picker.querySelector('[data-picker-selected]');
        const listContainer = picker.querySelector('[data-picker-list]');
        const countLabel = picker.querySelector('[data-picker-count]');

        const selectedOptions = () => options.filter((option) => option.selected);

        const renderSelected = () => {
            const selected = selectedOptions();

            countLabel.textContent = `${selected.length} siswa dipilih`;
            selectedContainer.innerHTML = selected.length > 0
                ? selected.map((option) => `
                    <button class="chip student-picker-chip" type="button" data-picker-remove="${escapeHtml(option.value)}">
                        ${escapeHtml(option.textContent.trim())}
                    </button>
                `).join('')
                : '<span class="meta">Belum ada siswa dipilih.</span>';
        };

        const renderList = () => {
            const query = searchInput.value.trim().toLowerCase();
            const level = levelSelect.value;
            const visible = options.filter((option) => {
                const levelMatches = !level || (option.dataset.levelId || 'none') === level;
                const queryMatches = !query || optionSearchText(option).includes(query);

                return levelMatches && queryMatches;
            });

            listContainer.innerHTML = visible.length > 0
                ? visible.map((option) => `
                    <label class="student-picker-option">
                        <input type="checkbox" value="${escapeHtml(option.value)}" ${option.selected ? 'checked' : ''}>
                        <span>
                            <strong>${escapeHtml(option.textContent.trim().split(' - ')[0])}</strong>
                            <small>${escapeHtml(option.dataset.code || '-')} | ${escapeHtml(option.dataset.levelName || 'Tanpa level')} | ${escapeHtml(option.dataset.className || '-')}</small>
                            ${option.dataset.guardian ? `<small>Orangtua: ${escapeHtml(option.dataset.guardian)}</small>` : ''}
                        </span>
                    </label>
                `).join('')
                : '<div class="line-card muted">Siswa tidak ditemukan.</div>';
        };

        const render = () => {
            renderSelected();
            renderList();
        };

        picker.addEventListener('change', (event) => {
            const checkbox = event.target.closest('.student-picker-option input[type="checkbox"]');
            if (!checkbox) {
                return;
            }

            const option = options.find((item) => item.value === checkbox.value);
            if (option) {
                option.selected = checkbox.checked;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            render();
        });

        picker.addEventListener('click', (event) => {
            const removeButton = event.target.closest('[data-picker-remove]');
            const clearButton = event.target.closest('[data-picker-action="clear"]');

            if (removeButton) {
                const option = options.find((item) => item.value === removeButton.dataset.pickerRemove);
                if (option) {
                    option.selected = false;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    render();
                }
            }

            if (clearButton) {
                options.forEach((option) => {
                    option.selected = false;
                });
                select.dispatchEvent(new Event('change', { bubbles: true }));
                render();
            }
        });

        searchInput.addEventListener('input', renderList);
        levelSelect.addEventListener('change', renderList);
        select.addEventListener('change', render);
        render();
    });

    document.querySelectorAll('[data-schedule-select-class-students]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const key = trigger.dataset.scheduleSelectClassStudents;
            const studentSelect = document.querySelector(`[data-schedule-student-select="${key}"]`);

            if (!studentSelect) {
                return;
            }

            const classSelect = document.querySelector(`[data-schedule-class-select="${key}"]`);
            const activeClassId = classSelect?.value || studentSelect.dataset.activeClassId;

            Array.from(studentSelect.options).forEach((option) => {
                option.selected = activeClassId ? option.dataset.classId === activeClassId : true;
            });
            studentSelect.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    document.querySelectorAll('[data-session-schedule-select]').forEach((select) => {
        const dateInput = document.querySelector('[data-session-date-input]');

        if (!dateInput) {
            return;
        }

        const toIsoDate = (date) => {
            const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);

            return local.toISOString().slice(0, 10);
        };

        const nextDateForDay = (day) => {
            const targetDay = Number(day);
            const today = new Date();
            const currentDay = today.getDay() === 0 ? 7 : today.getDay();
            const dayOffset = (targetDay - currentDay + 7) % 7;
            today.setDate(today.getDate() + dayOffset);

            return toIsoDate(today);
        };

        select.addEventListener('change', () => {
            const selected = select.selectedOptions[0];
            const day = selected?.dataset.day;

            if (day) {
                dateInput.value = nextDateForDay(day);
            }
        });
    });

    document.querySelectorAll('[data-attendance-date-picker]').forEach((input) => {
        input.addEventListener('change', () => {
            input.form?.requestSubmit();
        });
    });

    document.querySelectorAll('[data-observation-session-select]').forEach((sessionSelect) => {
        const form = sessionSelect.closest('[data-observation-monitoring-form]');
        const studentSelect = document.querySelector('[data-observation-student-select]');
        const studentCard = document.querySelector('[data-observation-student-card]');
        const dateInput = document.getElementById('observed_on');
        const noteInput = document.getElementById('note');
        const snapshotScript = document.getElementById('monitoring-snapshots-json');
        const snapshots = snapshotScript ? JSON.parse(snapshotScript.textContent || '{}') : {};
        const areaPanels = Array.from(document.querySelectorAll('[data-observation-area-step]'));
        const stepButtons = Array.from(document.querySelectorAll('[data-observation-step-target]'));
        const previousButton = document.querySelector('[data-observation-prev]');
        const nextButton = document.querySelector('[data-observation-next]');
        const progressText = document.querySelector('[data-observation-progress]');
        let activeStep = 0;

        if (!studentSelect || !form) {
            return;
        }

        const monitoringKey = () => `${sessionSelect.value}|${studentSelect.value}|${dateInput?.value || ''}`;

        const syncObservationChoices = () => {
            form.querySelectorAll('.observation-choice').forEach((choice) => {
                const input = choice.dataset.observationChoiceFor
                    ? document.getElementById(choice.dataset.observationChoiceFor)
                    : null;

                choice.classList.toggle('is-selected', Boolean(input?.checked));
            });
        };

        const updateAreaProgress = () => {
            syncObservationChoices();
            areaPanels.forEach((panel, index) => {
                const rows = Array.from(panel.querySelectorAll('.observation-row'));
                const completed = rows.filter((row) => row.querySelector('.observation-rating input:checked')).length;
                const badge = panel.querySelector('[data-observation-area-progress]');
                const stepButton = stepButtons[index];

                if (badge) {
                    badge.textContent = `${completed}/${rows.length} terisi`;
                }
                stepButton?.classList.toggle('complete', rows.length > 0 && completed === rows.length);
                stepButton?.classList.toggle('partial', completed > 0 && completed < rows.length);
            });
        };

        const showStep = (targetIndex) => {
            if (areaPanels.length === 0) {
                return;
            }

            activeStep = Math.max(0, Math.min(targetIndex, areaPanels.length - 1));
            areaPanels.forEach((panel, index) => {
                panel.hidden = index !== activeStep;
            });
            stepButtons.forEach((button, index) => {
                button.classList.toggle('active', index === activeStep);
            });

            if (previousButton) {
                previousButton.disabled = activeStep === 0;
            }
            if (nextButton) {
                nextButton.disabled = activeStep === areaPanels.length - 1;
            }
            if (progressText) {
                progressText.textContent = `Area ${activeStep + 1} dari ${areaPanels.length}`;
            }
        };

        const hasCheckedStatuses = () => Boolean(form.querySelector('.observation-rating input:checked'));

        const applyMonitoringSnapshot = (preserveWhenMissing = false) => {
            const snapshot = snapshots[monitoringKey()];

            if (!snapshot && preserveWhenMissing && hasCheckedStatuses()) {
                updateAreaProgress();
                return;
            }

            form.querySelectorAll('.observation-rating input[type="radio"]').forEach((input) => {
                input.checked = false;
            });

            if (snapshot?.items) {
                Object.entries(snapshot.items).forEach(([indicatorId, row]) => {
                    const input = form.querySelector(`input[name="observations[${indicatorId}][status]"][value="${row.status}"]`);
                    if (input) {
                        input.checked = true;
                    }
                });
            }

            if (noteInput) {
                noteInput.value = snapshot?.note || '';
            }
            updateAreaProgress();
        };

        const updateStudentCard = () => {
            const selected = studentSelect.selectedOptions[0];
            if (!studentCard || !selected) {
                return;
            }

            const avatar = studentCard.querySelector('.student-avatar');
            const name = studentCard.querySelector('strong');
            const metaRows = studentCard.querySelectorAll('.meta');

            if (avatar) {
                avatar.textContent = selected.dataset.initial || '-';
            }
            if (name) {
                name.textContent = selected.dataset.name || selected.textContent.trim();
            }
            if (metaRows[0]) {
                metaRows[0].textContent = `${selected.dataset.code || '-'} | ${selected.dataset.className || '-'} | ${selected.dataset.levelName || '-'}`;
            }
            if (metaRows[1]) {
                metaRows[1].textContent = selected.dataset.guardian || 'Orangtua belum diisi';
            }
        };

        const syncStudentsToSession = () => {
            const selectedSession = sessionSelect.selectedOptions[0];
            const allowed = new Set(String(selectedSession?.dataset.studentIds || '').split(',').filter(Boolean));
            const hasSessionFilter = selectedSession?.hasAttribute('data-student-ids') ?? false;
            let selectedStillVisible = false;

            Array.from(studentSelect.options).forEach((option) => {
                const isAllowed = !hasSessionFilter || allowed.has(option.value);
                option.hidden = !isAllowed;
                option.disabled = !isAllowed;
                if (option.selected && isAllowed) {
                    selectedStillVisible = true;
                }
            });

            if (!selectedStillVisible) {
                const firstAllowed = Array.from(studentSelect.options).find((option) => !option.disabled);
                if (firstAllowed) {
                    studentSelect.value = firstAllowed.value;
                }
            }

            updateStudentCard();
            applyMonitoringSnapshot();
        };

        sessionSelect.addEventListener('change', syncStudentsToSession);
        studentSelect.addEventListener('change', () => {
            updateStudentCard();
            applyMonitoringSnapshot();
        });
        dateInput?.addEventListener('change', () => {
            applyMonitoringSnapshot();
        });
        form.querySelectorAll('.observation-rating input[type="radio"]').forEach((input) => {
            input.addEventListener('change', updateAreaProgress);
        });
        form.querySelectorAll('.observation-choice').forEach((choice) => {
            choice.addEventListener('click', (event) => {
                const input = choice.dataset.observationChoiceFor
                    ? document.getElementById(choice.dataset.observationChoiceFor)
                    : null;

                if (! input) {
                    return;
                }

                event.preventDefault();
                input.checked = true;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
        stepButtons.forEach((button) => {
            button.addEventListener('click', () => {
                showStep(Number(button.dataset.observationStepTarget || 0));
            });
        });
        previousButton?.addEventListener('click', () => {
            showStep(activeStep - 1);
        });
        nextButton?.addEventListener('click', () => {
            showStep(activeStep + 1);
        });
        studentSelect.form?.addEventListener('reset', () => {
            setTimeout(() => {
                syncStudentsToSession();
                applyMonitoringSnapshot();
            });
        });
        document.querySelectorAll('[data-edit-monitoring]').forEach((button) => {
            button.addEventListener('click', () => {
                sessionSelect.value = button.dataset.editSessionId;
                syncStudentsToSession();
                studentSelect.value = button.dataset.editStudentId;
                if (dateInput) {
                    dateInput.value = button.dataset.editDate || dateInput.value;
                }
                updateStudentCard();
                applyMonitoringSnapshot();
                showStep(0);
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
        syncStudentsToSession();
        showStep(0);
        applyMonitoringSnapshot(true);
    });

    document.querySelectorAll('.table-wrap table').forEach((table, tableIndex) => {
        const tbody = table.tBodies[0];
        const tableWrap = table.closest('.table-wrap');
        const headers = Array.from(table.tHead?.rows[0]?.cells ?? []);

        if (!tbody || !tableWrap || headers.length === 0) {
            return;
        }

        const rows = Array.from(tbody.rows).filter((row) => !row.dataset.emptyRow);
        if (rows.length === 0) {
            return;
        }

        const state = {
            page: 1,
            pageSize: 10,
            query: '',
            sortIndex: null,
            sortDirection: 'asc',
        };
        const collator = new Intl.Collator('id', { numeric: true, sensitivity: 'base' });
        const controls = document.createElement('div');
        const footer = document.createElement('div');
        const searchId = `table-search-${tableIndex}`;
        const sizeId = `table-size-${tableIndex}`;

        controls.className = 'table-tools';
        controls.innerHTML = `
            <label class="table-search" for="${searchId}">
                <span>Cari</span>
                <input id="${searchId}" type="search" placeholder="Ketik kata kunci">
            </label>
            <label class="table-size" for="${sizeId}">
                <span>Tampilkan</span>
                <select id="${sizeId}">
                    <option value="5">5</option>
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="all">Semua</option>
                </select>
            </label>
        `;
        footer.className = 'table-pagination';

        tableWrap.before(controls);
        tableWrap.after(footer);

        const emptyRow = document.createElement('tr');
        emptyRow.dataset.emptyRow = 'true';
        emptyRow.className = 'data-table-empty';
        emptyRow.innerHTML = `<td colspan="${headers.length}">Data tidak ditemukan.</td>`;
        tbody.appendChild(emptyRow);

        rows.forEach((row, index) => {
            row.dataset.originalIndex = String(index);
        });

        const textFromElement = (element) => {
            const clone = element.cloneNode(true);
            clone.querySelectorAll('dialog, form, button, script').forEach((node) => node.remove());

            return clone.textContent.replace(/\s+/g, ' ').trim();
        };

        const rowText = (row) => textFromElement(row).toLowerCase();
        const cellText = (row, index) => textFromElement(row.cells[index] ?? row);

        const sortedRows = () => {
            const filtered = rows.filter((row) => rowText(row).includes(state.query));
            if (state.sortIndex === null) {
                return filtered.sort((a, b) => Number(a.dataset.originalIndex) - Number(b.dataset.originalIndex));
            }

            return filtered.sort((a, b) => {
                const result = collator.compare(cellText(a, state.sortIndex), cellText(b, state.sortIndex));

                return state.sortDirection === 'asc' ? result : -result;
            });
        };

        const render = () => {
            const filtered = sortedRows();
            const total = filtered.length;
            const pageSize = state.pageSize === 'all' ? Math.max(total, 1) : state.pageSize;
            const totalPages = Math.max(1, Math.ceil(total / pageSize));

            state.page = Math.min(state.page, totalPages);

            const start = (state.page - 1) * pageSize;
            const visibleRows = filtered.slice(start, start + pageSize);
            const visibleSet = new Set(visibleRows);
            const filteredSet = new Set(filtered);

            [...filtered, ...rows.filter((row) => !filteredSet.has(row))].forEach((row) => tbody.appendChild(row));
            tbody.appendChild(emptyRow);

            rows.forEach((row) => {
                row.hidden = !visibleSet.has(row);
            });
            emptyRow.hidden = total > 0;

            const from = total === 0 ? 0 : start + 1;
            const to = total === 0 ? 0 : start + visibleRows.length;
            footer.innerHTML = `
                <div class="table-count">Menampilkan ${from}-${to} dari ${total} data</div>
                <div class="table-pages">
                    <button class="btn ghost" type="button" data-page-action="prev" ${state.page <= 1 ? 'disabled' : ''}>Sebelumnya</button>
                    <span>Halaman ${state.page} / ${totalPages}</span>
                    <button class="btn ghost" type="button" data-page-action="next" ${state.page >= totalPages ? 'disabled' : ''}>Berikutnya</button>
                </div>
            `;
        };

        controls.querySelector('input[type="search"]').addEventListener('input', (event) => {
            state.query = event.target.value.trim().toLowerCase();
            state.page = 1;
            render();
        });

        controls.querySelector('select').addEventListener('change', (event) => {
            state.pageSize = event.target.value === 'all' ? 'all' : Number(event.target.value);
            state.page = 1;
            render();
        });

        footer.addEventListener('click', (event) => {
            const button = event.target.closest('[data-page-action]');
            if (!button) {
                return;
            }

            state.page += button.dataset.pageAction === 'next' ? 1 : -1;
            render();
        });

        headers.forEach((header, index) => {
            header.classList.add('sortable');
            header.tabIndex = 0;
            header.setAttribute('role', 'button');
            header.setAttribute('aria-sort', 'none');

            const sort = () => {
                if (state.sortIndex === index) {
                    state.sortDirection = state.sortDirection === 'asc' ? 'desc' : 'asc';
                } else {
                    state.sortIndex = index;
                    state.sortDirection = 'asc';
                }

                headers.forEach((item) => {
                    item.dataset.sortDirection = '';
                    item.setAttribute('aria-sort', 'none');
                });
                header.dataset.sortDirection = state.sortDirection;
                header.setAttribute('aria-sort', state.sortDirection === 'asc' ? 'ascending' : 'descending');
                state.page = 1;
                render();
            };

            header.addEventListener('click', sort);
            header.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    sort();
                }
            });
        });

        render();
    });

    document.querySelectorAll('[data-card-list]').forEach((list, listIndex) => {
        const items = Array.from(list.children).filter((item) => item.matches('[data-card-item]'));
        const initialSize = Number(list.dataset.cardPageSize || 6);

        if (items.length <= initialSize) {
            return;
        }

        const state = {
            query: '',
            pageSize: initialSize,
            visibleLimit: initialSize,
        };
        const controls = document.createElement('div');
        const footer = document.createElement('div');
        const empty = document.createElement('div');
        const searchId = `card-search-${listIndex}`;
        const sizeId = `card-size-${listIndex}`;
        const placeholder = list.dataset.cardSearchPlaceholder || 'Cari data';
        const escapeHtml = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        controls.className = 'card-tools';
        controls.innerHTML = `
            <label class="card-search" for="${searchId}">
                <span>Filter</span>
                <input id="${searchId}" type="search" placeholder="${escapeHtml(placeholder)}">
            </label>
            <label class="card-size" for="${sizeId}">
                <span>Tampilkan</span>
                <select id="${sizeId}">
                    <option value="4">4</option>
                    <option value="6">6</option>
                    <option value="12">12</option>
                    <option value="24">24</option>
                    <option value="all">Semua</option>
                </select>
            </label>
        `;
        footer.className = 'card-pagination';
        empty.className = 'line-card muted card-empty';
        empty.textContent = 'Data tidak ditemukan.';

        list.before(controls);
        list.after(footer);
        list.appendChild(empty);

        const sizeSelect = controls.querySelector('select');
        if (![...sizeSelect.options].some((option) => Number(option.value) === initialSize)) {
            sizeSelect.add(new Option(String(initialSize), String(initialSize)), 0);
        }
        sizeSelect.value = String(initialSize);

        const textFromElement = (element) => {
            const clone = element.cloneNode(true);
            clone.querySelectorAll('dialog, form, button, script, .card-tools, .card-pagination, .table-tools, .table-pagination').forEach((node) => node.remove());

            return clone.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
        };

        const filteredItems = () => items.filter((item) => textFromElement(item).includes(state.query));

        const render = () => {
            const filtered = filteredItems();
            const total = filtered.length;
            const pageSize = state.pageSize === 'all' ? Math.max(total, 1) : state.pageSize;
            const visibleLimit = state.pageSize === 'all' ? total : Math.min(state.visibleLimit, total);
            const visibleItems = filtered.slice(0, visibleLimit);
            const visibleSet = new Set(visibleItems);

            items.forEach((item) => {
                item.hidden = !visibleSet.has(item);
            });
            empty.hidden = total > 0;

            footer.innerHTML = `
                <div class="card-count">Menampilkan ${visibleItems.length} dari ${total} data</div>
                <div class="card-pages">
                    <button class="btn ghost" type="button" data-card-action="more" ${visibleLimit >= total ? 'disabled' : ''}>Tampilkan lagi</button>
                </div>
            `;
            footer.hidden = total <= pageSize && state.query === '';
        };

        controls.querySelector('input[type="search"]').addEventListener('input', (event) => {
            state.query = event.target.value.trim().toLowerCase();
            state.visibleLimit = state.pageSize === 'all' ? items.length : state.pageSize;
            render();
        });

        sizeSelect.addEventListener('change', (event) => {
            state.pageSize = event.target.value === 'all' ? 'all' : Number(event.target.value);
            state.visibleLimit = state.pageSize === 'all' ? items.length : state.pageSize;
            render();
        });

        footer.addEventListener('click', (event) => {
            const button = event.target.closest('[data-card-action="more"]');
            if (!button) {
                return;
            }

            state.visibleLimit += state.pageSize === 'all' ? items.length : state.pageSize;
            render();
        });

        render();
    });

    document.querySelectorAll('[data-modal-close]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            trigger.closest('dialog')?.close();
        });
    });

    if (activeModal) {
        const dialog = document.getElementById(activeModal);
        const template = document.getElementById('modal-error-template');
        const form = dialog?.querySelector('form');
        const head = dialog?.querySelector('.modal-head');

        if (dialog?.showModal) {
            if (template && form && head) {
                head.insertAdjacentElement('afterend', template.content.cloneNode(true).firstElementChild);
            }
            dialog.showModal();
        }
    }
</script>
</body>
</html>

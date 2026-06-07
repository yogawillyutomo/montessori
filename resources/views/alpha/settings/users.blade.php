@extends('alpha.layout')

@section('title', 'Setting User - Montessori Bloom')
@section('page_title', 'Setting User')
@section('page_subtitle', 'Kelola akun login, role, dan status akses pengguna.')

@section('content')
    <div class="section-head">
        <div>
            <h2>User & Login</h2>
            <div class="meta">Role di sini akan menjadi dasar tampilan dan akses setelah autentikasi penuh.</div>
        </div>
        <button class="btn primary" type="button" data-modal-target="modal-create-user">Tambah User</button>
    </div>

    <section class="panel">
        <div class="grid kpi">
            @foreach ($roleOptions as $role => $label)
                <div class="metric">
                    <span>{{ $label }}</span>
                    <strong>{{ $users->where('role', $role)->count() }}</strong>
                    <span>{{ $users->where('role', $role)->where('is_active', true)->count() }} aktif</span>
                </div>
            @endforeach
        </div>

        <div class="table-wrap" style="margin-top: 16px">
            <table data-table data-table-page-size="10" data-table-search-placeholder="Cari nama, email, role">
                <thead>
                <tr>
                    <th>Nama</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Telepon</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($users as $user)
                    @php $editModal = "modal-edit-user-{$user->id}"; @endphp
                    <tr>
                        <td><strong>{{ $user->name }}</strong></td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $roleOptions[$user->role] ?? $user->role }}</td>
                        <td>{{ $user->phone ?? '-' }}</td>
                        <td>
                            <span class="status status-{{ $user->is_active ? 'present' : 'empty' }}">
                                {{ $user->is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </td>
                        <td>
                            <div class="toolbar compact-actions">
                                <button class="btn ghost" type="button" data-modal-target="{{ $editModal }}">Edit</button>
                                @if (! $currentUser?->is($user))
                                    <button class="btn danger" type="button" data-delete-action="{{ route('alpha.settings.users.destroy', $user) }}" data-delete-label="Hapus user {{ $user->name }}? Akun ini tidak bisa login lagi setelah dihapus.">Hapus</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @foreach ($users as $user)
        <dialog class="modal" id="modal-edit-user-{{ $user->id }}">
            <form method="post" action="{{ route('alpha.settings.users.update', $user) }}">
                @csrf
                @method('patch')
                <div class="modal-head">
                    <div>
                        <h3>Edit User</h3>
                        <div class="meta">{{ $user->email }}</div>
                    </div>
                    <button class="icon-btn" type="button" data-modal-close aria-label="Tutup">
                        <i data-lucide="x" class="nav-icon"></i>
                    </button>
                </div>
                @include('alpha.settings._user-form', ['user' => $user, 'roleOptions' => $roleOptions, 'mode' => 'edit'])
                <div class="toolbar modal-actions">
                    <button class="btn ghost" type="button" data-modal-close>Batal</button>
                    <button class="btn primary" type="submit">Simpan User</button>
                </div>
            </form>
        </dialog>
    @endforeach

    <dialog class="modal" id="modal-create-user">
        <form method="post" action="{{ route('alpha.settings.users.store') }}">
            @csrf
            <div class="modal-head">
                <div>
                    <h3>Tambah User</h3>
                    <div class="meta">User baru langsung bisa login jika status aktif.</div>
                </div>
                <button class="icon-btn" type="button" data-modal-close aria-label="Tutup">
                    <i data-lucide="x" class="nav-icon"></i>
                </button>
            </div>
            @include('alpha.settings._user-form', ['user' => null, 'roleOptions' => $roleOptions, 'mode' => 'create'])
            <div class="toolbar modal-actions">
                <button class="btn ghost" type="button" data-modal-close>Batal</button>
                <button class="btn primary" type="submit">Tambah User</button>
            </div>
        </form>
    </dialog>
@endsection

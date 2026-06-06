<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Montessori Alpha')</title>
    <link rel="stylesheet" href="{{ asset('css/alpha.css') }}">
</head>
<body>
@php
    $roleParam = ['role' => $activeRole];
    $menus = [
        'Operasional' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => 'alpha.dashboard', 'dot' => 'sage'],
        ],
        'Master Data' => [
            ['key' => 'master', 'label' => 'Kelas, siswa, guru, indikator', 'route' => 'alpha.master', 'dot' => 'teal'],
        ],
        'Proses' => [
            ['key' => 'process', 'label' => 'Jadwal, sesi, observasi, ILP', 'route' => 'alpha.process', 'dot' => 'coral'],
        ],
        'Laporan' => [
            ['key' => 'reports', 'label' => 'Draft rapor otomatis', 'route' => 'alpha.reports', 'dot' => 'blue'],
        ],
    ];
@endphp
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">M</div>
            <div>
                <strong>Montessori Alpha</strong>
                <span>Sistem monitoring kelas</span>
            </div>
        </div>

        @foreach ($menus as $group => $items)
            <nav class="nav-group" aria-label="{{ $group }}">
                <div class="nav-title">{{ $group }}</div>
                @foreach ($items as $item)
                    <a class="nav-link {{ $activeMenu === $item['key'] ? 'active' : '' }}" href="{{ route($item['route'], $roleParam) }}">
                        <span class="dot {{ $item['dot'] }}"></span>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        @endforeach

        <div class="user-box">
            <strong>{{ $roleLabel }}</strong>
            <span>Mode alpha tanpa login dulu</span>
        </div>
    </aside>

    <main class="content">
        <header class="topbar">
            <div>
                <h1>@yield('page_title')</h1>
                <div class="meta">@yield('page_subtitle')</div>
            </div>
            <form class="role-switch" method="get">
                <label class="meta" for="role">Role</label>
                <select id="role" name="role" onchange="this.form.submit()">
                    @foreach ($roles as $key => $label)
                        <option value="{{ $key }}" @selected($activeRole === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </header>

        <section class="page">
            @if (session('status'))
                <div class="notice">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
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
</body>
</html>

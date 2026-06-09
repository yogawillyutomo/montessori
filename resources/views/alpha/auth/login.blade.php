<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Montessori Bloom</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/alpha.css') }}?v={{ filemtime(public_path('css/alpha.css')) }}">
</head>
<body class="login-page">
    <div class="bg-blob blob-1"></div>
    <div class="bg-blob blob-2"></div>
    <div class="bg-blob blob-3"></div>
    <main class="login-shell">
        <section class="login-card">
            <div class="login-brand">
                <div class="brand-mark">M</div>
                <div>
                    <strong>Montessori Bloom</strong>
                    <span>Learning Management</span>
                </div>
            </div>

            <div>
                <h1>Masuk ke sistem</h1>
                <p>Gunakan akun admin, guru, atau orangtua yang sudah dibuat di Setting User.</p>
            </div>

            @if (session('status'))
                <div class="notice">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="notice error">
                    <strong>Login belum berhasil.</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="post" action="{{ route('login.store') }}" class="login-form">
                @csrf
                <label class="field">
                    <span>Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" placeholder="admin@montessori.test" autofocus required>
                </label>
                <label class="field">
                    <span>Password</span>
                    <input type="password" name="password" placeholder="password" required>
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" name="remember" value="1">
                    <span>Ingat saya</span>
                </label>
                <button class="btn primary" type="submit">Login</button>
            </form>
        </section>
    </main>
</body>
</html>

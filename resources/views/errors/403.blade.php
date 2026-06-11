<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Akses Ditolak - Montessori Bloom</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f8fafc; color: #111827; }
        .panel { width: min(92vw, 520px); background: #fff; border: 1px solid #e5e7eb; border-radius: 20px; padding: 32px; box-shadow: 0 18px 50px rgba(15, 23, 42, .08); }
        .code { color: #2563eb; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 10px 0; font-size: clamp(28px, 6vw, 42px); line-height: 1.05; }
        p { color: #6b7280; line-height: 1.65; margin: 0 0 24px; }
        a { display: inline-flex; align-items: center; min-height: 42px; padding: 0 16px; border-radius: 12px; background: #2563eb; color: #fff; text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
    <main class="panel">
        <div class="code">403</div>
        <h1>Akses tidak tersedia</h1>
        <p>Maaf, Anda tidak memiliki akses ke halaman ini. Silakan kembali ke dashboard atau hubungi admin sekolah jika akses ini diperlukan.</p>
        <a href="{{ route('alpha.dashboard') }}">Kembali ke Dashboard</a>
    </main>
</body>
</html>

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sesi Berakhir - Montessori Bloom</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f8fafc; color: #111827; }
        .panel { width: min(92vw, 520px); background: #fff; border: 1px solid #e5e7eb; border-radius: 20px; padding: 32px; box-shadow: 0 18px 50px rgba(15, 23, 42, .08); }
        .code { color: #d97706; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 10px 0; font-size: clamp(28px, 6vw, 42px); line-height: 1.05; }
        p { color: #6b7280; line-height: 1.65; margin: 0 0 24px; }
        a { display: inline-flex; align-items: center; min-height: 42px; padding: 0 16px; border-radius: 12px; background: #2563eb; color: #fff; text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>
    <main class="panel">
        <div class="code">419</div>
        <h1>Sesi berakhir</h1>
        <p>Form tidak dapat diproses karena sesi sudah berakhir. Silakan masuk kembali lalu ulangi tindakan Anda.</p>
        <a href="{{ route('login') }}">Masuk Kembali</a>
    </main>
</body>
</html>

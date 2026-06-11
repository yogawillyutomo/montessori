# Montessori Bloom

Montessori Bloom adalah aplikasi Laravel untuk manajemen pembelajaran Montessori: master data sekolah, jadwal mingguan, sesi kelas, presensi, observasi, ILP, dan draft rapor.

Project ini masih fase alpha, tetapi struktur utamanya sudah disiapkan agar aman dikembangkan menuju production: route berbasis role, scope data per user, seeder demo, dan test authorization.

## Fitur Utama

- Dashboard monitoring sesuai data yang boleh diakses user.
- Master data tahun ajaran, term, level, kelas, siswa, wali, guru, area perkembangan, dan indikator.
- Jadwal mingguan fleksibel dengan peserta lintas kelas.
- Pembuatan sesi/presensi dari jadwal mingguan.
- Input presensi per siswa.
- Observasi indikator perkembangan per sesi.
- Draft ILP otomatis dari observasi yang butuh stimulasi.
- Draft rapor otomatis dari observasi, presensi, dan ILP.
- User dan login dengan role berbasis middleware.

## Role Pengguna

- `super_admin` - akses penuh, termasuk user dan role.
- `admin` - operasional sekolah, master data, proses, import, dan generate rapor.
- `teacher` - jadwal, sesi, presensi, observasi, ILP, dan rapor siswa yang terkait dengannya.
- `parent` - data/rapor anak sendiri yang sudah dipublish.
- `principal` - monitoring, rekap, dan akses laporan sekolah.

## Tech Stack

- Laravel 13
- Blade
- Tailwind CSS lewat Vite
- SQLite untuk development lokal
- Siap diarahkan ke MySQL/PostgreSQL dari `.env`

## Setup Lokal

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev
php artisan serve
```

Untuk SQLite lokal, file `database/database.sqlite` akan dibuat otomatis saat artisan/app berjalan.

Jika port 8000 sedang dipakai:

```bash
php artisan serve --port=8010
```

## Akun Default Development

Semua akun demo memakai password:

```text
password
```

- Super Admin: `admin@montessori.test`
- Admin Operasional: `ops@montessori.test`
- Kepala Sekolah: `principal@montessori.test`
- Guru: `raras@montessori.test`
- Guru: `mira@montessori.test`
- Orang Tua: `parent@montessori.test`

Ganti password default sebelum dipakai di environment production.

## Struktur Penting

- `app/Http/Middleware/EnsureUserHasRole.php` - middleware role route.
- `app/Services/Alpha/AccessScopeService.php` - scope data siswa, kelas, dan rapor per role.
- `app/Support/Alpha/Role.php` - daftar role dan label tampilan.
- `app/Http/Controllers/Alpha` - controller alpha app saat ini.
- `resources/views/alpha` - Blade UI alpha.
- `resources/views/errors` - halaman error production-friendly.
- `database/seeders/DatabaseSeeder.php` - data demo dan akun awal.
- `tests/Feature/AuthorizationTest.php` - test pembatasan akses.

## Catatan Production

- Set `APP_ENV=production` dan `APP_DEBUG=false`.
- Jangan commit `.env`, database SQLite lokal, log, cache, `vendor`, `node_modules`, atau `public/build`.
- Gunakan MySQL/PostgreSQL untuk production.
- Jalankan migration dari pipeline/deploy yang terkontrol.
- Pastikan akun Super Admin default sudah diganti passwordnya.

## Validasi

```bash
php artisan optimize:clear
php artisan test
```

## Roadmap Singkat

- Pecah controller besar menjadi controller per modul.
- Pindahkan validasi ke Form Request.
- Lengkapi workflow rapor: reviewed, approved, published, archived.
- Tambahkan policy untuk Student, Report, Observation, ClassSession, dan User.
- Perbaiki UX import dengan preview dan error per baris.
- Tambahkan export/print PDF rapor.

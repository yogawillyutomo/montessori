# Montessori Bloom

Montessori Bloom adalah aplikasi Laravel untuk manajemen pembelajaran Montessori. Implementasi saat ini masih fase alpha dan sedang diarahkan menjadi **Montessori Child Development & Learning Documentation Platform** dengan operasional session-based yang fleksibel.

Struktur alpha saat ini sudah memiliki master data sekolah, jadwal mingguan, sesi kelas, presensi, observasi, ILP, draft rapor, route berbasis role, scope data per user, seeder demo, dan test authorization. Beberapa perilaku alpha masih akan direvisi agar sesuai dengan business baseline terbaru.

## Business & Domain Documentation

Business workflow terbaru menjadi acuan untuk perubahan arsitektur berikutnya. Mulai dari:

- [`docs/business/README.md`](docs/business/README.md) — index dan prinsip domain.
- [`01-business-workflow-domain-model.md`](docs/business/01-business-workflow-domain-model.md) — session, environment, presentation, observation, progress, attendance, reporting.
- [`02-scheduling-reschedule-makeup.md`](docs/business/02-scheduling-reschedule-makeup.md) — recurring schedule, booking, reschedule, cancellation, makeup, capacity.
- [`03-enrollment-entitlement-package-rules.md`](docs/business/03-enrollment-entitlement-package-rules.md) — flexible plan seperti Infant 8x/month dan Glow 4x/month, entitlement, credit, adjustment.
- [`04-report-eligibility-observation-maturity.md`](docs/business/04-report-eligibility-observation-maturity.md) — minimum observation period, attended-session threshold, guide confirmation, first-report eligibility.

Prinsip penting:

> Business workflow menentukan desain software, bukan keterbatasan implementasi alpha yang menentukan workflow sekolah.

Jika README dan implementasi alpha berbeda dengan dokumen business baseline, perbedaan tersebut harus dianggap sebagai **migration gap yang perlu dibahas dan diuji**, bukan alasan untuk diam-diam mengubah aturan bisnis.

## Implementasi Alpha Saat Ini

- Dashboard monitoring sesuai data yang boleh diakses user.
- Master data tahun ajaran, term, level, kelas, siswa, wali, guru, area perkembangan, dan indikator.
- Jadwal mingguan fleksibel dengan peserta lintas kelas.
- Pembuatan sesi/presensi dari jadwal mingguan.
- Sesi Belajar sebagai ruang aktivitas harian guru.
- Panel presensi ringan per sesi, termasuk Semua Hadir dan Reset Presensi.
- Observasi terjadwal di dalam sesi belajar.
- Observasi spontan tanpa wajib terhubung ke sesi belajar.
- Draft ILP otomatis dari observasi yang butuh stimulasi **(perilaku alpha; akan dievaluasi terhadap business baseline)**.
- Draft rapor otomatis dari observasi, presensi, dan ILP **(perilaku alpha; eligibility/report workflow akan direvisi)**.
- User dan login dengan role berbasis middleware.

## Role Pengguna

- `super_admin` - akses penuh, termasuk user dan role.
- `admin` - operasional sekolah, master data, proses, import, dan generate rapor.
- `teacher` - jadwal, sesi, presensi, observasi, ILP, dan rapor siswa yang terkait dengannya.
- `parent` - data/rapor anak sendiri yang sudah dipublish.
- `principal` - monitoring, rekap, dan akses laporan sekolah.

Role di atas adalah role implementasi alpha saat ini. Business baseline mengarah pada pemisahan yang lebih kontekstual melalui environment assignment, guide responsibility, dan scoped access.

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
- `docs/business` - source of truth untuk business/domain baseline yang sedang disepakati.

## Alur Sesi Belajar dan Observasi — Alpha

Sesi Belajar adalah wadah aktivitas harian guru. Presensi, catatan kelas, observasi cepat, dan tutup sesi berada di ruang yang sama, tetapi presensi tidak menjadi syarat wajib untuk membuat observasi.

Observasi mendukung dua mode:

- Terjadwal: terhubung ke Sesi Belajar, jadwal, kelas, guru, dan siswa.
- Spontan: tidak wajib terhubung ke sesi; cukup memilih tanggal, guru, siswa, area perkembangan, level perkembangan, dan catatan naratif.

Level perkembangan observasi alpha: `emerging`, `developing`, `independent`, `exceeding`.

Business baseline baru memisahkan lebih tegas antara `Presentation`, `Observation`, `Development Progress`, dan `Progress Report`.

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

Prioritas berikutnya harus mengikuti business baseline terlebih dahulu sebelum refactor besar.

- Mapping gap antara implementasi alpha dan `docs/business`.
- Hardening authorization untuk session/child access.
- Refactor scheduling menjadi recurring schedule + session occurrence + child booking + booking movement.
- Mendukung delayed/retroactive attendance tanpa auto-absent/time lock.
- Menambahkan configurable Session Plan, Period Entitlement, Session Credit, dan adjustment.
- Menambahkan Report Policy dan first-report eligibility berbasis observation maturity + attended sessions + guide confirmation.
- Mengubah automatic ILP menjadi follow-up candidate/review flow sesuai keputusan bisnis.
- Lengkapi workflow rapor: reviewed, approved, published, archived/revision.
- Pecah controller besar menjadi controller/domain service per modul setelah guardrail dan business rules memiliki regression test.
- Pindahkan validasi ke Form Request.
- Tambahkan policy untuk Student, Report, Observation, ClassSession/SessionOccurrence, dan User.
- Perbaiki UX import dengan preview dan error per baris.
- Tambahkan export/print PDF rapor.
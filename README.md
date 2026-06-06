# Montessori Alpha

Aplikasi alpha untuk sistem monitoring kelas Montessori. Fokus versi ini adalah fondasi Laravel dengan migration, seed data, dan alur awal yang memisahkan **Master Data**, **Proses Harian**, dan **Laporan/Rapor**.

## Fitur Alpha

- Dashboard monitoring operasional.
- Master data kelas, siswa, orangtua, guru, area perkembangan, dan indikator.
- Jadwal mingguan fleksibel.
- Pembuatan sesi dari jadwal mingguan.
- Input observasi per siswa dan indikator.
- Draft ILP otomatis saat observasi berstatus perlu stimulasi.
- Draft rapor otomatis dari data observasi.
- Simulasi role via dropdown: Super Admin, Admin, Guru, Orangtua.

## Setup Lokal

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

Jika port 8000 sedang dipakai:

```bash
php artisan serve --port=8010
```

## Akun Demo

Semua akun demo memakai password:

```text
password
```

- `super@montessori.test`
- `admin@montessori.test`
- `raras@montessori.test`
- `mira@montessori.test`
- `parent@montessori.test`

## Catatan Data

Workbook Excel asli tidak ikut disimpan di repository karena berisi data anak/orangtua. Struktur workbook dipakai sebagai acuan migration dan seeder alpha.

## Validasi

```bash
php artisan test
```

<?php

namespace App\Services\Alpha;

use App\Models\DevelopmentArea;
use App\Models\Guardian;
use App\Models\Indicator;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MasterImportService
{
    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    /**
     * @return array{created: int, updated: int}
     */
    public function importStudents(array $rows): array
    {
        $classes = SchoolClass::query()->get()->flatMap(fn (SchoolClass $class) => [
            $this->reader->lookupKey($class->name) => $class,
            $this->reader->lookupKey($class->slug) => $class,
        ]);

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $code = $this->reader->rowValue($row, ['kode', 'code']);
            $name = $this->reader->rowValue($row, ['nama', 'nama_siswa', 'name']);
            $className = $this->reader->rowValue($row, ['kelas', 'class', 'school_class']);

            if (! $code || ! $name || ! $className) {
                $errors[] = "Baris {$line}: kode, nama, dan kelas wajib diisi.";
                continue;
            }

            $class = $classes->get($this->reader->lookupKey($className));
            if (! $class) {
                $errors[] = "Baris {$line}: kelas {$className} tidak ditemukan.";
                continue;
            }

            $guardianId = null;
            $guardianName = $this->reader->rowValue($row, ['nama_wali', 'wali', 'guardian_name']);
            if ($guardianName) {
                $guardian = Guardian::query()->firstOrCreate([
                    'name' => $guardianName,
                ], [
                    'relationship' => $this->reader->rowValue($row, ['relasi_wali', 'relasi', 'guardian_relationship']) ?: 'Orangtua',
                    'phone' => $this->reader->rowValue($row, ['telepon_wali', 'phone_wali', 'guardian_phone']),
                    'email' => $this->reader->rowValue($row, ['email_wali', 'guardian_email']),
                    'address' => $this->reader->rowValue($row, ['alamat_wali', 'guardian_address']),
                ]);
                $guardianId = $guardian->id;
            }

            $student = Student::query()->updateOrCreate([
                'code' => $code,
            ], [
                'school_class_id' => $class->id,
                'guardian_id' => $guardianId,
                'name' => $name,
                'gender' => $this->reader->rowValue($row, ['gender', 'jenis_kelamin']),
                'birth_place' => $this->reader->rowValue($row, ['tempat_lahir', 'birth_place']),
                'birth_date' => $this->reader->parseImportDate($this->reader->rowValue($row, ['tanggal_lahir', 'birth_date'])),
                'status' => $this->studentStatus($this->reader->rowValue($row, ['status'])),
                'medical_notes' => ($note = $this->reader->rowValue($row, ['catatan', 'catatan_kesehatan', 'medical_note']))
                    ? ['note' => $note]
                    : null,
            ]);

            $student->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->throwImportErrors($errors);

        return compact('created', 'updated');
    }

    /**
     * @return array{created: int, updated: int}
     */
    public function importTeachers(array $rows): array
    {
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $code = $this->reader->rowValue($row, ['kode', 'code']);
            $name = $this->reader->rowValue($row, ['nama', 'name']);

            if (! $code || ! $name) {
                $errors[] = "Baris {$line}: kode dan nama wajib diisi.";
                continue;
            }

            $teacher = Teacher::query()->updateOrCreate([
                'code' => $code,
            ], [
                'name' => $name,
                'focus_area' => $this->reader->rowValue($row, ['fokus_area', 'focus_area', 'fokus']),
                'phone' => $this->reader->rowValue($row, ['telepon', 'phone', 'hp']),
                'is_active' => $this->activeFlag($this->reader->rowValue($row, ['status', 'aktif']), true),
            ]);

            $teacher->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->throwImportErrors($errors);

        return compact('created', 'updated');
    }

    /**
     * @return array{created: int, updated: int}
     */
    public function importIndicators(array $rows): array
    {
        $created = 0;
        $updated = 0;
        $errors = [];
        $areas = DevelopmentArea::query()->get()->flatMap(fn (DevelopmentArea $area) => [
            $this->reader->lookupKey($area->name) => $area,
            $this->reader->lookupKey($area->slug) => $area,
        ]);

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $areaName = $this->reader->rowValue($row, ['area', 'area_perkembangan']);
            $code = $this->reader->rowValue($row, ['kode', 'code']);
            $subArea = $this->reader->rowValue($row, ['sub_area', 'subarea', 'aspek']);
            $description = $this->reader->rowValue($row, ['indikator', 'description', 'deskripsi']);

            if (! $areaName || ! $code || ! $subArea || ! $description) {
                $errors[] = "Baris {$line}: area, kode, sub_area, dan indikator wajib diisi.";
                continue;
            }

            $area = $areas->get($this->reader->lookupKey($areaName));
            if (! $area) {
                $area = DevelopmentArea::create([
                    'name' => $areaName,
                    'slug' => $this->uniqueSlug('development_areas', $areaName),
                    'color' => 'sage',
                    'sort_order' => (int) DevelopmentArea::query()->max('sort_order') + 1,
                ]);
                $areas[$this->reader->lookupKey($area->name)] = $area;
                $areas[$this->reader->lookupKey($area->slug)] = $area;
            }

            $indicator = Indicator::query()->updateOrCreate([
                'code' => $code,
            ], [
                'development_area_id' => $area->id,
                'sub_area' => $subArea,
                'description' => $description,
                'level' => $this->reader->rowValue($row, ['level', 'kelas']),
                'is_active' => $this->activeFlag($this->reader->rowValue($row, ['status', 'aktif']), true),
            ]);

            $indicator->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->throwImportErrors($errors);

        return compact('created', 'updated');
    }

    private function studentStatus(?string $value): string
    {
        return match ($this->reader->lookupKey($value)) {
            'inactive', 'nonaktif', 'tidak_aktif' => 'inactive',
            'graduated', 'lulus' => 'graduated',
            default => 'active',
        };
    }

    private function activeFlag(?string $value, bool $default): bool
    {
        if (! filled($value)) {
            return $default;
        }

        return ! in_array($this->reader->lookupKey($value), ['0', 'false', 'inactive', 'nonaktif', 'tidak_aktif'], true);
    }

    private function uniqueSlug(string $table, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $counter = 2;

        while (DB::table($table)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function throwImportErrors(array $errors): void
    {
        if ($errors) {
            throw ValidationException::withMessages(array_slice($errors, 0, 8));
        }
    }
}

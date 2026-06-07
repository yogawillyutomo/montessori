<?php

namespace App\Http\Controllers\Alpha\Concerns;

use Illuminate\Http\Request;

trait ProvidesAlphaShell
{
    /**
     * @var array<string, string>
     */
    private array $roles = [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'teacher' => 'Guru',
        'parent' => 'Orangtua',
    ];

    /**
     * @return array<string, mixed>
     */
    private function shell(Request $request, string $activeMenu): array
    {
        $user = $request->user();
        $role = $user?->role ?? 'admin';

        return [
            'activeMenu' => $activeMenu,
            'activeRole' => $role,
            'roles' => $this->roles,
            'roleLabel' => $this->roles[$role] ?? str($role)->headline()->toString(),
            'currentUser' => $user,
            'statusLabels' => $this->statusLabels(),
            'dayLabels' => $this->dayLabels(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            'achieved' => 'SM - Sudah maksimal',
            'emerging' => 'SB - Sudah berkembang',
            'needs_support' => 'SD - Sedang berkembang',
            'not_observed' => 'Belum diamati',
            'planned' => 'Direncanakan',
            'completed' => 'Selesai',
            'cancelled' => 'Dibatalkan',
            'present' => 'Hadir',
            'absent' => 'Tidak hadir',
            'late' => 'Terlambat',
            'sick' => 'Sakit',
            'excused' => 'Izin',
            'draft' => 'Draft',
            'in_progress' => 'Berjalan',
            'empty' => 'Belum ada data',
            'published' => 'Publish',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function dayLabels(): array
    {
        return [
            1 => 'Senin',
            2 => 'Selasa',
            3 => 'Rabu',
            4 => 'Kamis',
            5 => 'Jumat',
            6 => 'Sabtu',
            7 => 'Minggu',
        ];
    }
}

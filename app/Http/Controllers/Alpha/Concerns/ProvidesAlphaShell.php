<?php

namespace App\Http\Controllers\Alpha\Concerns;

use App\Support\Alpha\Role;
use Illuminate\Http\Request;

trait ProvidesAlphaShell
{
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
            'roles' => Role::labels(),
            'roleLabel' => Role::label($role),
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
            'reviewed' => 'Sudah direview',
            'approved' => 'Disetujui',
            'in_progress' => 'Berjalan',
            'empty' => 'Belum ada data',
            'published' => 'Dipublish',
            'archived' => 'Diarsipkan',
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

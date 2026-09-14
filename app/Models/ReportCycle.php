<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use LogicException;

#[Fillable([
    'report_policy_id',
    'name',
    'window_start',
    'window_end',
    'cutoff_date',
    'status',
])]
class ReportCycle extends Model
{
    use HasFactory;

    public const STATUSES = [
        'open',
        'closed',
    ];

    private const IMMUTABLE_WHEN_CLOSED = [
        'report_policy_id',
        'name',
        'window_start',
        'window_end',
        'cutoff_date',
    ];

    protected static function booted(): void
    {
        static::saving(function (ReportCycle $cycle): void {
            $cycle->name = trim((string) $cycle->name);

            if ($cycle->name === '') {
                throw ValidationException::withMessages([
                    'name' => 'Nama report cycle wajib diisi.',
                ]);
            }

            if (! in_array($cycle->status, self::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Status report cycle tidak valid.',
                ]);
            }

            $windowStart = $cycle->window_start?->toDateString();
            $windowEnd = $cycle->window_end?->toDateString();
            $cutoff = $cycle->cutoff_date?->toDateString();

            if (! $windowStart || ! $windowEnd || ! $cutoff) {
                throw ValidationException::withMessages([
                    'window_start' => 'Window start, window end, dan cutoff date wajib diisi.',
                ]);
            }

            if ($windowEnd < $windowStart) {
                throw ValidationException::withMessages([
                    'window_end' => 'Window end tidak boleh sebelum window start.',
                ]);
            }

            if ($cutoff < $windowStart || $cutoff > $windowEnd) {
                throw ValidationException::withMessages([
                    'cutoff_date' => 'Cutoff date harus berada di dalam evidence window report cycle.',
                ]);
            }

            $policy = $cycle->reportPolicy()->first();

            if (! $policy) {
                throw ValidationException::withMessages([
                    'report_policy_id' => 'Report policy tidak ditemukan.',
                ]);
            }

            if (! $cycle->exists && ! $policy->is_active) {
                throw ValidationException::withMessages([
                    'report_policy_id' => 'Report cycle baru hanya dapat dibuat dari report policy aktif.',
                ]);
            }

            $overlap = self::query()
                ->where('report_policy_id', $cycle->report_policy_id)
                ->whereDate('window_start', '<=', $windowEnd)
                ->whereDate('window_end', '>=', $windowStart)
                ->when($cycle->exists, fn ($query) => $query->whereKeyNot($cycle->getKey()))
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'window_start' => 'Evidence window report cycle tidak boleh overlap dengan cycle lain pada policy yang sama.',
                ]);
            }

            if ($cycle->exists && $cycle->getOriginal('status') === 'closed') {
                if ($cycle->status !== 'closed') {
                    throw ValidationException::withMessages([
                        'status' => 'Report cycle yang sudah ditutup tidak dapat dibuka kembali melalui mutation biasa.',
                    ]);
                }

                foreach (self::IMMUTABLE_WHEN_CLOSED as $field) {
                    if ($cycle->isDirty($field)) {
                        throw ValidationException::withMessages([
                            $field => 'Report cycle yang sudah ditutup tidak boleh diubah retroaktif.',
                        ]);
                    }
                }
            }
        });

        static::deleting(function (ReportCycle $cycle): void {
            if ($cycle->status === 'closed') {
                throw new LogicException('Report cycle yang sudah ditutup tidak boleh dihapus.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'window_start' => 'date',
            'window_end' => 'date',
            'cutoff_date' => 'date',
        ];
    }

    public function reportPolicy(): BelongsTo
    {
        return $this->belongsTo(ReportPolicy::class);
    }
}

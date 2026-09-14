<?php

namespace App\Http\Controllers\Alpha;

use App\Http\Controllers\Controller;
use App\Models\DevelopmentArea;
use App\Models\Indicator;
use App\Services\Alpha\MasterImportService;
use App\Services\Alpha\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CurriculumController extends Controller
{
    public function storeArea(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('development_areas', 'name')],
            'slug' => ['nullable', 'alpha_dash', 'max:120', Rule::unique('development_areas', 'slug')],
            'color' => ['required', 'in:sage,teal,coral,blue,gold,plum'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        DevelopmentArea::create([
            ...$validated,
            'slug' => $validated['slug'] ?: $this->uniqueSlug('development_areas', $validated['name']),
        ]);

        return back()->with('status', 'Area perkembangan berhasil ditambahkan.');
    }

    public function updateArea(Request $request, DevelopmentArea $developmentArea): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('development_areas', 'name')->ignore($developmentArea)],
            'slug' => ['nullable', 'alpha_dash', 'max:120', Rule::unique('development_areas', 'slug')->ignore($developmentArea)],
            'color' => ['required', 'in:sage,teal,coral,blue,gold,plum'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $developmentArea->update([
            ...$validated,
            'slug' => $validated['slug'] ?: $this->uniqueSlug('development_areas', $validated['name'], $developmentArea->id),
        ]);

        return back()->with('status', 'Area perkembangan berhasil diperbarui.');
    }

    public function destroyArea(DevelopmentArea $developmentArea): RedirectResponse
    {
        if ($developmentArea->indicators()->exists()) {
            return back()->withErrors('Area tidak bisa dihapus karena masih memiliki indikator.');
        }

        $developmentArea->delete();

        return back()->with('status', 'Area perkembangan berhasil dihapus.');
    }

    public function storeIndicator(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'development_area_id' => ['required', 'exists:development_areas,id'],
            'code' => ['required', 'string', 'max:40', Rule::unique('indicators', 'code')],
            'sub_area' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:1000'],
            'level' => ['nullable', 'string', 'max:80'],
        ]);

        Indicator::create([
            ...$validated,
            'is_active' => true,
        ]);

        return back()->with('status', 'Indikator perkembangan berhasil ditambahkan.');
    }

    public function updateIndicator(Request $request, Indicator $indicator): RedirectResponse
    {
        $validated = $request->validate([
            'development_area_id' => ['required', 'exists:development_areas,id'],
            'code' => ['required', 'string', 'max:40', Rule::unique('indicators', 'code')->ignore($indicator)],
            'sub_area' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:1000'],
            'level' => ['nullable', 'string', 'max:80'],
        ]);

        $indicator->update($validated);

        return back()->with('status', 'Indikator berhasil diperbarui.');
    }

    public function toggleIndicator(Indicator $indicator): RedirectResponse
    {
        $indicator->update(['is_active' => ! $indicator->is_active]);

        return back()->with('status', 'Status indikator berhasil diperbarui.');
    }

    public function destroyIndicator(Indicator $indicator): RedirectResponse
    {
        if ($indicator->observations()->exists() || $indicator->ilpPlans()->exists()) {
            return back()->withErrors('Indikator tidak bisa dihapus karena sudah dipakai observasi atau ILP.');
        }

        $indicator->delete();

        return back()->with('status', 'Indikator berhasil dihapus.');
    }

    public function importIndicators(Request $request, SpreadsheetReader $reader, MasterImportService $importer): RedirectResponse
    {
        $result = $importer->importIndicators($reader->rowsFromRequest($request));

        return back()->with('status', "Import kurikulum selesai. {$result['created']} dibuat, {$result['updated']} diperbarui.");
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
}

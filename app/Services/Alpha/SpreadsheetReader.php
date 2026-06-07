<?php

namespace App\Services\Alpha;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SpreadsheetReader
{
    /**
     * @return array<int, array<string, string>>
     */
    public function rowsFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ]);

        $file = $validated['file'];
        $rawRows = $this->xlsxRows($file->getRealPath());

        $rawRows = array_values(array_filter(
            $rawRows,
            fn (array $row): bool => collect($row)->filter(fn ($cell) => filled($cell))->isNotEmpty()
        ));

        if (count($rawRows) < 2) {
            throw ValidationException::withMessages(['file' => 'File import harus memiliki header dan minimal satu baris data.']);
        }

        $headers = array_map(fn ($header): string => $this->headerKey((string) $header), array_shift($rawRows));
        $rows = [];

        foreach ($rawRows as $rawRow) {
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = trim((string) ($rawRow[$index] ?? ''));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function rowValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$this->headerKey($key)] ?? null;
            if (filled($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    public function lookupKey(?string $value): string
    {
        return Str::slug(strtolower($value ?? ''), '_');
    }

    public function parseImportDate(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function headerKey(string $value): string
    {
        return str($value)
            ->lower()
            ->replace(['/', '-', '.', ' '], '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->toString();
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function xlsxRows(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'File Excel tidak bisa dibuka.']);
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            $zip->close();
            throw ValidationException::withMessages(['file' => 'Sheet pertama tidak ditemukan di file Excel.']);
        }

        $sharedStrings = $this->xlsxSharedStrings($zip);
        $xml = simplexml_load_string($sheet);
        if (! $xml) {
            $zip->close();
            throw ValidationException::withMessages(['file' => 'Sheet Excel tidak bisa dibaca.']);
        }

        $rows = [];
        foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $sheetRow) {
            $cells = [];
            foreach ($sheetRow->xpath('*[local-name()="c"]') ?: [] as $cell) {
                $attributes = $cell->attributes();
                $reference = (string) ($attributes['r'] ?? '');
                $type = (string) ($attributes['t'] ?? '');
                $column = $this->xlsxColumnIndex(preg_replace('/\d+/', '', $reference) ?: 'A');
                $valueNodes = $cell->xpath('*[local-name()="v"]') ?: [];
                $value = isset($valueNodes[0]) ? (string) $valueNodes[0] : '';

                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = collect($cell->xpath('.//*[local-name()="t"]') ?: [])
                        ->map(fn ($node): string => (string) $node)
                        ->implode('');
                }

                $cells[$column] = trim($value);
            }

            if ($cells) {
                ksort($cells);
                $row = [];
                for ($index = 0; $index <= max(array_keys($cells)); $index++) {
                    $row[] = $cells[$index] ?? '';
                }
                $rows[] = $row;
            }
        }

        $zip->close();

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function xlsxSharedStrings(\ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if ($content === false) {
            return [];
        }

        $xml = simplexml_load_string($content);
        if (! $xml) {
            return [];
        }

        $strings = [];
        foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $item) {
            $strings[] = collect($item->xpath('.//*[local-name()="t"]') ?: [])
                ->map(fn ($node): string => (string) $node)
                ->implode('');
        }

        return $strings;
    }

    private function xlsxColumnIndex(string $column): int
    {
        $index = 0;
        foreach (str_split(strtoupper($column)) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}

<?php

namespace App\Services\Alpha;

use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportTemplateService
{
    public function download(string $type): StreamedResponse
    {
        $templates = [
            'students' => [
                'filename' => 'format-import-siswa.xlsx',
                'headers' => ['kode', 'nama', 'kelas', 'status', 'gender', 'tempat_lahir', 'tanggal_lahir', 'nama_wali', 'relasi_wali', 'telepon_wali', 'email_wali', 'alamat_wali', 'catatan'],
                'sample' => ['SUN04', 'Nama Siswa', 'Sunny 1', 'active', 'Perempuan', 'Banyumas', '2021-06-15', 'Nama Wali', 'Ibu', '08xx', 'wali@email.test', 'Alamat singkat', 'Opsional'],
            ],
            'teachers' => [
                'filename' => 'format-import-guru.xlsx',
                'headers' => ['kode', 'nama', 'fokus_area', 'telepon', 'status'],
                'sample' => ['TCH05', 'Bu Sari', 'Bahasa dan Practical Life', '08xx', 'active'],
            ],
            'indicators' => [
                'filename' => 'format-import-kurikulum.xlsx',
                'headers' => ['area', 'kode', 'sub_area', 'indikator', 'level', 'status'],
                'sample' => ['Bahasa', 'BHS04', 'Ekspresi', 'Mampu menyampaikan kebutuhan dengan kalimat sederhana.', 'Sunny', 'active'],
            ],
        ];

        abort_unless(isset($templates[$type]), 404);

        $template = $templates[$type];
        $path = $this->writeXlsx([$template['headers'], $template['sample']]);

        return response()->streamDownload(function () use ($path): void {
            readfile($path);
            @unlink($path);
        }, $template['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function writeXlsx(array $rows): string
    {
        $directory = storage_path('app/import-templates');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = tempnam($directory, 'template_');
        $zip = new \ZipArchive();
        $opened = $zip->open($path, \ZipArchive::OVERWRITE);
        abort_unless($opened === true, 500, 'Template Excel tidak bisa dibuat.');

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($rows));
        $zip->close();

        return $path;
    }

    private function sheetXml(array $rows): string
    {
        $sheetRows = collect($rows)
            ->map(function (array $row, int $rowIndex): string {
                $rowNumber = $rowIndex + 1;
                $cells = collect($row)
                    ->map(function (string $value, int $columnIndex) use ($rowNumber): string {
                        $reference = $this->columnName($columnIndex + 1).$rowNumber;
                        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');

                        return '<c r="'.$reference.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
                    })
                    ->implode('');

                return '<row r="'.$rowNumber.'">'.$cells.'</row>';
            })
            ->implode('');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetData>'.$sheetRows.'</sheetData></worksheet>';
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Format Import" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            .'</styleSheet>';
    }
}

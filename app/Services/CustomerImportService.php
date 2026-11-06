<?php

namespace App\Services;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class CustomerImportService
{
    public const HEADERS = [
        'NIS',
        'Nama Lengkap Siswa',
        'Tingkat Kelas',
        'Jurusan',
        'Kelas',
        'Email Pribadi',
        'Setoran Awal (Rp)',
    ];

    public const FIELD_BY_HEADER = [
        'nis' => 'student_number',
        'nama lengkap siswa' => 'student_name',
        'tingkat kelas' => 'grade_level',
        'jurusan' => 'major_name',
        'kelas' => 'rombel',
        'email pribadi' => 'email',
        'setoran awal (rp)' => 'opening_balance',
    ];

    public function template(?array $rows = null, array $classReferences = []): string
    {
        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="Nasabah" sheetId="1" r:id="rId1"/>'
                .'<sheet name="Referensi Kelas" sheetId="2" r:id="rId2"/>'
                .'<sheet name="Petunjuk Pengisian" sheetId="3" r:id="rId3"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
                .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
                .'<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<numFmts count="2"><numFmt numFmtId="164" formatCode="@"/>'
                .'<numFmt numFmtId="165" formatCode="[$Rp-421] #,##0"/></numFmts>'
                .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
                .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                .'</styleSheet>',
            'xl/worksheets/sheet1.xml' => $this->worksheetXml($rows ?? [self::HEADERS]),
            'xl/worksheets/sheet2.xml' => $this->worksheetXml(array_merge(
                [['Tingkat Kelas', 'Jurusan', 'Kelas', 'Nama Kelas di Sistem']],
                $classReferences
            )),
            'xl/worksheets/sheet3.xml' => $this->worksheetXml([
                ['Kolom', 'Petunjuk Pengisian'],
                ['NIS', 'Nomor Induk Siswa. Simpan sebagai teks agar angka nol di awal tidak hilang.'],
                ['Nama Lengkap Siswa', 'Isi nama lengkap sesuai identitas siswa.'],
                ['Tingkat Kelas', 'Isi X, XI, atau XII.'],
                ['Jurusan', 'Pilih nama jurusan persis seperti yang tercantum pada sheet Referensi Kelas.'],
                ['Kelas', 'Isi nomor rombel sesuai kombinasi tingkat dan jurusan di sheet Referensi Kelas.'],
                ['Email Pribadi', 'Isi email aktif yang dapat diakses siswa. Email belum otomatis terverifikasi.'],
                ['Setoran Awal (Rp)', 'Isi angka Rupiah minimal 10000 tanpa simbol mata uang.'],
                ['Format file', 'Simpan dan unggah file dalam format .xlsx.'],
            ]),
        ];

        $temporaryDirectory = storage_path('app');
        if (! is_dir($temporaryDirectory) || ! is_writable($temporaryDirectory)) {
            throw new RuntimeException('Direktori penyimpanan sementara template tidak tersedia.');
        }

        $path = tempnam($temporaryDirectory, 'customer-template-');
        if ($path === false) {
            throw new RuntimeException('Gagal membuat file template sementara.');
        }

        try {
            $archive = new ZipArchive;
            if ($archive->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Gagal membuat arsip template Excel.');
            }

            try {
                foreach ($entries as $name => $contents) {
                    if (! $archive->addFromString($name, $contents)) {
                        throw new RuntimeException('Gagal menulis isi template Excel.');
                    }
                }
            } finally {
                $archive->close();
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Gagal membaca template Excel.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }

    public function parse(string $path): array
    {
        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            throw new RuntimeException('File bukan XLSX yang valid.');
        }

        try {
            foreach ([
                '[Content_Types].xml',
                'xl/workbook.xml',
                'xl/_rels/workbook.xml.rels',
                'xl/worksheets/sheet1.xml',
            ] as $requiredEntry) {
                if ($archive->locateName($requiredEntry) === false) {
                    throw new RuntimeException('File bukan XLSX yang valid atau struktur workbook tidak lengkap.');
                }
            }

            $contentTypes = $archive->getFromName('[Content_Types].xml');
            $workbookXml = $archive->getFromName('xl/workbook.xml');
            $relationshipsXml = $archive->getFromName('xl/_rels/workbook.xml.rels');
            if (
                $contentTypes === false
                || ! str_contains($contentTypes, 'spreadsheetml.sheet.main+xml')
                || $workbookXml === false
                || $relationshipsXml === false
            ) {
                throw new RuntimeException('File bukan workbook Excel .xlsx yang valid.');
            }
            $this->loadXml($contentTypes);
            $this->loadXml($workbookXml);
            $this->loadXml($relationshipsXml);

            $sheetIndex = $archive->locateName('xl/worksheets/sheet1.xml');
            $sheetStats = $sheetIndex === false ? false : $archive->statIndex($sheetIndex);
            if ($sheetStats === false || $sheetStats['size'] > 10 * 1024 * 1024) {
                throw new RuntimeException('Ukuran isi sheet Excel melebihi batas yang diperbolehkan.');
            }
            $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                throw new RuntimeException('Sheet pertama tidak ditemukan di file Excel.');
            }

            $sharedStrings = [];
            $sharedXml = $archive->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                $sharedIndex = $archive->locateName('xl/sharedStrings.xml');
                $sharedStats = $sharedIndex === false ? false : $archive->statIndex($sharedIndex);
                if ($sharedStats === false || $sharedStats['size'] > 10 * 1024 * 1024) {
                    throw new RuntimeException('Ukuran shared strings Excel melebihi batas yang diperbolehkan.');
                }
                $shared = $this->loadXml($sharedXml);
                foreach ($shared->si as $item) {
                    $sharedStrings[] = $this->sharedStringValue($item);
                }
            }

            $sheet = $this->loadXml($sheetXml);
            $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rows = $sheet->xpath('//x:sheetData/x:row') ?: [];
            if (count($rows) > 5001) {
                throw new RuntimeException('File Excel maksimal berisi 5.000 baris data.');
            }
            $parsedRows = [];

            foreach ($rows as $row) {
                $cells = [];
                $cellTypes = [];
                foreach ($row->c as $cell) {
                    $column = $this->columnIndex((string) $cell['r']);
                    $type = (string) $cell['t'];
                    if ($type === 'inlineStr') {
                        $value = $this->sharedStringValue($cell->is);
                    } else {
                        $value = (string) $cell->v;
                        if ($type === 's') {
                            $value = $sharedStrings[(int) $value] ?? '';
                        }
                    }
                    $cells[$column] = trim($value);
                    $cellTypes[$column] = in_array($type, ['s', 'inlineStr', 'str'], true)
                        ? 'text'
                        : ($type === '' || $type === 'n' ? 'number' : $type);
                }

                if ($cells !== []) {
                    $parsedRows[] = [
                        'row_number' => (int) $row['r'],
                        'cells' => $cells,
                        'cell_types' => $cellTypes,
                    ];
                }
            }

            return $parsedRows;
        } finally {
            $archive->close();
        }
    }

    public function worksheetXml(array $rows): string
    {
        $xmlRows = [];
        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            foreach ($row as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex + 1).($rowIndex + 1);
                if (
                    $columnIndex === 6
                    && $rowIndex > 0
                    && preg_match('/\A\d+\z/', (string) $value)
                ) {
                    $cells[] = '<c r="'.$reference.'" s="2"><v>'.(int) $value.'</v></c>';

                    continue;
                }
                $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $style = $columnIndex === 0 ? ' s="1"' : '';
                $cells[] = '<c r="'.$reference.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'
                    .$escaped.'</t></is></c>';
            }
            $xmlRows[] = '<row r="'.($rowIndex + 1).'">'.implode('', $cells).'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<cols><col min="1" max="1" width="18" style="1" customWidth="1" customFormat="1"/></cols>'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData></worksheet>';
    }

    public function mapRow(array $cells): array
    {
        $mapped = [];
        foreach (self::HEADERS as $index => $header) {
            $field = self::FIELD_BY_HEADER[mb_strtolower($header)];
            $mapped[$field] = $cells[$index] ?? '';
        }

        return $mapped;
    }

    private function loadXml(string $xml): SimpleXMLElement
    {
        $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        if ($element === false) {
            throw new RuntimeException('Struktur XML di file Excel tidak valid.');
        }

        return $element;
    }

    private function sharedStringValue(SimpleXMLElement $item): string
    {
        $item->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $texts = $item->xpath('.//x:t') ?: [];

        return implode('', array_map(static fn ($text) => (string) $text, $texts));
    }

    private function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $matches);
        $index = 0;
        foreach (str_split(strtoupper($matches[0] ?? '')) as $letter) {
            $index = ($index * 26) + ord($letter) - 64;
        }

        return $index - 1;
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }
}

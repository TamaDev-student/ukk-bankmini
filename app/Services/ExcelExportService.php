<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class ExcelExportService
{
    /**
     * @param  array<string, list<list<string|int|float|null|array{type: 'currency', value: int|float}>>>  $sheets
     */
    public function create(array $sheets): string
    {
        if ($sheets === []) {
            throw new RuntimeException('Workbook Excel harus memiliki minimal satu worksheet.');
        }

        $sheetNames = [];
        foreach (array_keys($sheets) as $name) {
            $sheetName = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/u', '_', $name) ?: 'Sheet', 0, 31);
            $sheetNames[] = $sheetName;
        }

        $entries = $this->baseEntries($sheetNames);
        foreach (array_values($sheets) as $index => $rows) {
            $entries['xl/worksheets/sheet'.($index + 1).'.xml'] = $this->worksheetXml($rows);
        }

        $temporaryDirectory = storage_path('app');
        if (! is_dir($temporaryDirectory) || ! is_writable($temporaryDirectory)) {
            throw new RuntimeException('Direktori penyimpanan sementara Excel tidak tersedia.');
        }

        $path = tempnam($temporaryDirectory, 'excel-export-');
        if ($path === false) {
            throw new RuntimeException('Gagal membuat file Excel sementara.');
        }

        try {
            $archive = new ZipArchive;
            if ($archive->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Gagal membuat arsip Excel.');
            }

            foreach ($entries as $entryName => $contents) {
                if (! $archive->addFromString($entryName, $contents)) {
                    $archive->close();
                    throw new RuntimeException('Gagal menulis worksheet Excel.');
                }
            }
            $archive->close();

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Gagal membaca file Excel hasil ekspor.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  list<string>  $sheetNames
     * @return array<string, string>
     */
    private function baseEntries(array $sheetNames): array
    {
        $contentOverrides = '';
        $workbookSheets = '';
        $workbookRelationships = '';
        foreach ($sheetNames as $index => $sheetName) {
            $id = $index + 1;
            $contentOverrides .= '<Override PartName="/xl/worksheets/sheet'.$id.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets .= '<sheet name="'.$this->escape($sheetName).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
            $workbookRelationships .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
        }
        $styleId = count($sheetNames) + 1;
        $workbookRelationships .= '<Relationship Id="rId'.$styleId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .$contentOverrides.'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets>'.$workbookSheets.'</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .$workbookRelationships.'</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0;[Red]-#,##0"/>'
                .'<numFmt numFmtId="165" formatCode="&quot;Rp&quot; #,##0;[Red]-&quot;Rp&quot; #,##0"/></numFmts>'
                .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
                .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                .'</styleSheet>',
        ];
    }

    /**
     * @param  list<list<string|int|float|null|array{type: 'currency', value: int|float}>>  $rows
     */
    private function worksheetXml(array $rows): string
    {
        $xmlRows = [];
        $columnCount = max(0, ...array_map('count', $rows));
        $columns = [];
        for ($columnIndex = 0; $columnIndex < $columnCount; $columnIndex++) {
            $maxLength = 10;
            foreach ($rows as $row) {
                [$value] = $this->normalizeCellValue($row[$columnIndex] ?? null);
                if ($value !== null) {
                    $maxLength = max($maxLength, mb_strlen((string) $value));
                }
            }
            $width = min(60, $maxLength + 2);
            $columnNumber = $columnIndex + 1;
            $columns[] = '<col min="'.$columnNumber.'" max="'.$columnNumber.'" width="'.$width.'" customWidth="1"/>';
        }

        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            $isSectionHeading = count($row) === 1 && is_string($row[0]);
            foreach ($row as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex + 1).($rowIndex + 1);
                $style = $rowIndex === 0 || $isSectionHeading ? ' s="1"' : '';
                [$cellValue, $isCurrency] = $this->normalizeCellValue($value);
                if (is_int($cellValue) || is_float($cellValue)) {
                    $numericStyle = $isCurrency ? '3' : '2';
                    $cells[] = '<c r="'.$reference.'" s="'.$numericStyle.'"><v>'.$cellValue.'</v></c>';
                } elseif ($cellValue !== null && $cellValue !== '') {
                    $cells[] = '<c r="'.$reference.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'
                        .$this->escape((string) $cellValue).'</t></is></c>';
                } else {
                    $cells[] = '<c r="'.$reference.'"'.$style.'/>';
                }

            }
            $xmlRows[] = '<row r="'.($rowIndex + 1).'">'.implode('', $cells).'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.implode('', $columns).'</cols>'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData></worksheet>';
    }

    /**
     * @return array{string|int|float|null, bool}
     */
    private function normalizeCellValue(mixed $value): array
    {
        if (is_array($value)) {
            if (
                ($value['type'] ?? null) !== 'currency'
                || ! (is_int($value['value'] ?? null) || is_float($value['value'] ?? null))
            ) {
                throw new RuntimeException('Nilai sel Excel bertipe khusus tidak valid.');
            }

            return [$value['value'], true];
        }

        if (! (is_string($value) || is_int($value) || is_float($value) || $value === null)) {
            throw new RuntimeException('Nilai sel Excel tidak valid.');
        }

        return [$value, false];
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

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class StudentImportService
{
    /**
     * Parsing file Excel (.xlsx) atau CSV untuk mengekstrak identitas siswa.
     */
    public function parseFile(string $filePath, ?int $academicYearStart = null, string $extension = 'xlsx'): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException('File tidak ditemukan pada server.');
        }

        if (strtolower($extension) === 'csv') {
            return $this->parseCsv($filePath, $academicYearStart);
        }

        return $this->parseXlsx($filePath, $academicYearStart);
    }

    /**
     * Parse file XLSX multi-sheet menggunakan native ZipArchive & SimpleXMLElement.
     */
    public function parseXlsx(string $filePath, ?int $customAcademicYear = null): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('File bukan workbook Excel .xlsx yang valid.');
        }

        try {
            // 1. Baca shared strings jika tersedia
            $sharedStrings = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                $shared = $this->loadXml($sharedXml);
                $shared->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                foreach ($shared->si as $si) {
                    $si->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $texts = [];
                    foreach ($si->xpath('.//x:t') as $t) {
                        $texts[] = (string) $t;
                    }
                    if (empty($texts) && isset($si->t)) {
                        $texts[] = (string) $si->t;
                    }
                    $sharedStrings[] = implode('', $texts);
                }
            }

            // 2. Baca workbook dan relasi sheet
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($workbookXml === false || $relationshipsXml === false) {
                throw new RuntimeException('Struktur workbook Excel tidak lengkap.');
            }

            $wb = $this->loadXml($workbookXml);
            $wb->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rels = $this->loadXml($relationshipsXml);
            $rels->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');

            $sheetMap = [];
            foreach ($rels->Relationship as $rel) {
                $sheetMap[(string) $rel['Id']] = (string) $rel['Target'];
            }

            $extractedStudents = [];
            $detectedAcademicYear = $customAcademicYear;

            // 3. Iterasi setiap sheet
            foreach ($wb->sheets->sheet as $sheetElem) {
                $sheetName = (string) $sheetElem['name'];
                $rId = (string) $sheetElem->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $target = $sheetMap[$rId] ?? '';
                $sheetPath = 'xl/' . ltrim($target, '/');

                $sheetXml = $zip->getFromName($sheetPath);
                if (!$sheetXml) {
                    continue;
                }

                $sheet = $this->loadXml($sheetXml);
                $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $rows = $sheet->xpath('//x:sheetData/x:row') ?: [];

                $currentClassString = null;
                $currentYearInSheet = null;
                $nisCol = null;
                $nameCol = null;
                $inStudentList = false;

                foreach ($rows as $row) {
                    $cells = [];
                    foreach ($row->c as $cell) {
                        $ref = (string) $cell['r'];
                        preg_match('/^[A-Z]+/i', $ref, $m);
                        $colLetter = strtoupper($m[0] ?? '');
                        $type = (string) $cell['t'];
                        $v = (string) $cell->v;
                        $val = ($type === 's') ? ($sharedStrings[(int) $v] ?? '') : $v;
                        $cells[$colLetter] = trim((string) $val);
                    }

                    // Deteksi teks header kelas dan tahun pelajaran
                    foreach ($cells as $cLetter => $cVal) {
                        if (preg_match('/KELAS\s*:\s*([^\r\n,;]+)/i', $cVal, $km)) {
                            $currentClassString = trim($km[1]);
                            $inStudentList = false;
                            $nisCol = null;
                            $nameCol = null;
                            break;
                        }

                        if (preg_match('/TAHUN\s+PELAJARAN\s*([0-9]{4})/i', $cVal, $ym)) {
                            $currentYearInSheet = (int) $ym[1];
                            if ($detectedAcademicYear === null) {
                                $detectedAcademicYear = $currentYearInSheet;
                            }
                        }
                    }

                    // Deteksi baris header NIS dan NAMA SISWA (hanya saat belum berada di dalam daftar siswa)
                    if (!$inStudentList) {
                        $foundNis = false;
                        $foundName = false;
                        foreach ($cells as $cLetter => $cVal) {
                            if (preg_match('/\bNIS\b/i', $cVal) && !preg_match('/NIP/i', $cVal)) {
                                $nisCol = $cLetter;
                                $foundNis = true;
                            }
                            if (preg_match('/NAMA\s*(SISWA|LENGKAP)?/i', $cVal)) {
                                $nameCol = $cLetter;
                                $foundName = true;
                            }
                        }

                        if ($foundNis || $foundName) {
                            $inStudentList = true;
                            continue;
                        }
                    }

                    if ($inStudentList && $currentClassString) {
                        $rowStr = implode(' ', $cells);
                        // Hentikan daftar jika sudah masuk bagian tanda tangan atau header kelas baru
                        if (preg_match('/(Guru\s+Mata\s+Pelajaran|NIP\.?|DAFTAR\s+HADIR|Mengetahui|Kepala\s+Sekolah|Wali\s+Kelas|KELAS\s*:)/i', $rowStr)) {
                            $inStudentList = false;
                            continue;
                        }

                        $nis = $cells[$nisCol ?? 'B'] ?? '';
                        $name = $cells[$nameCol ?? 'C'] ?? '';

                        // Fallback jika kolom nomor urut ada di A dan NIS di B
                        if (empty($nis) && isset($cells['A']) && is_numeric($cells['A']) && isset($cells['B'])) {
                            $nis = $cells['B'];
                        }

                        // Validasi format minimal identitas siswa
                        if (
                            !empty($nis)
                            && preg_match('/^[0-9A-Za-z\-_]+$/', $nis)
                            && !empty($name)
                            && mb_strlen($name) >= 2
                            && !preg_match('/(JUMLAH|NOMOR|NIS|NAMA|SEMESTER|URT|LAKI|PEREMPUAN)/i', $name)
                        ) {
                            $extractedStudents[] = [
                                'raw_nis' => $nis,
                                'raw_name' => $name,
                                'class_string' => $currentClassString,
                                'academic_year' => $currentYearInSheet ?? $detectedAcademicYear,
                            ];
                        }
                    }
                }
            }

            return [
                'students' => $extractedStudents,
                'detected_academic_year' => $detectedAcademicYear,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Parse file CSV sederhana.
     */
    public function parseCsv(string $filePath, ?int $customAcademicYear = null): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new RuntimeException('Gagal membuka file CSV.');
        }

        $extractedStudents = [];
        $currentClassString = null;
        $currentYear = $customAcademicYear;
        $nisIndex = null;
        $nameIndex = null;
        $inStudentList = false;

        try {
            while (($row = fgetcsv($handle, 2048, ',', '"', '\\')) !== false) {
                $rowStr = implode(' ', $row);

                // Cek header kelas
                if (preg_match('/KELAS\s*:\s*([^\r\n,;]+)/i', $rowStr, $km)) {
                    $currentClassString = trim($km[1]);
                    $inStudentList = false;
                    $nisIndex = null;
                    $nameIndex = null;
                }

                if (preg_match('/TAHUN\s+PELAJARAN\s*([0-9]{4})/i', $rowStr, $ym)) {
                    $currentYear = (int) $ym[1];
                }

                // Cek header kolom NIS dan Nama (hanya saat belum berada di dalam daftar siswa)
                if (!$inStudentList) {
                    $foundNis = false;
                    $foundName = false;
                    foreach ($row as $idx => $val) {
                        if (preg_match('/\bNIS\b/i', (string) $val) && !preg_match('/NIP/i', (string) $val)) {
                            $nisIndex = $idx;
                            $foundNis = true;
                        }
                        if (preg_match('/NAMA\s*(SISWA|LENGKAP)?/i', (string) $val)) {
                            $nameIndex = $idx;
                            $foundName = true;
                        }
                    }

                    if ($foundNis || $foundName) {
                        $inStudentList = true;
                        continue;
                    }
                }

                if ($inStudentList && $currentClassString) {
                    if (preg_match('/(Guru\s+Mata\s+Pelajaran|NIP\.?|DAFTAR\s+HADIR|Mengetahui|Kepala\s+Sekolah|Wali\s+Kelas|KELAS\s*:)/i', $rowStr)) {
                        $inStudentList = false;
                        continue;
                    }

                    $nis = trim((string) ($row[$nisIndex] ?? ''));
                    $name = trim((string) ($row[$nameIndex] ?? ''));

                    if (!empty($nis) && preg_match('/^[0-9A-Za-z\-_]+$/', $nis) && !empty($name) && mb_strlen($name) >= 2 && !preg_match('/(JUMLAH|NOMOR|NIS|NAMA|SEMESTER|URT|LAKI|PEREMPUAN)/i', $name)) {
                        $extractedStudents[] = [
                            'raw_nis' => $nis,
                            'raw_name' => $name,
                            'class_string' => $currentClassString,
                            'academic_year' => $currentYear,
                        ];
                    }
                }
            }

            return [
                'students' => $extractedStudents,
                'detected_academic_year' => $currentYear,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Memproses data mentah hasil parse menjadi daftar baris preview lengkap dengan validasi.
     */
    public function generatePreview(array $parsedData, ?int $academicYearOverride = null): array
    {
        $rawStudents = $parsedData['students'] ?? [];
        $baseAcademicYear = $academicYearOverride ?? $parsedData['detected_academic_year'] ?? $this->defaultAcademicYearStart();

        // Ambil semua referensi kelas dan jurusan dari DB
        $classes = SchoolClass::with('major')->get();
        $majors = Major::all();

        // Ambil data existing NIS di DB untuk deteksi duplikasi
        $existingStudentNumbers = Student::query()->pluck('student_number')->flip()->all();
        $existingCustomerNumbers = Customer::query()->pluck('student_number')->flip()->all();
        $existingUsernames = User::query()->pluck('username')->flip()->all();

        $previewRows = [];
        $seenInFile = [];

        $validCount = 0;
        $unconfiguredClassCount = 0;
        $duplicateCount = 0;
        $invalidCount = 0;

        foreach ($rawStudents as $index => $item) {
            $studentNumber = trim((string) ($item['raw_nis'] ?? ''));
            $studentName = trim((string) ($item['raw_name'] ?? ''));
            $classString = trim((string) ($item['class_string'] ?? ''));
            $academicYear = $item['academic_year'] ?? $baseAcademicYear;

            // 1. Parsing informasi kelas: Grade, Major, Rombel
            $classInfo = $this->parseClassString($classString, $classes, $majors);

            $gradeLevel = $classInfo['grade_level'];
            $majorCode = $classInfo['major_code'];
            $rombel = $classInfo['rombel'];
            $matchedClass = $classInfo['school_class'];
            $isClassConfigured = ($matchedClass !== null);

            // 2. Perhitungan tahun angkatan dan kelulusan
            $cohortInfo = $this->calculateCohortYears($gradeLevel, $academicYear);
            $cohortYear = $cohortInfo['cohort_year'];
            $graduationYear = $cohortInfo['graduation_year'];

            // 3. Validasi
            $errors = [];
            $warningMessage = null;
            $status = 'valid';

            if (empty($studentNumber)) {
                $errors[] = 'NIS tidak boleh kosong.';
            }

            if (empty($studentName)) {
                $errors[] = 'Nama siswa tidak boleh kosong.';
            }

            // Peringatan jika rombel kelas belum terkonfigurasi di sistem
            // Ketentuan: Data tetap disimpan sebagai master data siswa (class_id = null), namun belum dapat didaftarkan sebagai nasabah.
            if (!$isClassConfigured) {
                $warningMessage = "Kelas '{$classString}' belum terkonfigurasi pada rombel resmi sistem sekolah.";
                $status = 'unconfigured_class';
            }

            // Cek duplikasi di dalam file yang sama
            if (isset($seenInFile[$studentNumber])) {
                $errors[] = "Duplikasi NIS dalam file Excel (pertama kali ditemukan pada baris #{$seenInFile[$studentNumber]}).";
                $status = 'duplicate_file';
            } else {
                $seenInFile[$studentNumber] = $index + 1;
            }

            // Cek duplikasi di master data siswa database
            if (isset($existingStudentNumbers[$studentNumber])) {
                $errors[] = 'NIS sudah terdaftar di Master Data Siswa.';
                $status = 'duplicate_database';
            }

            // Cek apakah sudah terdaftar sebagai nasabah (hanya ditentukan berdasarkan record Customer yang relevan)
            $hasCustomer = isset($existingCustomerNumbers[$studentNumber]);

            $isValid = count($errors) === 0;

            if ($isValid) {
                $validCount++;
                if (!$isClassConfigured) {
                    $unconfiguredClassCount++;
                }
            } else {
                if (in_array($status, ['duplicate_file', 'duplicate_database'], true)) {
                    $duplicateCount++;
                } else {
                    $invalidCount++;
                }
            }

            $previewRows[] = [
                'index' => $index + 1,
                'student_number' => $studentNumber,
                'student_name' => $studentName,
                'raw_class' => $classString,
                'class_name' => $matchedClass ? $matchedClass->name : $classString,
                'class_id' => $matchedClass?->id,
                'original_class' => $classString,
                'is_class_configured' => $isClassConfigured,
                'warning_message' => $warningMessage,
                'grade_level' => $gradeLevel,
                'major_name' => $matchedClass?->major?->name ?? $majorCode,
                'major_code' => $majorCode,
                'rombel' => $rombel,
                'academic_year' => $academicYear,
                'cohort_year' => $cohortYear,
                'graduation_year' => $graduationYear,
                'has_customer' => $hasCustomer,
                'is_valid' => $isValid,
                'status' => $isValid ? ($isClassConfigured ? 'valid' : 'unconfigured_class') : $status,
                'error_message' => $errors !== [] ? implode(' ', $errors) : null,
            ];
        }

        $previewToken = (string) Str::uuid();

        // Simpan baris yang siap diimpor di cache selama 2 jam
        Cache::put("student_import_preview:{$previewToken}", [
            'rows' => $previewRows,
            'academic_year' => $baseAcademicYear,
        ], now()->addHours(2));

        return [
            'preview_token' => $previewToken,
            'academic_year' => $baseAcademicYear,
            'summary' => [
                'total_found' => count($rawStudents),
                'valid_count' => $validCount,
                'configured_count' => $validCount - $unconfiguredClassCount,
                'unconfigured_class_count' => $unconfiguredClassCount,
                'duplicate_count' => $duplicateCount,
                'invalid_count' => $invalidCount,
                'ready_to_import_count' => $validCount,
            ],
            'rows' => $previewRows,
        ];
    }

    /**
     * Konfirmasi import: simpan siswa valid ke tabel students.
     */
    public function confirmImport(string $previewToken): array
    {
        $cacheKey = "student_import_preview:{$previewToken}";
        $cached = Cache::get($cacheKey);

        if (!$cached || !isset($cached['rows'])) {
            throw new RuntimeException('Sesi preview import telah kedaluwarsa atau tidak valid. Silakan unggah ulang file Excel.');
        }

        $rows = $cached['rows'];
        $validRows = array_filter($rows, static fn($r) => !empty($r['is_valid']));

        if (empty($validRows)) {
            throw new RuntimeException('Tidak ada data siswa valid yang dapat diimpor.');
        }

        $importedCount = 0;

        DB::transaction(function () use ($validRows, &$importedCount) {
            $now = now();
            $batchData = [];

            foreach ($validRows as $row) {
                $batchData[] = [
                    'student_number' => $row['student_number'],
                    'student_name' => $row['student_name'],
                    'class_id' => $row['class_id'],
                    'original_class' => $row['original_class'] ?? $row['raw_class'] ?? null,
                    'grade_level' => $row['grade_level'] ?? 'X',
                    'cohort_year' => $row['cohort_year'],
                    'graduation_year' => $row['graduation_year'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $importedCount++;
            }

            // Lakukan chunking per 200 baris untuk insert efisien
            foreach (array_chunk($batchData, 200) as $chunk) {
                Student::query()->insert($chunk);
            }
        });

        // Hapus token preview dari cache
        Cache::forget($cacheKey);

        return [
            'imported_count' => $importedCount,
            'message' => "Berhasil mengimpor {$importedCount} data siswa ke Master Data Siswa.",
        ];
    }

    /**
     * Parser nama kelas fleksibel: mendukung 'X RPL 1', 'X AK 1', 'X BD', 'XII LPS', dll.
     */
    public function parseClassString(string $classString, Collection $classes, Collection $majors): array
    {
        $cleaned = trim(preg_replace('/\s+/', ' ', $classString));

        // Pattern 1: Tingkat (X/XI/XII/10/11/12) + Jurusan (RPL/TKJ/DKV/AK/AKL/LPS/MP/BR/BD) + Rombel opsional ([1-9]\d*)
        $gradeLevel = null;
        $majorCode = null;
        $rombel = 1;

        if (preg_match('/\A(XII|XI|X|12|11|10)\s+([A-Za-z]+)(?:\s+([1-9]\d*))?\z/i', $cleaned, $matches)) {
            $gradeLevel = $this->normalizeGradeLevel($matches[1]);
            $rawMajor = strtoupper(trim($matches[2]));
            $rombel = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 1;

            // Normalisasi alias jurusan umum di sekolah: AK -> AKL
            $majorCode = $rawMajor === 'AK' ? 'AKL' : $rawMajor;
        }

        // Cari SchoolClass yang cocok di database
        $matchedClass = null;

        if ($gradeLevel !== null && $majorCode !== null) {
            // Coba pencocokan langsung nama kelas
            $candidateNames = [
                "{$gradeLevel} {$majorCode} {$rombel}",
                "{$gradeLevel} {$rawMajor} {$rombel}",
            ];

            if ($rombel === 1) {
                $candidateNames[] = "{$gradeLevel} {$majorCode}";
                $candidateNames[] = "{$gradeLevel} {$rawMajor}";
            }

            $matchedClass = $classes->first(function (SchoolClass $cls) use ($candidateNames, $gradeLevel, $majorCode, $rombel) {
                if (in_array(trim($cls->name), $candidateNames, true)) {
                    return true;
                }

                // Cek relasi major code & rombel
                $clsMajorCode = strtoupper((string) $cls->major?->code);
                if ($clsMajorCode === $majorCode) {
                    if (preg_match('/([1-9]\d*)\z/', $cls->name, $rm)) {
                        return (int) $rm[1] === $rombel && str_starts_with($cls->name, $gradeLevel);
                    }
                }

                return false;
            });
        }

        return [
            'grade_level' => $gradeLevel ?? 'X',
            'major_code' => $majorCode ?? 'UMUM',
            'rombel' => $rombel,
            'school_class' => $matchedClass,
        ];
    }

    /**
     * Hitung tahun angkatan dan tahun kelulusan berdasarkan formula:
     * tahun_angkatan = tahun_awal_pelajaran - (tingkat_kelas - 10)
     * tahun_kelulusan = tahun_angkatan + 3
     */
    public function calculateCohortYears(?string $gradeLevel, int $academicYearStart): array
    {
        $gradeNumber = match (strtoupper((string) $gradeLevel)) {
            'XII', '12' => 12,
            'XI', '11' => 11,
            default => 10,
        };

        $cohortYear = $academicYearStart - ($gradeNumber - 10);
        $graduationYear = $cohortYear + 3;

        return [
            'cohort_year' => $cohortYear,
            'graduation_year' => $graduationYear,
        ];
    }

    /**
     * Default tahun awal pelajaran saat ini (Juli - Juni).
     */
    public function defaultAcademicYearStart(): int
    {
        $now = now();
        // Jika bulan Juli (7) atau setelahnya, tahun ajaran baru dimulai tahun ini
        // Jika sebelum Juli, masih masuk semester genap tahun ajaran yang dimulai tahun kemarin
        return (int) config('bank.academic_year_start', $now->month >= 7 ? $now->year : $now->year - 1);
    }

    public function normalizeGradeLevel(?string $grade): ?string
    {
        if ($grade === null) {
            return null;
        }

        return match (strtoupper(trim($grade))) {
            'X', '10' => 'X',
            'XI', '11' => 'XI',
            'XII', '12' => 'XII',
            default => null,
        };
    }

    private function loadXml(string $xml): SimpleXMLElement
    {
        $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        if ($element === false) {
            throw new RuntimeException('Struktur XML di file Excel tidak valid.');
        }

        return $element;
    }
}
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AccountNumberService;
use App\Services\ClassRosterService;
use App\Services\CustomerImportService;
use App\Services\TemporaryPasswordService;
use App\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminCustomerImportController extends Controller
{
    public function template(CustomerImportService $importService, ClassRosterService $classRoster)
    {
        $classReferences = $classRoster->configuredClasses()
            ->sortBy(fn ($class) => $class->major?->code.' '.$class->name)
            ->map(function ($class) use ($classRoster) {
                $identity = $classRoster->classIdentity($class);

                return [
                    $identity['grade_level'],
                    $class->major?->name,
                    $identity['rombel'],
                    $class->name,
                ];
            })
            ->all();

        return response($importService->template(classReferences: $classReferences), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="template-import-nasabah.xlsx"',
        ]);
    }

    public function preview(
        Request $request,
        CustomerImportService $importService,
        ClassRosterService $classRoster
    ): JsonResponse {
        $validated = Validator::make($request->all(), [
            'file' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:5120'],
        ], [
            'file.required' => 'File Excel wajib dipilih.',
            'file.file' => 'Upload tidak terbaca sebagai file yang valid.',
            'file.extensions' => 'File harus menggunakan ekstensi .xlsx.',
            'file.mimes' => 'Isi file bukan workbook Excel .xlsx yang valid.',
            'file.max' => 'Ukuran file maksimal 5 MB.',
        ])->validate();

        try {
            $rows = $importService->parse($validated['file']->getRealPath());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'File Excel tidak memiliki baris data.',
            ]);
        }

        $headerCells = $rows[0]['cells'];
        $headers = array_map(
            static fn ($index) => mb_strtolower(trim((string) ($headerCells[$index] ?? ''))),
            range(0, count(CustomerImportService::HEADERS) - 1)
        );
        $expectedHeaders = array_map('mb_strtolower', CustomerImportService::HEADERS);
        if (count($headerCells) !== count(CustomerImportService::HEADERS)) {
            throw ValidationException::withMessages([
                'file' => 'Template harus memiliki tepat 7 kolom dan tidak boleh ada kolom tambahan.',
            ]);
        }
        if (count(array_unique($headers)) !== count($headers)) {
            throw ValidationException::withMessages([
                'file' => 'Header Excel tidak boleh berulang.',
            ]);
        }
        if ($headers !== $expectedHeaders) {
            throw ValidationException::withMessages([
                'file' => 'Kolom wajib harus berurutan: '
                    .implode(', ', CustomerImportService::HEADERS).'.',
            ]);
        }

        $dataRows = array_slice($rows, 1);
        $hasData = collect($dataRows)->contains(
            static fn ($row) => count(array_filter(
                $row['cells'],
                static fn ($value) => $value !== ''
            )) > 0
        );
        if (! $hasData) {
            throw ValidationException::withMessages([
                'file' => 'Template belum berisi data nasabah.',
            ]);
        }

        $validatedRows = [];
        $issues = [];
        $previewRows = [];
        $seenStudentNumbers = [];
        $seenEmails = [];
        $duplicateCount = 0;
        $existingNumbers = [];
        $existingEmails = [];
        $numberCandidates = [];
        $emailCandidates = [];
        $nisColumnIndex = array_search('NIS', CustomerImportService::HEADERS, true);
        foreach (array_slice($rows, 1) as $row) {
            $mapped = $importService->mapRow($row['cells']);
            $number = trim((string) $mapped['student_number']);
            $email = mb_strtolower(trim((string) $mapped['email']));
            if ($number !== '') {
                $numberCandidates[] = $number;
            }
            if ($email !== '') {
                $emailCandidates[] = $email;
            }
        }
        foreach (array_chunk(array_values(array_unique($numberCandidates)), 500) as $numberChunk) {
            foreach (Customer::query()->whereIn('student_number', $numberChunk)->pluck('student_number') as $number) {
                $existingNumbers[mb_strtolower($number)] = true;
            }
            foreach (User::query()->whereIn('username', $numberChunk)->pluck('username') as $number) {
                $existingNumbers[mb_strtolower($number)] = true;
            }
        }
        foreach (array_chunk(array_values(array_unique($emailCandidates)), 500) as $emailChunk) {
            foreach (User::query()->whereIn('email', $emailChunk)->pluck('email') as $email) {
                $existingEmails[mb_strtolower($email)] = true;
            }
        }
        $classes = SchoolClass::query()
            ->with('major:id,name,code')
            ->whereHas('major', fn ($query) => $query->whereIn('code', array_keys($classRoster->majorRosters())))
            ->get(['id', 'name', 'major_id']);
        $majors = Major::query()
            ->whereIn('code', array_keys($classRoster->majorRosters()))
            ->get(['id', 'name', 'code']);

        foreach (array_slice($rows, 1) as $row) {
            $values = $importService->mapRow($row['cells']);
            if (count(array_filter($values, static fn ($value) => $value !== '')) === 0) {
                continue;
            }

            $studentNumber = $values['student_number'];
            $studentName = $values['student_name'];
            $gradeLevel = $values['grade_level'];
            $majorName = $values['major_name'];
            $rombel = $values['rombel'];
            $email = $values['email'];
            $openingBalance = $values['opening_balance'];
            $studentNumber = trim((string) $studentNumber);
            $studentName = trim((string) $studentName);
            $gradeLevel = trim((string) $gradeLevel);
            $majorName = trim((string) $majorName);
            $rombel = trim((string) $rombel);
            $email = mb_strtolower(trim((string) $email));
            $data = [
                'student_number' => $studentNumber,
                'student_name' => $studentName,
                'email' => $email,
                'opening_balance' => $openingBalance,
            ];
            $validator = Validator::make($data, [
                'student_number' => ['required', 'string', 'max:30'],
                'student_name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'opening_balance' => [
                    'required', 'integer',
                    'min:'.config('bank.min_deposit', 10000),
                ],
            ], [
                'student_number.required' => 'NIS wajib diisi.',
                'student_number.max' => 'NIS maksimal 30 karakter.',
                'student_name.required' => 'Nama lengkap siswa wajib diisi.',
                'student_name.max' => 'Nama lengkap siswa maksimal 100 karakter.',
                'email.required' => 'Email pribadi wajib diisi.',
                'email.email' => 'Format email tidak valid.',
                'email.max' => 'Email maksimal 255 karakter.',
                'opening_balance.integer' => 'Setoran awal harus berupa angka bulat.',
                'opening_balance.min' => 'Setoran awal minimal Rp10.000.',
            ]);
            $errors = $validator->errors()->all();
            if (
                $studentNumber !== ''
                && ($row['cell_types'][$nisColumnIndex] ?? null) !== 'text'
            ) {
                $errors[] = 'NIS harus disimpan sebagai teks agar angka nol di awal tidak hilang.';
            }

            $normalizedGrade = $classRoster->normalizeGradeLevel($gradeLevel);
            if ($normalizedGrade === null) {
                $errors[] = 'Tingkat kelas harus X, XI, atau XII.';
            }
            if ($majorName === '') {
                $errors[] = 'Nama jurusan wajib diisi.';
            }
            if ($rombel === '' || ! preg_match('/\A[1-9]\d*\z/', $rombel)) {
                $errors[] = 'Kelas harus berupa nomor rombel positif, misalnya 1.';
            }

            $matchedMajor = $classRoster->resolveMajor($majorName, $majors);
            if ($majorName !== '' && $matchedMajor === null) {
                $errors[] = 'Jurusan '.$majorName.' tidak ditemukan dalam daftar jurusan sistem.';
            }

            $maximumRombel = $matchedMajor
                ? ($classRoster->majorRosters()[mb_strtoupper($matchedMajor->code)] ?? null)
                : null;
            if (
                $rombel !== ''
                && preg_match('/\A[1-9]\d*\z/', $rombel)
                && $maximumRombel !== null
                && (int) $rombel > $maximumRombel
            ) {
                $errors[] = "Jurusan {$matchedMajor->name} hanya memiliki kelas 1 sampai {$maximumRombel}.";
            }

            if (
                $normalizedGrade !== null
                && $matchedMajor !== null
                && $rombel !== ''
                && preg_match('/\A[1-9]\d*\z/', $rombel)
                && $maximumRombel !== null
                && (int) $rombel <= $maximumRombel
            ) {
                $schoolClass = $classRoster->resolveClass(
                    $normalizedGrade,
                    $matchedMajor->code,
                    (int) $rombel,
                    $classes
                );
            } else {
                $schoolClass = null;
            }

            if (
                $normalizedGrade !== null
                && $matchedMajor !== null
                && $rombel !== ''
                && preg_match('/\A[1-9]\d*\z/', $rombel)
                && $maximumRombel !== null
                && (int) $rombel <= $maximumRombel
                && $schoolClass === null
            ) {
                $errors[] = "Kelas {$normalizedGrade} {$matchedMajor->name} {$rombel} belum tersedia atau tercatat lebih dari sekali. Silakan periksa data kelas di sistem.";
            }

            $normalizedStudentNumber = mb_strtolower($studentNumber);
            $isDuplicate = $studentNumber !== '' && (
                isset($seenStudentNumbers[$normalizedStudentNumber])
                || isset($existingNumbers[$normalizedStudentNumber])
            );
            if (isset($seenStudentNumbers[$normalizedStudentNumber])) {
                $errors[] = 'NIS duplikat pada file (baris '.$seenStudentNumbers[$normalizedStudentNumber].').';
            } elseif (isset($existingNumbers[$normalizedStudentNumber])) {
                $errors[] = 'NIS sudah terdaftar pada sistem.';
            }
            if ($studentNumber !== '') {
                $seenStudentNumbers[$normalizedStudentNumber] ??= $row['row_number'];
            }

            $normalizedEmail = mb_strtolower($email);
            if (isset($seenEmails[$normalizedEmail])) {
                $errors[] = 'Email duplikat pada file (baris '.$seenEmails[$normalizedEmail].').';
                $isDuplicate = true;
            } elseif (isset($existingEmails[$normalizedEmail])) {
                $errors[] = 'Email sudah digunakan pada sistem.';
                $isDuplicate = true;
            }
            if ($email !== '') {
                $seenEmails[$normalizedEmail] ??= $row['row_number'];
            }

            if ($errors !== []) {
                if ($isDuplicate) {
                    $duplicateCount++;
                }
                $issues[] = [
                    'row' => $row['row_number'],
                    'type' => $isDuplicate ? 'duplicate' : 'invalid',
                    'messages' => $errors,
                ];
            }

            $previewRow = [
                'row' => $row['row_number'],
                'nis' => $studentNumber,
                'student_name' => $studentName,
                'grade_level' => $normalizedGrade ?? $gradeLevel,
                'major_name' => $matchedMajor?->name ?? $majorName,
                'rombel' => $rombel,
                'class_id' => $schoolClass?->id,
                'email' => $email,
                'opening_balance' => $openingBalance,
                'status' => $errors === [] ? 'valid' : 'invalid',
                'messages' => $errors,
            ];
            $previewRows[] = $previewRow;

            if ($errors === []) {
                $validatedRows[] = [
                    'row' => $row['row_number'],
                    'data' => [
                        'student_number' => $studentNumber,
                        'student_name' => $studentName,
                        'class_id' => (int) $schoolClass->id,
                        'grade_level' => $normalizedGrade,
                        'major_name' => $matchedMajor->name,
                        'rombel' => (string) (int) $rombel,
                        'email' => $email,
                        'opening_balance' => (int) $openingBalance,
                    ],
                ];
            }
        }

        $token = (string) Str::uuid();
        Cache::put('customer_import_preview:'.$token, [
            'user_id' => $request->user()->id,
            'valid_rows' => $validatedRows,
            'issues' => $issues,
            'checked' => count($validatedRows) + count($issues),
            'duplicates' => $duplicateCount,
        ], now()->addMinutes(20));

        return response()->json([
            'message' => 'Pratinjau import berhasil diperiksa.',
            'data' => [
                'import_token' => $token,
                'checked_rows' => count($validatedRows) + count($issues),
                'valid_rows' => count($validatedRows),
                'failed_rows' => count($issues),
                'duplicate_rows' => $duplicateCount,
                'rows_to_import' => array_map(
                    static fn ($row) => [
                        'row' => $row['row'],
                        'nis' => $row['data']['student_number'],
                        'student_number' => $row['data']['student_number'],
                        'student_name' => $row['data']['student_name'],
                        'grade_level' => $row['data']['grade_level'],
                        'major_name' => $row['data']['major_name'],
                        'rombel' => $row['data']['rombel'],
                        'class_id' => $row['data']['class_id'],
                        'email' => $row['data']['email'],
                        'opening_balance' => $row['data']['opening_balance'],
                    ],
                    $validatedRows
                ),
                'preview_rows' => $previewRows,
                'issues' => $issues,
            ],
        ]);
    }

    public function confirm(
        Request $request,
        AccountNumberService $accountNumberService,
        TemporaryPasswordService $temporaryPasswordService,
        TransactionService $transactionService
    ): JsonResponse {
        $validated = $request->validate([
            'import_token' => ['required', 'uuid'],
        ]);
        $key = 'customer_import_preview:'.$validated['import_token'];

        $result = Cache::lock($key.':lock', 30)->block(5, function () use (
            $key,
            $request,
            $accountNumberService,
            $temporaryPasswordService,
            $transactionService
        ) {
            $preview = Cache::get($key);
            if (! $preview || (int) $preview['user_id'] !== (int) $request->user()->id) {
                throw ValidationException::withMessages([
                    'import_token' => 'Pratinjau kedaluwarsa atau tidak valid. Unggah file kembali.',
                ]);
            }
            Cache::forget($key);

            $successCount = 0;
            $issues = $preview['issues'];
            $issuedPasswords = [];
            $temporaryCredentials = [];
            foreach ($preview['valid_rows'] as $row) {
                try {
                    $temporaryPassword = $temporaryPasswordService->generate($issuedPasswords);
                    $expiresAt = $temporaryPasswordService->expiresAt();
                    DB::transaction(function () use (
                        $row,
                        $request,
                        $accountNumberService,
                        $transactionService,
                        $temporaryPassword,
                        $expiresAt
                    ) {
                        $data = $row['data'];
                        $user = User::create([
                            'name' => $data['student_name'],
                            'username' => $data['student_number'],
                            'full_name' => $data['student_name'],
                            'role' => 'nasabah',
                            'status' => 'active',
                            'email' => $data['email'],
                            'password' => $temporaryPassword,
                        ]);
                        $user->forceFill([
                            'must_change_password' => true,
                            'temporary_password_expires_at' => $expiresAt,
                        ])->save();
                        $customer = $user->customer()->create([
                            'account_number' => $accountNumberService
                                ->generateAccountNumber($data['class_id']),
                            'student_number' => $data['student_number'],
                            'student_name' => $data['student_name'],
                            'class_id' => $data['class_id'],
                            'status' => 'active',
                            'security_pin' => null,
                            'balance' => 0,
                        ]);
                        $transactionService->recordOpeningDeposit(
                            $customer,
                            (int) $data['opening_balance'],
                            (int) $request->user()->id
                        );
                    });
                    $successCount++;
                    $issuedPasswords[] = $temporaryPassword;
                    $temporaryCredentials[] = [
                        'student_name' => $row['data']['student_name'],
                        'username' => $row['data']['student_number'],
                        'temporary_password' => $temporaryPassword,
                        'expires_at' => $expiresAt->toIso8601String(),
                    ];
                } catch (QueryException $exception) {
                    report($exception);
                    $issues[] = [
                        'row' => $row['row'],
                        'type' => 'duplicate',
                        'messages' => [
                            'Baris terdeteksi duplikat saat disimpan dan tidak diimpor.',
                        ],
                    ];
                } catch (Throwable $exception) {
                    report($exception);
                    $issues[] = [
                        'row' => $row['row'],
                        'type' => 'failed',
                        'messages' => [
                            'Baris tidak dapat diproses karena data bertabrakan atau terjadi kesalahan database.',
                        ],
                    ];
                }
            }

            $duplicateCount = count(array_filter(
                $issues,
                static fn ($issue) => $issue['type'] === 'duplicate'
            ));

            return [
                'checked_rows' => $preview['checked'],
                'imported_rows' => $successCount,
                'failed_rows' => count($issues),
                'duplicate_rows' => $duplicateCount,
                'issues' => $issues,
                'initial_credentials' => $temporaryCredentials,
            ];
        });

        return response()->json([
            'message' => 'Proses import nasabah selesai.',
            'data' => $result,
        ]);
    }
}

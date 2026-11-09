<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\AccountNumberService;
use App\Services\ClassRosterService;
use App\Services\StudentImportService;
use App\Services\TemporaryPasswordService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminStudentController extends Controller
{
    protected AccountNumberService $accountNumberService;

    protected TemporaryPasswordService $temporaryPasswordService;

    protected TransactionService $transactionService;

    public function __construct(
        AccountNumberService $accountNumberService,
        TemporaryPasswordService $temporaryPasswordService,
        TransactionService $transactionService
    ) {
        $this->accountNumberService = $accountNumberService;
        $this->temporaryPasswordService = $temporaryPasswordService;
        $this->transactionService = $transactionService;
    }

    /**
     * Menampilkan daftar master data siswa dengan filter, pencarian, dan paginasi.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        $query = Student::query()
            ->with([
                'schoolClass:id,name,major_id',
                'schoolClass.major:id,name,code',
                'customer:id,account_number,student_number,status,balance',
            ]);

        // Filter pencarian NIS atau Nama
        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('student_number', 'like', "%{$search}%")
                    ->orWhere('student_name', 'like', "%{$search}%");
            });
        }

        // Filter tingkat kelas (X, XI, XII)
        if ($grade = trim((string) $request->input('grade_level', ''))) {
            $query->where('grade_level', strtoupper($grade));
        }

        // Filter kelas spesifik
        if ($classId = $request->input('class_id')) {
            $query->where('class_id', $classId);
        }

        // Filter jurusan
        if ($majorId = $request->input('major_id')) {
            $query->whereHas('schoolClass', function ($q) use ($majorId) {
                $q->where('major_id', $majorId);
            });
        }

        // Filter tahun angkatan
        if ($cohort = $request->input('cohort_year')) {
            $query->where('cohort_year', $cohort);
        }

        // Filter status nasabah
        $accountStatus = $request->input('account_status');
        if ($accountStatus === 'registered') {
            $query->whereHas('customer');
        } elseif ($accountStatus === 'unregistered') {
            $query->whereDoesntHave('customer');
        }

        // Pengurutan
        $query->orderBy('grade_level')
            ->orderBy('class_id')
            ->orderBy('student_name');

        $students = $query->paginate($perPage);

        $students->getCollection()->transform(function (Student $student) {
            $isClassConfigured = $student->class_id !== null;

            return [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'student_name' => $student->student_name,
                'class_id' => $student->class_id,
                'class_name' => $student->schoolClass?->name ?? $student->original_class ?? '-',
                'original_class' => $student->original_class,
                'is_class_configured' => $isClassConfigured,
                'class_warning' => !$isClassConfigured ? "Kelas '{$student->original_class}' belum terkonfigurasi di sistem." : null,
                'can_register' => $student->customer === null && $isClassConfigured,
                'grade_level' => $student->grade_level,
                'major_name' => $student->schoolClass?->major?->name ?? '-',
                'major_code' => $student->schoolClass?->major?->code ?? '-',
                'cohort_year' => $student->cohort_year,
                'graduation_year' => $student->graduation_year,
                'has_account' => $student->customer !== null,
                'customer' => $student->customer ? [
                    'id' => $student->customer->id,
                    'account_number' => $student->customer->account_number,
                    'status' => $student->customer->status,
                    'balance' => $student->customer->balance,
                ] : null,
                'created_at' => $student->created_at?->toISOString(),
            ];
        });

        return response()->json([
            'message' => 'Data master siswa berhasil diambil.',
            'data' => $students,
        ]);
    }

    /**
     * Ringkasan statistik master data siswa.
     */
    public function summary(): JsonResponse
    {
        $totalStudents = Student::query()->count();
        $gradeX = Student::query()->where('grade_level', 'X')->count();
        $gradeXI = Student::query()->where('grade_level', 'XI')->count();
        $gradeXII = Student::query()->where('grade_level', 'XII')->count();
        $withAccount = Student::query()->whereHas('customer')->count();
        $withoutAccount = $totalStudents - $withAccount;

        return response()->json([
            'message' => 'Statistik master siswa berhasil diambil.',
            'data' => [
                'total_students' => $totalStudents,
                'grade_x_count' => $gradeX,
                'grade_xi_count' => $gradeXI,
                'grade_xii_count' => $gradeXII,
                'with_account_count' => $withAccount,
                'without_account_count' => $withoutAccount,
            ],
        ]);
    }

    /**
     * Mengambil detail satu siswa master.
     */
    public function show(int $id): JsonResponse
    {
        $student = Student::query()
            ->with([
                'schoolClass.major',
                'customer',
            ])
            ->findOrFail($id);

        $isClassConfigured = $student->class_id !== null;

        return response()->json([
            'message' => 'Detail siswa berhasil diambil.',
            'data' => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'student_name' => $student->student_name,
                'class_id' => $student->class_id,
                'class_name' => $student->schoolClass?->name ?? $student->original_class ?? '-',
                'original_class' => $student->original_class,
                'is_class_configured' => $isClassConfigured,
                'class_warning' => !$isClassConfigured ? "Kelas '{$student->original_class}' belum terkonfigurasi di sistem." : null,
                'can_register' => $student->customer === null && $isClassConfigured,
                'grade_level' => $student->grade_level,
                'major_name' => $student->schoolClass?->major?->name ?? '-',
                'major_code' => $student->schoolClass?->major?->code ?? '-',
                'cohort_year' => $student->cohort_year,
                'graduation_year' => $student->graduation_year,
                'has_account' => $student->customer !== null,
                'customer' => $student->customer,
            ],
        ]);
    }

    /**
     * Preview import master data siswa dari Excel.
     */
    public function previewImport(Request $request, StudentImportService $importService): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:15360'],
            'academic_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ], [
            'file.required' => 'File Excel atau CSV wajib dipilih.',
            'file.file' => 'Upload tidak terbaca sebagai file yang valid.',
            'file.extensions' => 'File harus berekstensi .xlsx atau .csv.',
            'file.max' => 'Ukuran file maksimal 15 MB.',
        ])->validate();

        $file = $validated['file'];
        $academicYear = isset($validated['academic_year']) ? (int) $validated['academic_year'] : null;
        $extension = $file->getClientOriginalExtension() ?: 'xlsx';

        try {
            $parsed = $importService->parseFile($file->getRealPath(), $academicYear, $extension);
            $preview = $importService->generatePreview($parsed, $academicYear);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Pratinjau import data siswa berhasil dibuat.',
            'data' => $preview,
        ]);
    }

    /**
     * Konfirmasi import: simpan siswa valid ke tabel master siswa.
     */
    public function confirmImport(Request $request, StudentImportService $importService): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'preview_token' => ['required', 'string'],
        ], [
            'preview_token.required' => 'Token preview wajib disertakan.',
        ])->validate();

        try {
            $result = $importService->confirmImport($validated['preview_token']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'preview_token' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => $result['message'],
            'data' => $result,
        ]);
    }

    /**
     * Registrasi 1 akun nasabah langsung dari master siswa.
     */
    public function createAccount(int $id, Request $request, ClassRosterService $classRoster): JsonResponse
    {
        $student = Student::query()->with('schoolClass')->findOrFail($id);

        if ($student->customer()->exists()) {
            throw ValidationException::withMessages([
                'student' => "Siswa {$student->student_name} (NIS: {$student->student_number}) sudah terdaftar sebagai nasabah.",
            ]);
        }

        if (User::query()->where('username', $student->student_number)->exists()) {
            throw ValidationException::withMessages([
                'student' => "Username NIS {$student->student_number} sudah digunakan pada akun pengguna lain.",
            ]);
        }

        if (!$student->class_id) {
            throw ValidationException::withMessages([
                'class_id' => "Siswa {$student->student_name} belum memiliki data kelas yang sah di database.",
            ]);
        }

        $configuredClassIds = $classRoster->configuredClassIds();
        if (!in_array($student->class_id, $configuredClassIds, true)) {
            throw ValidationException::withMessages([
                'class_id' => "Kelas siswa ({$student->schoolClass?->name}) tidak termasuk dalam konfigurasi rombel resmi.",
            ]);
        }

        $validated = Validator::make($request->all(), [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'opening_balance' => [
                'required',
                'integer',
                'min:10000',
            ],
        ], [
            'email.required' => 'Email pribadi nasabah wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah digunakan oleh akun lain.',
            'opening_balance.required' => 'Setoran awal wajib diisi.',
            'opening_balance.min' => 'Setoran awal minimal Rp10.000.',
            'opening_balance.integer' => 'Setoran awal harus berupa angka bulat.',
        ])->validate();

        $admin = $request->user();
        $openingBalance = (int) $validated['opening_balance'];
        $temporaryPassword = $this->temporaryPasswordService->generate();
        $temporaryPasswordExpiresAt = $this->temporaryPasswordService->expiresAt();

        $result = DB::transaction(function () use ($student, $validated, $admin, $openingBalance, $temporaryPassword, $temporaryPasswordExpiresAt) {
            $user = User::create([
                'name' => $student->student_name,
                'username' => $student->student_number,
                'full_name' => $student->student_name,
                'role' => 'nasabah',
                'status' => 'active',
                'email' => strtolower(trim((string) $validated['email'])),
                'email_verified_at' => null,
                'password' => $temporaryPassword,
            ]);

            $user->forceFill([
                'must_change_password' => true,
                'temporary_password_expires_at' => $temporaryPasswordExpiresAt,
            ])->save();

            $accountNumber = $this->accountNumberService->generateAccountNumber($student->class_id);

            $customer = $user->customer()->create([
                'account_number' => $accountNumber,
                'student_number' => $student->student_number,
                'student_name' => $student->student_name,
                'class_id' => $student->class_id,
                'status' => 'active',
                'security_pin' => null,
                'balance' => 0,
            ]);

            $openingTransaction = $this->transactionService->recordOpeningDeposit(
                $customer,
                $openingBalance,
                $admin->id
            );

            return [
                'user' => $user,
                'customer' => $customer,
                'opening_transaction' => $openingTransaction,
            ];
        });

        $customer = Customer::with('schoolClass.major')->findOrFail($result['customer']->id);

        return response()->json([
            'message' => 'Rekening nasabah berhasil dibuat dari master data siswa.',
            'data' => [
                'customer' => [
                    'id' => $customer->id,
                    'account_number' => $customer->account_number,
                    'student_number' => $customer->student_number,
                    'student_name' => $customer->student_name,
                    'class_name' => $customer->schoolClass?->name ?? '-',
                    'balance' => $customer->balance,
                ],
                'credentials' => [
                    'username' => $student->student_number,
                    'temporary_password' => $temporaryPassword,
                    'expires_at' => $temporaryPasswordExpiresAt->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * Batch registrasi beberapa siswa terpilih (misal 5 siswa) menjadi nasabah.
     */
    public function batchCreateAccounts(Request $request, ClassRosterService $classRoster): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'students' => ['required', 'array', 'min:1', 'max:50'],
            'students.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'students.*.email' => ['required', 'string', 'email', 'max:255'],
            'students.*.opening_balance' => ['required', 'integer', 'min:10000'],
        ], [
            'students.required' => 'Daftar siswa yang dipilih wajib disertakan.',
            'students.min' => 'Pilih minimal 1 siswa untuk didaftarkan.',
            'students.max' => 'Maksimal 50 siswa per proses pendaftaran massal.',
            'students.*.student_id.required' => 'ID siswa wajib diisi.',
            'students.*.email.required' => 'Email siswa wajib diisi.',
            'students.*.email.email' => 'Format email siswa tidak valid.',
            'students.*.opening_balance.required' => 'Setoran awal wajib diisi.',
            'students.*.opening_balance.min' => 'Setoran awal minimal Rp10.000.',
        ])->validate();

        $admin = $request->user();
        $configuredClassIds = $classRoster->configuredClassIds();

        $items = $validated['students'];
        $createdAccounts = [];
        $failures = [];

        // Kumpulkan email untuk cek duplikasi di dalam batch
        $emailsInBatch = [];
        foreach ($items as $item) {
            $e = strtolower(trim((string) $item['email']));
            if (isset($emailsInBatch[$e])) {
                $emailsInBatch[$e]++;
            } else {
                $emailsInBatch[$e] = 1;
            }
        }

        foreach ($items as $item) {
            $studentId = $item['student_id'];
            $email = strtolower(trim((string) $item['email']));
            $openingBalance = (int) $item['opening_balance'];

            $student = Student::with('schoolClass.major')->find($studentId);

            if (!$student) {
                $failures[] = [
                    'student_id' => $studentId,
                    'reason' => 'Data siswa tidak ditemukan di master database.',
                ];
                continue;
            }

            // Validasi duplikasi email dalam batch
            if (($emailsInBatch[$email] ?? 0) > 1) {
                $failures[] = [
                    'student_id' => $student->id,
                    'student_number' => $student->student_number,
                    'student_name' => $student->student_name,
                    'reason' => "Email {$email} duplikat di dalam batch pilihan ini.",
                ];
                continue;
            }

            // Cek sudah jadi nasabah
            if ($student->customer()->exists()) {
                $failures[] = [
                    'student_id' => $student->id,
                    'student_number' => $student->student_number,
                    'student_name' => $student->student_name,
                    'reason' => 'Siswa sudah terdaftar sebagai nasabah.',
                ];
                continue;
            }

            // Cek username terpakai
            if (User::query()->where('username', $student->student_number)->exists()) {
                $failures[] = [
                    'student_id' => $student->id,
                    'student_number' => $student->student_number,
                    'student_name' => $student->student_name,
                    'reason' => "Username NIS {$student->student_number} sudah terdaftar pada pengguna lain.",
                ];
                continue;
            }

            // Cek email terpakai di user
            if (User::query()->where('email', $email)->exists()) {
                $failures[] = [
                    'student_id' => $student->id,
                    'student_number' => $student->student_number,
                    'student_name' => $student->student_name,
                    'reason' => "Email {$email} sudah digunakan oleh akun nasabah lain.",
                ];
                continue;
            }

            // Cek konfigurasi kelas
            if (!$student->class_id || !in_array($student->class_id, $configuredClassIds, true)) {
                $failures[] = [
                    'student_id' => $student->id,
                    'student_number' => $student->student_number,
                    'student_name' => $student->student_name,
                    'reason' => "Kelas siswa ('" . ($student->schoolClass?->name ?? $student->original_class ?? 'Belum Ditentukan') . "') belum terkonfigurasi pada rombel resmi sekolah.",
                ];
                continue;
            }

            // Proses pembuatan akun dalam transaksi terisolasi per siswa
            try {
                $temporaryPassword = $this->temporaryPasswordService->generate();
                $temporaryPasswordExpiresAt = $this->temporaryPasswordService->expiresAt();

                $customer = DB::transaction(function () use ($student, $email, $openingBalance, $admin, $temporaryPassword, $temporaryPasswordExpiresAt) {
                    $user = User::create([
                        'name' => $student->student_name,
                        'username' => $student->student_number,
                        'full_name' => $student->student_name,
                        'role' => 'nasabah',
                        'status' => 'active',
                        'email' => $email,
                        'email_verified_at' => null,
                        'password' => $temporaryPassword,
                    ]);

                    $user->forceFill([
                        'must_change_password' => true,
                        'temporary_password_expires_at' => $temporaryPasswordExpiresAt,
                    ])->save();

                    $accountNumber = $this->accountNumberService->generateAccountNumber($student->class_id);

                    $cust = $user->customer()->create([
                        'account_number' => $accountNumber,
                        'student_number' => $student->student_number,
                        'student_name' => $student->student_name,
                        'class_id' => $student->class_id,
                        'status' => 'active',
                        'security_pin' => null,
                        'balance' => 0,
                    ]);

                    $this->transactionService->recordOpeningDeposit(
                        $cust,
                        $openingBalance,
                        $admin->id
                    );

                    return $cust;
                });

                $createdAccounts[] = [
                    'student_id' => $student->id,
                    'student_number' => $student->student_number,
                    'student_name' => $student->student_name,
                    'account_number' => $customer->account_number,
                    'class_name' => $student->schoolClass?->name ?? '-',
                    'email' => $email,
                    'temporary_password' => $temporaryPassword,
                    'temporary_password_expires_at' => $temporaryPasswordExpiresAt->toIso8601String(),
                    'opening_balance' => $openingBalance,
                ];
            } catch (Throwable $e) {
                $failures[] = [
                    'student_id' => $student->id,
                    'student_number' => $student->student_number,
                    'student_name' => $student->student_name,
                    'reason' => 'Gagal memproses rekening: ' . $e->getMessage(),
                ];
            }
        }

        $successCount = count($createdAccounts);
        $failureCount = count($failures);

        return response()->json([
            'message' => "Proses registrasi selesai. Berhasil: {$successCount}, Gagal: {$failureCount}.",
            'data' => [
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'created_accounts' => $createdAccounts,
                'failures' => $failures,
            ],
        ]);
    }
}
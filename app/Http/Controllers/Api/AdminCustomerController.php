<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AccountNumberService;
use App\Services\ActivityLogService;
use App\Services\ClassRosterService;
use App\Services\TemporaryPasswordService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminCustomerController extends Controller
{
    protected AccountNumberService $accountNumberService;

    protected ActivityLogService $activityLogService;

    protected TemporaryPasswordService $temporaryPasswordService;

    public function __construct(
        AccountNumberService $accountNumberService,
        ActivityLogService $activityLogService,
        TemporaryPasswordService $temporaryPasswordService
    ) {
        $this->accountNumberService = $accountNumberService;
        $this->activityLogService = $activityLogService;
        $this->temporaryPasswordService = $temporaryPasswordService;
    }

    /**
     * Menampilkan seluruh data rekening nasabah.
     */
    /**
     * Menampilkan seluruh data rekening nasabah.
     */
    public function index()
    {
        $customers = Customer::query()
        ->with([
                'schoolClass:id,name,major_id',
                'schoolClass.major:id,name,code',
                'user:id,email,email_verified_at,role,must_change_password',
            ])
            ->select([
                'id',
                'account_number',
                'student_number',
                'student_name',
                'class_id',
                'user_id',
                'status',
                'balance',
            ])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'message' => 'Data rekening nasabah berhasil diambil.',
            'data' => $customers,
        ]);
    }

    /**
     * Membuat rekening nasabah baru.
     */
    public function store(StoreCustomerRequest $request, TransactionService $transactionService)
    {
        $validated = $request->validated();

        $admin = $request->user();

        $studentNumber = $validated['student_number'];
        $studentName = $validated['student_name'];
        $openingBalance = (int) $validated['opening_balance'];
        $temporaryPassword = $this->temporaryPasswordService->generate();
        $temporaryPasswordExpiresAt = $this->temporaryPasswordService->expiresAt();

        $result = DB::transaction(function () use (
            $validated,
            $admin,
            $studentNumber,
            $studentName,
            $openingBalance,
            $transactionService,
            $temporaryPassword,
            $temporaryPasswordExpiresAt
        ) {
            // Membuat user baru untuk nasabah.
            $user = User::create([
                'name' => $studentName,
                'username' => $studentNumber,
                'full_name' => $studentName,
                'role' => 'nasabah',
                'status' => 'active',
                'email' => $validated['email'] ?? null,
                'email_verified_at' => null,
                'password' => $temporaryPassword,
            ]);
            $user->forceFill([
                'must_change_password' => true,
                'temporary_password_expires_at' => $temporaryPasswordExpiresAt,
            ])->save();
            $accountNumber = $this->accountNumberService
                ->generateAccountNumber($validated['class_id']);

            $customer = $user->customer()->create([
                'account_number' => $accountNumber,
                'student_number' => $studentNumber,
                'student_name' => $studentName,
                'class_id' => $validated['class_id'],
                'status' => 'active',
                'security_pin' => null,
                'balance' => 0,
            ]);

            $openingTransaction = $transactionService->recordOpeningDeposit(
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
        $user = $result['user'];
        $customer = Customer::with('schoolClass.major')->findOrFail($result['customer']->id);
        $openingTransaction = $result['opening_transaction'];

        return response()->json([
            'message' => 'Rekening nasabah berhasil dibuat dan setoran awal berhasil dicatat.',
            'data' => [
                'customer' => [
                    'id' => $customer->id,
                    'account_number' => $customer->account_number,
                    'student_number' => $customer->student_number,
                    'student_name' => $customer->student_name,
                    'class_id' => $customer->class_id,
                    'class_name' => $customer->schoolClass?->name,
                    'major_name' => $customer->schoolClass?->major?->name,
                    'status' => $customer->status,
                    'balance' => $customer->balance,
                ],

                'login' => [
                    'username' => $user->username ?? null,
                    'temporary_password' => $temporaryPassword,
                    'expires_at' => $temporaryPasswordExpiresAt->toIso8601String(),
                ],

                'opening_transaction' => [
                    'id' => $openingTransaction->id,
                    'transaction_type' => $openingTransaction->transaction_type,
                    'amount' => $openingTransaction->amount,
                    'status' => $openingTransaction->status,
                ],
            ],
        ], 201);
    }

    public function issueTemporaryPassword(int $id): JsonResponse
    {
        $customer = Customer::with('user')->findOrFail($id);

        if (! $customer->user || $customer->user->role !== 'nasabah') {
            return response()->json([
                'message' => 'Akun login nasabah tidak ditemukan.',
            ], 404);
        }

        if (! $customer->user->must_change_password) {
            return response()->json([
                'message' => 'Password sementara hanya dapat diterbitkan ulang untuk akun yang masih wajib menggantinya.',
            ], 409);
        }

        $temporaryPassword = $this->temporaryPasswordService->generate();
        $expiresAt = $this->temporaryPasswordService->expiresAt();

        DB::transaction(function () use ($customer, $temporaryPassword, $expiresAt) {
            $customer->user->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'temporary_password_expires_at' => $expiresAt,
            ])->save();
        });

        return response()->json([
            'message' => 'Password sementara baru berhasil dibuat. Catat dan serahkan kepada nasabah; password ini hanya ditampilkan sekali.',
            'data' => [
                'username' => $customer->user->username,
                'temporary_password' => $temporaryPassword,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    /**
     * Menampilkan detail rekening berdasarkan ID.
     */
    /**
     * Menampilkan detail rekening berdasarkan ID.
     */
    public function show($id)
    {
        $customer = Customer::with([
            'schoolClass.major',
            'user:id,email,email_verified_at,role,must_change_password',
        ])->find($id);

        if (! $customer) {
            return response()->json([
                'message' => 'Rekening nasabah tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'message' => 'Data rekening nasabah berhasil ditemukan.',
            'data' => $customer,
        ]);
    }

    /**
     * Memperbarui data nasabah.
     */
    /**
     * Memperbarui data nasabah.
     */
    public function update(UpdateCustomerRequest $request, $id)
    {
        $customer = Customer::with([
            'schoolClass',
            'user',
        ])->find($id);

        if (! $customer) {
            return response()->json([
                'message' => 'Rekening nasabah tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validated();

        // Periksa jurusan jika class_id dikirim dalam request.
        if (array_key_exists('class_id', $validated)) {
            $newClass = SchoolClass::findOrFail($validated['class_id']);

            $currentMajorId = $customer->schoolClass?->major_id;

            if ($currentMajorId !== $newClass->major_id) {
                return response()->json([
                    'message' => 'Tidak dapat mengubah kelas ke jurusan yang berbeda.',
                ], 422);
            }
        }

        // Pisahkan email karena email disimpan pada tabel users.
        $emailWasProvided = array_key_exists('email', $validated);
        $email = $emailWasProvided ? $validated['email'] : null;

        unset($validated['email']);

        // Email tidak dapat diperbarui jika rekening belum memiliki
        // akun User yang terhubung atau role akunnya tidak sesuai.
        if ($emailWasProvided) {
            if (
                ! $customer->user ||
                $customer->user->role !== 'nasabah'
            ) {
                return response()->json([
                    'message' => 'Email tidak dapat diperbarui karena rekening belum terhubung dengan akun nasabah.',
                ], 409);
            }
        }

        DB::transaction(function () use ($customer, $validated, $emailWasProvided, $email) {
            // Memperbarui data rekening.
            $customer->update($validated);
            if (array_key_exists('status', $validated)) {
                $customer->pin_locked_at = null;
                $customer->save();
            }

            // Memperbarui email pada akun User.
            if (
                $emailWasProvided &&
                $customer->user->email !== $email
            ) {
                $customer->user->email = $email;

                // Email baru belum diverifikasi.
                $customer->user->email_verified_at = null;

                $customer->user->save();
            }

            // Sinkronisasi status akun User dengan status rekening.
            if (array_key_exists('status', $validated)) {
                if ($customer->user) {
                    $customer->user->update([
                        'status' => $validated['status'],
                    ]);
                }
            }
        });

        // Bersihkan cache percobaan PIN jika rekening diaktifkan kembali.
        if (($validated['status'] ?? null) === 'active') {
            Cache::forget(
                "customer_pin_failed_attempts_{$customer->id}"
            );
        }

        return response()->json([
            'message' => 'Data rekening nasabah berhasil diperbarui.',
            'data' => $customer->fresh([
                'schoolClass.major',
                'user:id,email,email_verified_at,role,must_change_password',
            ]),
        ]);
    }

    /**
     * Mengambil daftar kelas beserta informasi jurusan.
     */
    public function classes(ClassRosterService $classRoster)
    {
        $classes = $classRoster->configuredClasses()
            ->sortBy(fn ($class) => $class->major?->code.' '.$class->name)
            ->map(function ($class) use ($classRoster) {
                $identity = $classRoster->classIdentity($class);

                return [
                    'id' => $class->id,
                    'name' => $class->name,
                    'major_id' => $class->major_id,
                    'major_name' => $class->major?->name,
                    'major_code' => $class->major?->code,
                    'grade_level' => $identity['grade_level'],
                    'rombel' => $identity['rombel'],
                    'rombel_limit' => $classRoster->majorRosters()[$identity['major_code']],
                ];
            })->values();

        return response()->json([
            'message' => 'Data kelas berhasil diambil.',
            'data' => $classes,
        ]);
    }

    public function pinResetHistory(): JsonResponse
    {
        $history = ActivityLog::query()
            ->leftJoin('customers', function ($join) {
                $join->on('customers.id', '=', 'activity_logs.entity_id')
                    ->where('activity_logs.entity_type', '=', 'Customer');
            })
            ->whereIn('activity_logs.action', [
                'customer.pin_reset_requested',
                'customer.pin_reset_completed',
            ])
            ->orderByDesc('activity_logs.created_at')
            ->limit(100)
            ->get([
                'activity_logs.id',
                'activity_logs.action',
                'activity_logs.created_at',
                'customers.student_name',
                'customers.account_number',
            ])
            ->map(static fn ($entry) => [
                'id' => $entry->id,
                'customer_name' => $entry->student_name ?? 'Nasabah tidak ditemukan',
                'account_number' => $entry->account_number,
                'status' => $entry->action === 'customer.pin_reset_completed'
                    ? 'Berhasil'
                    : 'OTP diminta',
                'created_at' => $entry->created_at,
            ]);

        return response()->json([
            'message' => 'Riwayat aktivitas reset PIN berhasil diambil.',
            'data' => $history,
        ]);
    }
}

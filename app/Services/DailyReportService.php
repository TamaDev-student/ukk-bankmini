<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\DailyReportCorrection;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DailyReportService
{
    public function __construct(
        private readonly TransactionReportService $transactionReportService
    ) {}

    /**
     * Mengambil laporan, referensi saldo terakhir, dan rekap transaksi.
     */
    public function getReconciliation(User $teller, Carbon $date): array
    {
        $dateString = $date->toDateString();

        $this->ensureDateIsNotFuture($dateString);

        $report = DailyReport::query()
            ->with([
                'approver:id,full_name',
                'teller:id,full_name',
                'openingBalanceRecorder:id,full_name',
            ])
            ->where('teller_id', $teller->id)
            ->whereDate('report_date', $dateString)
            ->first();

        $previousReport = $this->getPreviousClosingReport($teller->id, $date);
        $lastApprovedBalance = $this->getLastApprovedBalance($teller->id, $date);
        $openingBalance = $report?->opening_balance
            ?? $previousReport?->system_balance;

        $reconciliation = $this->transactionReportService->reconciliation(
            $dateString,
            $teller->id
        );
        $totals = $report?->transaction_snapshot !== null
            ? $this->reportSummary($report)
            : $reconciliation['summary'];

        $systemBalance = $report?->transaction_snapshot !== null
            ? ($report->system_balance === null
                ? null
                : (int) $report->system_balance)
            : ($openingBalance === null
                ? null
                : $openingBalance
                    + $totals['total_deposit']
                    - $totals['total_withdrawal']);

        if ($report && $report->transaction_snapshot === null) {
            $report->setAttribute('system_balance', $systemBalance);
            $report->setAttribute(
                'closing_difference',
                $report->physical_balance === null || $systemBalance === null
                    ? null
                    : (int) $report->physical_balance - $systemBalance
            );
        }

        $result = [
            'date' => $dateString,
            'teller_name' => $teller->full_name,
            'last_approved_balance' => $lastApprovedBalance,
            'opening_balance' => $openingBalance,
            'opening_balance_source' => $report?->opening_balance_source
                ?? ($previousReport
                    ? 'previous_closure'
                    : ($openingBalance !== null
                        ? 'first_day_teller'
                        : 'first_day_input_required')),
            'opening_balance_source_label' => $this->openingBalanceSourceLabel(
                $report?->opening_balance_source
                    ?? ($previousReport
                        ? 'previous_closure'
                        : ($openingBalance !== null
                            ? 'first_day_teller'
                            : 'first_day_input_required'))
            ),
            'opening_balance_recorded_by' => $report?->openingBalanceRecorder,
            'opening_balance_recorded_at' => $report?->opening_balance_recorded_at,
            'previous_report' => $this->previousReportSummary($previousReport),
            'system_balance' => $systemBalance,
            'report' => $report,
            'transactions_summary' => $totals,
            'transactions' => $report?->transaction_snapshot
                ?? $this->transactionSnapshot($reconciliation['transactions']),
        ];
        $result['export'] = $this->reportExportData($result);

        return $result;
    }

    private function reportExportData(array $reconciliation): array
    {
        /** @var DailyReport|null $report */
        $report = $reconciliation['report'];
        $summary = $reconciliation['transactions_summary'];
        $closingDifference = $report?->closing_difference;
        $openingDifference = $report?->opening_difference;

        $cashMatchStatus = match (true) {
            $closingDifference === null => 'Belum dihitung',
            (int) $closingDifference === 0 => 'Cocok',
            (int) $closingDifference > 0 => 'Uang fisik lebih',
            default => 'Uang fisik kurang',
        };
        $hasCashDifference = ($openingDifference !== null && (int) $openingDifference !== 0)
            || ($closingDifference !== null && (int) $closingDifference !== 0);
        $overallMatchStatus = match (true) {
            $hasCashDifference
                || ($reconciliation['previous_report']['requires_review'] ?? false) => 'Memerlukan pemeriksaan',
            $openingDifference === null || $closingDifference === null => 'Belum lengkap',
            default => 'Kas cocok',
        };
        $reportStatus = match ($report?->status) {
            'draft' => 'Draft',
            'submitted' => 'Menunggu persetujuan',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
            'auto_closed', 'auto_closed_unverified' => 'Penutupan Otomatis · Belum Diverifikasi',
            default => 'Belum dibuat',
        };

        return [
            'system_name' => 'E-Teller Bank Mini Sekolah',
            'title' => 'Laporan Rekonsiliasi Kas',
            'date' => $reconciliation['date'],
            'teller_name' => $reconciliation['teller_name'] ?? '-',
            'generated_at' => now('Asia/Jakarta')->format('Y-m-d H:i:s'),
            'report_status' => $reportStatus,
            'reconciliation_status' => $overallMatchStatus,
            'opening_balance' => $reconciliation['opening_balance'],
            'opening_balance_source' => $reconciliation['opening_balance_source_label'],
            'opening_balance_recorded_by' => $report?->openingBalanceRecorder?->full_name
                ?? $report?->openingBalanceRecorder?->name,
            'opening_balance_recorded_at' => $report?->opening_balance_recorded_at
                ?->timezone('Asia/Jakarta')
                ->format('Y-m-d H:i:s'),
            'opening_physical_balance' => $report?->opening_physical_balance,
            'opening_difference' => $openingDifference,
            'total_transactions' => $summary['total_transactions'],
            'total_deposit' => $summary['total_deposit'],
            'total_withdrawal' => $summary['total_withdrawal'],
            'pending_transactions' => $summary['pending_transactions'],
            'failed_transactions' => $summary['failed_transactions'],
            'system_balance' => $reconciliation['system_balance'],
            'physical_balance' => $report?->physical_balance,
            'closing_difference' => $closingDifference,
            'cash_match_status' => $cashMatchStatus,
            'approval_status' => match ($report?->status) {
                'approved' => 'Disetujui Supervisor',
                'submitted' => 'Menunggu persetujuan Supervisor',
                'rejected' => 'Ditolak Supervisor',
                default => 'Belum disetujui Supervisor',
            },
            'approver_name' => $report?->approver?->full_name
                ?? $report?->approver?->name,
            'approved_at' => $report?->approved_at?->timezone('Asia/Jakarta')
                ->format('Y-m-d H:i:s'),
            'rejection_reason' => $report?->rejection_reason,
            'previous_report' => $reconciliation['previous_report'],
            'transactions' => array_map(
                static fn (array $transaction): array => [
                    'date' => $transaction['transaction_date'],
                    'reference' => $transaction['id'],
                    'type' => match ($transaction['transaction_type']) {
                        'deposit' => 'Setoran',
                        'withdrawal' => 'Penarikan',
                        default => $transaction['transaction_type'],
                    },
                    'amount' => $transaction['amount'],
                    'status' => match ($transaction['status']) {
                        'approved' => 'Berhasil',
                        'pending' => 'Pending',
                        'rejected', 'failed' => 'Gagal',
                        default => $transaction['status'],
                    },
                ],
                $reconciliation['transactions']
            ),
        ];
    }

    /**
     * Mengambil saldo sistem dari laporan rekonsiliasi terakhir yang disetujui.
     */
    public function getLastApprovedBalance(
        int $tellerId,
        Carbon $date
    ): ?int {
        $balance = DailyReport::query()
            ->where('teller_id', $tellerId)
            ->whereDate('report_date', '<', $date->toDateString())
            ->where('status', 'approved')
            ->orderByDesc('report_date')
            ->value('system_balance');

        return $balance === null ? null : (int) $balance;
    }

    private function getPreviousClosingReport(int $tellerId, Carbon $date): ?DailyReport
    {
        return DailyReport::query()
            ->where('teller_id', $tellerId)
            ->whereDate('report_date', '<', $date->toDateString())
            ->whereIn('status', [
                'submitted',
                'approved',
                'rejected',
                'auto_closed',
                'auto_closed_unverified',
            ])
            ->orderByDesc('report_date')
            ->first();
    }

    private function openingBalanceSourceLabel(?string $source): string
    {
        return match ($source) {
            'previous_closure' => 'Saldo akhir sistem penutupan sebelumnya',
            'first_day_teller' => 'Dicatat Teller saat operasional pertama',
            'supervisor_correction' => 'Saldo awal dikoreksi Supervisor',
            default => 'Belum dicatat',
        };
    }

    private function previousReportSummary(?DailyReport $report): ?array
    {
        if (! $report) {
            return null;
        }

        $reasons = [];
        if (in_array($report->status, ['auto_closed', 'auto_closed_unverified'], true)) {
            $reasons[] = 'Penutupan otomatis sebelumnya belum disetujui Supervisor.';
        } elseif ($report->status === 'submitted') {
            $reasons[] = 'Laporan sebelumnya masih menunggu persetujuan Supervisor.';
        } elseif ($report->status === 'rejected') {
            $reasons[] = 'Laporan sebelumnya ditolak Supervisor dan perlu diperiksa.';
        }

        if ($report->system_balance === null) {
            $reasons[] = 'Saldo sistem pada penutupan sebelumnya belum tersedia.';
        }

        if ($report->physical_balance === null) {
            $reasons[] = 'Kas fisik pada penutupan sebelumnya belum diverifikasi.';
        }

        if (
            $report->closing_difference !== null
            && (int) $report->closing_difference !== 0
        ) {
            $reasons[] = 'Terdapat selisih kas pada penutupan sebelumnya.';
        }

        return [
            'date' => $report->report_date->toDateString(),
            'status' => $report->status,
            'system_balance' => $report->system_balance === null
                ? null
                : (int) $report->system_balance,
            'physical_balance' => $report->physical_balance === null
                ? null
                : (int) $report->physical_balance,
            'closing_difference' => $report->closing_difference === null
                ? null
                : (int) $report->closing_difference,
            'requires_review' => $reasons !== [],
            'review_reasons' => $reasons,
        ];
    }

    /**
     * Membuat draft atau memperbarui data kas awal.
     */
    public function createOrUpdateDraft(
        User $teller,
        Carbon $date,
        array $data
    ): DailyReport {
        $validated = Validator::make($data, [
            'opening_balance' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
            ],
            'opening_physical_balance' => [
                'required',
                'integer',
                'min:0',
            ],
        ])->validate();

        $dateString = $date->toDateString();

        $this->ensureDateIsToday($dateString);

        return DB::transaction(function () use ($teller, $date, $dateString, $validated) {
            /*
             * Mengunci baris Teller untuk menyerialisasi perubahan
             * laporan bagi Teller yang sama.
             */
            User::query()
                ->whereKey($teller->id)
                ->lockForUpdate()
                ->firstOrFail();

            $report = DailyReport::query()
                ->where('teller_id', $teller->id)
                ->whereDate('report_date', $dateString)
                ->lockForUpdate()
                ->first();

            if (
                $report && (
                    in_array($report->status, ['submitted', 'approved'], true)
                    || $report->locked_at !== null
                )
            ) {
                throw ValidationException::withMessages([
                    'date' => [
                        'Laporan yang sudah dikirim atau disetujui tidak dapat diubah.',
                    ],
                ]);
            }

            if (
                $report?->opening_balance !== null
                && array_key_exists('opening_balance', $validated)
                && (int) $validated['opening_balance'] !== (int) $report->opening_balance
            ) {
                throw ValidationException::withMessages([
                    'opening_balance' => [
                        'Saldo awal yang sudah dicatat tidak dapat diubah oleh Teller.',
                    ],
                ]);
            }

            $previousReport = $this->getPreviousClosingReport($teller->id, $date);
            $lastApprovedBalance = $this->getLastApprovedBalance(
                $teller->id,
                $date
            );
            if ($previousReport && $previousReport->system_balance === null) {
                throw ValidationException::withMessages([
                    'opening_balance' => [
                        'Penutupan sebelumnya belum memiliki saldo sistem. Minta Supervisor memeriksa laporan sebelum operasional dimulai.',
                    ],
                ]);
            }
            if ($previousReport && array_key_exists('opening_balance', $validated)) {
                throw ValidationException::withMessages([
                    'opening_balance' => [
                        'Saldo awal hari ini berasal dari saldo sistem penutupan sebelumnya.',
                    ],
                ]);
            }

            $openingBalance = $report?->opening_balance
                ?? $previousReport?->system_balance
                ?? ($validated['opening_balance'] ?? null);

            if ($openingBalance === null) {
                throw ValidationException::withMessages([
                    'opening_balance' => [
                        'Catat saldo awal sistem yang benar-benar diterima sebelum memulai operasional.',
                    ],
                ]);
            }

            $openingSource = $report?->opening_balance_source
                ?? ($previousReport ? 'previous_closure' : 'first_day_teller');
            $openingBalanceRecorderId = $report?->opening_balance_recorded_by
                ?? ($openingSource === 'first_day_teller' ? $teller->id : null);
            $openingBalanceRecordedAt = $report?->opening_balance_recorded_at
                ?? ($openingSource === 'first_day_teller' ? now() : null);
            $openingPhysicalBalance = (int) $validated[
                'opening_physical_balance'
            ];

            $attributes = [
                'last_approved_balance' => $lastApprovedBalance,
                'opening_balance' => $openingBalance,
                'opening_balance_source' => $openingSource,
                'opening_balance_recorded_by' => $openingBalanceRecorderId,
                'opening_balance_recorded_at' => $openingBalanceRecordedAt,
                'opening_balance_difference' => null,
                'opening_physical_balance' => $openingPhysicalBalance,
                'opening_difference' => $openingPhysicalBalance
                    - $openingBalance,
            ];

            if ($report) {
                if ($report->status === 'rejected') {
                    $attributes['status'] = 'draft';
                    $attributes['submitted_at'] = null;
                }

                /*
                 * Perubahan kas awal membatalkan hasil penghitungan
                 * kas akhir yang sebelumnya tersimpan.
                 * Teller harus menghitung kas akhir kembali.
                 */
                $attributes['physical_balance'] = null;
                $attributes['system_balance'] = $openingBalance;
                $attributes['closing_difference'] = null;
                $attributes['transaction_snapshot'] = null;

                $report->fill($attributes);
                $report->save();

                // rejection_reason sengaja dipertahankan
                // sampai laporan berhasil dikirim kembali.
                return $report->refresh();
            }

            return DailyReport::create(array_merge(
                $attributes,
                [
                    'report_date' => $dateString,
                    'teller_id' => $teller->id,
                    'status' => 'draft',
                    'total_deposit' => 0,
                    'total_withdrawal' => 0,
                    'system_balance' => $openingBalance,
                ]
            ));
        });
    }

    /**
     * Menyimpan kas fisik akhir dan menghitung saldo sistem.
     */
    public function closeReport(
        User $teller,
        int $reportId,
        int $physicalBalance
    ): DailyReport {
        Validator::make(
            ['physical_balance' => $physicalBalance],
            [
                'physical_balance' => [
                    'required',
                    'integer',
                    'min:0',
                ],
            ]
        )->validate();

        return DB::transaction(function () use ($teller, $reportId, $physicalBalance) {
            User::query()
                ->whereKey($teller->id)
                ->lockForUpdate()
                ->firstOrFail();

            $report = DailyReport::query()
                ->whereKey($reportId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $report->teller_id !== (int) $teller->id) {
                throw new AuthorizationException(
                    'Kamu tidak memiliki akses ke laporan Teller ini.'
                );
            }

            $this->ensureDateIsToday($report->report_date->toDateString());

            if (
                in_array(
                    $report->status,
                    ['submitted', 'approved', 'auto_closed', 'auto_closed_unverified'],
                    true
                )
                || $report->locked_at !== null
            ) {
                throw ValidationException::withMessages([
                    'report_id' => [
                        'Laporan yang sudah dikirim atau disetujui tidak dapat diubah.',
                    ],
                ]);
            }

            if ($report->opening_physical_balance === null) {
                throw ValidationException::withMessages([
                    'opening_physical_balance' => [
                        'Kas awal harus diisi sebelum kas akhir dicatat.',
                    ],
                ]);
            }
            if ($report->opening_balance === null) {
                throw ValidationException::withMessages([
                    'opening_balance' => [
                        'Saldo awal sistem belum dicatat.',
                    ],
                ]);
            }

            $reconciliation = $this->transactionReportService->reconciliation(
                $report->report_date->toDateString(),
                $teller->id
            );
            $totals = $reconciliation['summary'];

            $systemBalance = (int) $report->opening_balance
                + $totals['total_deposit']
                - $totals['total_withdrawal'];

            $attributes = [
                'total_deposit' => $totals['total_deposit'],
                'total_withdrawal' => $totals['total_withdrawal'],
                ...$this->summaryAttributes($totals),
                'transaction_snapshot' => null,
                'system_balance' => $systemBalance,
                'physical_balance' => $physicalBalance,
                'closing_difference' => $physicalBalance - $systemBalance,
            ];

            if ($report->status === 'rejected') {
                $attributes['status'] = 'draft';
                $attributes['submitted_at'] = null;
            }

            $report->fill($attributes);
            $report->save();

            // Alasan penolakan tetap tersedia saat Teller mengoreksi laporan.
            return $report->refresh();
        });
    }

    /**
     * Mengirim laporan harian yang sudah memiliki data rekonsiliasi lengkap.
     */
    public function submit(User $teller, Carbon $date): DailyReport
    {
        $dateString = $date->toDateString();

        $this->ensureDateIsToday($dateString);

        return DB::transaction(function () use ($teller, $dateString) {
            /*
             * Penguncian ini menyelaraskan perubahan laporan antar-request
             * untuk Teller yang sama. Perlindungan terhadap transaksi yang
             * dibuat bersamaan juga perlu diterapkan di TransactionService.
             */
            User::query()
                ->whereKey($teller->id)
                ->lockForUpdate()
                ->firstOrFail();

            $report = DailyReport::query()
                ->where('teller_id', $teller->id)
                ->whereDate('report_date', $dateString)
                ->lockForUpdate()
                ->first();

            if (! $report) {
                throw ValidationException::withMessages([
                    'date' => [
                        'Laporan harian belum dibuat. Isi kas awal dan kas akhir terlebih dahulu.',
                    ],
                ]);
            }

            $this->ensureDateIsToday($report->report_date->toDateString());

            if (
                ! in_array($report->status, ['draft', 'rejected'], true)
                || $report->locked_at !== null
            ) {
                throw ValidationException::withMessages([
                    'date' => [
                        'Laporan dengan status saat ini tidak dapat dikirim.',
                    ],
                ]);
            }

            if (
                $report->opening_physical_balance === null
                || $report->physical_balance === null
                || $report->opening_balance === null
            ) {
                throw ValidationException::withMessages([
                    'date' => [
                        'Laporan belum lengkap. Periksa saldo awal dan penghitungan uang saat tutup kas.',
                    ],
                ]);
            }

            /*
             * Hitung ulang transaksi aktual saat submit.
             * Nilai selisih kas akhir dapat berubah jika transaksi
             * bertambah setelah kas fisik akhir dihitung.
             */
            $reconciliation = $this->transactionReportService->reconciliation(
                $dateString,
                $teller->id
            );
            $totals = $reconciliation['summary'];

            $systemBalance = (int) $report->opening_balance
                + $totals['total_deposit']
                - $totals['total_withdrawal'];

            $report->fill([
                'total_deposit' => $totals['total_deposit'],
                'total_withdrawal' => $totals['total_withdrawal'],
                ...$this->summaryAttributes($totals),
                'transaction_snapshot' => $this->transactionSnapshot(
                    $reconciliation['transactions']
                ),
                'system_balance' => $systemBalance,

                'closing_difference' => (int) $report->physical_balance
                    - $systemBalance,

                'status' => 'submitted',
                'submitted_at' => now(),
                'rejection_reason' => null,
            ]);

            $report->save();

            return $report->refresh();
        });
    }

    /**
     * Menghitung total transaksi deposit dan withdrawal
     * milik satu Teller pada satu tanggal.
     */
    /**
     * Menyimpan snapshot detail transaksi tanpa data sensitif.
     */
    private function transactionSnapshot(iterable $transactions): array
    {
        $snapshot = [];

        foreach ($transactions as $transaction) {
            $snapshot[] = [
                'id' => $transaction->id,
                'transaction_date' => $transaction->transaction_date
                    ?->toISOString(),
                'customer' => [
                    'account_number' => $transaction->customer?->account_number,
                    'student_name' => $transaction->customer?->student_name,
                ],
                'transaction_type' => $transaction->transaction_type,
                'amount' => (int) $transaction->amount,
                'status' => $transaction->status,
            ];
        }

        return $snapshot;
    }

    /**
     * Membangun kolom snapshot rekap transaksi.
     *
     * @param  array<string, int>  $summary
     * @return array<string, int>
     */
    private function summaryAttributes(array $summary): array
    {
        return [
            'total_transactions' => $summary['total_transactions'],
            'pending_transactions' => $summary['pending_transactions'],
            'pending_total' => $summary['pending_total'],
            'failed_transactions' => $summary['failed_transactions'],
            'failed_total' => $summary['failed_total'],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function reportSummary(DailyReport $report): array
    {
        return [
            'total_transactions' => (int) $report->total_transactions,
            'total_deposit' => (int) $report->total_deposit,
            'total_withdrawal' => (int) $report->total_withdrawal,
            'pending_transactions' => (int) $report->pending_transactions,
            'pending_total' => (int) $report->pending_total,
            'failed_transactions' => (int) $report->failed_transactions,
            'failed_total' => (int) $report->failed_total,
        ];
    }

    public function correctFirstDayOpeningBalance(
        User $supervisor,
        int $reportId,
        int $correctedBalance,
        string $reason
    ): DailyReport {
        Validator::make(
            [
                'opening_balance' => $correctedBalance,
                'reason' => $reason,
            ],
            [
                'opening_balance' => ['required', 'integer', 'min:0'],
                'reason' => ['required', 'string', 'min:10', 'max:1000'],
            ]
        )->validate();

        $tellerId = DailyReport::query()
            ->whereKey($reportId)
            ->value('teller_id');

        return DB::transaction(function () use (
            $supervisor,
            $reportId,
            $tellerId,
            $correctedBalance,
            $reason
        ) {
            User::query()
                ->whereKey($tellerId)
                ->lockForUpdate()
                ->firstOrFail();

            $report = DailyReport::query()
                ->whereKey($reportId)
                ->lockForUpdate()
                ->firstOrFail();

            $automaticMissingOpening = $report->status === 'auto_closed_unverified'
                && $report->opening_balance === null;
            if (
                (! $automaticMissingOpening && ! in_array(
                    $report->opening_balance_source,
                    ['first_day_teller', 'first_day_input_required', 'supervisor_correction'],
                    true
                ))
                || (
                    ! in_array($report->status, ['draft', 'rejected'], true)
                    && ! $automaticMissingOpening
                )
                || ($report->locked_at !== null && ! $automaticMissingOpening)
            ) {
                throw ValidationException::withMessages([
                    'report_id' => [
                        'Hanya saldo awal laporan yang belum memiliki saldo sistem yang dapat dikoreksi sebelum transaksi dimulai.',
                    ],
                ]);
            }

            $operationalStart = Carbon::parse(
                $report->report_date->toDateString(),
                'Asia/Jakarta'
            )->startOfDay()->utc();
            $operationalEnd = $operationalStart->copy()->addDay();
            $hasTransactions = Transaction::query()
                ->where('user_id', $report->teller_id)
                ->where('transaction_date', '>=', $operationalStart)
                ->where('transaction_date', '<', $operationalEnd)
                ->exists();

            if ($hasTransactions) {
                throw ValidationException::withMessages([
                    'report_id' => [
                        'Saldo awal tidak dapat dikoreksi setelah transaksi operasional dimulai.',
                    ],
                ]);
            }

            $previousBalance = $report->opening_balance === null
                ? null
                : (int) $report->opening_balance;
            $report->opening_balance = $correctedBalance;
            $report->opening_balance_source = 'supervisor_correction';
            $report->opening_balance_recorded_by = $supervisor->id;
            $report->opening_balance_recorded_at = now();
            $report->system_balance = $correctedBalance;
            $report->opening_difference = $report->opening_physical_balance === null
                ? null
                : (int) $report->opening_physical_balance - $correctedBalance;
            $report->physical_balance = null;
            $report->closing_difference = null;
            $report->transaction_snapshot = null;
            if ($automaticMissingOpening) {
                $report->status = 'auto_closed_unverified';
            }
            $report->save();

            DailyReportCorrection::query()->create([
                'daily_report_id' => $report->id,
                'user_id' => $supervisor->id,
                'previous_opening_balance' => $previousBalance,
                'corrected_opening_balance' => $correctedBalance,
                'reason' => $reason,
            ]);

            return $report->refresh();
        });
    }

    /**
     * Membuat penutupan operasional otomatis tanpa mengasumsikan kas fisik.
     */
    public function autoCloseForDate(Carbon $date): int
    {
        $dateString = $date->toDateString();
        $this->ensureDateIsToday($dateString);
        $closedCount = 0;

        User::query()
            ->where('role', 'teller')
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (User $teller) use ($dateString, $date, &$closedCount) {
                $closed = DB::transaction(function () use ($teller, $dateString, $date) {
                    User::query()
                        ->whereKey($teller->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $report = DailyReport::query()
                        ->where('teller_id', $teller->id)
                        ->whereDate('report_date', $dateString)
                        ->lockForUpdate()
                        ->first();

                    if ($report && ! in_array($report->status, ['draft'], true)) {
                        return false;
                    }

                    $previousReport = $this->getPreviousClosingReport($teller->id, $date);
                    $lastApprovedBalance = $this->getLastApprovedBalance(
                        $teller->id,
                        $date
                    );
                    $openingBalance = $report?->opening_balance
                        ?? $previousReport?->system_balance;
                    $reconciliation = $this->transactionReportService->reconciliation(
                        $dateString,
                        $teller->id
                    );
                    $totals = $reconciliation['summary'];
                    $systemBalance = $openingBalance === null
                        ? null
                        : $openingBalance
                            + $totals['total_deposit']
                            - $totals['total_withdrawal'];
                    $physicalWasCounted = $report?->physical_balance !== null;
                    $closedAt = now('Asia/Jakarta');

                    $attributes = [
                        'last_approved_balance' => $lastApprovedBalance,
                        'opening_balance' => $openingBalance,
                        'opening_balance_source' => $report?->opening_balance_source
                            ?? ($previousReport
                                ? 'previous_closure'
                                : ($openingBalance === null
                                    ? 'first_day_input_required'
                                    : 'first_day_teller')),
                        'opening_balance_difference' => null,
                        'total_deposit' => $totals['total_deposit'],
                        'total_withdrawal' => $totals['total_withdrawal'],
                        ...$this->summaryAttributes($totals),
                        'system_balance' => $systemBalance,
                        'closing_difference' => $physicalWasCounted && $systemBalance !== null
                            ? (int) $report->physical_balance - $systemBalance
                            : null,
                        'transaction_snapshot' => $this->transactionSnapshot(
                            $reconciliation['transactions']
                        ),
                        'status' => 'auto_closed_unverified',
                        'auto_closed_at' => $closedAt,
                        'locked_at' => $closedAt,
                    ];

                    if ($report) {
                        $report->fill($attributes);
                        $report->save();
                    } else {
                        DailyReport::create([
                            ...$attributes,
                            'report_date' => $dateString,
                            'teller_id' => $teller->id,
                            'opening_physical_balance' => null,
                            'opening_difference' => null,
                            'physical_balance' => null,
                            'closing_difference' => null,
                            'opening_balance_recorded_by' => null,
                            'opening_balance_recorded_at' => null,
                        ]);
                    }

                    return true;
                });

                if ($closed) {
                    $closedCount++;
                }
            });

        return $closedCount;
    }

    /**
     * Laporan baru dan perubahan kas hanya diizinkan pada hari operasional ini.
     */
    private function ensureDateIsToday(string $date): void
    {
        if ($date !== now('Asia/Jakarta')->toDateString()) {
            throw ValidationException::withMessages([
                'date' => [
                    'Penutupan kas baru hanya dapat dibuat untuk tanggal operasional hari ini.',
                ],
            ]);
        }
    }

    private function ensureDateIsNotFuture(string $date): void
    {
        if ($date > now('Asia/Jakarta')->toDateString()) {
            throw ValidationException::withMessages([
                'date' => ['Tanggal laporan tidak boleh melebihi tanggal hari ini.'],
            ]);
        }
    }
}

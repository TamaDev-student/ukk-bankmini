<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Services\ExcelExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AccountingController extends Controller
{
    public function journals(Request $request): JsonResponse
    {
        $validated = $request->validate($this->filterRules());
        $journals = $this->filteredJournals($validated)->get();

        return response()->json([
            'message' => 'Berhasil mengambil data jurnal',
            'data' => $journals,
        ], 200);
    }

    public function export(
        Request $request,
        ExcelExportService $excelExportService
    ): Response {
        $validated = $request->validate($this->filterRules());
        $journals = $this->filteredJournals($validated)
            ->get();

        $debitTotal = (int) $journals
            ->where('type', 'debit')
            ->sum('amount');
        $creditTotal = (int) $journals
            ->where('type', 'credit')
            ->sum('amount');

        $summaryRows = [
            ['Laporan', 'Jurnal Akuntansi'],
            ['Dibuat', now('Asia/Jakarta')->format('Y-m-d H:i:s')],
            ['Tanggal transaksi', $validated['tanggal_transaksi'] ?? 'Semua tanggal'],
            ['Nama Teller', $validated['nama_teller'] ?? 'Semua Teller'],
            ['Nama nasabah', $validated['nama_nasabah'] ?? 'Semua nasabah'],
            ['Nomor rekening', $validated['nomor_rekening'] ?? 'Semua rekening'],
            ['Jumlah baris jurnal', $journals->count()],
            ['Total debit (Rp)', $debitTotal],
            ['Total kredit (Rp)', $creditTotal],
            ['Selisih debit-kredit (Rp)', $debitTotal - $creditTotal],
        ];
        $journalRows = [[
            'No.',
            'Tanggal jurnal',
            'Referensi transaksi',
            'Keterangan',
            'Kode akun',
            'Nama akun',
            'Debit (Rp)',
            'Kredit (Rp)',
        ]];

        foreach ($journals as $index => $journal) {
            $transaction = $journal->transaction;
            $journalRows[] = [
                $index + 1,
                $transaction?->transaction_date
                    ?->copy()
                    ->setTimezone('Asia/Jakarta')
                    ->format('Y-m-d H:i:s') ?? '',
                (string) ($transaction?->id ?? $journal->transaction_id),
                ucfirst((string) $transaction?->transaction_type)
                    .' transaksi #'.($transaction?->id ?? $journal->transaction_id),
                '',
                $journal->account_name,
                $journal->type === 'debit' ? (int) $journal->amount : null,
                $journal->type === 'credit' ? (int) $journal->amount : null,
            ];
        }

        $contents = $excelExportService->create([
            'Ringkasan' => $summaryRows,
            'Jurnal' => $journalRows,
        ]);
        $filename = isset($validated['tanggal_transaksi'])
            ? 'jurnal-akuntansi-'.$validated['tanggal_transaksi'].'.xlsx'
            : 'jurnal-akuntansi.xlsx';

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
        ]);
    }

    private function filterRules(): array
    {
        return [
            'tanggal_transaksi' => ['sometimes', 'date_format:Y-m-d'],
            'nama_teller' => ['sometimes', 'string', 'max:100'],
            'nama_nasabah' => ['sometimes', 'string', 'max:100'],
            'nomor_rekening' => ['sometimes', 'string', 'max:50'],
        ];
    }

    private function filteredJournals(array $filters): Builder
    {
        $query = Journal::query()
            ->select('journals.*')
            ->join(
                'transactions',
                'transactions.id',
                '=',
                'journals.transaction_id'
            )
            ->with([
                'transaction.user',
                'transaction.customer',
            ])
            ->whereHas('transaction', function (Builder $transactionQuery) use ($filters) {
                $transactionQuery->where('status', 'approved');

                if (isset($filters['tanggal_transaksi'])) {
                    $transactionQuery->whereDate(
                        'transaction_date',
                        $filters['tanggal_transaksi']
                    );
                }

                if (isset($filters['nama_teller'])) {
                    $transactionQuery->whereHas('user', function (Builder $userQuery) use ($filters) {
                        $userQuery->where('name', 'like', '%'.$filters['nama_teller'].'%');
                    });
                }

                if (isset($filters['nama_nasabah'])) {
                    $transactionQuery->whereHas('customer', function (Builder $customerQuery) use ($filters) {
                        $customerQuery->where('student_name', 'like', '%'.$filters['nama_nasabah'].'%');
                    });
                }

                if (isset($filters['nomor_rekening'])) {
                    $transactionQuery->whereHas('customer', function (Builder $customerQuery) use ($filters) {
                        $customerQuery->where('account_number', 'like', '%'.$filters['nomor_rekening'].'%');
                    });
                }
            })
            ->orderByDesc('transactions.transaction_date')
            ->orderByDesc('transactions.id')
            ->orderBy('journals.id');

        return $query;
    }
}

<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class TransactionReportService
{
    public const SUCCESS_STATUS = 'approved';

    public const PENDING_STATUS = 'pending';

    public const CANCELLED_STATUS = 'cancelled';

    public const FAILED_STATUSES = ['rejected', 'failed'];

    public function daily(string $date, ?int $tellerId = null): array
    {
        $start = CarbonImmutable::parse($date, 'Asia/Jakarta')
            ->startOfDay()
            ->utc();
        $end = $start->addDay();

        return $this->buildReport($start, $end, $tellerId, 'daily');
    }

    public function dailySummary(string $date, ?int $tellerId = null): array
    {
        $start = CarbonImmutable::parse($date, 'Asia/Jakarta')
            ->startOfDay()
            ->utc();

        return $this->summarize(
            $this->transactionQuery($start, $start->addDay(), $tellerId)
        );
    }

    /**
     * Summarizes reconciliation activity by the time its outcome takes effect.
     * A pending or rejected transaction remains associated with its request day;
     * an approved transaction is counted on its approval day.
     *
     * @return array{summary: array<string, int>, transactions: Collection<int, Transaction>}
     */
    public function reconciliation(string $date, int $tellerId): array
    {
        $start = CarbonImmutable::parse($date, 'Asia/Jakarta')
            ->startOfDay()
            ->utc();
        $end = $start->addDay();

        $baseQuery = Transaction::query()
            ->where('user_id', $tellerId);

        $successful = (clone $baseQuery)
            ->where('status', self::SUCCESS_STATUS)
            ->where(function (Builder $query) use ($start, $end) {
                $query->where(function (Builder $approved) use ($start, $end) {
                    $approved->whereNotNull('approved_at')
                        ->where('approved_at', '>=', $start->toDateTimeString())
                        ->where('approved_at', '<', $end->toDateTimeString());
                })->orWhere(function (Builder $legacy) use ($start, $end) {
                    $legacy->whereNull('approved_at')
                        ->where('transaction_date', '>=', $start->toDateTimeString())
                        ->where('transaction_date', '<', $end->toDateTimeString());
                });
            });

        $pending = (clone $baseQuery)
            ->where('status', self::PENDING_STATUS)
            ->where('transaction_date', '>=', $start->toDateTimeString())
            ->where('transaction_date', '<', $end->toDateTimeString());

        $failed = (clone $baseQuery)
            ->whereIn('status', self::FAILED_STATUSES)
            ->where(function (Builder $query) use ($start, $end) {
                $query->where(function (Builder $rejected) use ($start, $end) {
                    $rejected->whereNotNull('rejected_at')
                        ->where('rejected_at', '>=', $start->toDateTimeString())
                        ->where('rejected_at', '<', $end->toDateTimeString());
                })->orWhere(function (Builder $legacy) use ($start, $end) {
                    $legacy->whereNull('rejected_at')
                        ->where('transaction_date', '>=', $start->toDateTimeString())
                        ->where('transaction_date', '<', $end->toDateTimeString());
                });
            });

        $transactions = (clone $baseQuery)
            ->where(function (Builder $query) use ($start, $end) {
                $query->where(function (Builder $approved) use ($start, $end) {
                    $approved->where('status', self::SUCCESS_STATUS)
                        ->where(function (Builder $dateQuery) use ($start, $end) {
                            $dateQuery->where(function (Builder $dated) use ($start, $end) {
                                $dated->whereNotNull('approved_at')
                                    ->where('approved_at', '>=', $start->toDateTimeString())
                                    ->where('approved_at', '<', $end->toDateTimeString());
                            })->orWhere(function (Builder $legacy) use ($start, $end) {
                                $legacy->whereNull('approved_at')
                                    ->where('transaction_date', '>=', $start->toDateTimeString())
                                    ->where('transaction_date', '<', $end->toDateTimeString());
                            });
                        });
                })->orWhere(function (Builder $pending) use ($start, $end) {
                    $pending->where('status', self::PENDING_STATUS)
                        ->where('transaction_date', '>=', $start->toDateTimeString())
                        ->where('transaction_date', '<', $end->toDateTimeString());
                })->orWhere(function (Builder $failed) use ($start, $end) {
                    $failed->whereIn('status', self::FAILED_STATUSES)
                        ->where(function (Builder $dateQuery) use ($start, $end) {
                            $dateQuery->where(function (Builder $dated) use ($start, $end) {
                                $dated->whereNotNull('rejected_at')
                                    ->where('rejected_at', '>=', $start->toDateTimeString())
                                    ->where('rejected_at', '<', $end->toDateTimeString());
                            })->orWhere(function (Builder $legacy) use ($start, $end) {
                                $legacy->whereNull('rejected_at')
                                    ->where('transaction_date', '>=', $start->toDateTimeString())
                                    ->where('transaction_date', '<', $end->toDateTimeString());
                            });
                        });
                })->orWhere(function (Builder $cancelled) use ($start, $end) {
                    $cancelled->where('status', self::CANCELLED_STATUS)
                        ->where(function (Builder $dateQuery) use ($start, $end) {
                            $dateQuery->where(function (Builder $dated) use ($start, $end) {
                                $dated->whereNotNull('cancelled_at')
                                    ->where('cancelled_at', '>=', $start->toDateTimeString())
                                    ->where('cancelled_at', '<', $end->toDateTimeString());
                            })->orWhere(function (Builder $legacy) use ($start, $end) {
                                $legacy->whereNull('cancelled_at')
                                    ->where('transaction_date', '>=', $start->toDateTimeString())
                                    ->where('transaction_date', '<', $end->toDateTimeString());
                            });
                        });
                });
            })
            ->with([
                'customer:id,account_number,student_name',
                'user:id,username,full_name,role',
            ])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        return [
            'summary' => [
                'total_transactions' => (int) (clone $successful)->count(),
                'total_deposit' => (int) (clone $successful)
                    ->where('transaction_type', 'deposit')
                    ->sum('amount'),
                'total_withdrawal' => (int) (clone $successful)
                    ->where('transaction_type', 'withdrawal')
                    ->sum('amount'),
                'pending_transactions' => (int) (clone $pending)->count(),
                'pending_total' => (int) (clone $pending)->sum('amount'),
                'failed_transactions' => (int) (clone $failed)->count(),
                'failed_total' => (int) (clone $failed)->sum('amount'),
            ],
            'transactions' => $transactions,
        ];
    }

    public function monthly(string $month, ?int $tellerId = null): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'Asia/Jakarta')
            ->startOfMonth()
            ->utc();
        $end = $start->addMonth();

        return $this->buildReport($start, $end, $tellerId, 'monthly');
    }

    public function summarize(Builder $query): array
    {
        $successful = (clone $query)
            ->where('status', self::SUCCESS_STATUS);
        $pending = (clone $query)
            ->where('status', self::PENDING_STATUS);
        $failed = (clone $query)
            ->whereIn('status', self::FAILED_STATUSES);

        return [
            'total_transactions' => (int) (clone $successful)->count(),
            'total_deposit' => (int) (clone $successful)
                ->where('transaction_type', 'deposit')
                ->sum('amount'),
            'total_withdrawal' => (int) (clone $successful)
                ->where('transaction_type', 'withdrawal')
                ->sum('amount'),
            'pending_transactions' => (int) (clone $pending)->count(),
            'pending_total' => (int) (clone $pending)->sum('amount'),
            'failed_transactions' => (int) (clone $failed)->count(),
            'failed_total' => (int) (clone $failed)->sum('amount'),
        ];
    }

    private function buildReport(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $tellerId,
        string $period
    ): array {
        $query = $this->transactionQuery($start, $end, $tellerId)
            ->with([
                'customer:id,account_number,student_name,status',
                'user:id,username,full_name,role',
            ]);

        $summary = $this->summarize($query);
        $transactions = (clone $query)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $dailyBreakdown = $period === 'monthly'
            ? $transactions
                ->groupBy(fn (Transaction $transaction) => CarbonImmutable::parse(
                    $transaction->transaction_date,
                    'UTC'
                )->setTimezone('Asia/Jakarta')->toDateString())
                ->map(function ($dailyTransactions, string $date) {
                    $dailySummary = $this->summarize(
                        Transaction::query()->whereKey(
                            $dailyTransactions->pluck('id')
                        )
                    );

                    return array_merge(['date' => $date], $dailySummary);
                })
                ->values()
                ->all()
            : [];

        return [
            'period' => $period,
            'from' => $start->setTimezone('Asia/Jakarta')->toDateString(),
            'to' => $end->subSecond()->setTimezone('Asia/Jakarta')->toDateString(),
            'summary' => $summary,
            'daily_breakdown' => $dailyBreakdown,
            'transactions' => $transactions,
        ];
    }

    private function transactionQuery(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $tellerId
    ): Builder {
        $query = Transaction::query()
            ->where('transaction_date', '>=', $start->toDateTimeString())
            ->where('transaction_date', '<', $end->toDateTimeString());

        if ($tellerId !== null) {
            $query->where('user_id', $tellerId);
        }

        return $query;
    }
}

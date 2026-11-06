import { useCallback, useEffect, useState } from "react";
import {
    AlertCircle,
    ArrowDownLeft,
    ArrowUpRight,
    CalendarDays,
    CheckCircle2,
    Clock3,
    Download,
    FileText,
    FileSpreadsheet,
    RefreshCw,
    XCircle,
} from "lucide-react";

import api from "../../../../config/api";
import { downloadReportPdf } from "../../../../utils/reportPdf";

const todayJakarta = () =>
    new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Jakarta",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(new Date());

const formatRupiah = (value) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value) || 0);

const formatDate = (value) => {
    if (!value) return "-";

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);

    return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "medium",
        timeZone: "Asia/Jakarta",
    }).format(date);
};

const formatDateTime = (value) => {
    if (!value) return "-";

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);

    return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "medium",
        timeStyle: "short",
        timeZone: "Asia/Jakarta",
    }).format(date);
};

const transactionTypeLabel = (type) => {
    if (type === "deposit") return "Setoran";
    if (type === "withdrawal") return "Penarikan";
    return type || "-";
};

const transactionStatus = {
    approved: {
        label: "Berhasil",
        className: "bg-emerald-100 text-emerald-800",
    },
    pending: {
        label: "Pending",
        className: "bg-amber-100 text-amber-800",
    },
    rejected: {
        label: "Ditolak",
        className: "bg-rose-100 text-rose-800",
    },
    failed: {
        label: "Gagal",
        className: "bg-rose-100 text-rose-800",
    },
};

function TransactionReports({
    endpointBase = "/admin/reports/transactions",
}) {
    const [period, setPeriod] = useState("daily");
    const [date, setDate] = useState(todayJakarta());
    const [month, setMonth] = useState(todayJakarta().slice(0, 7));
    const [report, setReport] = useState(null);
    const [loading, setLoading] = useState(true);
    const [exporting, setExporting] = useState(false);
    const [error, setError] = useState("");
    const [exportError, setExportError] = useState("");
    const [exportSuccess, setExportSuccess] = useState("");

    const loadReport = useCallback(
        async (event) => {
            event?.preventDefault();
            setLoading(true);
            setError("");

            try {
                const params =
                    period === "daily" ? { period, date } : { period, month };
                const response = await api.get(
                    endpointBase,
                    { params }
                );
                setReport(response.data.data);
            } catch (requestError) {
                setReport(null);
                setError(
                    requestError?.response?.data?.message ||
                        "Laporan transaksi gagal dimuat."
                );
            } finally {
                setLoading(false);
            }
        },
        [date, endpointBase, month, period]
    );

    useEffect(() => {
        const timer = window.setTimeout(() => {
            void loadReport();
        }, 0);

        return () => window.clearTimeout(timer);
    }, [loadReport]);

    const exportReport = async () => {
        setExporting(true);
        setExportError("");
        setExportSuccess("");
        try {
            const params =
                period === "daily" ? { period, date } : { period, month };
            const response = await api.get(
                `${endpointBase}/export`,
                { params, responseType: "blob" }
            );
            const url = URL.createObjectURL(response.data);
            const link = document.createElement("a");
            link.href = url;
            link.download = `laporan-transaksi-${period === "daily" ? date : month}.xlsx`;
            link.click();
            URL.revokeObjectURL(url);
            setExportSuccess("File Excel laporan transaksi berhasil diunduh.");
        } catch (requestError) {
            setExportError(
                requestError?.response?.data?.message ||
                    "File laporan gagal diekspor."
            );
        } finally {
            setExporting(false);
        }
    };

    const exportReportPdf = () => {
        setExporting(true);
        setExportError("");
        setExportSuccess("");
        try {
            const summaryRows = [
                [
                    "Total transaksi",
                    Number(summary?.total_transactions ?? 0) +
                        Number(summary?.pending_transactions ?? 0) +
                        Number(summary?.failed_transactions ?? 0),
                ],
                ["Transaksi berhasil", summary?.total_transactions ?? 0],
                ["Total setoran berhasil", formatRupiah(summary?.total_deposit)],
                ["Total penarikan berhasil", formatRupiah(summary?.total_withdrawal)],
                ["Transaksi pending", summary?.pending_transactions ?? 0],
                ["Transaksi gagal/ditolak", summary?.failed_transactions ?? 0],
            ];
            if (period === "monthly") {
                for (const day of dailyBreakdown) {
                    summaryRows.push([
                        `Rekap ${formatDate(day.date)}`,
                        `${day.total_transactions} berhasil; setoran ${formatRupiah(day.total_deposit)}; penarikan ${formatRupiah(day.total_withdrawal)}; ${day.pending_transactions} pending; ${day.failed_transactions} gagal`,
                    ]);
                }
            }

            downloadReportPdf({
                title: period === "daily" ? "Laporan Transaksi Harian" : "Laporan Transaksi Bulanan",
                subtitle: `Periode: ${report.from} - ${report.to}`,
                filename: `laporan-transaksi-${period === "daily" ? date : month}.pdf`,
                summary: summaryRows,
                columns: [
                    "No.",
                    "Tanggal dan waktu",
                    "Referensi",
                    "No. rekening",
                    "Nama nasabah",
                    "Jenis",
                    "Nominal (Rp)",
                    "Status",
                    "Teller",
                ],
                rows: transactions.map((transaction, index) => [
                    index + 1,
                    formatDateTime(transaction.transaction_date),
                    transaction.id ?? "-",
                    transaction.customer?.account_number ?? "-",
                    transaction.customer?.student_name ?? "-",
                    transactionTypeLabel(transaction.transaction_type),
                    formatRupiah(transaction.amount),
                    transactionStatus[transaction.status]?.label ??
                        transaction.status ??
                        "-",
                    transaction.user?.full_name ??
                        transaction.user?.name ??
                        "-",
                ]),
            });
            setExportSuccess("File PDF laporan transaksi berhasil diunduh.");
        } catch (requestError) {
            setExportError(
                requestError?.message || "File laporan gagal diekspor."
            );
        } finally {
            setExporting(false);
        }
    };

    const summary = report?.summary;
    const transactions = report?.transactions ?? [];
    const dailyBreakdown = report?.daily_breakdown ?? [];

    return (
        <main className="mx-auto w-full max-w-7xl space-y-6 pb-10">
            <header className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                <div>
                    <p className="text-sm font-medium text-blue-700">
                        Analitik Operasional
                    </p>
                    <h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900">
                        Laporan Transaksi
                    </h1>
                    <p className="mt-1 text-sm text-slate-600">
                        Pantau transaksi dan ringkasan aktivitas berdasarkan
                        periode.
                    </p>
                </div>
                {report && (
                    <div className="text-sm text-slate-500">
                        Periode laporan
                        <p className="mt-0.5 font-semibold text-slate-800">
                            {formatDate(report.from)} – {formatDate(report.to)}
                        </p>
                    </div>
                )}
            </header>

            <form
                className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
                onSubmit={loadReport}
            >
                <div className="grid gap-4 sm:grid-cols-[minmax(160px,0.7fr)_minmax(220px,1fr)_auto] sm:items-end">
                    <div>
                        <label
                            className="mb-1.5 block text-sm font-medium text-slate-700"
                            htmlFor="report-period"
                        >
                            Periode
                        </label>
                        <select
                            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                            id="report-period"
                            onChange={(event) => setPeriod(event.target.value)}
                            value={period}
                        >
                            <option value="daily">Harian</option>
                            <option value="monthly">Bulanan</option>
                        </select>
                    </div>
                    <div>
                        <label
                            className="mb-1.5 block text-sm font-medium text-slate-700"
                            htmlFor="report-date"
                        >
                            {period === "daily" ? "Tanggal laporan" : "Bulan laporan"}
                        </label>
                        <div className="relative">
                            <CalendarDays
                                aria-hidden="true"
                                className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"
                                size={17}
                            />
                            <input
                                className="w-full rounded-lg border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                                id="report-date"
                                onChange={(event) =>
                                    period === "daily"
                                        ? setDate(event.target.value)
                                        : setMonth(event.target.value)
                                }
                                type={period === "daily" ? "date" : "month"}
                                value={period === "daily" ? date : month}
                            />
                        </div>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <button
                            className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
                            disabled={loading}
                            type="submit"
                        >
                            <RefreshCw
                                className={loading ? "animate-spin" : ""}
                                size={16}
                            />
                            {loading ? "Memuat..." : "Tampilkan"}
                        </button>
                        <button
                            className="inline-flex items-center justify-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50"
                            disabled={loading || exporting || !report}
                            onClick={exportReport}
                            type="button"
                        >
                            {exporting ? (
                                <RefreshCw
                                    className="animate-spin"
                                    size={16}
                                />
                            ) : (
                                <Download size={16} />
                            )}
                            {exporting ? "Mengekspor..." : "Export Excel"}
                        </button>
                        <button
                            className="inline-flex items-center justify-center gap-2 rounded-lg border border-rose-300 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-800 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50"
                            disabled={loading || exporting || !report}
                            onClick={exportReportPdf}
                            type="button"
                        >
                            {exporting ? (
                                <RefreshCw className="animate-spin" size={16} />
                            ) : (
                                <FileText size={16} />
                            )}
                            {exporting ? "Mengekspor..." : "Export PDF"}
                        </button>
                    </div>
                </div>
            </form>

            {error && (
                <div
                    className="flex items-start gap-3 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800"
                    role="alert"
                >
                    <AlertCircle className="mt-0.5 shrink-0" size={18} />
                    <div className="flex-1">
                        <p className="font-semibold">
                            Laporan transaksi gagal dimuat
                        </p>
                        <p className="mt-1">{error}</p>
                        <button
                            className="mt-2 font-semibold underline"
                            onClick={() => void loadReport()}
                            type="button"
                        >
                            Coba lagi
                        </button>
                    </div>
                </div>
            )}
            {exportError && (
                <div
                    className="flex items-start gap-3 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800"
                    role="alert"
                >
                    <AlertCircle className="mt-0.5 shrink-0" size={18} />
                    <div>
                        <p className="font-semibold">Export Excel gagal</p>
                        <p className="mt-1">{exportError}</p>
                    </div>
                </div>
            )}
            {exportSuccess && (
                <div
                    className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800"
                    role="status"
                >
                    {exportSuccess}
                </div>
            )}

            {loading && !report ? (
                <div className="flex items-center justify-center gap-3 rounded-xl border border-slate-200 bg-white p-12 text-sm text-slate-500">
                    <RefreshCw className="animate-spin" size={18} />
                    Memuat laporan transaksi...
                </div>
            ) : summary ? (
                <>
                    <section
                        aria-label="Ringkasan transaksi"
                        className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"
                    >
                        <SummaryCard
                            label="Transaksi berhasil"
                            value={summary.total_transactions}
                            detail="Transaksi selesai"
                            tone="blue"
                            icon={<CheckCircle2 size={18} />}
                        />
                        <SummaryCard
                            label="Total setoran berhasil"
                            value={formatRupiah(summary.total_deposit)}
                            detail="Dana masuk"
                            tone="emerald"
                            icon={<ArrowDownLeft size={18} />}
                        />
                        <SummaryCard
                            label="Total penarikan berhasil"
                            value={formatRupiah(summary.total_withdrawal)}
                            detail="Dana keluar"
                            tone="indigo"
                            icon={<ArrowUpRight size={18} />}
                        />
                        <SummaryCard
                            label="Pending"
                            value={summary.pending_transactions}
                            detail={formatRupiah(summary.pending_total)}
                            tone="amber"
                            icon={<Clock3 size={18} />}
                        />
                        <SummaryCard
                            label="Gagal / ditolak"
                            value={summary.failed_transactions}
                            detail={formatRupiah(summary.failed_total)}
                            tone="rose"
                            icon={<XCircle size={18} />}
                        />
                    </section>

                    {dailyBreakdown.length > 0 && (
                        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div className="border-b border-slate-100 px-4 py-4 sm:px-5">
                                <h2 className="font-semibold text-slate-900">
                                    Ringkasan Per Hari
                                </h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    Rincian aktivitas harian dalam periode
                                    bulanan.
                                </p>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[720px] text-left text-sm">
                                    <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th className="px-4 py-3 font-semibold sm:px-5">
                                                Tanggal
                                            </th>
                                            <th className="px-4 py-3 text-right font-semibold">
                                                Berhasil
                                            </th>
                                            <th className="px-4 py-3 text-right font-semibold">
                                                Setoran
                                            </th>
                                            <th className="px-4 py-3 text-right font-semibold">
                                                Penarikan
                                            </th>
                                            <th className="px-4 py-3 text-right font-semibold">
                                                Pending
                                            </th>
                                            <th className="px-4 py-3 text-right font-semibold sm:px-5">
                                                Gagal
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {dailyBreakdown.map((day) => (
                                            <tr
                                                className="hover:bg-slate-50/70"
                                                key={day.date}
                                            >
                                                <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-800 sm:px-5">
                                                    {formatDate(day.date)}
                                                </td>
                                                <td className="px-4 py-3 text-right text-slate-700">
                                                    {day.total_transactions}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-right text-emerald-700">
                                                    {formatRupiah(
                                                        day.total_deposit
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-right text-indigo-700">
                                                    {formatRupiah(
                                                        day.total_withdrawal
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right text-amber-700">
                                                    {day.pending_transactions}
                                                </td>
                                                <td className="px-4 py-3 text-right text-rose-700 sm:px-5">
                                                    {day.failed_transactions}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}

                    <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div className="flex flex-col justify-between gap-1 border-b border-slate-100 px-4 py-4 sm:flex-row sm:items-center sm:px-5">
                            <div>
                                <h2 className="font-semibold text-slate-900">
                                    Detail Transaksi
                                </h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    {transactions.length} transaksi pada periode
                                    ini
                                </p>
                            </div>
                            <FileSpreadsheet
                                aria-hidden="true"
                                className="hidden text-slate-400 sm:block"
                                size={20}
                            />
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[760px] text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3 font-semibold sm:px-5">
                                            Tanggal &amp; Waktu
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Nasabah
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Nomor Rekening
                                        </th>
                                        <th className="px-4 py-3 font-semibold">
                                            Jenis
                                        </th>
                                        <th className="px-4 py-3 text-right font-semibold">
                                            Nominal
                                        </th>
                                        <th className="px-4 py-3 text-center font-semibold sm:px-5">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {transactions.map((transaction) => (
                                        <tr
                                            className="hover:bg-slate-50/70"
                                            key={transaction.id}
                                        >
                                            <td className="whitespace-nowrap px-4 py-3 text-slate-600 sm:px-5">
                                                {formatDateTime(
                                                    transaction.transaction_date
                                                )}
                                            </td>
                                            <td className="px-4 py-3 font-medium text-slate-800">
                                                {transaction.customer
                                                    ?.student_name || "-"}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-600">
                                                {transaction.customer
                                                    ?.account_number || "-"}
                                            </td>
                                            <td className="px-4 py-3 text-slate-700">
                                                {transactionTypeLabel(
                                                    transaction.transaction_type
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-800">
                                                {formatRupiah(
                                                    transaction.amount
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-center sm:px-5">
                                                <StatusBadge
                                                    status={transaction.status}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                    {transactions.length === 0 && (
                                        <tr>
                                            <td
                                                className="px-4 py-12 text-center sm:px-5"
                                                colSpan={6}
                                            >
                                                <div className="mx-auto flex max-w-sm flex-col items-center text-slate-500">
                                                    <FileSpreadsheet
                                                        className="mb-3 text-slate-300"
                                                        size={28}
                                                    />
                                                    <p className="font-medium text-slate-700">
                                                        Tidak ada transaksi
                                                    </p>
                                                    <p className="mt-1 text-sm">
                                                        Tidak ditemukan
                                                        transaksi pada periode
                                                        yang dipilih.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </>
            ) : !error ? (
                <div className="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">
                    Pilih periode dan tanggal laporan untuk menampilkan data.
                </div>
            ) : null}
        </main>
    );
}

function SummaryCard({ label, value, detail, tone, icon }) {
    const toneClasses = {
        blue: "bg-blue-50 text-blue-700",
        emerald: "bg-emerald-50 text-emerald-700",
        indigo: "bg-indigo-50 text-indigo-700",
        amber: "bg-amber-50 text-amber-700",
        rose: "bg-rose-50 text-rose-700",
    };

    return (
        <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-medium text-slate-600">{label}</p>
                <span
                    className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${toneClasses[tone]}`}
                >
                    {icon}
                </span>
            </div>
            <p className="mt-3 break-words text-xl font-bold tracking-tight text-slate-900">
                {value}
            </p>
            <p className="mt-1 text-xs text-slate-500">{detail}</p>
        </article>
    );
}

function StatusBadge({ status }) {
    const config = transactionStatus[status] ?? {
        label: status || "Tidak diketahui",
        className: "bg-slate-100 text-slate-700",
    };

    return (
        <span
            className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ${config.className}`}
        >
            {config.label}
        </span>
    );
}

export default TransactionReports;

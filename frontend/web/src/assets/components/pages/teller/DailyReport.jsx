import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";

import {
    AlertCircle,
    CheckCircle2,
    Clock,
    FileText,
    FileSpreadsheet,
    LoaderCircle,
    RefreshCw,
    Send,
    Wallet,
} from "lucide-react";

import { api } from "../../../../config/api";
import { downloadReportPdf } from "../../../../utils/reportPdf";

const formatRupiah = (value) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value) || 0);

const formatTanggal = (date) => {
    if (!date) return "-";

    return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "long",
        timeZone: "Asia/Jakarta",
    }).format(new Date(`${date}T00:00:00+07:00`));
};

const formatWaktu = (date) => {
    if (!date) return "-";

    return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "short",
        timeStyle: "short",
        timeZone: "Asia/Jakarta",
    }).format(new Date(date));
};

const transactionStatusLabel = (status) => {
    const labels = {
        approved: "Berhasil",
        pending: "Pending",
        rejected: "Ditolak",
        failed: "Gagal",
        cancelled: "Dibatalkan",
    };

    return labels[status] ?? status ?? "-";
};

const statusConfig = {
    draft: {
        label: "Draft",
        className: "bg-gray-100 text-gray-700",
    },
    submitted: {
        label: "Menunggu Persetujuan",
        className: "bg-amber-100 text-amber-800",
    },
    approved: {
        label: "Disetujui",
        className: "bg-green-100 text-green-700",
    },
    rejected: {
        label: "Ditolak",
        className: "bg-red-100 text-red-700",
    },
};

const getErrorMessage = (error) => {
    const validationErrors = error.response?.data?.errors;

    if (validationErrors) {
        return (
            Object.values(validationErrors).flat()[0] ??
            "Data yang dikirim tidak valid."
        );
    }

    return (
        error.response?.data?.message ??
        "Terjadi kesalahan saat mengambil data."
    );
};

export default function DailyReport() {
    const [date, setDate] = useState(() => {
        const now = new Date();

        return new Intl.DateTimeFormat("en-CA", {
            timeZone: "Asia/Jakarta",
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
        }).format(now);
    });

    const [summary, setSummary] = useState(null);
    const [reports, setReports] = useState([]);

    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const [exporting, setExporting] = useState("");
    const [exportError, setExportError] = useState("");
    const [exportSuccess, setExportSuccess] = useState("");

    const fetchReportData = useCallback(async () => {
        setLoading(true);
        setError("");

        try {
            const [summaryResponse, reportsResponse] = await Promise.all([
                api.get("/teller/daily-report", {
                    params: { date },
                }),
                api.get("/teller/daily-reports"),
            ]);

            setSummary(summaryResponse.data?.data ?? null);
            setReports(reportsResponse.data?.data ?? []);
        } catch (requestError) {
            setError(getErrorMessage(requestError));
        } finally {
            setLoading(false);
        }
    }, [date]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            void fetchReportData();
        }, 0);

        return () => window.clearTimeout(timer);
    }, [fetchReportData]);

    const existingReport = reports.find(
        (report) => report.report_date === date
    );

    const canSubmit =
        !loading &&
        !submitting &&
        summary !== null &&
        !existingReport;

    const handleSubmit = async () => {
        if (!canSubmit) return;

        const confirmed = window.confirm(
            `Kirim laporan harian untuk tanggal ${formatTanggal(date)}?\n\n` +
            `Total setoran: ${formatRupiah(summary.total_deposit)}\n` +
            `Total penarikan: ${formatRupiah(summary.total_withdrawal)}\n` +
            `Jumlah transaksi: ${summary.total_transactions}`
        );

        if (!confirmed) return;

        setSubmitting(true);
        setError("");
        setSuccess("");

        try {
            const response = await api.post("/teller/submit-daily-report", {
                date,
            });

            setSuccess(
                response.data?.message ??
                "Laporan harian berhasil dikirim."
            );

            await fetchReportData();
        } catch (requestError) {
            setError(getErrorMessage(requestError));

            // Jika backend telah menyimpan laporan tetapi respons jaringan
            // gagal diterima, muat ulang data sebelum mencoba lagi.
            await fetchReportData();
        } finally {
            setSubmitting(false);
        }
    };

    const exportExcel = async () => {
        setExporting("excel");
        setExportError("");
        setExportSuccess("");
        try {
            const response = await api.get("/teller/daily-report/export", {
                params: { date },
                responseType: "blob",
            });
            const url = URL.createObjectURL(response.data);
            const link = document.createElement("a");
            link.href = url;
            link.download = `laporan-harian-${date}.xlsx`;
            link.click();
            URL.revokeObjectURL(url);
            setExportSuccess("File Excel laporan harian berhasil diunduh.");
        } catch (requestError) {
            setExportError(getErrorMessage(requestError));
        } finally {
            setExporting("");
        }
    };

    const exportPdf = () => {
        setExporting("pdf");
        setExportError("");
        setExportSuccess("");
        try {
            downloadReportPdf({
                title: "Laporan Harian Teller",
                subtitle: `Tanggal operasional: ${date}`,
                filename: `laporan-harian-${date}.pdf`,
                summary: [
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
                ],
                columns: [
                    "No.",
                    "Tanggal dan waktu",
                    "Referensi",
                    "No. rekening",
                    "Nama nasabah",
                    "Jenis",
                    "Nominal (Rp)",
                    "Status",
                ],
                rows: (summary?.transactions ?? []).map(
                    (transaction, index) => [
                        index + 1,
                        formatWaktu(transaction.transaction_date),
                        transaction.id ?? "-",
                        transaction.customer?.account_number ?? "-",
                        transaction.customer?.student_name ?? "-",
                        transaction.transaction_type === "deposit"
                            ? "Setoran"
                            : transaction.transaction_type === "withdrawal"
                              ? "Penarikan"
                              : transaction.transaction_type ?? "-",
                        formatRupiah(transaction.amount),
                        transactionStatusLabel(transaction.status),
                    ]
                ),
            });
            setExportSuccess("File PDF laporan harian berhasil diunduh.");
        } catch (requestError) {
            setExportError(
                requestError?.message || "File laporan gagal diekspor."
            );
        } finally {
            setExporting("");
        }
    };

    return (
        <div className="mx-auto max-w-7xl space-y-6">
            {/* Header */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">
                        Laporan Harian Teller
                    </h1>

                    <p className="mt-1 text-sm text-gray-500">
                        Periksa rekap transaksi dan kirim laporan harian.
                    </p>
                </div>

                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={exportExcel}
                        disabled={loading || submitting || Boolean(exporting) || !summary}
                        className="inline-flex items-center justify-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-800 hover:bg-emerald-100 disabled:opacity-50"
                    >
                        {exporting === "excel" ? <LoaderCircle size={17} className="animate-spin" /> : <FileSpreadsheet size={17} />}
                        {exporting === "excel" ? "Mengekspor..." : "Export Excel"}
                    </button>
                    <button
                        type="button"
                        onClick={exportPdf}
                        disabled={loading || submitting || Boolean(exporting) || !summary}
                        className="inline-flex items-center justify-center gap-2 rounded-lg border border-rose-300 bg-rose-50 px-4 py-2.5 text-sm font-medium text-rose-800 hover:bg-rose-100 disabled:opacity-50"
                    >
                        {exporting === "pdf" ? <LoaderCircle size={17} className="animate-spin" /> : <FileText size={17} />}
                        {exporting === "pdf" ? "Mengekspor..." : "Export PDF"}
                    </button>
                    <button
                        type="button"
                        onClick={fetchReportData}
                        disabled={loading || submitting || Boolean(exporting)}
                        className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                    >
                        <RefreshCw
                            size={17}
                            className={loading ? "animate-spin" : ""}
                        />
                        Muat Ulang
                    </button>
                </div>
            </div>

            {exportError && (
                <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <p className="font-semibold">Ekspor laporan gagal</p>
                    <p className="mt-1">{exportError}</p>
                </div>
            )}
            {exportSuccess && (
                <div role="status" className="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700">
                    {exportSuccess}
                </div>
            )}
            {/* Error */}
            {error && (
                <div
                    role="alert"
                    className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"
                >
                    <AlertCircle size={20} className="mt-0.5 shrink-0" />
                    <p>{error}</p>
                </div>
            )}

            {/* Success */}
            {success && (
                <div
                    role="status"
                    className="flex items-start gap-3 rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-700"
                >
                    <CheckCircle2 size={20} className="mt-0.5 shrink-0" />
                    <div>
                        <p className="font-semibold">{success}</p>
                        <p className="mt-1">
                            Periksa status laporan pada bagian riwayat di bawah.
                        </p>
                    </div>
                </div>
            )}

            {/* Pemilihan tanggal */}
            <section className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <label
                    htmlFor="report-date"
                    className="mb-2 block text-sm font-medium text-gray-700"
                >
                    Tanggal laporan
                </label>

                <input
                    id="report-date"
                    type="date"
                    value={date}
                    max={new Intl.DateTimeFormat("en-CA", {
                        timeZone: "Asia/Jakarta",
                        year: "numeric",
                        month: "2-digit",
                        day: "2-digit",
                    }).format(new Date())}
                    disabled={loading || submitting}
                    onChange={(event) => {
                        setDate(event.target.value);
                        setSuccess("");
                    }}
                    className="w-full max-w-sm rounded-lg border border-gray-300 px-4 py-3 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
                />

                <p className="mt-2 text-sm text-gray-500">
                    Tanggal yang dipilih digunakan untuk mengambil rekap
                    transaksi Teller.
                </p>
            </section>

            {/* Loading */}
            {loading && (
                <div className="flex items-center justify-center gap-3 rounded-xl border border-gray-200 bg-white p-10 text-gray-500">
                    <LoaderCircle size={21} className="animate-spin" />
                    Memuat laporan harian...
                </div>
            )}

            {/* Ringkasan dan transaksi */}
            {!loading && summary && (
                <>
                    <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <SummaryCard
                            title="Jumlah Transaksi"
                            value={summary.total_transactions ?? 0}
                            description="Transaksi Teller pada tanggal terpilih"
                            icon={<FileText size={22} />}
                        />

                        <SummaryCard
                            title="Total Setoran"
                            value={formatRupiah(summary.total_deposit)}
                            description="Akumulasi transaksi setoran"
                            icon={<Wallet size={22} />}
                        />

                        <SummaryCard
                            title="Total Penarikan"
                            value={formatRupiah(summary.total_withdrawal)}
                            description="Akumulasi transaksi penarikan"
                            icon={<Wallet size={22} />}
                        />
                    </section>

                    {/* Status laporan tanggal terpilih */}
                    <section className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="font-semibold text-gray-900">
                                    Status Laporan
                                </h2>

                                <p className="mt-1 text-sm text-gray-500">
                                    {formatTanggal(date)}
                                </p>
                            </div>

                            {existingReport ? (
                                <StatusBadge status={existingReport.status} />
                            ) : (
                                <span className="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700">
                                    Belum Dikirim
                                </span>
                            )}
                        </div>

                        {existingReport?.status === "rejected" && (
                            <div className="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                                <p className="font-semibold">
                                    Laporan sebelumnya ditolak.
                                </p>

                                {existingReport.rejection_reason && (
                                    <p className="mt-1">
                                        Alasan: {existingReport.rejection_reason}
                                    </p>
                                )}

                                <p className="mt-1">
                                    Kamu dapat mengirim ulang laporan setelah
                                    memastikan datanya benar.
                                </p>
                            </div>
                        )}

                        <div className="mt-5 rounded-lg bg-blue-50 p-4 text-sm text-blue-800">
                            Halaman ini menampilkan rekap transaksi dari
                            backend. Pemeriksaan kesesuaian uang fisik di laci
                            Teller belum diterapkan pada backend saat ini.
                        </div>

                        <button
                            type="button"
                            onClick={handleSubmit}
                            disabled={!canSubmit}
                            className="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-3 font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                        >
                            {submitting ? (
                                <>
                                    <LoaderCircle
                                        size={19}
                                        className="animate-spin"
                                    />
                                    Mengirim Laporan...
                                </>
                            ) : (
                                <>
                                    <Send size={19} />
                                    {existingReport?.status === "rejected"
                                        ? "Ajukan Ulang Laporan"
                                        : "Kirim Laporan Harian"}
                                </>
                            )}
                        </button>

                        {existingReport &&
                            existingReport.status !== "rejected" && (
                                <p className="mt-3 text-sm text-gray-500">
                                    Laporan untuk tanggal ini sudah dibuat.
                                    Statusnya tidak dapat diubah dari halaman
                                    Teller.
                                </p>
                            )}
                    </section>

                    {/* Tabel transaksi */}
                    <section className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <div className="border-b border-gray-200 p-5">
                            <h2 className="font-semibold text-gray-900">
                                Rincian Transaksi
                            </h2>

                            <p className="mt-1 text-sm text-gray-500">
                                {formatTanggal(date)}
                            </p>
                        </div>

                        {(summary.transactions ?? []).length === 0 ? (
                            <div className="p-8 text-center">
                                <FileText
                                    size={35}
                                    className="mx-auto text-gray-300"
                                />

                                <p className="mt-3 font-medium text-gray-700">
                                    Belum ada transaksi
                                </p>

                                <p className="mt-1 text-sm text-gray-500">
                                    Tidak ada transaksi Teller pada tanggal ini.
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[760px] text-left text-sm">
                                    <thead className="bg-gray-50 text-gray-600">
                                        <tr>
                                            <th className="px-5 py-3 font-medium">
                                                Waktu
                                            </th>
                                            <th className="px-5 py-3 font-medium">
                                                Nasabah
                                            </th>
                                            <th className="px-5 py-3 font-medium">
                                                Nomor Rekening
                                            </th>
                                            <th className="px-5 py-3 font-medium">
                                                Jenis Transaksi
                                            </th>
                                            <th className="px-5 py-3 text-right font-medium">
                                                Nominal
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody className="divide-y divide-gray-100">
                                        {summary.transactions.map(
                                            (transaction) => (
                                                <tr key={transaction.id}>
                                                    <td className="whitespace-nowrap px-5 py-4 text-gray-600">
                                                        {formatWaktu(
                                                            transaction.transaction_date
                                                        )}
                                                    </td>

                                                    <td className="px-5 py-4 font-medium text-gray-900">
                                                        {transaction.customer
                                                            ?.student_name ??
                                                            "-"}
                                                    </td>

                                                    <td className="px-5 py-4 text-gray-600">
                                                        {transaction.customer
                                                            ?.account_number ??
                                                            "-"}
                                                    </td>

                                                    <td className="px-5 py-4">
                                                        <span
                                                            className={`rounded-full px-2.5 py-1 text-xs font-semibold ${transaction.transaction_type ===
                                                                    "deposit"
                                                                    ? "bg-green-100 text-green-700"
                                                                    : "bg-orange-100 text-orange-700"
                                                                }`}
                                                        >
                                                            {transaction.transaction_type ===
                                                                "deposit"
                                                                ? "Setoran"
                                                                : "Penarikan"}
                                                        </span>
                                                    </td>

                                                    <td className="whitespace-nowrap px-5 py-4 text-right font-medium text-gray-900">
                                                        {formatRupiah(
                                                            transaction.amount
                                                        )}
                                                    </td>
                                                </tr>
                                            )
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </>
            )}

            {/* Riwayat laporan */}
            {!loading && (
                <section className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div className="border-b border-gray-200 p-5">
                        <div className="flex items-center gap-2">
                            <Clock size={20} className="text-gray-500" />
                            <h2 className="font-semibold text-gray-900">
                                Riwayat Laporan Harian
                            </h2>
                        </div>
                    </div>

                    {reports.length === 0 ? (
                        <p className="p-6 text-sm text-gray-500">
                            Belum ada laporan yang tersimpan.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[600px] text-left text-sm">
                                <thead className="bg-gray-50 text-gray-600">
                                    <tr>
                                        <th className="px-5 py-3 font-medium">
                                            Tanggal
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Total Setoran
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Total Penarikan
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Status
                                        </th>
                                        <th className="px-5 py-3 font-medium">
                                            Waktu Pengiriman
                                        </th>
                                    </tr>
                                </thead>

                                <tbody className="divide-y divide-gray-100">
                                    {reports.map((report) => (
                                        <tr key={report.id}>
                                            <td className="whitespace-nowrap px-5 py-4 font-medium text-gray-900">
                                                {formatTanggal(
                                                    typeof report.report_date ===
                                                        "string"
                                                        ? report.report_date.slice(
                                                            0,
                                                            10
                                                        )
                                                        : report.report_date
                                                )}
                                            </td>

                                            <td className="whitespace-nowrap px-5 py-4 text-gray-700">
                                                {formatRupiah(
                                                    report.total_deposit
                                                )}
                                            </td>

                                            <td className="whitespace-nowrap px-5 py-4 text-gray-700">
                                                {formatRupiah(
                                                    report.total_withdrawal
                                                )}
                                            </td>

                                            <td className="px-5 py-4">
                                                <StatusBadge
                                                    status={report.status}
                                                />
                                            </td>

                                            <td className="whitespace-nowrap px-5 py-4 text-gray-600">
                                                {formatWaktu(
                                                    report.submitted_at
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            )}

            <div className="pb-4">
                <Link
                    to="/teller/dashboard"
                    className="text-sm font-medium text-blue-600 hover:text-blue-700"
                >
                    Kembali ke Dashboard Teller
                </Link>
            </div>
        </div>
    );
}

function SummaryCard({ title, value, description, icon }) {
    return (
        <article className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div className="flex items-start justify-between">
                <div>
                    <p className="text-sm font-medium text-gray-500">
                        {title}
                    </p>

                    <p className="mt-2 text-xl font-bold text-gray-900">
                        {value}
                    </p>
                </div>

                <div className="rounded-lg bg-blue-50 p-3 text-blue-600">
                    {icon}
                </div>
            </div>

            <p className="mt-3 text-xs text-gray-500">{description}</p>
        </article>
    );
}

function StatusBadge({ status }) {
    const config = statusConfig[status] ?? {
        label: status || "Tidak diketahui",
        className: "bg-gray-100 text-gray-700",
    };

    return (
        <span
            className={`inline-flex rounded-full px-3 py-1.5 text-xs font-semibold ${config.className}`}
        >
            {config.label}
        </span>
    );
}
import { useEffect, useState } from "react";
import {
    CheckCircle,
    XCircle,
    RefreshCw,
    FileText,
    AlertCircle,
    LoaderCircle,
    Eye,
} from "lucide-react";

import api from "../../../../config/api";

function ReviewReport() {
    const [reports, setReports] = useState([]);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState("");
    const [processingId, setProcessingId] = useState(null);
    const [rejectingReport, setRejectingReport] = useState(null);
    const [rejectionReason, setRejectionReason] = useState("");
    const [successMessage, setSuccessMessage] = useState("");

    const [selectedReport, setSelectedReport] = useState(null);
    const [approvingReport, setApprovingReport] = useState(null);
    const [reportDetail, setReportDetail] = useState(null);
    const [isLoadingDetail, setIsLoadingDetail] = useState(false);
    const [refreshVersion, setRefreshVersion] = useState(0);

    useEffect(() => {
        const controller = new AbortController();

        const fetchReports = async () => {
            try {
                const response = await api.get("/supervisor/daily-reports", {
                    signal: controller.signal,
                });
                if (controller.signal.aborted) return;

                const result = response.data?.data;
                setReports(Array.isArray(result) ? result : []);
                setError("");
            } catch (err) {
                if (controller.signal.aborted) return;

                setError(
                    err.response?.data?.message ||
                        "Gagal mengambil daftar laporan. Silakan coba lagi."
                );
            } finally {
                if (!controller.signal.aborted) {
                    setIsLoading(false);
                }
            }
        };

        void fetchReports();

        return () => controller.abort();
    }, [refreshVersion]);

    const handleRefresh = () => {
        setIsLoading(true);
        setError("");
        setRefreshVersion((version) => version + 1);
    };

    const handleViewDetail = async (report) => {
        setSelectedReport(report);
        setReportDetail(null);
        setIsLoadingDetail(true);
        setError("");
    
        try {
            const response = await api.get(
                `/supervisor/daily-reports/${report.id}`
            );
    
            setReportDetail(response.data?.data ?? null);
        } catch (err) {
            setError(
                err.response?.data?.message ||
                    "Gagal mengambil detail laporan."
            );
    
            setSelectedReport(null);
        } finally {
            setIsLoadingDetail(false);
        }
    };

    const handleApprove = async () => {
        if (!approvingReport) return;
    
        const reportId = approvingReport.id;
    
        setProcessingId(reportId);
        setError("");
        setSuccessMessage("");
    
        try {
            const response = await api.post(
                `/supervisor/daily-reports/${reportId}/approve`
            );
    
            setReports((currentReports) =>
                currentReports.filter((item) => item.id !== reportId)
            );
    
            setSuccessMessage(
                response.data?.message || "Laporan berhasil disetujui."
            );
    
            setApprovingReport(null);
        } catch (err) {
            setError(
                err.response?.data?.message ||
                    "Gagal menyetujui laporan. Silakan coba lagi."
            );
        } finally {
            setProcessingId(null);
        }
    };

    const handleReject = async (event) => {
        event.preventDefault();

        if (!rejectingReport) return;

        const reason = rejectionReason.trim();

        if (reason.length < 3) {
            setError("Alasan penolakan minimal 3 karakter.");
            return;
        }

        const reportId = rejectingReport.id;

        setProcessingId(reportId);
        setError("");
        setSuccessMessage("");

        try {
            const response = await api.post(
                `/supervisor/daily-reports/${reportId}/reject`,
                {
                    rejection_reason: reason,
                }
            );

            setReports((currentReports) =>
                currentReports.filter((item) => item.id !== reportId)
            );

            setSuccessMessage(
                response.data?.message || "Laporan berhasil ditolak."
            );

            setRejectingReport(null);
            setRejectionReason("");
        } catch (err) {
            setError(
                err.response?.data?.message ||
                    "Gagal menolak laporan. Silakan coba lagi."
            );
        } finally {
            setProcessingId(null);
        }
    };

    const formatDate = (date) => {
        if (!date) return "-";

        const parsedDate = new Date(`${date}`.slice(0, 10) + "T00:00:00");

        if (Number.isNaN(parsedDate.getTime())) return date;

        return new Intl.DateTimeFormat("id-ID", {
            day: "2-digit",
            month: "long",
            year: "numeric",
        }).format(parsedDate);
    };

    const formatCurrency = (amount) => {
        return new Intl.NumberFormat("id-ID", {
            style: "currency",
            currency: "IDR",
            maximumFractionDigits: 0,
        }).format(Number(amount) || 0);
    };

    return (
        <div className="flex w-full flex-col gap-6 py-6">
            <div className="flex flex-col justify-between gap-4 border-b border-gray-200 pb-5 sm:flex-row sm:items-center">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">
                        Laporan Harian Teller
                    </h1>
                    <p className="mt-1 text-sm text-gray-500">
                        Periksa laporan yang telah dikirim Teller sebelum
                        memberikan persetujuan.
                    </p>
                </div>

                <button
                    type="button"
                    onClick={handleRefresh}
                    disabled={isLoading}
                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <RefreshCw
                        size={16}
                        className={isLoading ? "animate-spin" : ""}
                    />
                    Muat Ulang
                </button>
            </div>

            {successMessage && (
                <div
                    role="status"
                    className="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800"
                >
                    <CheckCircle size={20} className="shrink-0" />
                    <p>{successMessage}</p>
                </div>
            )}

            {error && (
                <div
                    role="alert"
                    className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"
                >
                    <AlertCircle size={20} className="shrink-0" />
                    <p>{error}</p>
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div className="flex items-center gap-3">
                        <div className="rounded-lg bg-blue-50 p-3 text-blue-600">
                            <FileText size={22} />
                        </div>

                        <div>
                            <p className="text-sm text-gray-500">
                                Menunggu Persetujuan
                            </p>
                            <p className="text-2xl font-bold text-gray-900">
                                {reports.length}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div className="flex items-center gap-3">
                        <div className="rounded-lg bg-amber-50 p-3 text-amber-600">
                            <AlertCircle size={22} />
                        </div>

                        <div>
                            <p className="text-sm text-gray-500">
                                Status Halaman
                            </p>
                            <p className="text-base font-semibold text-gray-900">
                                {isLoading ? "Memuat data..." : "Data terbaru"}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div className="border-b border-gray-200 px-5 py-4">
                    <h2 className="font-semibold text-gray-900">
                        Daftar Laporan
                    </h2>
                    <p className="mt-1 text-sm text-gray-500">
                        Laporan yang masih berstatus submitted.
                    </p>
                </div>

                {isLoading ? (
                    <div className="flex flex-col items-center justify-center gap-3 px-5 py-16 text-gray-500">
                        <LoaderCircle
                            size={28}
                            className="animate-spin text-blue-600"
                        />
                        <p className="text-sm">Mengambil data laporan...</p>
                    </div>
                ) : reports.length === 0 ? (
                    <div className="flex flex-col items-center justify-center px-5 py-16 text-center">
                        <FileText
                            size={36}
                            className="mb-3 text-gray-300"
                        />
                        <p className="font-medium text-gray-800">
                            Tidak ada laporan yang menunggu persetujuan
                        </p>
                        <p className="mt-1 text-sm text-gray-500">
                            Laporan yang dikirim Teller akan muncul di sini.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] text-left text-sm">
                            <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="px-5 py-4">No.</th>
                                    <th className="px-5 py-4">Nama Teller</th>
                                    <th className="px-5 py-4">Tanggal</th>
                                    <th className="px-5 py-4">Status</th>
                                    <th className="px-5 py-4 text-right">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-gray-100">
                                {reports.map((report, index) => (
                                    <tr
                                        key={report.id}
                                        className="hover:bg-gray-50"
                                    >
                                        <td className="px-5 py-4 text-gray-500">
                                            {index + 1}
                                        </td>

                                        <td className="px-5 py-4">
                                            <p className="font-medium text-gray-900">
                                                {report.teller?.full_name ||
                                                    report.teller?.name ||
                                                    report.teller?.username ||
                                                    "-"}
                                            </p>
                                            <p className="mt-1 text-xs text-gray-500">
                                                {report.teller?.username
                                                    ? `@${report.teller.username}`
                                                    : ""}
                                            </p>
                                        </td>

                                        <td className="whitespace-nowrap px-5 py-4 text-gray-700">
                                            {formatDate(report.report_date)}
                                        </td>

                                        <td className="px-5 py-4">
                                            <span className="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800">
                                                Menunggu Persetujuan
                                            </span>
                                        </td>

                                        <td className="px-5 py-4">
                                            <div className="flex justify-end gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setRejectingReport(
                                                            report
                                                        )
                                                    }
                                                    disabled={
                                                        processingId !== null
                                                    }
                                                    className="inline-flex items-center gap-1 rounded-lg border border-red-200 px-3 py-2 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                                                >
                                                    <XCircle size={15} />
                                                    Tolak
                                                </button>
                                                <button
                                                    type="button"
                                                            onClick={() => handleViewDetail(report)}
                                                            disabled={processingId !== null}
                                                             className="inline-flex items-center gap-1 rounded-lg border border-blue-200 px-3 py-2 text-xs font-medium text-blue-700 hover:bg-blue-50 disabled:opacity-50"
                                                >
                                                <Eye size={15} />
                                                            Detail
                                                </button>

                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setApprovingReport(report)
                                                    }
                                                    disabled={
                                                        processingId !== null
                                                    }
                                                    className="inline-flex items-center gap-1 rounded-lg bg-green-600 px-3 py-2 text-xs font-medium text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    {processingId ===
                                                    report.id ? (
                                                        <LoaderCircle
                                                            size={15}
                                                            className="animate-spin"
                                                        />
                                                    ) : (
                                                        <CheckCircle
                                                            size={15}
                                                        />
                                                    )}
                                                    Setujui
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {rejectingReport && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="reject-report-title"
                        className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl"
                    >
                        <h2
                            id="reject-report-title"
                            className="text-lg font-bold text-gray-900"
                        >
                            Tolak Laporan
                        </h2>

                        <p className="mt-2 text-sm text-gray-600">
                            Masukkan alasan penolakan agar Teller mengetahui
                            hal yang perlu diperbaiki.
                        </p>

                        <form
                            onSubmit={handleReject}
                            className="mt-5 space-y-4"
                        >
                            <label
                                htmlFor="rejection-reason"
                                className="block text-sm font-medium text-gray-700"
                            >
                                Alasan Penolakan
                            </label>

                            <textarea
                                id="rejection-reason"
                                value={rejectionReason}
                                onChange={(event) =>
                                    setRejectionReason(event.target.value)
                                }
                                minLength={3}
                                required
                                rows={4}
                                placeholder="Contoh: Mohon periksa kembali kesesuaian saldo."
                                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                            />

                            <div className="flex justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setRejectingReport(null);
                                        setRejectionReason("");
                                        setError("");
                                    }}
                                    disabled={processingId !== null}
                                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                >
                                    Batal
                                </button>

                                <button
                                    type="submit"
                                    disabled={processingId !== null}
                                    className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {processingId === rejectingReport.id
                                        ? "Memproses..."
                                        : "Konfirmasi Penolakan"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
            {selectedReport && (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="detail-report-title"
            className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl"
        >
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h2
                        id="detail-report-title"
                        className="text-xl font-bold text-gray-900"
                    >
                        Detail Laporan Harian
                    </h2>
                    <p className="mt-1 text-sm text-gray-500">
                        Informasi laporan yang dikirim oleh Teller.
                    </p>
                </div>

                <button
                    type="button"
                    onClick={() => {
                        setSelectedReport(null);
                        setReportDetail(null);
                    }}
                    className="rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
                >
                    Tutup
                </button>
            </div>

            {isLoadingDetail ? (
                <div className="flex flex-col items-center gap-3 py-12 text-gray-500">
                    <LoaderCircle
                        size={28}
                        className="animate-spin text-blue-600"
                    />
                    <p className="text-sm">Memuat detail laporan...</p>
                </div>
            ) : reportDetail ? (
                <div className="mt-6 space-y-6">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="rounded-lg border border-gray-200 p-4">
                            <p className="text-sm text-gray-500">
                                Nama Teller
                            </p>
                            <p className="mt-1 font-semibold text-gray-900">
                                {reportDetail.report?.teller?.full_name ||
                                    reportDetail.report?.teller?.name ||
                                    "-"}
                            </p>
                            <p className="mt-1 text-sm text-gray-500">
                                {reportDetail.report?.teller?.username
                                    ? `@${reportDetail.report.teller.username}`
                                    : ""}
                            </p>
                        </div>

                        <div className="rounded-lg border border-gray-200 p-4">
                            <p className="text-sm text-gray-500">
                                Tanggal Laporan
                            </p>
                            <p className="mt-1 font-semibold text-gray-900">
                                {formatDate(
                                    reportDetail.report?.report_date
                                )}
                            </p>
                        </div>

                        <div className="rounded-lg border border-gray-200 p-4">
                            <p className="text-sm text-gray-500">
                                Total Setoran Laporan
                            </p>
                            <p className="mt-1 font-semibold text-green-700">
                                {formatCurrency(
                                    reportDetail.report?.total_deposit
                                )}
                            </p>
                        </div>

                        <div className="rounded-lg border border-gray-200 p-4">
                            <p className="text-sm text-gray-500">
                                Total Penarikan Laporan
                            </p>
                            <p className="mt-1 font-semibold text-red-700">
                                {formatCurrency(
                                    reportDetail.report?.total_withdrawal
                                )}
                            </p>
                        </div>
                    </div>

                    {reportDetail.report?.submitted_at && (
                        <div>
                            <p className="text-sm text-gray-500">
                                Waktu Pengiriman
                            </p>
                            <p className="mt-1 text-sm font-medium text-gray-900">
                                {new Date(
                                    reportDetail.report.submitted_at
                                ).toLocaleString("id-ID")}
                            </p>
                        </div>
                    )}

                    <div>
                        <h3 className="font-semibold text-gray-900">
                            Daftar Transaksi
                        </h3>

                        {reportDetail.transactions?.length > 0 ? (
                            <div className="mt-3 overflow-x-auto rounded-lg border border-gray-200">
                                <table className="w-full min-w-[760px] text-left text-sm">
                                    <thead className="bg-gray-50 text-xs uppercase text-gray-500">
                                        <tr>
                                            <th className="px-4 py-3">
                                                Waktu
                                            </th>
                                            <th className="px-4 py-3">
                                                Nasabah
                                            </th>
                                            <th className="px-4 py-3">
                                                Jenis
                                            </th>
                                            <th className="px-4 py-3">
                                                Status
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                Nominal
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody className="divide-y divide-gray-100">
                                        {reportDetail.transactions.map(
                                            (transaction) => (
                                                <tr key={transaction.id}>
                                                    <td className="whitespace-nowrap px-4 py-3">
                                                        {new Date(
                                                            transaction.transaction_date
                                                        ).toLocaleTimeString(
                                                            "id-ID",
                                                            {
                                                                hour: "2-digit",
                                                                minute: "2-digit",
                                                            }
                                                        )}
                                                    </td>

                                                    <td className="px-4 py-3">
                                                        {transaction.customer
                                                            ?.student_name ||
                                                            "-"}
                                                    </td>

                                                    <td className="px-4 py-3">
                                                        {transaction.transaction_type ===
                                                        "deposit"
                                                            ? "Setoran"
                                                            : transaction.transaction_type ===
                                                                "withdrawal"
                                                              ? "Penarikan"
                                                              : transaction.transaction_type}
                                                    </td>

                                                    <td className="px-4 py-3">
                                                        {transaction.status ===
                                                        "approved"
                                                            ? "Berhasil"
                                                            : transaction.status ===
                                                                "pending"
                                                              ? "Pending"
                                                              : transaction.status ===
                                                                  "rejected"
                                                                ? "Ditolak"
                                                                : transaction.status ===
                                                                    "failed"
                                                                  ? "Gagal"
                                                                  : transaction.status ===
                                                                      "cancelled"
                                                                    ? "Dibatalkan"
                                                                  : transaction.status ||
                                                                    "-"}
                                                    </td>

                                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                                        {formatCurrency(
                                                            transaction.amount
                                                        )}
                                                    </td>
                                                </tr>
                                            )
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                                Belum ada transaksi yang ditemukan untuk
                                teller dan tanggal laporan ini.
                            </div>
                        )}
                    </div>

                    <div className="rounded-lg bg-gray-50 p-4">
                        <h3 className="font-semibold text-gray-900">
                            Rekap Transaksi yang Ditemukan
                        </h3>

                        <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <div>
                                <p className="text-xs text-gray-500">
                                    Transaksi berhasil
                                </p>
                                <p className="mt-1 font-semibold text-gray-900">
                                    {reportDetail.total_transactions ?? 0}
                                </p>
                            </div>

                            <div>
                                <p className="text-xs text-gray-500">
                                    Transaksi pending
                                </p>
                                <p className="mt-1 font-semibold text-amber-700">
                                    {reportDetail.pending_transactions ?? 0}
                                </p>
                            </div>

                            <div>
                                <p className="text-xs text-gray-500">
                                    Transaksi gagal
                                </p>
                                <p className="mt-1 font-semibold text-rose-700">
                                    {reportDetail.failed_transactions ?? 0}
                                </p>
                            </div>

                            <div>
                                <p className="text-xs text-gray-500">
                                    Setoran berhasil
                                </p>
                                <p className="mt-1 font-semibold text-green-700">
                                    {formatCurrency(
                                        reportDetail.total_deposit
                                    )}
                                </p>
                            </div>

                            <div>
                                <p className="text-xs text-gray-500">
                                    Penarikan berhasil
                                </p>
                                <p className="mt-1 font-semibold text-red-700">
                                    {formatCurrency(
                                        reportDetail.total_withdrawal
                                    )}
                                </p>
                            </div>
                        </div>

                        <p className="mt-3 text-xs text-gray-500">
                            Rekap transaksi tersimpan sebagai snapshot pada saat
                            laporan dikirim agar laporan historis tidak berubah.
                        </p>
                    </div>
                </div>
            ) : (
                <p className="py-8 text-center text-sm text-gray-500">
                    Detail laporan tidak tersedia.
                </p>
            )}
        </div>
    </div>
    )}
    {approvingReport && (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="approve-report-title"
            className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl"
        >
            <div className="flex items-center gap-3">
                <div className="rounded-full bg-green-100 p-3 text-green-700">
                    <CheckCircle size={24} />
                </div>

                <h2
                    id="approve-report-title"
                    className="text-lg font-bold text-gray-900"
                >
                    Setujui Laporan?
                </h2>
            </div>

            <p className="mt-4 text-sm leading-6 text-gray-600">
                Apakah kamu yakin ingin menyetujui laporan tanggal{" "}
                <strong>
                    {formatDate(approvingReport.report_date)}
                </strong>{" "}
                dari{" "}
                <strong>
                    {approvingReport.teller?.full_name ||
                        approvingReport.teller?.name ||
                        "Teller"}
                </strong>
                ?
            </p>

            <div className="mt-4 rounded-lg bg-gray-50 p-4">
                <div className="flex justify-between gap-3 text-sm">
                    <span className="text-gray-500">Total Setoran</span>
                    <span className="font-semibold text-green-700">
                        {formatCurrency(approvingReport.total_deposit)}
                    </span>
                </div>

                <div className="mt-2 flex justify-between gap-3 text-sm">
                    <span className="text-gray-500">Total Penarikan</span>
                    <span className="font-semibold text-red-700">
                        {formatCurrency(approvingReport.total_withdrawal)}
                    </span>
                </div>
            </div>

            <div className="mt-6 flex justify-end gap-3">
                <button
                    type="button"
                    onClick={() => setApprovingReport(null)}
                    disabled={processingId !== null}
                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                >
                    Batal
                </button>

                <button
                    type="button"
                    onClick={handleApprove}
                    disabled={processingId !== null}
                    className="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {processingId === approvingReport.id ? (
                        <LoaderCircle
                            size={16}
                            className="animate-spin"
                        />
                    ) : (
                        <CheckCircle size={16} />
                    )}

                    {processingId === approvingReport.id
                        ? "Memproses..."
                        : "Ya, Setujui"}
                </button>
            </div>
        </div>
    </div>
)}
        </div>
    );
}

export default ReviewReport;
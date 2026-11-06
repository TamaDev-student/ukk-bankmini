import { useCallback, useEffect, useMemo, useState } from "react";
import {
    AlertCircle,
    Check,
    CheckCircle2,
    Clock3,
    FileSpreadsheet,
    FileText,
    RefreshCw,
    Save,
    Send,
} from "lucide-react";

import { api } from "../../../../config/api";
import { downloadReportPdf } from "../../../../utils/reportPdf";

const formatRupiah = (value) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value) || 0);

const formatMaybeRupiah = (value) =>
    value === null || value === undefined
        ? "Belum dihitung"
        : formatRupiah(value);

const formatSignedRupiah = (value) => {
    if (value === null || value === undefined) return "Belum dihitung";
    const amount = Number(value);
    if (amount > 0) return `+${formatRupiah(amount)}`;
    if (amount < 0) return `−${formatRupiah(Math.abs(amount))}`;
    return formatRupiah(0);
};

const formatDate = (date) => {
    if (!date) return "Belum tersedia";
    const parsedDate = new Date(`${date}T00:00:00+07:00`);
    if (Number.isNaN(parsedDate.getTime())) return date;

    return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "long",
        timeZone: "Asia/Jakarta",
    }).format(parsedDate);
};

const toInputValue = (value) =>
    value === null || value === undefined ? "" : String(value);

const isValidAmount = (value) =>
    value !== "" &&
    Number.isSafeInteger(Number(value)) &&
    Number(value) >= 0;

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
        error.message ??
        "Terjadi kesalahan saat berkomunikasi dengan server."
    );
};

const statusLabels = {
    draft: ["Draft", "bg-slate-100 text-slate-700"],
    submitted: ["Menunggu Persetujuan", "bg-amber-100 text-amber-800"],
    approved: ["Disetujui Supervisor", "bg-emerald-100 text-emerald-800"],
    rejected: ["Perlu Diperbaiki", "bg-rose-100 text-rose-800"],
    auto_closed: [
        "Penutupan Otomatis · Belum Diverifikasi",
        "bg-amber-100 text-amber-800",
    ],
    auto_closed_unverified: [
        "Penutupan Otomatis · Belum Diverifikasi",
        "bg-amber-100 text-amber-800",
    ],
};

export default function TellerCashReconciliation() {
    const [reconciliation, setReconciliation] = useState(null);
    const [form, setForm] = useState({
        opening_balance: "",
        opening_physical_balance: "",
        physical_balance: "",
    });
    const [editingOpening, setEditingOpening] = useState(false);
    const [editingClosing, setEditingClosing] = useState(false);
    const [showTransactions, setShowTransactions] = useState(false);
    const [loading, setLoading] = useState(true);
    const [savingOpening, setSavingOpening] = useState(false);
    const [savingClosing, setSavingClosing] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [exporting, setExporting] = useState("");
    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const [exportError, setExportError] = useState("");

    const fetchReconciliation = useCallback(async () => {
        setLoading(true);
        setError("");

        try {
            const response = await api.get("/teller/cash-reconciliation");
            const result = response.data?.data;
            if (!result) {
                throw new Error("Server tidak mengembalikan data rekonsiliasi.");
            }

            setReconciliation(result);
            setForm({
                opening_balance: toInputValue(result.report?.opening_balance),
                opening_physical_balance: toInputValue(
                    result.report?.opening_physical_balance
                ),
                physical_balance: toInputValue(result.report?.physical_balance),
            });
            setEditingOpening(false);
            setEditingClosing(false);
        } catch (requestError) {
            setError(getErrorMessage(requestError));
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            void fetchReconciliation();
        }, 0);
        return () => window.clearTimeout(timer);
    }, [fetchReconciliation]);

    const report = reconciliation?.report ?? null;
    const reportDate = reconciliation?.date;
    const previousReport = reconciliation?.previous_report ?? null;
    const hasPreviousClosing = Boolean(previousReport);
    const previousBalanceUnavailable =
        hasPreviousClosing &&
        (previousReport.system_balance === null ||
            previousReport.system_balance === undefined);
    const openingBalance =
        report?.opening_balance ?? reconciliation?.opening_balance ?? null;
    const systemBalance = reconciliation?.system_balance ?? null;
    const summary = reconciliation?.transactions_summary ?? {};
    const transactions = reconciliation?.transactions ?? [];
    const status = report?.status ?? null;
    const isAutoClosed = [
        "auto_closed",
        "auto_closed_unverified",
    ].includes(status);
    const isAutoClosedUnverified = status === "auto_closed_unverified";
    const isSubmitted = status === "submitted";
    const isApproved = status === "approved";
    const canEdit =
        Boolean(reportDate) &&
        reportDate ===
            new Intl.DateTimeFormat("en-CA", {
                timeZone: "Asia/Jakarta",
                year: "numeric",
                month: "2-digit",
                day: "2-digit",
            }).format(new Date()) &&
        (!report ||
            (["draft", "rejected"].includes(status) && !report.locked_at));

    const isOpeningSaved =
        report?.opening_balance !== null &&
        report?.opening_balance !== undefined &&
        report?.opening_physical_balance !== null &&
        report?.opening_physical_balance !== undefined;
    const isClosingSaved =
        report?.physical_balance !== null &&
        report?.physical_balance !== undefined;
    const initialBalanceRequired =
        !hasPreviousClosing &&
        (report?.opening_balance === null ||
            report?.opening_balance === undefined);
    const openingChanged =
        !isOpeningSaved ||
        Number(form.opening_physical_balance) !==
            Number(report?.opening_physical_balance);
    const closingChanged =
        !isClosingSaved ||
        Number(form.physical_balance) !== Number(report?.physical_balance);
    const openingBalanceIsValid =
        !previousBalanceUnavailable &&
        (!initialBalanceRequired || isValidAmount(form.opening_balance));

    const canSaveOpening =
        canEdit &&
        !loading &&
        !savingOpening &&
        !savingClosing &&
        !submitting &&
        openingBalanceIsValid &&
        isValidAmount(form.opening_physical_balance) &&
        openingChanged;
    const canSaveClosing =
        canEdit &&
        !loading &&
        !savingOpening &&
        !savingClosing &&
        !submitting &&
        Boolean(report?.id) &&
        isOpeningSaved &&
        isValidAmount(form.physical_balance) &&
        closingChanged;
    const canSubmit =
        canEdit &&
        !loading &&
        !savingOpening &&
        !savingClosing &&
        !submitting &&
        ["draft", "rejected"].includes(status) &&
        isOpeningSaved &&
        isClosingSaved;

    const reviewReasons = useMemo(() => {
        const reasons = [...(previousReport?.review_reasons ?? [])];
        if (Number(report?.opening_difference) !== 0 &&
            report?.opening_difference !== null &&
            report?.opening_difference !== undefined) {
            reasons.push("Terdapat selisih uang saat pembukaan.");
        }
        if (Number(report?.closing_difference) !== 0 &&
            report?.closing_difference !== null &&
            report?.closing_difference !== undefined) {
            reasons.push("Terdapat selisih uang saat tutup kas.");
        }
        return [...new Set(reasons)];
    }, [
        previousReport?.review_reasons,
        report?.opening_difference,
        report?.closing_difference,
    ]);
    const needsReview = reviewReasons.length > 0;
    const openingStatus = !isOpeningSaved
        ? previousBalanceUnavailable
            ? "Memerlukan pemeriksaan"
            : form.opening_physical_balance || form.opening_balance
            ? "Sedang dikerjakan"
            : "Belum dimulai"
        : report.opening_difference !== 0
          ? "Memerlukan pemeriksaan"
          : "Selesai";
    const transactionStatus = isOpeningSaved
        ? "Selesai"
        : "Belum dimulai";
    const closingStatus = isClosingSaved
        ? Number(report.closing_difference) === 0
            ? "Selesai"
            : "Memerlukan pemeriksaan"
        : isOpeningSaved
          ? "Sedang dikerjakan"
          : "Belum dimulai";
    const sendStatus = isAutoClosed
        ? "Memerlukan pemeriksaan"
        : isSubmitted || isApproved
          ? "Selesai"
          : isClosingSaved
            ? "Sedang dikerjakan"
            : "Belum dimulai";

    const steps = [
        { label: "Kas Awal", state: openingStatus },
        { label: "Transaksi Hari Ini", state: transactionStatus },
        { label: "Hitung Uang Saat Tutup Kas", state: closingStatus },
        { label: "Kirim Laporan", state: sendStatus },
    ];

    const updateForm = (field, value) => {
        setForm((current) => ({ ...current, [field]: value }));
        setSuccess("");
    };

    const handleSaveOpening = async (event) => {
        event.preventDefault();
        if (!canSaveOpening) return;

        setSavingOpening(true);
        setError("");
        setSuccess("");
        try {
            const payload = {
                date: reportDate,
                opening_physical_balance: Number(
                    form.opening_physical_balance
                ),
            };
            if (initialBalanceRequired) {
                payload.opening_balance = Number(form.opening_balance);
            }
            await api.post("/teller/cash-reconciliation/opening", payload);
            await fetchReconciliation();
            setSuccess("Kas awal berhasil disimpan.");
        } catch (requestError) {
            setError(getErrorMessage(requestError));
        } finally {
            setSavingOpening(false);
        }
    };

    const handleSaveClosing = async (event) => {
        event.preventDefault();
        if (!canSaveClosing) return;

        setSavingClosing(true);
        setError("");
        setSuccess("");
        try {
            await api.post("/teller/cash-reconciliation/closing", {
                report_id: report.id,
                physical_balance: Number(form.physical_balance),
            });
            await fetchReconciliation();
            setSuccess("Hasil penghitungan uang berhasil disimpan.");
        } catch (requestError) {
            setError(getErrorMessage(requestError));
        } finally {
            setSavingClosing(false);
        }
    };

    const handleSubmitReport = async () => {
        if (!canSubmit) return;
        const notice = needsReview
            ? " Ada selisih atau pemeriksaan sebelumnya yang akan terlihat oleh Supervisor."
            : "";
        if (
            !window.confirm(
                `Kirim laporan rekonsiliasi tanggal ${formatDate(reportDate)} kepada Supervisor?${notice}`
            )
        ) {
            return;
        }

        setSubmitting(true);
        setError("");
        setSuccess("");
        try {
            await api.post("/teller/submit-daily-report", {
                date: reportDate,
            });
            await fetchReconciliation();
            setSuccess("Laporan dikirim kepada Supervisor untuk diperiksa.");
        } catch (requestError) {
            setError(getErrorMessage(requestError));
        } finally {
            setSubmitting(false);
        }
    };

    const exportExcel = async () => {
        setExporting("excel");
        setExportError("");
        try {
            const response = await api.get(
                "/teller/cash-reconciliation/export",
                { params: { date: reportDate }, responseType: "blob" }
            );
            const url = URL.createObjectURL(response.data);
            const link = document.createElement("a");
            link.href = url;
            link.download = `rekonsiliasi-kas-${reportDate}.xlsx`;
            link.click();
            URL.revokeObjectURL(url);
        } catch (requestError) {
            setExportError(getErrorMessage(requestError));
        } finally {
            setExporting("");
        }
    };

    const exportPdf = async () => {
        setExporting("pdf");
        setExportError("");
        try {
            const response = await api.get("/teller/cash-reconciliation", {
                params: { date: reportDate },
            });
            const data = response.data?.data?.export;
            if (!data) throw new Error("Data ekspor rekonsiliasi tidak tersedia.");

            const rupiah = formatMaybeRupiah;
            const signed = formatSignedRupiah;
            const summaryRows = [
                ["Identitas Laporan", ""],
                ["Nama sistem", data.system_name],
                ["Tanggal operasional", formatDate(data.date)],
                ["Nama Teller", data.teller_name],
                ["Dibuat", data.generated_at],
                ["Status laporan", data.report_status],
                ["BAGIAN 1 — KAS AWAL", ""],
                ["Saldo awal sistem", rupiah(data.opening_balance)],
                ["Sumber saldo awal", data.opening_balance_source],
                ["Dicatat oleh", data.opening_balance_recorded_by ?? "Belum tersedia"],
                ["Waktu pencatatan", data.opening_balance_recorded_at ?? "Belum tersedia"],
                ["Uang fisik saat pembukaan", rupiah(data.opening_physical_balance)],
                ["Selisih uang awal", signed(data.opening_difference)],
                ["BAGIAN 2 — TRANSAKSI HARI INI", ""],
                ["Setoran berhasil", rupiah(data.total_deposit)],
                ["Penarikan berhasil", rupiah(data.total_withdrawal)],
                ["Jumlah transaksi berhasil", data.total_transactions],
                ["Jumlah transaksi pending", data.pending_transactions],
                ["Jumlah transaksi gagal", data.failed_transactions],
                ["BAGIAN 3 — PERHITUNGAN SALDO AKHIR SISTEM", ""],
                [
                    "Rumus",
                    "Saldo awal sistem + setoran berhasil - penarikan berhasil",
                ],
                ["Saldo awal sistem", rupiah(data.opening_balance)],
                ["Setoran berhasil", `+${rupiah(data.total_deposit)}`],
                ["Penarikan berhasil", `−${rupiah(data.total_withdrawal)}`],
                ["Saldo seharusnya menurut sistem", rupiah(data.system_balance)],
                ["BAGIAN 4 — HITUNG UANG SAAT TUTUP KAS", ""],
                ["Saldo seharusnya menurut sistem", rupiah(data.system_balance)],
                ["Uang fisik saat tutup kas", rupiah(data.physical_balance)],
                ["Selisih uang akhir", signed(data.closing_difference)],
                ["Status kecocokan kas", data.cash_match_status],
                ["BAGIAN 5 — STATUS PENUTUPAN", ""],
                ["Status rekonsiliasi", data.reconciliation_status],
                ["Status laporan", data.report_status],
                ["Status persetujuan Supervisor", data.approval_status],
                ["Supervisor penyetuju", data.approver_name ?? "Belum tersedia"],
                ["Waktu persetujuan", data.approved_at ?? "Belum tersedia"],
                ["Catatan penolakan", data.rejection_reason ?? "Tidak ada"],
            ];
            if (data.previous_report?.requires_review) {
                summaryRows.push(["Pemeriksaan penutupan sebelumnya", ""]);
                for (const reason of data.previous_report.review_reasons) {
                    summaryRows.push([reason, ""]);
                }
            }

            downloadReportPdf({
                title: data.title,
                subtitle: `Tanggal operasional: ${formatDate(data.date)}`,
                filename: `rekonsiliasi-kas-${data.date}.pdf`,
                generatedAt: data.generated_at,
                summary: summaryRows,
                columns: [
                    "No.",
                    "Tanggal dan waktu",
                    "Referensi",
                    "Jenis",
                    "Nominal",
                    "Status",
                ],
                rows: data.transactions.map((transaction, index) => [
                    index + 1,
                    transaction.date
                        ? new Intl.DateTimeFormat("id-ID", {
                              dateStyle: "short",
                              timeStyle: "short",
                              timeZone: "Asia/Jakarta",
                          }).format(new Date(transaction.date))
                        : "—",
                    transaction.reference ?? "—",
                    transaction.type === "deposit"
                        ? "Setoran"
                        : transaction.type === "withdrawal"
                          ? "Penarikan"
                          : transaction.type ?? "—",
                    rupiah(transaction.amount),
                    transaction.status === "approved"
                        ? "Berhasil"
                        : transaction.status === "pending"
                          ? "Pending"
                          : transaction.status === "rejected" ||
                              transaction.status === "failed"
                            ? "Gagal"
                            : transaction.status ?? "—",
                ]),
                notes: data.transactions.length
                    ? []
                    : ["Belum ada transaksi pada tanggal operasional ini."],
            });
        } catch (requestError) {
            setExportError(getErrorMessage(requestError));
        } finally {
            setExporting("");
        }
    };

    const statusDisplay = statusLabels[status] ?? [
        "Belum Dibuat",
        "bg-slate-100 text-slate-700",
    ];

    return (
        <main className="mx-auto max-w-4xl space-y-4 px-3 pt-6 pb-8 sm:px-0">
            <header className="flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 className="text-xl font-bold text-slate-900">
                        Rekonsiliasi Kas
                    </h1>
                    <p className="mt-1 text-sm text-slate-600">
                        Catat uang awal, periksa transaksi, dan hitung uang saat
                        operasional selesai.
                    </p>
                    <p className="mt-2 text-sm font-medium text-slate-700">
                        Tanggal operasional: {formatDate(reportDate)}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <span
                        className={`inline-flex rounded-full px-3 py-1.5 text-xs font-semibold ${statusDisplay[1]}`}
                    >
                        {statusDisplay[0]}
                    </span>
                    <button
                        type="button"
                        onClick={exportExcel}
                        disabled={loading || Boolean(exporting) || !reportDate}
                        className="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                    >
                        {exporting === "excel" ? (
                            <RefreshCw size={16} className="animate-spin" />
                        ) : (
                            <FileSpreadsheet size={16} />
                        )}
                        Export Excel
                    </button>
                    <button
                        type="button"
                        onClick={exportPdf}
                        disabled={loading || Boolean(exporting) || !reportDate}
                        className="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                    >
                        {exporting === "pdf" ? (
                            <RefreshCw size={16} className="animate-spin" />
                        ) : (
                            <FileText size={16} />
                        )}
                        Export PDF
                    </button>
                    <button
                        type="button"
                        onClick={() => void fetchReconciliation()}
                        disabled={loading || Boolean(exporting)}
                        aria-label="Muat ulang rekonsiliasi kas"
                        className="rounded-lg border border-slate-300 p-2 text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                    >
                        <RefreshCw
                            size={17}
                            className={loading ? "animate-spin" : ""}
                        />
                    </button>
                </div>
            </header>

            {exportError && (
                <div
                    role="alert"
                    className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800"
                >
                    {exportError}
                </div>
            )}
            {error && (
                <div
                    role="alert"
                    className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800"
                >
                    {error}
                </div>
            )}
            {success && (
                <div
                    role="status"
                    className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800"
                >
                    {success}
                </div>
            )}
            {previousReport?.requires_review && (
                <div
                    role="status"
                    className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                >
                    <AlertCircle size={18} className="mt-0.5 shrink-0" />
                    <div>
                        <p className="font-semibold">
                            Perlu pemeriksaan dari penutupan sebelumnya
                        </p>
                        <ul className="mt-1 list-inside list-disc">
                            {previousReport.review_reasons.map((reason) => (
                                <li key={reason}>{reason}</li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}
            {previousBalanceUnavailable && (
                <div
                    role="alert"
                    className="flex gap-3 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800"
                >
                    <AlertCircle size={18} className="mt-0.5 shrink-0" />
                    <p>
                        Saldo sistem penutupan sebelumnya belum tersedia.
                        Operasional belum dapat dimulai; minta Supervisor
                        memeriksa dan mengoreksi laporan tersebut.
                    </p>
                </div>
            )}
            {isAutoClosed && (
                <div
                    role="status"
                    className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
                >
                    <AlertCircle size={18} className="mt-0.5 shrink-0" />
                    <p>
                        {isAutoClosedUnverified
                            ? "Penutupan otomatis tercatat. Uang fisik belum diverifikasi dan laporan belum disetujui Supervisor."
                            : "Penutupan otomatis tercatat setelah uang fisik dimasukkan. Laporan belum disetujui Supervisor."}
                    </p>
                </div>
            )}

            {loading ? (
                <div className="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500">
                    <RefreshCw
                        size={18}
                        className="mx-auto mb-2 animate-spin"
                    />
                    Memuat rekonsiliasi...
                </div>
            ) : reconciliation ? (
                <>
                    <StepProgress steps={steps} />

                    <section className="rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
                        <SectionHeading
                            title="1. Kas Awal"
                            description="Catat saldo awal sistem dan hitung uang fisik sebelum melayani nasabah."
                        />
                        {report?.rejection_reason && status === "rejected" && (
                            <p className="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">
                                Catatan Supervisor: {report.rejection_reason}
                            </p>
                        )}

                        {isOpeningSaved && !editingOpening ? (
                            <div className="mt-4 space-y-3">
                                <SummaryRow
                                    label="Saldo Awal Sistem"
                                    value={formatMaybeRupiah(openingBalance)}
                                    note={
                                        reconciliation.opening_balance_source_label
                                    }
                                />
                                <SummaryRow
                                    label="Uang Fisik Saat Pembukaan"
                                    value={formatMaybeRupiah(
                                        report.opening_physical_balance
                                    )}
                                />
                                <SummaryRow
                                    label="Selisih Uang Awal"
                                    value={formatSignedRupiah(
                                        report.opening_difference
                                    )}
                                    valueClass={differenceColor(
                                        report.opening_difference
                                    )}
                                    final
                                />
                                {canEdit && (
                                    <button
                                        type="button"
                                        onClick={() => setEditingOpening(true)}
                                        className="text-sm font-semibold text-blue-700 hover:text-blue-900"
                                    >
                                        Ubah uang fisik pembukaan
                                    </button>
                                )}
                            </div>
                        ) : (
                            <form
                                onSubmit={handleSaveOpening}
                                className="mt-4 space-y-4"
                            >
                                {hasPreviousClosing ? (
                                    <SummaryRow
                                        label="Saldo Awal Sistem"
                                        value={formatMaybeRupiah(openingBalance)}
                                        note={`Sumber: saldo akhir sistem ${formatDate(previousReport.date)}`}
                                    />
                                ) : (
                                    <div>
                                        <AmountInput
                                            id="opening-balance"
                                            label="Saldo Awal Sistem"
                                            value={form.opening_balance}
                                            onChange={(value) =>
                                                updateForm(
                                                    "opening_balance",
                                                    value
                                                )
                                            }
                                            disabled={
                                                !canEdit ||
                                                savingOpening ||
                                                !initialBalanceRequired
                                            }
                                        />
                                        <p className="mt-1 text-xs text-slate-500">
                                            Masukkan jumlah modal kas
                                            operasional yang benar-benar
                                            diterima. Setelah disimpan, koreksi
                                            hanya dapat dilakukan Supervisor.
                                        </p>
                                    </div>
                                )}
                                <div>
                                    <AmountInput
                                        id="opening-physical-balance"
                                        label="Uang Fisik Saat Pembukaan"
                                        value={form.opening_physical_balance}
                                        onChange={(value) =>
                                            updateForm(
                                                "opening_physical_balance",
                                                value
                                            )
                                        }
                                        disabled={!canEdit || savingOpening}
                                    />
                                    <p className="mt-1 text-xs text-slate-500">
                                        Masukkan total uang fisik yang telah
                                        dihitung.
                                    </p>
                                </div>
                                {openingBalance !== null &&
                                    isValidAmount(
                                        form.opening_physical_balance
                                    ) && (
                                        <DifferencePanel
                                            label="Selisih Uang Awal"
                                            value={
                                                Number(
                                                    form.opening_physical_balance
                                                ) - Number(openingBalance)
                                            }
                                        />
                                    )}
                                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                    <PrimaryButton
                                        type="submit"
                                        disabled={!canSaveOpening}
                                        icon={<Save size={16} />}
                                    >
                                        {savingOpening
                                            ? "Menyimpan..."
                                            : "Simpan Kas Awal"}
                                    </PrimaryButton>
                                    {editingOpening && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setEditingOpening(false)
                                            }
                                            className="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100 sm:w-auto"
                                        >
                                            Batal
                                        </button>
                                    )}
                                </div>
                            </form>
                        )}
                    </section>

                    <section className="rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
                        <SectionHeading
                            title="2. Transaksi Hari Ini"
                            description="Ringkasan uang yang masuk dan keluar selama operasional."
                        />
                        <div className="mt-4 divide-y divide-slate-100">
                            <SummaryRow
                                label="Saldo Awal Sistem"
                                value={formatMaybeRupiah(openingBalance)}
                            />
                            <SummaryRow
                                label="Setoran Berhasil"
                                value={formatRupiah(summary.total_deposit)}
                                valueClass="text-emerald-700"
                            />
                            <SummaryRow
                                label="Penarikan Berhasil"
                                value={formatRupiah(summary.total_withdrawal)}
                                valueClass="text-amber-700"
                            />
                            <SummaryRow
                                label="Saldo Seharusnya Menurut Sistem"
                                value={formatMaybeRupiah(systemBalance)}
                                final
                            />
                        </div>
                        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                            <span>{summary.total_transactions ?? 0} transaksi berhasil</span>
                            <span>{summary.pending_transactions ?? 0} pending</span>
                            <span>{summary.failed_transactions ?? 0} gagal</span>
                        </div>
                        <button
                            type="button"
                            aria-expanded={showTransactions}
                            onClick={() =>
                                setShowTransactions((shown) => !shown)
                            }
                            className="mt-3 text-sm font-semibold text-blue-700 hover:text-blue-900"
                        >
                            {showTransactions
                                ? "Tutup Rincian Transaksi"
                                : "Lihat Rincian Transaksi"}
                        </button>
                        {showTransactions && (
                            <TransactionDetails transactions={transactions} />
                        )}
                    </section>

                    <section className="rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
                        <SectionHeading
                            title="3. Hitung Uang Saat Tutup Kas"
                            description="Hitung seluruh uang fisik yang tersisa setelah pelayanan transaksi hari ini selesai."
                        />
                        {isClosingSaved && !editingClosing ? (
                            <div className="mt-4 space-y-3">
                                <SummaryRow
                                    label="Saldo Seharusnya Menurut Sistem"
                                    value={formatMaybeRupiah(systemBalance)}
                                />
                                <SummaryRow
                                    label="Uang Fisik Saat Tutup Kas"
                                    value={formatMaybeRupiah(
                                        report.physical_balance
                                    )}
                                />
                                <DifferencePanel
                                    label="Selisih Uang Akhir"
                                    value={report.closing_difference}
                                />
                                {report.closing_difference !== 0 && (
                                    <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                                        {report.closing_difference < 0
                                            ? `Peringatan: Uang fisik kurang ${formatRupiah(Math.abs(report.closing_difference))} dibandingkan saldo sistem. Periksa kembali penghitungan dan transaksi.`
                                            : `Peringatan: Uang fisik lebih ${formatRupiah(report.closing_difference)} dibandingkan saldo sistem. Periksa kembali penghitungan dan transaksi.`}
                                    </p>
                                )}
                                {canEdit && (
                                    <button
                                        type="button"
                                        onClick={() => setEditingClosing(true)}
                                        className="text-sm font-semibold text-blue-700 hover:text-blue-900"
                                    >
                                        Ubah hasil penghitungan
                                    </button>
                                )}
                            </div>
                        ) : (
                            <form
                                onSubmit={handleSaveClosing}
                                className="mt-4 space-y-4"
                            >
                                <SummaryRow
                                    label="Saldo Seharusnya Menurut Sistem"
                                    value={formatMaybeRupiah(systemBalance)}
                                />
                                <div>
                                    <AmountInput
                                        id="physical-balance"
                                        label="Uang Fisik Saat Tutup Kas"
                                        value={form.physical_balance}
                                        onChange={(value) =>
                                            updateForm(
                                                "physical_balance",
                                                value
                                            )
                                        }
                                        disabled={
                                            !canEdit ||
                                            !isOpeningSaved ||
                                            savingClosing
                                        }
                                    />
                                    <p className="mt-1 text-xs text-slate-500">
                                        Masukkan total uang yang benar-benar
                                        tersedia setelah seluruh transaksi
                                        selesai.
                                    </p>
                                </div>
                                {systemBalance !== null &&
                                    isValidAmount(form.physical_balance) && (
                                        <DifferencePanel
                                            label="Selisih Uang Akhir"
                                            value={
                                                Number(form.physical_balance) -
                                                Number(systemBalance)
                                            }
                                        />
                                    )}
                                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                    <PrimaryButton
                                        type="submit"
                                        disabled={!canSaveClosing}
                                        icon={<Save size={16} />}
                                    >
                                        {savingClosing
                                            ? "Menyimpan..."
                                            : "Simpan Hasil Penghitungan"}
                                    </PrimaryButton>
                                    {editingClosing && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setEditingClosing(false)
                                            }
                                            className="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100 sm:w-auto"
                                        >
                                            Batal
                                        </button>
                                    )}
                                </div>
                            </form>
                        )}
                    </section>

                    <section className="rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
                        <SectionHeading
                            title="4. Kirim Laporan"
                            description="Periksa hasil penghitungan sebelum menyelesaikan laporan harian."
                        />
                        <div className="mt-4 rounded-lg bg-slate-50 p-4">
                            {isAutoClosed || isSubmitted || isApproved ? (
                                <div className="flex items-start gap-3">
                                    {isApproved ? (
                                        <CheckCircle2
                                            size={19}
                                            className="mt-0.5 text-emerald-700"
                                        />
                                    ) : isSubmitted ? (
                                        <Clock3
                                            size={19}
                                            className="mt-0.5 text-amber-700"
                                        />
                                    ) : (
                                        <AlertCircle
                                            size={19}
                                            className="mt-0.5 text-amber-700"
                                        />
                                    )}
                                    <div>
                                        <p className="font-semibold text-slate-900">
                                            {isApproved
                                                ? "Disetujui Supervisor"
                                                : isSubmitted
                                                  ? "Menunggu Pemeriksaan Supervisor"
                                                  : "Penutupan Otomatis · Belum Diverifikasi"}
                                        </p>
                                        <p className="mt-1 text-sm text-slate-600">
                                            {isApproved
                                                ? "Laporan disetujui Supervisor."
                                                : isSubmitted
                                                  ? "Laporan sudah dikirim dan belum disetujui."
                                                  : "Penutupan otomatis bukan penghitungan kas fisik dan bukan laporan final."}
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <>
                                    <div className="space-y-2">
                                        <SummaryRow
                                            label="Saldo Awal Sistem"
                                            value={formatMaybeRupiah(
                                                openingBalance
                                            )}
                                        />
                                        <SummaryRow
                                            label="Selisih Uang Awal"
                                            value={formatSignedRupiah(
                                                report?.opening_difference
                                            )}
                                        />
                                        <SummaryRow
                                            label="Setoran Berhasil"
                                            value={formatRupiah(
                                                summary.total_deposit
                                            )}
                                        />
                                        <SummaryRow
                                            label="Penarikan Berhasil"
                                            value={formatRupiah(
                                                summary.total_withdrawal
                                            )}
                                        />
                                        <SummaryRow
                                            label="Saldo Seharusnya Menurut Sistem"
                                            value={formatMaybeRupiah(
                                                systemBalance
                                            )}
                                        />
                                        <SummaryRow
                                            label="Uang Fisik Saat Tutup Kas"
                                            value={formatMaybeRupiah(
                                                report?.physical_balance
                                            )}
                                        />
                                        <SummaryRow
                                            label="Selisih Uang Akhir"
                                            value={formatSignedRupiah(
                                                report?.closing_difference
                                            )}
                                            final
                                        />
                                    </div>
                                    <p
                                        className={`mt-3 rounded-lg p-3 text-sm ${
                                            needsReview
                                                ? "bg-amber-100 text-amber-900"
                                                : "bg-emerald-50 text-emerald-800"
                                        }`}
                                    >
                                        {needsReview
                                            ? "Memerlukan pemeriksaan Supervisor. Selisih tetap dicatat dan tidak mengubah saldo sistem."
                                            : "Kas cocok. Laporan tetap menunggu persetujuan Supervisor setelah dikirim."}
                                    </p>
                                    <PrimaryButton
                                        type="button"
                                        onClick={handleSubmitReport}
                                        disabled={!canSubmit}
                                        icon={<Send size={16} />}
                                        className="mt-4"
                                    >
                                        {submitting
                                            ? "Mengirim..."
                                            : "Kirim Laporan"}
                                    </PrimaryButton>
                                </>
                            )}
                        </div>
                    </section>
                </>
            ) : (
                <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
                    Data rekonsiliasi belum tersedia.
                </div>
            )}
        </main>
    );
}

function StepProgress({ steps }) {
    return (
        <nav
            aria-label="Tahapan rekonsiliasi kas"
            className="rounded-xl border border-slate-200 bg-white p-4"
        >
            <ol className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {steps.map((step, index) => {
                    const complete = step.state === "Selesai";
                    const review = step.state === "Memerlukan pemeriksaan";
                    const active = step.state === "Sedang dikerjakan";
                    return (
                        <li
                            key={step.label}
                            className="flex min-w-0 items-start gap-3"
                        >
                            <span
                                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold ${
                                    complete
                                        ? "bg-emerald-100 text-emerald-800"
                                        : review
                                          ? "bg-amber-100 text-amber-800"
                                          : active
                                            ? "bg-blue-100 text-blue-800"
                                            : "bg-slate-100 text-slate-500"
                                }`}
                            >
                                {complete ? <Check size={15} /> : index + 1}
                            </span>
                            <span className="min-w-0">
                                <span className="block text-sm font-semibold text-slate-800">
                                    {step.label}
                                </span>
                                <span className="block text-xs text-slate-500">
                                    {step.state}
                                </span>
                            </span>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

function SectionHeading({ title, description }) {
    return (
        <div>
            <h2 className="text-base font-semibold text-slate-900">{title}</h2>
            <p className="mt-1 text-sm text-slate-500">{description}</p>
        </div>
    );
}

function AmountInput({ id, label, value, onChange, disabled = false }) {
    return (
        <div className="min-w-0">
            <label
                htmlFor={id}
                className="mb-1.5 block text-sm font-medium text-slate-700"
            >
                {label}
            </label>
            <div className="relative">
                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-500">
                    Rp
                </span>
                <input
                    id={id}
                    type="number"
                    inputMode="numeric"
                    min="0"
                    step="1"
                    required
                    value={value}
                    placeholder="Masukkan jumlah uang"
                    onChange={(event) => onChange(event.target.value)}
                    disabled={disabled}
                    className="w-full min-w-0 rounded-lg border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-100"
                />
            </div>
        </div>
    );
}

function SummaryRow({ label, value, note, valueClass = "", final = false }) {
    return (
        <div
            className={`flex flex-col gap-1 py-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4 ${
                final ? "border-t border-slate-200 pt-3" : ""
            }`}
        >
            <span
                className={`text-sm ${
                    final
                        ? "font-semibold text-slate-900"
                        : "text-slate-600"
                }`}
            >
                {label}
                {note && (
                    <span className="mt-0.5 block text-xs font-normal text-slate-500">
                        {note}
                    </span>
                )}
            </span>
            <span
                className={`break-words text-left font-semibold sm:text-right ${
                    final ? "text-lg text-slate-950" : "text-sm text-slate-800"
                } ${valueClass}`}
            >
                {value}
            </span>
        </div>
    );
}

function DifferencePanel({ label, value }) {
    const status =
        value === null || value === undefined
            ? "Belum dihitung"
            : Number(value) === 0
              ? "Cocok"
              : Number(value) > 0
                ? "Uang fisik lebih"
                : "Uang fisik kurang";
    const color =
        status === "Cocok"
            ? "border-emerald-200 bg-emerald-50 text-emerald-800"
            : status === "Belum dihitung"
              ? "border-slate-200 bg-slate-50 text-slate-600"
              : "border-amber-200 bg-amber-50 text-amber-900";

    return (
        <div className={`rounded-lg border p-3 ${color}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm font-medium">{label}</span>
                <span className="text-sm font-semibold">
                    {status} · {formatSignedRupiah(value)}
                </span>
            </div>
        </div>
    );
}

function TransactionDetails({ transactions }) {
    if (transactions.length === 0) {
        return (
            <p className="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
                Belum ada transaksi pada tanggal operasional ini.
            </p>
        );
    }

    return (
        <div
            className="mt-3 overflow-x-auto rounded-lg border border-slate-200"
            tabIndex={0}
            aria-label="Daftar transaksi, geser horizontal untuk melihat kolom lainnya"
        >
            <table className="w-full min-w-[560px] text-left text-sm">
                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th className="px-3 py-2">Waktu</th>
                        <th className="px-3 py-2">Referensi</th>
                        <th className="px-3 py-2">Jenis</th>
                        <th className="px-3 py-2">Status</th>
                        <th className="px-3 py-2 text-right">Nominal</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {transactions.map((transaction) => (
                        <tr key={transaction.id}>
                            <td className="whitespace-nowrap px-3 py-2">
                                {transaction.transaction_date
                                    ? new Intl.DateTimeFormat("id-ID", {
                                          dateStyle: "short",
                                          timeStyle: "short",
                                          timeZone: "Asia/Jakarta",
                                      }).format(
                                          new Date(transaction.transaction_date)
                                      )
                                    : "—"}
                            </td>
                            <td className="px-3 py-2">{transaction.id}</td>
                            <td className="px-3 py-2">
                                {transaction.transaction_type === "deposit"
                                    ? "Setoran"
                                    : transaction.transaction_type ===
                                        "withdrawal"
                                      ? "Penarikan"
                                      : transaction.transaction_type}
                            </td>
                            <td className="px-3 py-2">
                                {transaction.status === "approved"
                                    ? "Berhasil"
                                    : transaction.status === "pending"
                                      ? "Pending"
                                      : "Gagal"}
                            </td>
                            <td className="whitespace-nowrap px-3 py-2 text-right">
                                {formatRupiah(transaction.amount)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function PrimaryButton({
    type,
    onClick,
    disabled,
    icon,
    className = "",
    children,
}) {
    return (
        <button
            type={type}
            onClick={onClick}
            disabled={disabled}
            className={`inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto ${className}`}
        >
            {icon}
            {children}
        </button>
    );
}

function differenceColor(value) {
    if (value === null || value === undefined || Number(value) === 0) {
        return "text-emerald-700";
    }
    return Number(value) < 0 ? "text-rose-700" : "text-amber-700";
}

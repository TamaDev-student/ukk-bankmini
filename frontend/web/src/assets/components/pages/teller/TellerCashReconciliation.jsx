import { useCallback, useEffect, useMemo, useState } from "react";
import {
    AlertCircle,
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
            await api.post(
                "/teller/cash-reconciliation/opening",
                payload
            );
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
        <main className="mx-auto max-w-5xl space-y-4 px-3 pt-6 pb-8 sm:px-0">
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
                    <section className="rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <SectionHeading
                                title="Pembukaan Kas"
                                description="Catat modal kas operasional dan hitung uang fisik sebelum melayani nasabah."
                            />
                            <StatusPill
                                label={isOpeningSaved ? "Sudah dibuka" : "Belum dibuka"}
                                tone={isOpeningSaved ? "success" : "warning"}
                            />
                        </div>
                        {!isOpeningSaved && (
                            <div
                                role="status"
                                className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"
                            >
                                Pembukaan kas untuk tanggal operasional ini belum
                                dicatat.
                            </div>
                        )}
                        {isOpeningSaved && !editingOpening ? (
                            <div className="mt-5 grid gap-3 sm:grid-cols-3">
                                <SummaryTile
                                    label="Modal Awal Kas"
                                    value={formatMaybeRupiah(openingBalance)}
                                    note={reconciliation.opening_balance_source_label}
                                />
                                <SummaryTile
                                    label="Uang Fisik Saat Pembukaan"
                                    value={formatMaybeRupiah(report.opening_physical_balance)}
                                />
                                <SummaryTile
                                    label="Selisih Uang Awal"
                                    value={formatSignedRupiah(report.opening_difference)}
                                    valueClass={differenceColor(report.opening_difference)}
                                    note={differenceStatus(report.opening_difference)}
                                />
                                {canEdit && (
                                    <button
                                        type="button"
                                        onClick={() => setEditingOpening(true)}
                                        className="text-left text-sm font-semibold text-blue-700 hover:text-blue-900 sm:col-span-3"
                                    >
                                        Koreksi uang fisik pembukaan
                                    </button>
                                )}
                            </div>
                        ) : (
                            <form
                                onSubmit={handleSaveOpening}
                                className="mt-5 space-y-4"
                            >
                                {hasPreviousClosing ? (
                                    <SummaryTile
                                        label="Modal Awal Kas"
                                        value={formatMaybeRupiah(openingBalance)}
                                        note={`Mengikuti saldo akhir sistem ${formatDate(previousReport.date)}`}
                                    />
                                ) : (
                                    <div>
                                        <AmountInput
                                            id="opening-balance"
                                            label="Modal Awal Kas"
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
                                            Modal kas operasional, bukan saldo
                                            rekening nasabah. Setelah dicatat,
                                            nominal sistem hanya dapat dikoreksi
                                            melalui mekanisme Supervisor.
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
                                        Hitung uang fisik yang diterima
                                        sebelum layanan dimulai.
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
                                            : "Simpan Pembukaan Kas"}
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
                            title="Ringkasan Transaksi Hari Ini"
                            description="Ringkasan uang yang masuk dan keluar selama operasional."
                        />
                        <div className="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <SummaryTile
                                label="Setoran Berhasil"
                                value={formatRupiah(summary.total_deposit ?? 0)}
                                valueClass="text-emerald-700"
                            />
                            <SummaryTile
                                label="Penarikan Berhasil"
                                value={formatRupiah(summary.total_withdrawal ?? 0)}
                                valueClass="text-amber-700"
                            />
                            <SummaryTile
                                label="Transaksi Berhasil"
                                value={summary.total_transactions ?? 0}
                                note={`${summary.pending_transactions ?? 0} pending · ${summary.failed_transactions ?? 0} gagal`}
                            />
                            <SummaryTile
                                label="Saldo Kas Seharusnya"
                                value={formatMaybeRupiah(systemBalance)}
                                valueClass="text-blue-800"
                            />
                        </div>
                        <p className="mt-3 text-xs text-slate-500">
                            Ringkasan berasal dari backend; transaksi pending
                            dan gagal tidak dihitung sebagai kas berhasil.
                        </p>
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
                            title="Penutupan Kas"
                            description="Hitung uang fisik setelah transaksi selesai, lalu kirim laporan untuk diperiksa Supervisor."
                        />
                        {isClosingSaved && !editingClosing ? (
                            <div className="mt-5 grid gap-3 sm:grid-cols-3">
                                <SummaryTile
                                    label="Saldo Kas Seharusnya"
                                    value={formatMaybeRupiah(systemBalance)}
                                />
                                <SummaryTile
                                    label="Uang Fisik Saat Tutup Kas"
                                    value={formatMaybeRupiah(report.physical_balance)}
                                />
                                <SummaryTile
                                    label="Selisih Uang Akhir"
                                    value={formatSignedRupiah(report.closing_difference)}
                                    valueClass={differenceColor(report.closing_difference)}
                                    note={differenceStatus(report.closing_difference)}
                                />
                                {report.closing_difference !== 0 && (
                                    <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 sm:col-span-3">
                                        {report.closing_difference < 0
                                            ? `Peringatan: Uang fisik kurang ${formatRupiah(Math.abs(report.closing_difference))} dibandingkan saldo sistem. Periksa kembali penghitungan dan transaksi.`
                                            : `Peringatan: Uang fisik lebih ${formatRupiah(report.closing_difference)} dibandingkan saldo sistem. Periksa kembali penghitungan dan transaksi.`}
                                    </p>
                                )}
                                {canEdit && (
                                    <button
                                        type="button"
                                        onClick={() => setEditingClosing(true)}
                                        className="text-left text-sm font-semibold text-blue-700 hover:text-blue-900 sm:col-span-3"
                                    >
                                        Koreksi uang fisik penutupan
                                    </button>
                                )}
                            </div>
                        ) : canEdit && isOpeningSaved ? (
                            <form
                                onSubmit={handleSaveClosing}
                                className="mt-5 space-y-4"
                            >
                                <SummaryTile
                                    label="Saldo Kas Seharusnya"
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
                        ) : (
                            <p
                                role="status"
                                className="mt-5 rounded-lg bg-slate-50 p-3 text-sm text-slate-600"
                            >
                                {!isOpeningSaved
                                    ? "Pembukaan kas harus dicatat sebelum penghitungan penutupan dapat dilakukan."
                                    : isAutoClosed
                                      ? "Penutupan otomatis tercatat dan tidak dapat diubah dari halaman ini."
                                      : "Laporan pada tanggal operasional ini tidak dapat diubah."}
                            </p>
                        )}
                        <div className="mt-5 border-t border-slate-200 pt-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h3 className="text-sm font-semibold text-slate-900">
                                        Status laporan
                                    </h3>
                                    <p className="mt-1 text-sm text-slate-600">
                                        Persetujuan akhir tetap menjadi kewenangan Supervisor.
                                    </p>
                                </div>
                                <StatusPill
                                    label={statusDisplay[0]}
                                    tone={
                                        isApproved
                                            ? "success"
                                            : isSubmitted
                                              ? "info"
                                              : isAutoClosed || status === "rejected"
                                                ? "warning"
                                                : "neutral"
                                    }
                                />
                            </div>
                            {isAutoClosed || isSubmitted || isApproved ? (
                                <div className="mt-3 flex items-start gap-3 rounded-lg bg-slate-50 p-3">
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
                                        {report?.approver && (
                                            <p className="mt-1 text-xs text-slate-500">
                                                Disetujui oleh {report.approver.full_name ?? report.approver.name}
                                                {report.approved_at
                                                    ? ` · ${formatDate(report.approved_at.slice(0, 10))}`
                                                    : ""}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <>
                                    {status === "rejected" && report?.rejection_reason && (
                                        <p className="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">
                                            Catatan Supervisor: {report.rejection_reason}
                                        </p>
                                    )}
                                    <p className={`mt-3 rounded-lg p-3 text-sm ${needsReview ? "bg-amber-50 text-amber-900" : "bg-slate-50 text-slate-700"}`}>
                                        {needsReview
                                            ? "Ada selisih kas atau pemeriksaan sebelumnya. Selisih tetap dicatat dan tidak mengubah saldo sistem."
                                            : "Pastikan uang fisik sudah dihitung dan disimpan sebelum mengirim laporan."}
                                    </p>
                                    <PrimaryButton
                                        type="button"
                                        onClick={handleSubmitReport}
                                        disabled={!canSubmit}
                                        icon={<Send size={16} />}
                                        className="mt-3"
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

function SummaryTile({ label, value, note, valueClass = "" }) {
    return (
        <div className="min-w-0 rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p className="text-xs font-medium text-slate-500">
                {label}
            </p>
            <p className={`mt-1 break-words text-base font-semibold ${valueClass || "text-slate-900"}`}>
                {value}
            </p>
            {note && <p className="mt-1 text-xs text-slate-500">{note}</p>}
        </div>
    );
}

function StatusPill({ label, tone }) {
    const toneClasses = {
        success: "bg-emerald-100 text-emerald-800",
        warning: "bg-amber-100 text-amber-800",
        info: "bg-blue-100 text-blue-800",
        neutral: "bg-slate-100 text-slate-700",
    };

    return (
        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${toneClasses[tone]}`}>
            {label}
        </span>
    );
}

function differenceStatus(value) {
    if (value === null || value === undefined) return "Belum dihitung";
    if (Number(value) === 0) return "Cocok";
    return Number(value) > 0 ? "Uang fisik lebih" : "Uang fisik kurang";
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
                                      : transaction.status === "cancelled"
                                        ? "Dibatalkan"
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

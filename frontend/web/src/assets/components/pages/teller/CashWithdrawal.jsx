import { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import {
    AlertCircle,
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    Clock3,
    Info,
    LoaderCircle,
    Lock,
    Printer,
    QrCode,
    RefreshCw,
    Search,
    ShieldCheck,
    UserRound,
    Wallet,
} from "lucide-react";

import { api } from "../../../../config/api";
import { useAuth } from "../../../../context/AuthContext";
import QrScannerModal from "../../common/QrScannerModal";

const MINIMUM_BALANCE = 10_000;
const LARGE_WITHDRAWAL_LIMIT = 1_000_000;
const QUICK_AMOUNTS = [10_000, 20_000, 50_000, 100_000];

const formatRupiah = (value) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value) || 0);

const formatTanggalWaktu = (value) => {
    if (!value) return "-";

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return "-";
    }

    return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "medium",
        timeStyle: "medium",
        timeZone: "Asia/Jakarta",
    }).format(date);
};

const getErrorMessage = (error) => {
    const validationErrors = error.response?.data?.errors;

    if (validationErrors) {
        return (
            Object.values(validationErrors).flat()[0] ??
            "Data transaksi tidak valid."
        );
    }

    return (
        error.response?.data?.message ??
        error.message ??
        "Terjadi kesalahan saat mengajukan penarikan."
    );
};

const terbilang = (input) => {
    const number = Math.floor(Number(input));

    if (!Number.isFinite(number) || number < 0) {
        return "";
    }

    if (number === 0) {
        return "nol rupiah";
    }

    const words = [
        "",
        "satu",
        "dua",
        "tiga",
        "empat",
        "lima",
        "enam",
        "tujuh",
        "delapan",
        "sembilan",
        "sepuluh",
        "sebelas",
    ];

    const spell = (n) => {
        if (n < 12) {
            return words[n];
        }

        if (n < 20) {
            return `${spell(n - 10)} belas`;
        }

        if (n < 100) {
            return `${spell(Math.floor(n / 10))} puluh${n % 10 ? ` ${spell(n % 10)}` : ""
                }`;
        }

        if (n < 200) {
            return `seratus${n > 100 ? ` ${spell(n - 100)}` : ""}`;
        }

        if (n < 1000) {
            return `${spell(Math.floor(n / 100))} ratus${n % 100 ? ` ${spell(n % 100)}` : ""
                }`;
        }

        if (n < 2000) {
            return `seribu${n > 1000 ? ` ${spell(n - 1000)}` : ""}`;
        }

        if (n < 1_000_000) {
            return `${spell(Math.floor(n / 1000))} ribu${n % 1000 ? ` ${spell(n % 1000)}` : ""
                }`;
        }

        if (n < 1_000_000_000) {
            return `${spell(Math.floor(n / 1_000_000))} juta${n % 1_000_000
                ? ` ${spell(n % 1_000_000)}`
                : ""
                }`;
        }

        if (n < 1_000_000_000_000) {
            return `${spell(Math.floor(n / 1_000_000_000))} miliar${n % 1_000_000_000
                ? ` ${spell(n % 1_000_000_000)}`
                : ""
                }`;
        }

        return number.toLocaleString("id-ID");
    };

    return `${spell(number)} rupiah`;
};

export default function CashWithdrawal() {
    const location = useLocation();
    const { user } = useAuth();

    const initialCustomer = location.state?.customer ?? null;
    const [customer, setCustomer] = useState(initialCustomer);
    const [identitySource, setIdentitySource] = useState(
        initialCustomer ? "account" : null
    );
    const [isLocked, setIsLocked] = useState(
        Boolean(initialCustomer || location.state?.locked || location.state?.fromQr)
    );
    const [isQrModalOpen, setIsQrModalOpen] = useState(false);

    const handleQrSuccess = (scannedCustomer) => {
        if (loading || result) return;

        setIsQrModalOpen(false);
        setCustomer(scannedCustomer);
        setIdentitySource("qr");
        setIsLocked(true);
        setAmount("");
        setError("");
        setResult(null);
    };

    const handleUnlockOrChangeCustomer = () => {
        if (loading) return;

        const confirmed = window.confirm(
            "Apakah Anda yakin ingin mengganti nasabah?\n\nFormulir transaksi saat ini akan dibersihkan dan kunci nasabah akan dibuka."
        );
        if (!confirmed) return;

        setIsLocked(false);
        setCustomer(null);
        setIdentitySource(null);
        setAmount("");
        setError("");
        setResult(null);
    };

    const [amount, setAmount] = useState("");
    const [loading, setLoading] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState("");
    const [result, setResult] = useState(null);
    const [statusSyncError, setStatusSyncError] = useState("");
    useEffect(() => {
        const transactionId = result?.transactionId;

        if (!transactionId) {
            return;
        }

        const isFinalStatus = (status) =>
            [
                "approved",
                "success",
                "completed",
                "confirmed",
                "rejected",
                "failed",
                "cancelled",
                "canceled",
            ].includes(String(status ?? "").toLowerCase());

        // Tidak perlu melakukan polling jika transaksi sudah selesai.
        if (isFinalStatus(result.status)) {
            return;
        }

        let isActive = true;
        let isChecking = false;

        const checkTransactionStatus = async () => {
            if (!isActive || isChecking) {
                return;
            }

            isChecking = true;

            try {
                // 1. Periksa status transaksi terbaru dari backend.
                const statusResponse = await api.get(
                    `/teller/transactions/${transactionId}/status`
                );

                if (!isActive) {
                    return;
                }

                const transaction = statusResponse.data?.data;

                if (!transaction) {
                    throw new Error(
                        "Data status transaksi tidak ditemukan."
                    );
                }

                const latestStatus = String(
                    transaction.status ?? "pending"
                ).toLowerCase();

                // 2. Perbarui status dan informasi transaksi.
                setResult((previous) => {
                    if (
                        !previous ||
                        String(previous.transactionId) !==
                        String(transactionId)
                    ) {
                        return previous;
                    }

                    return {
                        ...previous,
                        status: latestStatus,
                        rejectionReason:
                            transaction.rejection_reason ?? null,
                        approvedAt: transaction.approved_at ?? null,
                        rejectedAt: transaction.rejected_at ?? null,
                    };
                });

                setStatusSyncError("");

                // Jika ditolak atau masih pending, jangan mengubah saldo.
                if (latestStatus !== "approved") {
                    return;
                }

                // 3. Tentukan rekening nasabah yang melakukan transaksi.
                const accountNumber =
                    result.customer?.account_number ??
                    customer?.account_number;

                if (!accountNumber) {
                    setStatusSyncError(
                        "Status transaksi sudah disetujui, tetapi nomor rekening tidak tersedia untuk memperbarui saldo."
                    );
                    return;
                }

                // 4. Ambil saldo terbaru dari backend.
                try {
                    const customerResponse = await api.get(
                        `/teller/customers/${encodeURIComponent(
                            accountNumber
                        )}`
                    );

                    if (!isActive) {
                        return;
                    }

                    const latestCustomer =
                        customerResponse.data?.data;

                    if (!latestCustomer) {
                        throw new Error(
                            "Data nasabah terbaru tidak ditemukan."
                        );
                    }

                    if (
                        latestCustomer.balance === undefined ||
                        latestCustomer.balance === null
                    ) {
                        throw new Error(
                            "Saldo terbaru tidak tersedia dari backend."
                        );
                    }

                    const latestBalance = Number(
                        latestCustomer.balance
                    );

                    if (!Number.isFinite(latestBalance)) {
                        throw new Error(
                            "Saldo terbaru dari backend tidak valid."
                        );
                    }

                    // 5. Perbarui data nasabah yang sedang dipilih.
                    setCustomer((previous) => {
                        if (
                            previous &&
                            String(previous.id) ===
                            String(latestCustomer.id)
                        ) {
                            return {
                                ...previous,
                                ...latestCustomer,
                                balance: latestBalance,
                            };
                        }

                        return previous;
                    });

                    // 7. Perbarui saldo pada bukti transaksi.
                    setResult((previous) => {
                        if (
                            !previous ||
                            String(previous.transactionId) !==
                            String(transactionId)
                        ) {
                            return previous;
                        }

                        return {
                            ...previous,
                            status: latestStatus,
                            balance: latestBalance,
                            customer: {
                                ...previous.customer,
                                ...latestCustomer,
                                balance: latestBalance,
                            },
                        };
                    });

                    setStatusSyncError("");
                } catch (balanceError) {
                    if (!isActive) {
                        return;
                    }

                    console.error(
                        "Gagal menyinkronkan saldo:",
                        balanceError.response?.data?.message ??
                        balanceError.message
                    );

                    setStatusSyncError(
                        "Transaksi telah disetujui, tetapi saldo terbaru belum berhasil dimuat. Gunakan tombol Muat Ulang Data pada halaman rekening untuk memverifikasinya."
                    );
                }
            } catch (statusError) {
                if (!isActive) {
                    return;
                }

                console.error(
                    "Gagal memeriksa status transaksi:",
                    statusError.response?.data?.message ??
                    statusError.message
                );

                setStatusSyncError(
                    "Status transaksi belum berhasil diperiksa. Sistem akan mencoba kembali secara otomatis."
                );
            } finally {
                isChecking = false;
            }
        };

        // Periksa status segera setelah efek dijalankan.
        checkTransactionStatus();

        // Ulangi pemeriksaan setiap 3 detik.
        const intervalId = setInterval(
            checkTransactionStatus,
            3000
        );

        return () => {
            isActive = false;
            clearInterval(intervalId);
        };
    }, [
        result?.transactionId,
        result?.status,
        result?.customer?.account_number,
        customer?.account_number,
    ]);

    const nominal = Number(amount);

    const hasValidAmount =
        amount.trim() !== "" &&
        Number.isSafeInteger(nominal) &&
        nominal > 0;

    const currentBalance = Number(customer?.balance) || 0;

    const remainingBalance =
        currentBalance - (hasValidAmount ? nominal : 0);

    const leavesMinimumBalance =
        hasValidAmount && remainingBalance >= MINIMUM_BALANCE;

    const canTransact = customer?.status === "active";

    const requiresAdvanceConfirmation =
        hasValidAmount && nominal > LARGE_WITHDRAWAL_LIMIT;

    const handleRefresh = async () => {
        setError("");
        setRefreshing(true);

        try {
            if (customer?.account_number) {
                const response = await api.get(
                    `/teller/customers/${encodeURIComponent(
                        customer.account_number
                    )}`
                );

                const latestCustomer = response.data?.data;

                if (!latestCustomer) {
                    setError("Data nasabah tidak ditemukan.");
                    return;
                }

                setCustomer(latestCustomer);
            } else {
                setIsQrModalOpen(true);
            }
        } catch (requestError) {
            setError(getErrorMessage(requestError));
        } finally {
            setRefreshing(false);
        }
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        setError("");

        if (loading || result) return;

        if (!customer) {
            setError("Pilih nasabah terlebih dahulu.");
            return;
        }

        if (!canTransact) {
            setError(
                "Rekening nasabah tidak aktif. Permintaan penarikan tidak dapat diajukan."
            );
            return;
        }

        if (!hasValidAmount) {
            setError(
                "Nominal penarikan harus berupa bilangan bulat lebih dari Rp0."
            );
            return;
        }

        if (nominal > currentBalance) {
            setError(
                "Saldo nasabah tidak mencukupi untuk nominal penarikan tersebut."
            );
            return;
        }

        if (!leavesMinimumBalance) {
            setError(
                `Saldo setelah penarikan harus menyisakan minimal ${formatRupiah(
                    MINIMUM_BALANCE
                )}.`
            );
            return;
        }

        const previousBalance = currentBalance;

        setLoading(true);

        try {
            const response = await api.post("/teller/withdrawal", {
                customer_id: customer.id,
                amount: nominal,
            });

            const responseData = response.data ?? {};
            const transaction = responseData.data ?? {};

            const transactionStatus = String(
                transaction.status ?? "pending"
            ).toLowerCase();

            const updatedCustomer = { ...customer };

            let confirmedBalance = null;

            if (
                responseData.balance !== undefined &&
                responseData.balance !== null
            ) {
                confirmedBalance = Number(responseData.balance);
                updatedCustomer.balance = confirmedBalance;
            } else if (
                transaction.customer?.balance !== undefined &&
                transaction.customer?.balance !== null
            ) {
                confirmedBalance = Number(transaction.customer.balance);
                updatedCustomer.balance = confirmedBalance;
            } else if (customer.account_number) {
                try {
                    const customerResponse = await api.get(
                        `/teller/customers/${encodeURIComponent(
                            customer.account_number
                        )}`
                    );

                    const latestCustomer = customerResponse.data?.data;

                    if (latestCustomer) {
                        Object.assign(updatedCustomer, latestCustomer);

                        if (
                            latestCustomer.balance !== undefined &&
                            latestCustomer.balance !== null
                        ) {
                            confirmedBalance = Number(
                                latestCustomer.balance
                            );
                        }
                    }
                } catch {
                    confirmedBalance = null;
                }
            }

            setCustomer(updatedCustomer);

            setResult({
                message:
                    responseData.message ??
                    "Permintaan penarikan berhasil dibuat. Menunggu konfirmasi nasabah.",
                transactionId: transaction.id ?? null,
                transactionDate:
                    transaction.transaction_date ??
                    transaction.created_at ??
                    null,
                transactionType:
                    transaction.transaction_type ?? "withdrawal",
                status: transactionStatus,
                amount: Number(transaction.amount ?? nominal),
                previousBalance,
                balance: confirmedBalance,
                customer: updatedCustomer,
                tellerName:
                    user?.full_name ??
                    user?.name ??
                    user?.username ??
                    "Teller",
            });

            setAmount("");
        } catch (requestError) {
            setError(getErrorMessage(requestError));
        } finally {
            setLoading(false);
        }
    };

    const startNextWithdrawal = () => {
        if (isPending) {
            return;
        }

        setResult(null);
        setError("");
        setAmount("");
    };

    const resultCustomer = result?.customer ?? customer;
    const receiptAmount = Number(result?.amount) || 0;

    const resultStatus = String(
        result?.status ?? "pending"
    ).toLowerCase();

    const isApproved = [
        "approved",
        "success",
        "completed",
        "confirmed",
    ].includes(resultStatus);

    const isRejected = [
        "rejected",
        "failed",
    ].includes(resultStatus);

    const isCancelled = [
        "cancelled",
        "canceled",
    ].includes(resultStatus);

    const isPending = !isApproved && !isRejected && !isCancelled;

    const resultTitle = isApproved
        ? "Penarikan Disetujui"
        : isCancelled
            ? "Penarikan Dibatalkan"
        : isRejected
            ? "Penarikan Ditolak"
            : "Permintaan Penarikan Dibuat";

    const resultLabel = isApproved
        ? "Transaksi disetujui"
        : isCancelled
            ? "Dibatalkan"
        : isRejected
            ? "Transaksi ditolak"
            : "Menunggu konfirmasi nasabah";

    /*
     * PERBAIKAN WARNA:
     * - Pending (menunggu konfirmasi nasabah): Amber
     * - Rejected (ditolak): Merah (red)
     * - Approved (disetujui): Hijau (emerald)
     * Status pending tidak diubah menjadi approved.
     */
    const resultTheme = isPending
        ? {
            border: "border-amber-200",
            card: "border-amber-200 bg-amber-50",
            icon: "bg-amber-100 text-amber-700",
            label: "text-amber-700",
            amountBox: "bg-amber-50",
            amountText: "text-amber-800",
            badge: "bg-amber-100 text-amber-800",
            notice: "border-amber-200 bg-amber-50 text-amber-900",
        }
        : isRejected
            ? {
                border: "border-red-200",
                card: "border-red-200 bg-red-50",
                icon: "bg-red-100 text-red-700",
                label: "text-red-700",
                amountBox: "bg-red-50",
                amountText: "text-red-800",
                badge: "bg-red-50 text-red-700",
                notice:
                    "border-red-200 bg-red-50 text-red-900",
            }
            : isCancelled
                ? {
                    border: "border-slate-200",
                    card: "border-slate-200 bg-slate-50",
                    icon: "bg-slate-100 text-slate-700",
                    label: "text-slate-700",
                    amountBox: "bg-slate-50",
                    amountText: "text-slate-800",
                    badge: "bg-slate-100 text-slate-700",
                    notice: "border-slate-200 bg-slate-50 text-slate-800",
                }
            : {
                border: "border-emerald-200",
                card: "border-emerald-200 bg-emerald-50",
                icon: "bg-emerald-100 text-emerald-700",
                label: "text-emerald-700",
                amountBox: "bg-emerald-50",
                amountText: "text-emerald-800",
                badge: "bg-emerald-100 text-emerald-800",
                notice:
                    "border-emerald-200 bg-emerald-50 text-emerald-900",
            };

    const ResultIcon = isRejected || isCancelled
        ? AlertCircle
        : isApproved
            ? CheckCircle2
            : Clock3;

    return (
        <div className="mx-auto w-full max-w-7xl space-y-6">
            <style>{`
                @media print {
                    @page {
                        size: 80mm auto;
                        margin: 4mm;
                    }

                    body * {
                        visibility: hidden !important;
                    }

                    .receipt-print,
                    .receipt-print * {
                        visibility: visible !important;
                    }

                    .receipt-print {
                        position: absolute !important;
                        left: 0 !important;
                        top: 0 !important;
                        width: 72mm !important;
                        max-width: none !important;
                        margin: 0 !important;
                        padding: 0 !important;
                        border: 0 !important;
                        border-radius: 0 !important;
                        box-shadow: none !important;
                        background: #fff !important;
                        color: #000 !important;
                    }

                    .no-print {
                        display: none !important;
                    }
                }
            `}</style>

            <header className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <div className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-blue-700">
                        <Wallet size={16} />
                        Layanan Teller
                    </div>

                    <h1 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                        Penarikan Tunai
                    </h1>

                    <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
                        Ajukan permintaan penarikan tunai untuk nasabah.
                        Permintaan menunggu konfirmasi nasabah melalui
                        aplikasi sebelum transaksi dinyatakan selesai.
                    </p>
                </div>

                {!result && (
                    <button
                        type="button"
                        onClick={handleRefresh}
                        disabled={refreshing || loading}
                        className="no-print inline-flex items-center justify-center gap-2 self-start rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <RefreshCw
                            size={16}
                            className={refreshing ? "animate-spin" : ""}
                        />
                        Muat Ulang Data
                    </button>
                )}
            </header>

            {error && (
                <div
                    role="alert"
                    className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
                >
                    <AlertCircle size={19} className="mt-0.5 shrink-0" />
                    <p>{error}</p>
                </div>
            )}

            {!result ? (
                <>
                    {/* Modal QR Scanner */}
                    <QrScannerModal
                        isOpen={isQrModalOpen}
                        onClose={() => setIsQrModalOpen(false)}
                        onSuccess={handleQrSuccess}
                        title="Pindai QR Nasabah (Penarikan)"
                    />

                    {/* Pilih nasabah / Status Terkunci */}
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        {isLocked && customer ? (
                            <div className="space-y-3">
                                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 rounded-xl border border-blue-200 bg-blue-50/80 p-4">
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-white shadow-xs">
                                            <Lock size={22} />
                                        </div>
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="font-bold text-slate-900 text-base">
                                                    {customer.student_name}
                                                </h3>
                                                <span className="inline-flex items-center gap-1 rounded-full bg-blue-600 px-2.5 py-0.5 text-xs font-bold text-white shadow-xs">
                                                    <Lock size={12} /> Terkunci ({identitySource === "qr" ? "Scan QR" : "nomor rekening"})
                                                </span>
                                                <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                                                    customer.status === "active" ? "bg-emerald-100 text-emerald-700" : "bg-red-100 text-red-700"
                                                }`}>
                                                    {customer.status === "active" ? "Aktif" : "Diblokir"}
                                                </span>
                                            </div>
                                            <p className="mt-0.5 text-xs text-slate-600">
                                                No. Rekening: <span className="font-semibold text-slate-800">{customer.account_number}</span> • Saldo: <span className="font-bold text-emerald-700">{formatRupiah(customer.balance)}</span> • Tersedia Ditarik: <span className="font-bold text-blue-700">{formatRupiah(Math.max(0, (customer.balance || 0) - MINIMUM_BALANCE))}</span>
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={handleUnlockOrChangeCustomer}
                                            disabled={loading || isPending}
                                            className="rounded-lg border border-red-300 bg-white px-3.5 py-2 text-xs font-bold text-red-700 shadow-xs hover:bg-red-50 transition"
                                        >
                                            Ganti Nasabah
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                const confirmed = window.confirm("Pindai ulang QR Code? Data formulir saat ini akan dibersihkan.");
                                                if (confirmed) {
                                                    setIdentitySource(null);
                                                    setIsLocked(false);
                                                    setCustomer(null);
                                                    setAmount("");
                                                    setIsQrModalOpen(true);
                                                }
                                            }}
                                            disabled={loading || isPending}
                                            className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-2 text-xs font-bold text-white shadow-xs hover:bg-blue-700 transition"
                                        >
                                            <QrCode size={14} /> Scan Ulang
                                        </button>
                                    </div>
                                </div>
                                <p className="text-xs text-slate-500 italic">
                                    Identitas nasabah dikunci dari {identitySource === "qr" ? "hasil Scan QR" : "pencarian nomor rekening"}.
                                </p>
                            </div>
                        ) : (
                            <div>
                                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                    <div className="flex items-start gap-3">
                                        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                            <Search size={21} />
                                        </div>

                                        <div>
                                            <h2 className="font-semibold text-slate-900">
                                                1. Pilih Nasabah
                                            </h2>

                                            <p className="mt-1 text-sm leading-5 text-slate-500">
                                                Identifikasi dengan Scan QR atau cari nomor rekening tertentu.
                                            </p>
                                        </div>
                                    </div>

                                    <div className="w-full lg:max-w-xs">
                                        <button
                                            type="button"
                                            onClick={() => setIsQrModalOpen(true)}
                                            disabled={loading || Boolean(result)}
                                            className="inline-flex items-center justify-center gap-1.5 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-blue-700 transition shrink-0"
                                            title="Scan QR Nasabah"
                                        >
                                            <QrCode size={18} />
                                            <span>Scan QR</span>
                                        </button>
                                    </div>
                                </div>

                                <div className="mt-4 border-t border-slate-100 pt-3">
                                    <Link
                                        to="/teller/find-customer"
                                        className="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:text-blue-800"
                                    >
                                        <Search size={15} />
                                        Cari melalui halaman pencarian nasabah
                                    </Link>
                                </div>
                            </div>
                        )}
                    </section>

                    <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
                        <section className="space-y-5 xl:col-span-7">
                            <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                                <div className="flex items-start gap-3">
                                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                        <Wallet size={21} />
                                    </div>

                                    <div>
                                        <h2 className="font-semibold text-slate-900">
                                            2. Nominal Penarikan
                                        </h2>

                                        <p className="mt-1 text-sm leading-5 text-slate-500">
                                            Masukkan jumlah uang yang ingin
                                            ditarik oleh nasabah.
                                        </p>
                                    </div>
                                </div>

                                {customer ? (
                                    <form
                                        onSubmit={handleSubmit}
                                        className="mt-6 space-y-5"
                                    >
                                        <div>
                                            <label
                                                htmlFor="withdrawal-amount"
                                                className="mb-2 block text-sm font-semibold text-slate-700"
                                            >
                                                Nominal penarikan (Rupiah)
                                            </label>

                                            <div className="flex overflow-hidden rounded-xl border border-slate-200 transition focus-within:border-blue-500 focus-within:ring-4 focus-within:ring-blue-100">
                                                <span className="flex items-center border-r border-slate-200 bg-slate-50 px-4 text-sm font-semibold text-slate-600">
                                                    Rp
                                                </span>

                                                <input
                                                    id="withdrawal-amount"
                                                    type="number"
                                                    min="1"
                                                    step="1"
                                                    inputMode="numeric"
                                                    value={amount}
                                                    onChange={(event) => {
                                                        setAmount(
                                                            event.target.value
                                                        );
                                                        setError("");
                                                    }}
                                                    placeholder="Contoh: 10.000"
                                                    disabled={loading}
                                                    className="w-full min-w-0 px-4 py-3 text-lg font-semibold text-slate-900 outline-none disabled:bg-slate-100"
                                                />
                                            </div>

                                            <p className="mt-2 text-xs leading-5 text-slate-500">
                                                Masukkan angka bulat tanpa
                                                titik atau koma.
                                            </p>
                                        </div>

                                        <div>
                                            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                Pilihan nominal cepat
                                            </p>

                                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                                {QUICK_AMOUNTS.map(
                                                    (quickAmount) => (
                                                        <button
                                                            key={quickAmount}
                                                            type="button"
                                                            onClick={() => {
                                                                setAmount(
                                                                    String(
                                                                        quickAmount
                                                                    )
                                                                );
                                                                setError("");
                                                            }}
                                                            disabled={loading}
                                                            className={`rounded-xl border px-3 py-3 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 ${nominal ===
                                                                quickAmount
                                                                ? "border-blue-600 bg-blue-50 text-blue-700"
                                                                : "border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50"
                                                                }`}
                                                        >
                                                            {formatRupiah(
                                                                quickAmount
                                                            )}
                                                        </button>
                                                    )
                                                )}
                                            </div>
                                        </div>

                                        {hasValidAmount && (
                                            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                    Nominal dalam huruf
                                                </p>

                                                <p className="mt-2 text-sm font-medium leading-6 text-slate-800">
                                                    {terbilang(nominal)}
                                                </p>
                                            </div>
                                        )}

                                        {hasValidAmount &&
                                            nominal > currentBalance && (
                                                <div
                                                    role="alert"
                                                    className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
                                                >
                                                    <AlertCircle
                                                        size={18}
                                                        className="mt-0.5 shrink-0"
                                                    />
                                                    <p>
                                                        Saldo nasabah tidak
                                                        mencukupi untuk nominal
                                                        penarikan tersebut.
                                                    </p>
                                                </div>
                                            )}

                                        {hasValidAmount &&
                                            nominal <= currentBalance &&
                                            !leavesMinimumBalance && (
                                                <div
                                                    role="alert"
                                                    className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800"
                                                >
                                                    <Info
                                                        size={18}
                                                        className="mt-0.5 shrink-0"
                                                    />
                                                    <p>
                                                        Penarikan ini akan
                                                        menyisakan saldo kurang
                                                        dari{" "}
                                                        {formatRupiah(
                                                            MINIMUM_BALANCE
                                                        )}
                                                        . Kurangi nominal
                                                        penarikan.
                                                    </p>
                                                </div>
                                            )}

                                        {requiresAdvanceConfirmation && (
                                            <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                                <Info
                                                    size={19}
                                                    className="mt-0.5 shrink-0"
                                                />

                                                <div>
                                                    <p className="font-semibold">
                                                        Nominal penarikan besar
                                                    </p>

                                                    <p className="mt-1 leading-5">
                                                        Nominal ini melebihi{" "}
                                                        {formatRupiah(
                                                            LARGE_WITHDRAWAL_LIMIT
                                                        )}
                                                        . Pastikan identitas
                                                        nasabah dan nominal
                                                        sudah diperiksa sesuai
                                                        prosedur operasional.
                                                    </p>
                                                </div>
                                            </div>
                                        )}

                                        <div className="rounded-xl border border-blue-100 bg-blue-50 p-4">
                                            <div className="flex items-start gap-3">
                                                <Info
                                                    size={19}
                                                    className="mt-0.5 shrink-0 text-blue-700"
                                                />

                                                <div className="text-sm leading-6 text-blue-900">
                                                    <p className="font-semibold">
                                                        Konfirmasi nasabah
                                                        diperlukan
                                                    </p>

                                                    <p className="mt-1">
                                                        Permintaan akan dikirim
                                                        ke backend dan menunggu
                                                        konfirmasi nasabah
                                                        melalui aplikasi.
                                                        Jangan serahkan uang
                                                        tunai sebelum status
                                                        transaksi benar-benar
                                                        disetujui.
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex flex-col-reverse gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setAmount("");
                                                    setError("");
                                                }}
                                                disabled={loading}
                                                className="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                                            >
                                                Bersihkan Form
                                            </button>

                                            <button
                                                type="submit"
                                                disabled={
                                                    loading ||
                                                    !canTransact ||
                                                    !hasValidAmount ||
                                                    !leavesMinimumBalance
                                                }
                                                className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {loading ? (
                                                    <>
                                                        <LoaderCircle
                                                            size={18}
                                                            className="animate-spin"
                                                        />
                                                        Mengajukan Penarikan...
                                                    </>
                                                ) : (
                                                    <>
                                                        Ajukan Penarikan
                                                        <ArrowRight size={18} />
                                                    </>
                                                )}
                                            </button>
                                        </div>
                                    </form>
                                ) : (
                                    <div className="mt-6 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-10 text-center">
                                        <UserRound
                                            size={30}
                                            className="mx-auto text-slate-400"
                                        />

                                        <p className="mt-3 text-sm font-semibold text-slate-700">
                                            Nasabah belum dipilih
                                        </p>

                                        <p className="mt-1 text-sm text-slate-500">
                                            Pilih nasabah terlebih dahulu
                                            sebelum mengajukan penarikan.
                                        </p>
                                    </div>
                                )}
                            </div>
                        </section>

                        <aside className="space-y-4 xl:sticky xl:top-20 xl:col-span-5 xl:self-start">
                            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                                <div className="flex items-center justify-between gap-3">
                                    <h2 className="font-semibold text-slate-900">
                                        Ringkasan Rekening
                                    </h2>

                                    {customer && (
                                        <StatusBadge
                                            status={customer.status}
                                        />
                                    )}
                                </div>

                                {customer ? (
                                    <>
                                        <div className="mt-5 flex items-center gap-3 rounded-xl bg-slate-50 p-4">
                                            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700">
                                                <UserRound size={24} />
                                            </div>

                                            <div className="min-w-0">
                                                <p className="truncate font-semibold text-slate-900">
                                                    {customer.student_name ||
                                                        "Nama belum tersedia"}
                                                </p>

                                                <p className="mt-1 text-sm text-slate-600">
                                                    Rekening:{" "}
                                                    {customer.account_number ||
                                                        "-"}
                                                </p>

                                            </div>
                                        </div>

                                        <div className="mt-5 space-y-3">
                                            <SummaryRow
                                                label="Saldo saat ini"
                                                value={formatRupiah(
                                                    currentBalance
                                                )}
                                            />

                                            <SummaryRow
                                                label="Nominal penarikan"
                                                value={
                                                    hasValidAmount
                                                        ? `− ${formatRupiah(
                                                            nominal
                                                        )}`
                                                        : "—"
                                                }
                                                valueClass="text-red-700"
                                            />

                                            <div className="rounded-xl bg-blue-50 p-4">
                                                <p className="text-xs font-semibold uppercase tracking-wide text-blue-800">
                                                    Estimasi saldo setelah
                                                    penarikan
                                                </p>

                                                <p className="mt-2 break-words text-2xl font-bold tracking-tight text-blue-800">
                                                    {formatRupiah(
                                                        hasValidAmount
                                                            ? remainingBalance
                                                            : currentBalance
                                                    )}
                                                </p>

                                                <p className="mt-1 text-xs leading-5 text-blue-700">
                                                    Ini hanya estimasi. Saldo
                                                    resmi mengikuti hasil
                                                    pemrosesan dan konfirmasi
                                                    transaksi oleh backend.
                                                </p>
                                            </div>
                                        </div>
                                    </>
                                ) : (
                                    <div className="mt-5 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
                                        <Wallet
                                            size={26}
                                            className="mx-auto text-slate-400"
                                        />

                                        <p className="mt-3 text-sm font-semibold text-slate-700">
                                            Ringkasan belum tersedia
                                        </p>

                                        <p className="mt-1 text-sm text-slate-500">
                                            Pilih nasabah untuk melihat
                                            informasi rekening.
                                        </p>
                                    </div>
                                )}
                            </section>

                            <section className="rounded-xl border border-slate-200 bg-white mb-4 p-4">
                                <div className="flex items-start gap-3">
                                    <ShieldCheck
                                        size={19}
                                        className="mt-0.5 shrink-0 text-slate-500"
                                    />

                                    <div>
                                        <h3 className="text-sm font-semibold text-slate-800">
                                            Pemeriksaan sebelum mengajukan
                                        </h3>

                                        <ul className="mt-2 list-disc space-y-2 pl-4 text-sm leading-5 text-slate-500">
                                            <li>
                                                Pastikan identitas dan nomor
                                                rekening nasabah benar.
                                            </li>
                                            <li>
                                                Pastikan saldo mencukupi dan
                                                nominal penarikan sesuai
                                                permintaan nasabah.
                                            </li>
                                            <li>
                                                Tunggu konfirmasi transaksi
                                                sebelum menyerahkan uang tunai.
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </section>
                        </aside>
                    </div>
                </>
            ) : (
                <div className="space-y-5">
                    <section
                        className={`rounded-2xl border p-5 shadow-sm sm:p-6 ${resultTheme.card}`}
                    >
                        <div className="flex flex-col gap-4 border-b border-slate-200/70 pb-5 sm:flex-row sm:items-start sm:justify-between">
                            <div className="flex items-start gap-3">
                                <div
                                    className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-full ${resultTheme.icon}`}
                                >
                                    <ResultIcon size={27} />
                                </div>

                                <div>
                                    <p
                                        className={`text-xs font-bold uppercase tracking-wider ${resultTheme.label}`}
                                    >
                                        {resultLabel}
                                    </p>

                                    <h2 className="mt-1 text-xl font-bold text-slate-900 sm:text-2xl">
                                        {resultTitle}
                                    </h2>

                                    <p className="mt-1 text-sm leading-6 text-slate-600">
                                        {result.message}
                                    </p>
                                </div>
                            </div>

                            <div className="rounded-xl bg-white/80 px-4 py-3 sm:text-right">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    ID transaksi
                                </p>

                                <p className="mt-1 font-mono text-sm font-bold text-slate-900">
                                    {result.transactionId ?? "Tidak tersedia"}
                                </p>

                                <p className="mt-1 text-xs text-slate-500">
                                    {formatTanggalWaktu(
                                        result.transactionDate
                                    )}
                                </p>
                            </div>
                        </div>

                        <div className="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-12">
                            <div className="space-y-4 lg:col-span-7">
                                <div
                                    className={`rounded-xl p-5 ${resultTheme.amountBox}`}
                                >
                                    <p
                                        className={`text-xs font-semibold uppercase tracking-wide ${resultTheme.amountText}`}
                                    >
                                        Nominal penarikan
                                    </p>

                                    <p
                                        className={`mt-2 break-words text-3xl font-bold tracking-tight ${resultTheme.amountText}`}
                                    >
                                        {formatRupiah(receiptAmount)}
                                    </p>

                                    <p
                                        className={`mt-2 text-sm italic ${resultTheme.amountText}`}
                                    >
                                        {terbilang(receiptAmount)}
                                    </p>
                                </div>

                                <div className="rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
                                    <h3 className="font-semibold text-slate-900">
                                        Rincian Permintaan
                                    </h3>

                                    <div className="mt-3 divide-y divide-slate-100">
                                        <SummaryRow
                                            label="Nama nasabah"
                                            value={
                                                resultCustomer?.student_name ||
                                                "-"
                                            }
                                        />

                                        <SummaryRow
                                            label="Nomor rekening"
                                            value={
                                                resultCustomer?.account_number ||
                                                "-"
                                            }
                                        />


                                        <SummaryRow
                                            label="Saldo sebelum permintaan"
                                            value={formatRupiah(
                                                result.previousBalance
                                            )}
                                        />

                                        <SummaryRow
                                            label="Petugas Teller"
                                            value={
                                                result.tellerName || "Teller"
                                            }
                                        />

                                        <SummaryRow
                                            label="Status"
                                            value={resultLabel}
                                        />
                                    </div>

                                    <div className="mt-4 rounded-xl bg-blue-50 p-4">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-blue-800">
                                            Saldo terbaru dari backend
                                        </p>

                                        {result.balance !== null &&
                                            result.balance !== undefined ? (
                                            <p className="mt-1 text-2xl font-bold text-blue-800">
                                                {formatRupiah(result.balance)}
                                            </p>
                                        ) : (
                                            <p className="mt-1 text-sm leading-5 text-blue-800">
                                                Saldo terbaru belum berhasil
                                                diverifikasi. Muat ulang detail
                                                rekening sebelum melanjutkan.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div className="lg:col-span-5">
                                <div className="mb-2 flex items-center justify-between gap-2">
                                    <h3 className="flex items-center gap-2 font-semibold text-slate-900">
                                        <Wallet size={18} />
                                        Bukti Permintaan
                                    </h3>

                                    <span
                                        className={`rounded-full px-2.5 py-1 text-xs font-semibold ${resultTheme.badge}`}
                                    >
                                        {resultLabel}
                                    </span>
                                </div>

                                <div className="receipt-print mx-auto w-full max-w-sm rounded-xl border border-dashed border-slate-300 bg-white p-5 font-mono text-xs text-slate-800 sm:p-6">
                                    <div className="border-b border-dashed border-slate-300 pb-4 text-center">
                                        <p className="text-sm font-bold tracking-wide">
                                            E-TELLER BANK MINI SEKOLAH
                                        </p>

                                        <p className="mt-1 text-[10px] text-slate-500">
                                            BUKTI PERMINTAAN PENARIKAN TUNAI
                                        </p>
                                    </div>

                                    <div className="space-y-2 border-b border-dashed border-slate-300 py-4">
                                        <ReceiptRow
                                            label="ID TRANSAKSI"
                                            value={result.transactionId ?? "-"}
                                        />

                                        <ReceiptRow
                                            label="WAKTU"
                                            value={formatTanggalWaktu(
                                                result.transactionDate
                                            )}
                                        />

                                        <ReceiptRow
                                            label="TELLER"
                                            value={result.tellerName || "-"}
                                        />
                                    </div>

                                    <div className="space-y-2 border-b border-dashed border-slate-300 py-4">
                                        <ReceiptRow
                                            label="NASABAH"
                                            value={
                                                resultCustomer?.student_name ||
                                                "-"
                                            }
                                        />

                                        <ReceiptRow
                                            label="REKENING"
                                            value={
                                                resultCustomer?.account_number ||
                                                "-"
                                            }
                                        />

                                    </div>

                                    <div className="space-y-2 border-b border-dashed border-slate-300 py-4">
                                        <ReceiptRow
                                            label="JENIS"
                                            value="PENARIKAN TUNAI"
                                        />

                                        <ReceiptRow
                                            label="NOMINAL"
                                            value={formatRupiah(receiptAmount)}
                                            prominent
                                        />

                                        <ReceiptRow
                                            label="STATUS"
                                            value={resultLabel.toUpperCase()}
                                            prominent
                                        />

                                        {result.balance !== null &&
                                            result.balance !== undefined && (
                                                <ReceiptRow
                                                    label="SALDO DARI BACKEND"
                                                    value={formatRupiah(
                                                        result.balance
                                                    )}
                                                    prominent
                                                />
                                            )}
                                    </div>

                                    <div className="pt-4 text-center">
                                        <p className="font-bold tracking-wide">
                                            {isApproved
                                                ? "TRANSAKSI DISETUJUI"
                                                : isCancelled
                                                    ? "TRANSAKSI DIBATALKAN"
                                                : isRejected
                                                    ? "TRANSAKSI DITOLAK"
                                                    : "MENUNGGU KONFIRMASI NASABAH"}
                                        </p>

                                        <p className="mt-2 leading-5 text-slate-500">
                                            {isApproved
                                                ? "Pastikan proses penyerahan uang mengikuti prosedur operasional."
                                                : isCancelled
                                                    ? "Transaksi dibatalkan nasabah. Tidak ada saldo yang berubah."
                                                : isRejected
                                                    ? "Permintaan ini tidak boleh diproses sebagai penarikan yang disetujui."
                                                    : "Bukti ini bukan bukti penarikan selesai. Tunggu konfirmasi nasabah."}
                                        </p>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <div className="no-print mt-6 flex flex-col-reverse gap-3 border-t border-slate-200/70 pt-5 sm:flex-row sm:items-center sm:justify-between">
                            <Link
                                to={`/teller/customers/${encodeURIComponent(
                                    resultCustomer?.account_number ?? ""
                                )}`}
                                state={{ customer: resultCustomer }}
                                className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                            >
                                <ArrowLeft size={17} />
                                Kembali ke Detail Nasabah
                            </Link>

                            <div className="flex flex-col gap-3 sm:flex-row">
                                <button
                                    type="button"
                                    onClick={() => window.print()}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                                >
                                    <Printer size={17} />
                                    Cetak Bukti
                                </button>

                                <button
                                    type="button"
                                    onClick={startNextWithdrawal}
                                    disabled={isPending}
                                    className={`rounded-lg px-4 py-2 font-medium transition ${isPending
                                        ? "cursor-not-allowed bg-gray-300 text-gray-500"
                                        : "bg-blue-600 text-white hover:bg-blue-700"
                                        }`}
                                >
                                    {isPending
                                        ? "Menunggu Konfirmasi Nasabah"
                                        : "Penarikan Berikutnya"}
                                </button>
                            </div>
                        </div>
                    </section>
                    {isPending && (
                        <div
                            className="no-print rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900"
                            role="status"
                            aria-live="polite"
                        >
                            <div className="flex items-start gap-3">
                                <RefreshCw
                                    size={18}
                                    className="mt-0.5 shrink-0 animate-spin"
                                />

                                <div>
                                    <p className="font-semibold">
                                        Memeriksa status transaksi
                                    </p>

                                    <p className="mt-1 leading-6">
                                        Sistem akan memperbarui status secara otomatis
                                        setelah nasabah mengonfirmasi transaksi.
                                    </p>

                                    {statusSyncError && (
                                        <p className="mt-2 text-red-700">
                                            {statusSyncError}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    {isPending && (
                        <div
                            className={`no-print flex items-start gap-3 rounded-xl border p-4 text-sm ${resultTheme.notice}`}
                        >
                            <Clock3
                                size={19}
                                className="mt-0.5 shrink-0"
                            />

                            <div>
                                <p className="font-semibold">
                                    Menunggu konfirmasi nasabah
                                </p>

                                <p className="mt-1 leading-6">
                                    Permintaan berhasil dibuat, tetapi belum
                                    dinyatakan disetujui. Nasabah perlu
                                    mengonfirmasi transaksi melalui aplikasi.
                                    Jangan menyerahkan uang tunai sebelum
                                    status transaksi terverifikasi sebagai
                                    disetujui.
                                </p>
                            </div>
                        </div>
                    )}

                    {isApproved && (
                        <div className="no-print flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                            <ShieldCheck
                                size={19}
                                className="mt-0.5 shrink-0"
                            />

                            <p className="leading-6">
                                Status transaksi menunjukkan persetujuan.
                                Tetap periksa status terbaru dan ikuti prosedur
                                operasional sebelum menyerahkan uang tunai.
                            </p>
                        </div>
                    )}

                    {isRejected && (
                        <div className="no-print flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                            <AlertCircle
                                size={19}
                                className="mt-0.5 shrink-0"
                            />

                            <p className="leading-6">
                                Permintaan ini ditolak atau gagal. Jangan
                                menyerahkan uang tunai berdasarkan permintaan
                                ini. Periksa status rekening dan detail
                                transaksi sebelum mengajukan permintaan baru.
                            </p>
                        </div>
                    )}
                    {isCancelled && (
                        <div className="no-print flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-800">
                            <AlertCircle
                                size={19}
                                className="mt-0.5 shrink-0"
                            />

                            <p className="leading-6">
                                Transaksi dibatalkan nasabah. Saldo tidak berubah
                                dan penarikan ini tidak boleh diproses.
                            </p>
                        </div>
                    )}
                </div>
            )}
        </div>
    );

}

function SummaryRow({ label, value, valueClass = "" }) {
    return (
        <div className="flex items-start justify-between gap-4 py-3 text-sm">
            <span className="text-slate-500">{label}</span>

            <span
                className={`max-w-[65%] break-words text-right font-semibold text-slate-800 ${valueClass}`}
            >
                {value}
            </span>
        </div>
    );
}

function ReceiptRow({ label, value, prominent = false }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <span className="shrink-0 text-slate-500">{label}</span>

            <span
                className={`break-words text-right ${prominent
                    ? "font-bold text-black"
                    : "font-semibold text-slate-800"
                    }`}
            >
                {value}
            </span>
        </div>
    );
}

function StatusBadge({ status }) {
    const normalizedStatus = String(status ?? "").toLowerCase();

    const config =
        normalizedStatus === "active"
            ? {
                label: "Aktif",
                className: "bg-emerald-50 text-emerald-700",
            }
            : normalizedStatus === "blocked"
                ? {
                    label: "Diblokir",
                    className: "bg-red-50 text-red-700",
                }
                : {
                    label: status || "Tidak diketahui",
                    className: "bg-slate-100 text-slate-600",
                };

    return (
        <span
            className={`inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${config.className}`}
        >
            {config.label}
        </span>
    );
}
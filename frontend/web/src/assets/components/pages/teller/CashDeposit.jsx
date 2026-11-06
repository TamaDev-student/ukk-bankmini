import { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import {
    AlertCircle,
    ArrowLeft,
    ArrowRight,
    CheckCircle2,
    CircleHelp,
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

const formatRupiah = (value) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value) || 0);

const formatTanggalWaktu = (value) => {
    if (!value) return "-";

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "-";

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
        "Terjadi kesalahan saat memproses setoran."
    );
};

const terbilang = (input) => {
    const number = Math.floor(Number(input));
    if (!Number.isFinite(number) || number < 0) return "";
    if (number === 0) return "nol rupiah";

    const words = [
        "", "satu", "dua", "tiga", "empat", "lima", "enam",
        "tujuh", "delapan", "sembilan", "sepuluh", "sebelas",
    ];

    const spell = (n) => {
        if (n < 12) return words[n];
        if (n < 20) return `${spell(n - 10)} belas`;
        if (n < 100) {
            return `${spell(Math.floor(n / 10))} puluh${n % 10 ? ` ${spell(n % 10)}` : ""}`;
        }
        if (n < 200) return `seratus${n > 100 ? ` ${spell(n - 100)}` : ""}`;
        if (n < 1000) {
            return `${spell(Math.floor(n / 100))} ratus${n % 100 ? ` ${spell(n % 100)}` : ""}`;
        }
        if (n < 2000) return `seribu${n > 1000 ? ` ${spell(n - 1000)}` : ""}`;
        if (n < 1_000_000) {
            return `${spell(Math.floor(n / 1000))} ribu${n % 1000 ? ` ${spell(n % 1000)}` : ""}`;
        }
        if (n < 1_000_000_000) {
            return `${spell(Math.floor(n / 1_000_000))} juta${n % 1_000_000 ? ` ${spell(n % 1_000_000)}` : ""}`;
        }
        if (n < 1_000_000_000_000) {
            return `${spell(Math.floor(n / 1_000_000_000))} miliar${n % 1_000_000_000 ? ` ${spell(n % 1_000_000_000)}` : ""}`;
        }
        return number.toLocaleString("id-ID");
    };

    return `${spell(number)} rupiah`;
};

const QUICK_AMOUNTS = [10_000, 20_000, 50_000, 100_000];

export default function CashDeposit() {
    const location = useLocation();
    const { user } = useAuth();

    const initialCustomer = location.state?.customer ?? null;
    const [customer, setCustomer] = useState(initialCustomer);
    const [scanToken, setScanToken] = useState(null);
    const [customerLocked, setCustomerLocked] = useState(Boolean(initialCustomer));
    const [isQrModalOpen, setIsQrModalOpen] = useState(false);
    const [validatingSelectedCustomer, setValidatingSelectedCustomer] = useState(
        Boolean(initialCustomer)
    );

    const handleQrSuccess = (scannedCustomer, verifiedScanToken) => {
        if (customerLocked || loading || pendingTransaction) return;
        if (!scannedCustomer?.id || !verifiedScanToken) {
            setIsQrModalOpen(false);
            setError("Verifikasi QR tidak mengembalikan identitas dan token scan yang sah.");
            return;
        }

        setIsQrModalOpen(false);
        setCustomer(scannedCustomer);
        setScanToken(verifiedScanToken);
        setCustomerLocked(true);
        setAmount("");
        setChecks({ cashReceived: false, cashChecked: false, slipMatches: false });
        setError("");
        setResult(null);
    };

    const handleUnlockOrChangeCustomer = () => {
        if (loading || pendingTransaction) return;

        const confirmed = window.confirm(
            "Apakah Anda yakin ingin mengganti nasabah?\n\nData formulir yang belum diproses akan dibersihkan. Transaksi yang sudah tercatat tetap tersimpan."
        );
        if (!confirmed) return;

        setIsQrModalOpen(false);
        setCustomerLocked(false);
        setScanToken(null);
        setCustomer(null);
        setValidatingSelectedCustomer(false);
        setAmount("");
        setChecks({ cashReceived: false, cashChecked: false, slipMatches: false });
        setError("");
        setTransactionError("");
        setResult(null);
    };

    const [amount, setAmount] = useState("");
    const [checks, setChecks] = useState({
        cashReceived: false,
        cashChecked: false,
        slipMatches: false,
    });
    const [loading, setLoading] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState("");
    const [result, setResult] = useState(null);
    const [pendingTransaction, setPendingTransaction] = useState(null);
    const [checkingStatus, setCheckingStatus] = useState(false);
    const [transactionError, setTransactionError] = useState("");

    useEffect(() => {
        if (!initialCustomer?.account_number) return undefined;

        let cancelled = false;

        const validateSelectedCustomer = async () => {
            try {
                const response = await api.post(
                    `/teller/customers/${encodeURIComponent(initialCustomer.account_number)}/scan-token`
                );
                const data = response.data?.data;

                if (!data?.customer?.id || !data?.scan_token) {
                    throw new Error("Backend tidak mengembalikan data nasabah dan token transaksi yang sah.");
                }

                if (!cancelled) {
                    setCustomer(data.customer);
                    setScanToken(data.scan_token);
                }
            } catch (requestError) {
                if (!cancelled) {
                    setError(getErrorMessage(requestError));
                }
            } finally {
                if (!cancelled) {
                    setValidatingSelectedCustomer(false);
                }
            }
        };

        validateSelectedCustomer();

        return () => {
            cancelled = true;
        };
    }, [initialCustomer]);

    const nominal = Number(amount);
    const MIN_DEPOSIT = 10000;
    const validAmount =
        amount.trim() !== "" &&
        Number.isSafeInteger(nominal) &&
        nominal >= MIN_DEPOSIT;
    const currentBalance = Number(customer?.balance) || 0;
    const estimatedBalance = currentBalance + (validAmount ? nominal : 0);
    const allChecksComplete = Object.values(checks).every(Boolean);
    const canTransact = customer?.status === "active";

    const handleRefresh = async () => {
        setError("");
        setRefreshing(true);
        try {
            if (customer?.account_number) {
                const response = await api.post(
                    `/teller/customers/${encodeURIComponent(customer.account_number)}/scan-token`
                );
                const data = response.data?.data;
                const latestCustomer = data?.customer;

                if (!latestCustomer || !data?.scan_token) {
                    setError("Backend tidak mengembalikan data nasabah dan token transaksi yang sah.");
                    return;
                }

                setCustomer(latestCustomer);
                setScanToken(data.scan_token);
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
        setTransactionError("");

        if (loading || pendingTransaction || result) return;
        if (!customer) {
            setError("Pilih nasabah terlebih dahulu.");
            return;
        }
        if (!canTransact) {
            setError("Rekening nasabah tidak aktif atau diblokir. Setoran tidak dapat diproses.");
            return;
        }
        if (!validAmount) {
            setError("Minimal setoran adalah Rp10.000.");
            return;
        }
        if (!allChecksComplete) {
            setError("Lengkapi pemeriksaan uang fisik dan slip sebelum melanjutkan.");
            return;
        }
        if (!scanToken) {
            setError(
                validatingSelectedCustomer
                    ? "Sedang memvalidasi nasabah. Tunggu sebentar."
                    : "Identitas nasabah belum tervalidasi. Silakan pilih nasabah kembali."
            );
            if (!customerLocked) setIsQrModalOpen(true);
            return;
        }

        const customerSnapshot = { ...customer };
        const previousBalance = currentBalance;
        const amountToDeposit = nominal;
        setLoading(true);

        try {
            const response = await api.post("/teller/deposit", {
                customer_id: customerSnapshot.id,
                amount: amountToDeposit,
                scan_token: scanToken,
            });
            setScanToken(null);

            const transaction = response.data?.data;
            if (!transaction?.id) {
                // POST sudah mungkin tercatat di server. Jangan otomatis mengirim ulang.
                setError("Server tidak mengembalikan ID transaksi. Periksa riwayat transaksi sebelum mencoba kembali agar tidak terjadi setoran ganda.");
                return;
            }

            if (transaction.status && transaction.status !== "pending") {
                setError(`Transaksi tercatat dengan status '${transaction.status}'. Periksa status transaksi sebelum membuat setoran baru.`);
                return;
            }

            setPendingTransaction({
                id: transaction.id,
                customerId: customerSnapshot.id,
                customer: customerSnapshot,
                amount: Number(transaction.amount) || amountToDeposit,
                previousBalance,
                transactionDate: transaction.transaction_date ?? transaction.created_at ?? new Date().toISOString(),
                tellerName: user?.full_name ?? user?.name ?? user?.username ?? "Teller",
            });
            setAmount("");
            setChecks({ cashReceived: false, cashChecked: false, slipMatches: false });
        } catch (requestError) {
            if ([409, 410].includes(requestError.response?.status)) {
                setScanToken(null);
                setCustomerLocked(false);
                setIsQrModalOpen(true);
            }
            setError(`${getErrorMessage(requestError)} Jika koneksi terputus saat pengiriman, periksa riwayat transaksi sebelum mengirim ulang.`);
        } finally {
            setLoading(false);
        }
    };

    // Pantau status dari backend. Slip keberhasilan hanya dibuat setelah status approved.
    useEffect(() => {
        if (!pendingTransaction) return undefined;

        let cancelled = false;
        let timerId = null;
        let requestInProgress = false;

        const checkStatus = async () => {
            if (cancelled || requestInProgress) return;
            requestInProgress = true;
            if (!cancelled) setCheckingStatus(true);

            let shouldContinuePolling = true;
            try {
                const response = await api.get(`/teller/transactions/${pendingTransaction.id}/status`);
                if (cancelled) return;

                const transaction = response.data?.data;
                if (!transaction) {
                    setTransactionError("Data status transaksi belum tersedia dari server.");
                } else if (transaction.status === "approved") {
                    shouldContinuePolling = false;
                    let updatedCustomer = pendingTransaction.customer;
                    let confirmedBalance = null;

                    try {
                        const customerResponse = await api.get(
                            `/teller/customers/${encodeURIComponent(pendingTransaction.customer.account_number)}`
                        );
                        if (cancelled) return;
                        if (customerResponse.data?.data) {
                            updatedCustomer = customerResponse.data.data;
                            confirmedBalance = updatedCustomer.balance ?? null;
                        }
                    } catch {
                        // Jangan menampilkan saldo lama sebagai saldo resmi bila refresh gagal.
                    }

                    if (cancelled) return;
                    setCustomer(updatedCustomer);
                    setResult({
                        message: "Setoran telah dikonfirmasi oleh nasabah dan disahkan oleh backend.",
                        transactionId: transaction.id,
                        transactionDate: transaction.approved_at ?? transaction.transaction_date ?? pendingTransaction.transactionDate,
                        transactionType: transaction.transaction_type ?? "deposit",
                        amount: Number(transaction.amount) || pendingTransaction.amount,
                        previousBalance: pendingTransaction.previousBalance,
                        balance: confirmedBalance,
                        customer: updatedCustomer,
                        tellerName: pendingTransaction.tellerName,
                    });
                    setPendingTransaction(null);
                    setTransactionError("");
                } else if (transaction.status === "rejected") {
                    shouldContinuePolling = false;
                    setPendingTransaction(null);
                    setError(transaction.rejection_reason
                        ? `Transaksi ditolak oleh nasabah: ${transaction.rejection_reason}`
                        : "Transaksi ditolak oleh nasabah. Tidak ada saldo yang ditambahkan.");
                    setTransactionError("");
                } else if (transaction.status === "cancelled") {
                    shouldContinuePolling = false;
                    setPendingTransaction(null);
                    setError("Transaksi dibatalkan oleh nasabah. Status: Dibatalkan. Saldo tidak berubah.");
                    setTransactionError("");
                } else if (transaction.status === "pending") {
                    setTransactionError("");
                } else {
                    setTransactionError(`Status transaksi tidak dikenali: ${transaction.status ?? "kosong"}. Hubungi administrator untuk pemeriksaan.`);
                }
            } catch (requestError) {
                if (!cancelled) {
                    setTransactionError(`Belum dapat memeriksa status transaksi. ${getErrorMessage(requestError)}`);
                }
            } finally {
                requestInProgress = false;
                if (!cancelled) setCheckingStatus(false);
                if (!cancelled && shouldContinuePolling) {
                    timerId = window.setTimeout(checkStatus, 3000);
                }
            }
        };

        checkStatus();
        return () => {
            cancelled = true;
            if (timerId !== null) window.clearTimeout(timerId);
        };
    }, [pendingTransaction]);

    const startNextDeposit = () => {
        handleUnlockOrChangeCustomer();
    };

    const resultCustomer = result?.customer ?? customer;
    const receiptAmount = Number(result?.amount) || 0;

    return (
        <div className="mx-auto w-full max-w-7xl space-y-6">
            <QrScannerModal
                isOpen={isQrModalOpen && !customerLocked && !loading && !pendingTransaction}
                onClose={() => setIsQrModalOpen(false)}
                onSuccess={handleQrSuccess}
                scanPurpose="cash_deposit"
                title="Pindai QR Nasabah (Setoran)"
            />

            <style>{`
                @media print {
                    @page { size: 80mm auto; margin: 4mm; }
                    body * { visibility: hidden !important; }
                    .receipt-print, .receipt-print * { visibility: visible !important; }
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
                    .no-print { display: none !important; }
                }
            `}</style>

            <header className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <div className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-blue-700">
                        <Wallet size={16} />
                        Layanan Teller
                    </div>
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                        Setoran Tunai
                    </h1>
                    <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
                        Catat setoran setelah uang fisik diterima dan dihitung oleh Teller.
                    </p>
                </div>

                {!result && !pendingTransaction && (
                    <button
                        type="button"
                        onClick={handleRefresh}
                        disabled={refreshing || loading}
                        className="no-print inline-flex items-center justify-center gap-2 self-start rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <RefreshCw size={16} className={refreshing ? "animate-spin" : ""} />
                        Muat Ulang Data
                    </button>
                )}
            </header>

            {error && (
                <div role="alert" className="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                    <AlertCircle size={19} className="mt-0.5 shrink-0" />
                    <p>{error}</p>
                </div>
            )}

            {pendingTransaction ? (
                <section className="rounded-2xl border border-amber-200 bg-white p-6 shadow-sm sm:p-8">
                    <div className="mx-auto max-w-2xl text-center">
                        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                            {checkingStatus ? <LoaderCircle size={32} className="animate-spin" /> : <ShieldCheck size={32} />}
                        </div>
                        <p className="mt-5 text-xs font-bold uppercase tracking-wider text-amber-700">Menunggu konfirmasi</p>
                        <h2 className="mt-2 text-2xl font-bold text-slate-900">Setoran Belum Selesai</h2>
                        <p className="mx-auto mt-3 max-w-xl text-sm leading-6 text-slate-600">
                            Transaksi #{pendingTransaction.id} sudah dicatat dan menunggu nasabah mengonfirmasi melalui aplikasi. Saldo belum dianggap bertambah dan slip keberhasilan belum tersedia.
                        </p>
                        <div className="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4 text-left">
                            <SummaryRow label="Nama nasabah" value={pendingTransaction.customer?.student_name || "-"} />
                            <SummaryRow label="Nomor rekening" value={pendingTransaction.customer?.account_number || "-"} />
                            <SummaryRow label="Nominal setoran" value={formatRupiah(pendingTransaction.amount)} valueClass="text-blue-700" />
                            <SummaryRow label="Status" value="Menunggu konfirmasi nasabah" valueClass="text-amber-700" />
                        </div>
                        {transactionError && (
                            <div role="alert" className="mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-left text-sm text-amber-900">
                                <AlertCircle size={18} className="mt-0.5 shrink-0" />
                                <p>{transactionError}</p>
                            </div>
                        )}
                        <p className="mt-4 text-xs text-slate-500">{checkingStatus ? "Memeriksa status transaksi secara berkala…" : "Status akan diperiksa kembali secara otomatis."}</p>
                    </div>
                </section>
            ) : !result ? (
                <>
                    {/* Pilih nasabah: seleksi dibuat full-width agar mudah ditemukan. */}
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                        {customerLocked && customer && (
                            <div className="mb-4 flex flex-col gap-4 rounded-xl border border-blue-200 bg-blue-50/80 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-white">
                                        <Lock size={22} />
                                    </div>
                                    <div>
                                        <p className="font-bold text-slate-900">Nasabah Terpilih — Terkunci</p>
                                        <p className="mt-1 text-sm text-slate-700">
                                            {customer.student_name || "Nama belum tersedia"} · Rekening {customer.account_number || "-"}
                                        </p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={handleUnlockOrChangeCustomer}
                                    disabled={loading || Boolean(pendingTransaction)}
                                    className="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-blue-300 bg-white px-3.5 py-2 text-sm font-bold text-blue-800 transition hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <QrCode size={16} />
                                    Ganti Nasabah / Scan Ulang
                                </button>
                            </div>
                        )}

                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-start gap-3">
                                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                    <Search size={21} />
                                </div>
                                <div>
                                    <h2 className="font-semibold text-slate-900">1. Pilih Nasabah</h2>
                                    <p className="mt-1 text-sm leading-5 text-slate-500">
                                        {customerLocked
                                            ? "Nasabah dari proses identifikasi sebelumnya sudah dipilih dan dikunci."
                                            : "Pindai QR nasabah untuk memvalidasi identitas setoran."}
                                    </p>
                                    {customerLocked && validatingSelectedCustomer && (
                                        <p role="status" className="mt-1 text-xs text-blue-700">
                                            Memvalidasi rekening untuk transaksi...
                                        </p>
                                    )}
                                </div>
                            </div>

                            {!customerLocked && <div className="w-full lg:max-w-xs">
                                <button
                                    type="button"
                                    onClick={() => setIsQrModalOpen(true)}
                                    disabled={customerLocked || loading || Boolean(pendingTransaction) || Boolean(result)}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50 sm:col-span-2 lg:col-span-1"
                                >
                                    <QrCode size={18} />
                                    Scan QR
                                </button>
                            </div>}
                        </div>
                        <div className="mt-4 border-t border-slate-100 pt-3">
                            <Link to="/teller/find-customer" className="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:text-blue-800">
                                <ArrowLeft size={15} /> Buka halaman Cari Nasabah
                            </Link>
                        </div>
                    </section>

                    <div className="grid grid-cols-1 items-start gap-x-5 gap-y-8 xl:grid-cols-12 xl:gap-x-6">
                        {/* Form transaksi */}
                        <section className="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 xl:col-span-7">
                            <div className="flex items-start gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                    <Wallet size={20} />
                                </div>
                                <div>
                                    <h2 className="font-semibold text-slate-900">2. Detail Setoran</h2>
                                    <p className="mt-1 text-sm text-slate-500">Masukkan nominal uang yang telah diterima secara fisik.</p>
                                </div>
                            </div>

                            {!customer ? (
                                <div className="flex flex-col items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 px-5 py-10 text-center">
                                    <UserRound size={28} className="text-slate-400" />
                                    <p className="mt-3 font-semibold text-slate-700">Belum ada nasabah dipilih</p>
                                    <p className="mt-1 max-w-sm text-sm text-slate-500">Pilih nasabah di bagian atas untuk menampilkan form dan ringkasan setoran.</p>
                                </div>
                            ) : (
                                <form onSubmit={handleSubmit} className="space-y-6">
                                    <div>
                                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                            <label htmlFor="deposit-amount" className="text-sm font-semibold text-slate-800">
                                                Nominal Setoran (Rp)
                                            </label>
                                            <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">Wajib diisi</span>
                                        </div>
                                        <div className="relative">
                                            <span className="pointer-events-none absolute inset-y-0 left-4 flex items-center text-lg font-bold text-blue-700">Rp</span>
                                            <input
                                                id="deposit-amount"
                                                type="number"
                                                inputMode="numeric"
                                                min="1"
                                                step="1"
                                                required
                                                disabled={loading || !canTransact}
                                                value={amount}
                                                onChange={(event) => {
                                                    setAmount(event.target.value);
                                                    // Jika nominal berubah, checklist kecocokan slip perlu diperiksa ulang.
                                                    setChecks((previous) => ({ ...previous, slipMatches: false }));
                                                    setError("");
                                                }}
                                                placeholder="0"
                                                className="w-full rounded-xl border border-slate-200 bg-slate-50 py-4 pl-14 pr-4 text-2xl font-bold tracking-tight text-slate-900 outline-none transition placeholder:text-slate-300 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-100"
                                            />
                                        </div>
                                        {validAmount && (
                                            <p className="mt-2 text-sm text-blue-700">
                                                Terbilang: <span className="italic">{terbilang(nominal)}</span>
                                            </p>
                                        )}
                                        <div className="mt-4">
                                            <p className="mb-2 text-xs font-semibold text-slate-500">Nominal cepat</p>
                                            <div className="flex flex-wrap gap-2">
                                                {QUICK_AMOUNTS.map((preset) => (
                                                    <button
                                                        key={preset}
                                                        type="button"
                                                        disabled={loading || !canTransact}
                                                        onClick={() => {
                                                            setAmount(String(preset));
                                                            setChecks((previous) => ({ ...previous, slipMatches: false }));
                                                            setError("");
                                                        }}
                                                        className={`rounded-lg border px-3 py-2 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 ${Number(amount) === preset ? "border-blue-600 bg-blue-600 text-white" : "border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700"}`}
                                                    >
                                                        {formatRupiah(preset)}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    </div>

                                    {!canTransact && (
                                        <div className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3.5 text-sm text-amber-800">
                                            <AlertCircle size={18} className="mt-0.5 shrink-0" />
                                            <p>Rekening ini tidak aktif atau diblokir. Periksa status rekening sebelum melanjutkan transaksi.</p>
                                        </div>
                                    )}

                                    <div className="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                                        <div className="flex items-start gap-3">
                                            <ShieldCheck size={21} className="mt-0.5 shrink-0 text-blue-700" />
                                            <div>
                                                <h3 className="text-sm font-semibold text-slate-900">Pemeriksaan Slip & Uang Tunai</h3>
                                                <p className="mt-1 text-xs leading-5 text-slate-500">Pastikan pemeriksaan fisik selesai sebelum transaksi dikirim.</p>
                                            </div>
                                        </div>

                                        <ChecklistItem
                                            checked={checks.cashReceived}
                                            onChange={(checked) => setChecks((previous) => ({ ...previous, cashReceived: checked }))}
                                            label="Uang fisik telah diterima dan dihitung di hadapan penyetor."
                                        />
                                        <ChecklistItem
                                            checked={checks.cashChecked}
                                            onChange={(checked) => setChecks((previous) => ({ ...previous, cashChecked: checked }))}
                                            label="Kondisi uang kertas dan logam telah diperiksa."
                                        />
                                        <ChecklistItem
                                            checked={checks.slipMatches}
                                            onChange={(checked) => setChecks((previous) => ({ ...previous, slipMatches: checked }))}
                                            label="Nominal uang fisik sesuai dengan slip setoran manual."
                                        />
                                    </div>

                                    <div className="rounded-xl bg-blue-50 p-4 text-sm leading-6 text-blue-900">
                                        Perubahan saldo hanya dilakukan setelah backend berhasil memproses transaksi. Pastikan rekening dan nominal sudah benar sebelum melanjutkan.
                                    </div>

                                    <div className="flex flex-col-reverse gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setAmount("");
                                                setError("");
                                                setChecks({ cashReceived: false, cashChecked: false, slipMatches: false });
                                            }}
                                            disabled={loading}
                                            className="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                                        >
                                            Bersihkan Form
                                        </button>
                                        <button
                                            type="submit"
                                            disabled={loading || validatingSelectedCustomer || !scanToken || !canTransact || !validAmount || !allChecksComplete}
                                            className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {loading ? (
                                                <>
                                                    <LoaderCircle size={18} className="animate-spin" />
                                                    Memproses Setoran...
                                                </>
                                            ) : validatingSelectedCustomer ? (
                                                <>
                                                    <LoaderCircle size={18} className="animate-spin" />
                                                    Memvalidasi Nasabah...
                                                </>
                                            ) : (
                                                <>
                                                    Konfirmasi Setoran
                                                    <ArrowRight size={18} />
                                                </>
                                            )}
                                        </button>
                                    </div>
                                </form>
                            )}
                        </section>

                        {/* Ringkasan rekening */}
                        <aside className="space-y-6 xl:col-span-5">
                            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                                <div className="flex items-center justify-between gap-3">
                                    <h2 className="font-semibold text-slate-900">Ringkasan Rekening</h2>
                                    {customer && <StatusBadge status={customer.status} />}
                                </div>

                                {customer ? (
                                    <>
                                        <div className="mt-5 flex items-center gap-3 rounded-xl bg-slate-50 p-4">
                                            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700">
                                                <UserRound size={24} />
                                            </div>
                                            <div className="min-w-0">
                                                <p className="truncate font-semibold text-slate-900">{customer.student_name || "Nama belum tersedia"}</p>
                                                <p className="mt-1 text-sm text-slate-600">Rekening: {customer.account_number || "-"}</p>
                                            </div>
                                        </div>

                                        <div className="mt-5 space-y-3">
                                            <SummaryRow label="Saldo saat ini" value={formatRupiah(customer.balance)} />
                                            <SummaryRow label="Tambahan setoran" value={validAmount ? `+ ${formatRupiah(nominal)}` : "—"} valueClass="text-emerald-700" />
                                            <div className="rounded-xl bg-blue-50 p-4">
                                                <p className="text-xs font-semibold uppercase tracking-wide text-blue-800">Estimasi saldo setelah setoran</p>
                                                <p className="mt-2 break-words text-2xl font-bold tracking-tight text-blue-800">{formatRupiah(estimatedBalance)}</p>
                                                <p className="mt-1 text-xs leading-5 text-blue-700">Estimasi ini belum menjadi saldo resmi sampai transaksi berhasil diproses backend.</p>
                                            </div>
                                        </div>
                                    </>
                                ) : (
                                    <div className="mt-5 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
                                        <Wallet size={26} className="mx-auto text-slate-400" />
                                        <p className="mt-3 text-sm font-semibold text-slate-700">Ringkasan belum tersedia</p>
                                        <p className="mt-1 text-sm text-slate-500">Pilih nasabah untuk melihat informasi rekening.</p>
                                    </div>
                                )}
                            </section>

                            <section className="rounded-xl border border-slate-200 bg-white mb-4 p-4">
                                <div className="flex items-start gap-3">
                                    <CircleHelp size={19} className="mt-0.5 shrink-0 text-slate-500" />
                                    <div>
                                        <h3 className="text-sm font-semibold text-slate-800">Sebelum mengonfirmasi</h3>
                                        <p className="mt-1 text-sm leading-5 text-slate-500">Periksa rekening, nominal setoran, uang fisik, dan slip. Pastikan tidak ada kesalahan sebelum transaksi dikirim.</p>
                                    </div>
                                </div>
                            </section>
                        </aside>
                    </div>
                </>
            ) : (
                /* Hasil transaksi dan pratinjau slip hanya ditampilkan setelah backend berhasil. */
                <div className="space-y-5">
                    <section className="rounded-2xl border border-emerald-200 bg-white p-5 shadow-sm sm:p-6">
                        <div className="flex flex-col gap-4 border-b border-slate-100 pb-5 sm:flex-row sm:items-start sm:justify-between">
                            <div className="flex items-start gap-3">
                                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                                    <CheckCircle2 size={27} />
                                </div>
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-wider text-emerald-700">Transaksi selesai</p>
                                    <h2 className="mt-1 text-xl font-bold text-slate-900 sm:text-2xl">Setoran Berhasil</h2>
                                    <p className="mt-1 text-sm leading-6 text-slate-500">{result.message} Saldo yang ditampilkan mengikuti data yang dikonfirmasi backend.</p>
                                </div>
                            </div>
                            <div className="rounded-xl bg-slate-50 px-4 py-3 sm:text-right">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">ID transaksi</p>
                                <p className="mt-1 font-mono text-sm font-bold text-slate-900">{result.transactionId ?? "Tidak tersedia"}</p>
                                <p className="mt-1 text-xs text-slate-500">{formatTanggalWaktu(result.transactionDate)}</p>
                            </div>
                        </div>

                        <div className="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-12">
                            <div className="space-y-4 lg:col-span-7">
                                <div className="rounded-xl bg-emerald-50 p-5">
                                    <p className="text-xs font-semibold uppercase tracking-wide text-emerald-800">Nominal disetorkan</p>
                                    <p className="mt-2 break-words text-3xl font-bold tracking-tight text-emerald-800">{formatRupiah(receiptAmount)}</p>
                                    <p className="mt-2 text-sm italic text-emerald-800">{terbilang(receiptAmount)}</p>
                                </div>

                                <div className="rounded-xl border border-slate-200 p-4 sm:p-5">
                                    <h3 className="font-semibold text-slate-900">Rincian Pembukuan</h3>
                                    <div className="mt-3 divide-y divide-slate-100">
                                        <SummaryRow label="Nama nasabah" value={resultCustomer?.student_name || "-"} />
                                        <SummaryRow label="Nomor rekening" value={resultCustomer?.account_number || "-"} />
                                        <SummaryRow label="Saldo awal" value={formatRupiah(result.previousBalance)} />
                                        <SummaryRow label="Petugas Teller" value={result.tellerName || "Teller"} />
                                    </div>
                                    <div className="mt-4 rounded-xl bg-blue-50 p-4">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-blue-800">Saldo setelah setoran</p>
                                        {result.balance !== null && result.balance !== undefined ? (
                                            <p className="mt-1 text-2xl font-bold text-blue-800">{formatRupiah(result.balance)}</p>
                                        ) : (
                                            <p className="mt-1 text-sm text-blue-800">Saldo terbaru belum berhasil dimuat. Muat ulang detail rekening sebelum melanjutkan.</p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div className="lg:col-span-5">
                                <div className="mb-2 flex items-center justify-between gap-2">
                                    <h3 className="flex items-center gap-2 font-semibold text-slate-900">
                                        <Wallet size={18} /> Pratinjau Slip Fisik
                                    </h3>
                                    <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">Siap dicetak</span>
                                </div>

                                <div className="receipt-print mx-auto w-full max-w-sm rounded-xl border border-dashed border-slate-300 bg-white p-5 font-mono text-xs text-slate-800 sm:p-6">
                                    <div className="border-b border-dashed border-slate-300 pb-4 text-center">
                                        <p className="text-sm font-bold tracking-wide">E-TELLER BANK MINI SEKOLAH</p>
                                        <p className="mt-1 text-[10px] text-slate-500">BUKTI TRANSAKSI SETORAN TUNAI</p>
                                    </div>

                                    <div className="space-y-2 border-b border-dashed border-slate-300 py-4">
                                        <ReceiptRow label="ID TRANSAKSI" value={result.transactionId ?? "-"} />
                                        <ReceiptRow label="WAKTU" value={formatTanggalWaktu(result.transactionDate)} />
                                        <ReceiptRow label="TELLER" value={result.tellerName || "-"} />
                                    </div>

                                    <div className="space-y-2 border-b border-dashed border-slate-300 py-4">
                                        <ReceiptRow label="NASABAH" value={resultCustomer?.student_name || "-"} />
                                        <ReceiptRow label="REKENING" value={resultCustomer?.account_number || "-"} />
                                    </div>

                                    <div className="space-y-2 border-b border-dashed border-slate-300 py-4">
                                        <ReceiptRow label="JENIS" value="SETORAN TUNAI" />
                                        <ReceiptRow label="NOMINAL" value={formatRupiah(receiptAmount)} prominent />
                                        {result.balance !== null && result.balance !== undefined && (
                                            <ReceiptRow label="SALDO AKHIR" value={formatRupiah(result.balance)} prominent />
                                        )}
                                    </div>

                                    <div className="pt-4 text-center">
                                        <p className="font-bold tracking-wide">TRANSAKSI BERHASIL</p>
                                        <p className="mt-2 leading-5 text-slate-500">Simpan bukti transaksi sesuai prosedur Bank Mini Sekolah.</p>
                                    </div>
                                </div>

                                <p className="mt-3 text-xs leading-5 text-slate-500">Pratinjau menggunakan data transaksi dan rekening yang tersedia dari backend. Informasi yang tidak tersedia tidak dibuat-buat.</p>
                            </div>
                        </div>

                        <div className="no-print mt-6 flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-between">
                            <Link
                                to={`/teller/customers/${encodeURIComponent(resultCustomer?.account_number ?? "")}`}
                                state={{ customer: resultCustomer }}
                                className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                            >
                                <ArrowLeft size={17} /> Kembali ke Detail Nasabah
                            </Link>
                            <div className="flex flex-col gap-3 sm:flex-row">
                                <button
                                    type="button"
                                    onClick={handleUnlockOrChangeCustomer}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-blue-200 bg-white px-5 py-3 text-sm font-semibold text-blue-800 transition hover:bg-blue-50"
                                >
                                    <Lock size={17} /> Ganti Nasabah / Scan Ulang
                                </button>
                                <button
                                    type="button"
                                    onClick={() => window.print()}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                                >
                                    <Printer size={17} /> Cetak Struk
                                </button>
                                <button
                                    type="button"
                                    onClick={startNextDeposit}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-700 px-5 py-3 text-sm font-semibold text-white transition hover:bg-blue-800"
                                >
                                    Setoran Berikutnya <ArrowRight size={17} />
                                </button>
                            </div>
                        </div>
                    </section>
                    <div className="no-print flex items-start gap-3 rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm mb-4 text-blue-900">
                        <ShieldCheck size={19} className="mt-0.5 shrink-0" />
                        <p>Transaksi telah dikonfirmasi berhasil oleh backend. Pastikan uang fisik disimpan sesuai prosedur operasional sebelum melanjutkan pelayanan.</p>
                    </div>
                </div>
            )}
        </div>
    );
}

function ChecklistItem({ checked, onChange, label }) {
    return (
        <label className="flex cursor-pointer items-start gap-3 text-sm leading-5 text-slate-700">
            <input
                type="checkbox"
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 accent-blue-700 focus:ring-blue-600"
            />
            <span>{label}</span>
        </label>
    );
}

function SummaryRow({ label, value, valueClass = "" }) {
    return (
        <div className="flex items-start justify-between gap-4 py-3 text-sm">
            <span className="text-slate-500">{label}</span>
            <span className={`max-w-[65%] break-words text-right font-semibold text-slate-800 ${valueClass}`}>
                {value}
            </span>
        </div>
    );
}

function ReceiptRow({ label, value, prominent = false }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <span className="shrink-0 text-slate-500">{label}</span>
            <span className={`break-words text-right ${prominent ? "font-bold text-black" : "font-semibold text-slate-800"}`}>
                {value}
            </span>
        </div>
    );
}

function StatusBadge({ status }) {
    const config = status === "active"
        ? { label: "Aktif", className: "bg-emerald-50 text-emerald-700" }
        : status === "blocked"
            ? { label: "Diblokir", className: "bg-red-50 text-red-700" }
            : { label: status || "Tidak diketahui", className: "bg-slate-100 text-slate-600" };

    return (
        <span className={`inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${config.className}`}>
            {config.label}
        </span>
    );
}

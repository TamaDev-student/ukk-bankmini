import { useState, useEffect, useRef } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
    Landmark,
    Eye,
    EyeOff,
    LoaderCircle,
    CircleAlert,
    CheckCircle2,
    ArrowLeft,
    Mail,
    RefreshCw,
} from "lucide-react";
import api, { getCsrfCookie } from "../../../../config/api";

function getErrorMessage(error, defaultMsg = "Terjadi kesalahan. Silakan coba lagi.") {
    const responseMessage = error.response?.data?.message;
    if (responseMessage) return responseMessage;
    if (!error.response) {
        return "Tidak dapat terhubung ke server. Pastikan backend Laravel berjalan.";
    }
    return defaultMsg;
}

export default function ForgotPasswordPage() {
    const navigate = useNavigate();

    // Steps: 1 (Username), 2 (OTP), 3 (New Password), 4 (Success)
    const [step, setStep] = useState(1);

    // Form inputs
    const [username, setUsername] = useState("");
    const [otp, setOtp] = useState("");
    const [resetToken, setResetToken] = useState("");
    const [password, setPassword] = useState("");
    const [passwordConfirmation, setPasswordConfirmation] = useState("");
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);

    // UI States
    const [submitting, setSubmitting] = useState(false);
    const [resending, setResending] = useState(false);
    const [errorMessage, setErrorMessage] = useState("");
    const [infoMessage, setInfoMessage] = useState("");

    // Timers
    const [otpTimer, setOtpTimer] = useState(300); // 5 minutes validity
    const [resendCooldown, setResendCooldown] = useState(60); // 60s cooldown

    const timerRef = useRef(null);
    const cooldownRef = useRef(null);

    // Countdown for OTP 5 mins validity
    useEffect(() => {
        if (step === 2 && otpTimer > 0) {
            timerRef.current = setInterval(() => {
                setOtpTimer((prev) => (prev > 0 ? prev - 1 : 0));
            }, 1000);
        }
        return () => clearInterval(timerRef.current);
    }, [step, otpTimer]);

    // Countdown for Resend cooldown
    useEffect(() => {
        if (step === 2 && resendCooldown > 0) {
            cooldownRef.current = setInterval(() => {
                setResendCooldown((prev) => (prev > 0 ? prev - 1 : 0));
            }, 1000);
        }
        return () => clearInterval(cooldownRef.current);
    }, [step, resendCooldown]);

    const formatTimer = (seconds) => {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
    };

    // -------------------------------------------------------------
    // STEP 1: Request OTP
    // -------------------------------------------------------------
    const handleRequestOtp = async (event) => {
        event.preventDefault();
        const trimmed = username.trim();
        if (!trimmed || submitting) return;

        setErrorMessage("");
        setInfoMessage("");
        setSubmitting(true);

        try {
            await getCsrfCookie();
            const res = await api.post("/web/forgot-password", {
                username: trimmed,
            });

            setInfoMessage(
                res.data?.message ||
                "Jika akun terdaftar dan memiliki email pemulihan, kode OTP telah dikirimkan."
            );
            setStep(2);
            setOtpTimer(300);
            setResendCooldown(60);
        } catch (error) {
            setErrorMessage(getErrorMessage(error, "Gagal memproses permintaan OTP."));
        } finally {
            setSubmitting(false);
        }
    };

    // -------------------------------------------------------------
    // RESEND OTP
    // -------------------------------------------------------------
    const handleResendOtp = async () => {
        if (resendCooldown > 0 || resending) return;

        setErrorMessage("");
        setResending(true);

        try {
            await getCsrfCookie();
            const res = await api.post("/web/forgot-password", {
                username: username.trim(),
            });

            setInfoMessage(res.data?.message || "Kode OTP baru telah dikirimkan ke email.");
            setOtpTimer(300);
            setResendCooldown(60);
            setOtp("");
        } catch (error) {
            setErrorMessage(getErrorMessage(error, "Gagal mengirim ulang kode OTP."));
        } finally {
            setResending(false);
        }
    };

    // -------------------------------------------------------------
    // STEP 2: Verify OTP
    // -------------------------------------------------------------
    const handleVerifyOtp = async (event) => {
        event.preventDefault();
        const trimmedOtp = otp.trim();
        if (trimmedOtp.length !== 6 || submitting) return;

        setErrorMessage("");
        setSubmitting(true);

        try {
            await getCsrfCookie();
            const res = await api.post("/web/verify-reset-otp", {
                username: username.trim(),
                otp: trimmedOtp,
            });

            if (res.data?.reset_token) {
                setResetToken(res.data.reset_token);
                setStep(3);
                setInfoMessage("Kode OTP berhasil diverifikasi. Silakan buat password baru.");
            } else {
                setErrorMessage("Respons verifikasi tidak valid.");
            }
        } catch (error) {
            setErrorMessage(getErrorMessage(error, "Kode OTP salah atau telah kedaluwarsa."));
        } finally {
            setSubmitting(false);
        }
    };

    // -------------------------------------------------------------
    // STEP 3: Reset Password
    // -------------------------------------------------------------
    const handleResetPassword = async (event) => {
        event.preventDefault();
        if (submitting) return;

        if (
            password.length < 10 ||
            !/[a-z]/.test(password) ||
            !/[A-Z]/.test(password) ||
            !/[0-9]/.test(password)
        ) {
            setErrorMessage("Password harus minimal 10 karakter serta memuat huruf besar, huruf kecil, dan angka.");
            return;
        }

        if (password !== passwordConfirmation) {
            setErrorMessage("Konfirmasi password tidak cocok.");
            return;
        }

        setErrorMessage("");
        setSubmitting(true);

        try {
            await getCsrfCookie();
            await api.post("/web/reset-password", {
                username: username.trim(),
                reset_token: resetToken,
                password,
                password_confirmation: passwordConfirmation,
            });

            setStep(4);
        } catch (error) {
            setErrorMessage(getErrorMessage(error, "Gagal memperbarui password. Silakan coba lagi."));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <main className="flex min-h-screen items-center justify-center bg-[#F4F6FA] px-4 py-10">
            <div className="w-full max-w-md">
                {/* Header Identitas Aplikasi */}
                <div className="mb-8 text-center">
                    <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-[#003FB3] text-white shadow-lg shadow-blue-900/15">
                        <Landmark size={31} aria-hidden="true" />
                    </div>
                    <h1 className="mt-5 text-2xl font-bold tracking-tight text-gray-900">
                        E-Teller Bank Mini
                    </h1>
                    <p className="mt-2 text-sm text-gray-500">
                        Pemulihan Password Portal Web Staf
                    </p>
                </div>

                {/* Kartu Utama Form */}
                <section className="rounded-2xl border border-gray-200 bg-white p-6 shadow-xl shadow-slate-900/5 sm:p-8">
                    {/* Pesan Kesalahan */}
                    {errorMessage && (
                        <div
                            role="alert"
                            className="mb-5 flex gap-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm leading-5 text-red-700"
                        >
                            <CircleAlert size={19} className="mt-0.5 shrink-0" aria-hidden="true" />
                            <p>{errorMessage}</p>
                        </div>
                    )}

                    {/* Pesan Info */}
                    {infoMessage && step !== 4 && (
                        <div className="mb-5 flex gap-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm leading-5 text-blue-800">
                            <Mail size={19} className="mt-0.5 shrink-0 text-[#003FB3]" aria-hidden="true" />
                            <p>{infoMessage}</p>
                        </div>
                    )}

                    {/* ========================================================= */}
                    {/* STEP 1: IDENTIFIKASI USERNAME STAF                        */}
                    {/* ========================================================= */}
                    {step === 1 && (
                        <div>
                            <div className="mb-6">
                                <h2 className="text-xl font-semibold text-gray-900">
                                    Lupa Password?
                                </h2>
                                <p className="mt-2 text-sm leading-6 text-gray-500">
                                    Masukkan username akun staf Anda (Administrator, Teller, atau Supervisor) untuk menerima kode OTP pemulihan via email.
                                </p>
                            </div>

                            <form onSubmit={handleRequestOtp} className="space-y-5">
                                <div>
                                    <label
                                        htmlFor="forgot-username"
                                        className="mb-2 block text-sm font-medium text-gray-700"
                                    >
                                        Username Staf
                                    </label>
                                    <input
                                        id="forgot-username"
                                        type="text"
                                        autoComplete="username"
                                        value={username}
                                        onChange={(e) => setUsername(e.target.value)}
                                        placeholder="Masukkan username Anda"
                                        required
                                        autoFocus
                                        disabled={submitting}
                                        className="w-full rounded-lg border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-[#003FB3] focus:ring-4 focus:ring-blue-100 disabled:bg-gray-50"
                                    />
                                </div>

                                <button
                                    type="submit"
                                    disabled={submitting || !username.trim()}
                                    className="flex w-full items-center justify-center gap-2 rounded-lg bg-[#003FB3] px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {submitting ? (
                                        <>
                                            <LoaderCircle size={18} className="animate-spin" aria-hidden="true" />
                                            Mengirim kode OTP...
                                        </>
                                    ) : (
                                        "Kirim Kode OTP"
                                    )}
                                </button>
                            </form>

                            {/* Info bantuan akun tanpa email */}
                            <div className="mt-6 rounded-lg bg-gray-50 p-3.5 border border-gray-200 text-xs text-gray-600 leading-relaxed">
                                <p className="font-semibold text-gray-800 mb-1">
                                    Belum mempunyai email pemulihan?
                                </p>
                                <p>
                                    Silakan hubungi administrator sistem untuk mendapatkan bantuan pemulihan password akun Anda.
                                </p>
                            </div>
                        </div>
                    )}

                    {/* ========================================================= */}
                    {/* STEP 2: VERIFIKASI KODE OTP                               */}
                    {/* ========================================================= */}
                    {step === 2 && (
                        <div>
                            <div className="mb-6">
                                <div className="flex items-center justify-between">
                                    <h2 className="text-xl font-semibold text-gray-900">
                                        Verifikasi OTP
                                    </h2>
                                    <span className="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-[#003FB3]">
                                        Masa Berlaku: {formatTimer(otpTimer)}
                                    </span>
                                </div>
                                <p className="mt-2 text-sm leading-6 text-gray-500">
                                    Masukkan 6 digit kode OTP yang telah dikirimkan ke email pemulihan akun <strong>{username}</strong>.
                                </p>
                            </div>

                            <form onSubmit={handleVerifyOtp} className="space-y-5">
                                <div>
                                    <label
                                        htmlFor="otp-input"
                                        className="mb-2 block text-sm font-medium text-gray-700"
                                    >
                                        Kode OTP (6 Digit)
                                    </label>
                                    <input
                                        id="otp-input"
                                        type="text"
                                        inputMode="numeric"
                                        pattern="[0-9]*"
                                        maxLength={6}
                                        value={otp}
                                        onChange={(e) => setOtp(e.target.value.replace(/\D/g, ""))}
                                        placeholder="000000"
                                        required
                                        autoFocus
                                        disabled={submitting || otpTimer === 0}
                                        className="w-full text-center tracking-[0.5em] font-mono text-2xl font-bold rounded-lg border border-gray-300 bg-white px-4 py-3 text-gray-900 outline-none transition placeholder:text-gray-300 focus:border-[#003FB3] focus:ring-4 focus:ring-blue-100 disabled:bg-gray-50"
                                    />
                                    {otpTimer === 0 && (
                                        <p className="mt-1.5 text-xs text-red-600 font-medium">
                                            Kode OTP telah kedaluwarsa. Silakan minta kode baru.
                                        </p>
                                    )}
                                </div>

                                <button
                                    type="submit"
                                    disabled={submitting || otp.trim().length !== 6 || otpTimer === 0}
                                    className="flex w-full items-center justify-center gap-2 rounded-lg bg-[#003FB3] px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {submitting ? (
                                        <>
                                            <LoaderCircle size={18} className="animate-spin" aria-hidden="true" />
                                            Memverifikasi...
                                        </>
                                    ) : (
                                        "Verifikasi Kode OTP"
                                    )}
                                </button>
                            </form>

                            {/* Resend Cooldown */}
                            <div className="mt-5 text-center">
                                <button
                                    type="button"
                                    onClick={handleResendOtp}
                                    disabled={resendCooldown > 0 || resending}
                                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#003FB3] hover:underline disabled:cursor-not-allowed disabled:text-gray-400"
                                >
                                    <RefreshCw size={14} className={resending ? "animate-spin" : ""} />
                                    {resendCooldown > 0
                                        ? `Kirim Ulang Kode (${resendCooldown}s)`
                                        : "Kirim Ulang Kode OTP"}
                                </button>
                            </div>

                            <div className="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 leading-relaxed">
                                <strong>Keamanan:</strong> Jangan bagikan kode OTP ini kepada siapapun termasuk petugas bank atau pihak sekolah.
                            </div>
                        </div>
                    )}

                    {/* ========================================================= */}
                    {/* STEP 3: BUAT PASSWORD BARU                                */}
                    {/* ========================================================= */}
                    {step === 3 && (
                        <div>
                            <div className="mb-6">
                                <h2 className="text-xl font-semibold text-gray-900">
                                    Buat Password Baru
                                </h2>
                                <p className="mt-2 text-sm leading-6 text-gray-500">
                                    Masukkan password baru yang kuat untuk akun <strong>{username}</strong>.
                                </p>
                            </div>

                            <form onSubmit={handleResetPassword} className="space-y-5">
                                <div>
                                    <label
                                        htmlFor="new-password"
                                        className="mb-2 block text-sm font-medium text-gray-700"
                                    >
                                        Password Baru (Min. 10 Karakter)
                                    </label>
                                    <div className="relative">
                                        <input
                                            id="new-password"
                                            type={showPassword ? "text" : "password"}
                                            value={password}
                                            onChange={(e) => setPassword(e.target.value)}
                                            placeholder="Masukkan password baru"
                                            required
                                            minLength={10}
                                            disabled={submitting}
                                            className="w-full rounded-lg border border-gray-300 bg-white px-4 py-3 pr-12 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-[#003FB3] focus:ring-4 focus:ring-blue-100 disabled:bg-gray-50"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowPassword((prev) => !prev)}
                                            className="absolute inset-y-0 right-0 flex items-center justify-center px-4 text-gray-500 hover:text-[#003FB3]"
                                        >
                                            {showPassword ? <EyeOff size={19} /> : <Eye size={19} />}
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <label
                                        htmlFor="confirm-password"
                                        className="mb-2 block text-sm font-medium text-gray-700"
                                    >
                                        Konfirmasi Password Baru
                                    </label>
                                    <div className="relative">
                                        <input
                                            id="confirm-password"
                                            type={showConfirmPassword ? "text" : "password"}
                                            value={passwordConfirmation}
                                            onChange={(e) => setPasswordConfirmation(e.target.value)}
                                            placeholder="Ulangi password baru"
                                            required
                                            minLength={10}
                                            disabled={submitting}
                                            className="w-full rounded-lg border border-gray-300 bg-white px-4 py-3 pr-12 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-[#003FB3] focus:ring-4 focus:ring-blue-100 disabled:bg-gray-50"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowConfirmPassword((prev) => !prev)}
                                            className="absolute inset-y-0 right-0 flex items-center justify-center px-4 text-gray-500 hover:text-[#003FB3]"
                                        >
                                            {showConfirmPassword ? <EyeOff size={19} /> : <Eye size={19} />}
                                        </button>
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    disabled={submitting || password.length < 10 || !passwordConfirmation}
                                    className="flex w-full items-center justify-center gap-2 rounded-lg bg-[#003FB3] px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {submitting ? (
                                        <>
                                            <LoaderCircle size={18} className="animate-spin" aria-hidden="true" />
                                            Menyimpan Password...
                                        </>
                                    ) : (
                                        "Simpan Password Baru"
                                    )}
                                </button>
                            </form>
                        </div>
                    )}

                    {/* ========================================================= */}
                    {/* STEP 4: BERHASIL                                          */}
                    {/* ========================================================= */}
                    {step === 4 && (
                        <div className="text-center py-4">
                            <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 mb-4">
                                <CheckCircle2 size={36} aria-hidden="true" />
                            </div>
                            <h2 className="text-xl font-bold text-gray-900">
                                Password Berhasil Diperbarui!
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-gray-500">
                                Password akun <strong>{username}</strong> telah berhasil diubah. Silakan kembali ke halaman login untuk masuk menggunakan password baru.
                            </p>

                            <div className="mt-8">
                                <button
                                    type="button"
                                    onClick={() => navigate("/login")}
                                    className="flex w-full items-center justify-center gap-2 rounded-lg bg-[#003FB3] px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800"
                                >
                                    Kembali ke Halaman Login
                                </button>
                            </div>
                        </div>
                    )}

                    {/* Tombol Navigasi Kembali ke Login */}
                    {step !== 4 && (
                        <div className="mt-6 border-t border-gray-100 pt-5 text-center">
                            <Link
                                to="/login"
                                className="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-600 hover:text-[#003FB3] transition"
                            >
                                <ArrowLeft size={14} />
                                Kembali ke Halaman Login
                            </Link>
                        </div>
                    )}
                </section>
            </div>
        </main>
    );
}

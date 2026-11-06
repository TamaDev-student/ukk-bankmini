import { useState } from "react";
import { Link, Navigate, useLocation } from "react-router-dom";
import {
    Landmark,
    Eye,
    EyeOff,
    LoaderCircle,
    ShieldCheck,
    CircleAlert,
} from "lucide-react";

import { useAuth } from "../../../../context/AuthContext";
import { getRoleHomePath } from "../../../../config/roles";

function getLoginErrorMessage(error) {
    const status = error.response?.status;
    const responseMessage = error.response?.data?.message;

    if (status === 401) {
        return "Username atau password yang kamu masukkan salah.";
    }

    if (status === 403) {
        return (
            responseMessage ||
            "Akun tidak memiliki izin untuk mengakses portal web."
        );
    }

    if (status === 419) {
        return "Sesi keamanan berakhir. Silakan coba login kembali.";
    }

    if (status === 422) {
        return "Data login belum lengkap atau tidak sesuai.";
    }

    if (status === 429) {
        return (
            responseMessage ||
            "Terlalu banyak percobaan login. Silakan coba beberapa saat lagi."
        );
    }

    if (!error.response) {
        return (
            "Tidak dapat terhubung ke server. " +
            "Pastikan Laravel dan frontend sedang berjalan."
        );
    }

    return responseMessage || "Terjadi kesalahan. Silakan coba lagi.";
}

function LoginPage() {
    const { user, loading, authError, login } = useAuth();
    const location = useLocation();

    const [username, setUsername] = useState("");
    const [password, setPassword] = useState("");
    const [showPassword, setShowPassword] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [errorMessage, setErrorMessage] = useState("");

    const forbidden =
        new URLSearchParams(location.search).get("error") === "forbidden";

    // Jika session masih aktif dan role valid, arahkan ke dashboard.
    if (!loading && user) {
        const homePath = getRoleHomePath(user.role);

        if (homePath) {
            return <Navigate to={homePath} replace />;
        }
    }

    async function handleSubmit(event) {
        event.preventDefault();

        if (submitting) {
            return;
        }

        setErrorMessage("");
        setSubmitting(true);

        try {
            const authenticatedUser = await login(
                username.trim(),
                password
            );

            const homePath = getRoleHomePath(authenticatedUser.role);

            if (!homePath) {
                setErrorMessage(
                    "Akun ini tidak memiliki izin untuk mengakses portal web."
                );

                return;
            }

            // Tujuan dashboard ditentukan dari role yang dikembalikan backend.
            window.history.replaceState(
                window.history.state,
                "",
                window.location.pathname
            );

            window.location.assign(homePath);
        } catch (error) {
            setErrorMessage(getLoginErrorMessage(error));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <main className="flex min-h-screen items-center justify-center bg-[#F4F6FA] px-4 py-6 sm:py-10">
            <div className="w-full max-w-md">
                {/* Identitas aplikasi */}
                <div className="mb-8 text-center">
                    <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-[#003FB3] text-white shadow-lg shadow-blue-900/15">
                        <Landmark size={31} aria-hidden="true" />
                    </div>

                    <h1 className="mt-5 text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                        E-Teller Bank Mini
                    </h1>

                    <p className="mt-2 text-sm text-gray-500">
                        Sistem Informasi Bank Mini Sekolah
                    </p>
                </div>

                {/* Form login */}
                <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-xl shadow-slate-900/5 sm:p-8">
                    <div className="mb-7">
                        <h2 className="text-xl font-semibold text-gray-900">
                            Login ke Portal
                        </h2>

                        <p className="mt-2 text-sm leading-6 text-gray-500">
                            Gunakan akun staf yang telah terdaftar untuk
                            melanjutkan ke sistem operasional.
                        </p>
                    </div>

                    {forbidden && user && !getRoleHomePath(user.role) && (
                        <div
                            role="alert"
                            className="mb-5 flex gap-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                        >
                            <CircleAlert
                                size={19}
                                className="mt-0.5 shrink-0"
                                aria-hidden="true"
                            />

                            <p>
                                Akun ini tidak diizinkan mengakses portal web.
                            </p>
                        </div>
                    )}

                    {(errorMessage || authError) && (
                        <div
                            role="alert"
                            className="mb-5 flex gap-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm leading-5 text-red-700"
                        >
                            <CircleAlert
                                size={19}
                                className="mt-0.5 shrink-0"
                                aria-hidden="true"
                            />

                            <p>{errorMessage || authError}</p>
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-5">
                        <div>
                            <label
                                htmlFor="username"
                                className="mb-2 block text-sm font-medium text-gray-700"
                            >
                                Username
                            </label>

                            <input
                                id="username"
                                name="username"
                                type="text"
                                autoComplete="username"
                                value={username}
                                onChange={(event) =>
                                    setUsername(event.target.value)
                                }
                                placeholder="Masukkan username"
                                required
                                disabled={submitting}
                                className="w-full rounded-lg border border-gray-300 bg-white px-4 py-3 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-[#003FB3] focus:ring-4 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-gray-50"
                            />
                        </div>

                        <div>
                            <div className="mb-2 flex items-center justify-between"><label
                                htmlFor="password"
                                className="mb-2 block text-sm font-medium text-gray-700"
                            >
                                Password
                            </label><Link to="/forgot-password" className="text-xs font-semibold text-[#003FB3] hover:underline">Lupa Password?</Link></div>

                            <div className="relative">
                                <input
                                    id="password"
                                    name="password"
                                    type={showPassword ? "text" : "password"}
                                    autoComplete="current-password"
                                    value={password}
                                    onChange={(event) =>
                                        setPassword(event.target.value)
                                    }
                                    placeholder="Masukkan password"
                                    required
                                    disabled={submitting}
                                    className="w-full rounded-lg border border-gray-300 bg-white px-4 py-3 pr-12 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-[#003FB3] focus:ring-4 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-gray-50"
                                />

                                <button
                                    type="button"
                                    onClick={() =>
                                        setShowPassword((visible) => !visible)
                                    }
                                    disabled={submitting}
                                    aria-label={
                                        showPassword
                                            ? "Sembunyikan password"
                                            : "Tampilkan password"
                                    }
                                    aria-pressed={showPassword}
                                    className="absolute inset-y-0 right-0 flex items-center justify-center px-4 text-gray-500 transition hover:text-[#003FB3] disabled:cursor-not-allowed"
                                >
                                    {showPassword ? (
                                        <EyeOff
                                            size={19}
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <Eye
                                            size={19}
                                            aria-hidden="true"
                                        />
                                    )}
                                </button>
                            </div>
                        </div>

                        <button
                            type="submit"
                            disabled={
                                submitting ||
                                !username.trim() ||
                                !password
                            }
                            className="flex w-full items-center justify-center gap-2 rounded-lg bg-[#003FB3] px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-200 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {submitting ? (
                                <>
                                    <LoaderCircle
                                        size={18}
                                        className="animate-spin"
                                        aria-hidden="true"
                                    />

                                    Memproses login...
                                </>
                            ) : (
                                "Login"
                            )}
                        </button>
                    </form>

                    <div className="mt-6 flex items-start gap-3 border-t border-gray-100 pt-5">
                        <ShieldCheck
                            size={19}
                            className="mt-0.5 shrink-0 text-[#003FB3]"
                            aria-hidden="true"
                        />

                        <p className="text-xs leading-5 text-gray-500">
                            Akses portal dibatasi untuk pengguna dengan
                            hak akses yang sesuai. Jangan membagikan
                            password akun kepada orang lain.
                        </p>
                    </div>
                </section>
            </div>
        </main>
    );
}

export default LoginPage;
import React, { useState } from "react";
import { Navigate, Outlet, useLocation } from "react-router-dom";
import { FunctionSquare, LoaderCircle, RefreshCw, ShieldAlert } from "lucide-react";

import { useAuth } from "./context/AuthContext";
import { getRoleHomePath } from "./config/roles";

function StatusScreen({ children }) {
    return (
        <main className="flex min-h-screen items-center justify-center bg-[#F4F6FA] px-6">
            <div className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-sm">
                {children}
            </div>
        </main>
    );
}

function ProtectedRoute({ allowedRoles = [] }) {
    const { user, loading, authError, refreshUser } = useAuth();
    const location = useLocation();

    const [retrying, setRetrying] = useState(false);

    async function handleRetry() {
        setRetrying(true);

        try {
            await refreshUser();
        } catch {
            //pesan kegagalan pemeriksaan session dikelola AuthContext.
        } finally {
            setRetrying(false);
        }
    }
    // Tunggu sampai pemeriksaan session awal selesai.
    if (loading) {
        return (
            <StatusScreen>
                <LoaderCircle
                    className="mx-auto mb-4 animate-spin text-[#003FB3]"
                    size={36}
                    aria-hidden="true"
                />

                <h1 className="text-lg font-semibold text-gray-900">
                    Memeriksa sesi
                </h1>

                <p className="mt-2 text-sm text-gray-500">
                    Mohon tunggu, kami sedang memeriksa autentikasi akun Anda.
                </p>
            </StatusScreen>
        );
    }

    // Jangan langsung menganggap pengguna belum login ketika
    // backend tidak dapat dihubungi.
    if (authError && !user) {
        return (
            <StatusScreen>
                <ShieldAlert
                    className="mx-auto mb-4 text-amber-600"
                    size={38}
                    aria-hidden="true"
                />

                <h1 className="text-lg font-semibold text-gray-900">
                    Tidak dapat memeriksa sesi
                </h1>

                <p className="mt-2 text-sm leading-6 text-gray-600">
                    {authError}
                </p>

                <button
                    type="button"
                    onClick={handleRetry}
                    disabled={retrying}
                    className="mt-6 inline-flex items-center justify-center gap-2 rounded-lg bg-[#003FB3] px-5 py-3 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <RefreshCw
                        size={16}
                        className={retrying ? "animate-spin" : ""}
                        aria-hidden="true"
                    />

                    {retrying ? "Memeriksa..." : "Coba lagi"}
                </button>
            </StatusScreen>
        );
    }

    // Pengguna tanpa session diarahkan ke halaman login.
    if (!user) {
        return (
            <Navigate
                to="/login"
                state={{ from: location }}
                replace
            />
        );
    }

    // Pengguna hanya boleh membuka halaman yang sesuai dengan rolenya.
    if (
        allowedRoles.length > 0 &&
        !allowedRoles.includes(user.role)
    ) {
        const homePath = getRoleHomePath(user.role);

        if (homePath) {
            return <Navigate to={homePath} replace />;
        }

        // Contoh: pengguna nasabah tidak mempunyai dashboard web.
        return (
            <Navigate
                to="/login?error=forbidden"
                replace
            />
        );
    }

    return <Outlet />;
}

export default ProtectedRoute;


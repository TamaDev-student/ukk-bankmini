import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { LogOut, LoaderCircle, CircleAlert, Menu } from "lucide-react";

import { useAuth } from "../../../context/AuthContext";
import { getRoleLabel } from "../../../config/roles";

function Topbar({ navConfig, onMenuToggle, navigationOpen }) {
    const { user, logout } = useAuth();
    const navigate = useNavigate();

    const [loggingOut, setLoggingOut] = useState(false);
    const [logoutError, setLogoutError] = useState("");

    const breadcrumb = navConfig?.breadcrumb ?? "Operasional Bank";

    const displayName =
        user?.name ||
        user?.full_name ||
        user?.username ||
        "Pengguna";

    const displayRole = getRoleLabel(user?.role);

    async function handleLogout() {
        if (loggingOut) {
            return;
        }

        setLoggingOut(true);
        setLogoutError("");

        try {
            await logout();

            navigate("/login", {
                replace: true,
            });
        } catch (error) {
            const status = error.response?.status;

            if (status === 419) {
                setLogoutError(
                    "Sesi keamanan berakhir. Muat ulang halaman dan coba lagi."
                );
            } else {
                setLogoutError(
                    "Logout belum berhasil dipastikan. Periksa koneksi server, kemudian coba lagi."
                );
            }
        } finally {
            setLoggingOut(false);
        }
    }

    return (
        <header className="fixed inset-x-0 top-0 z-40 flex h-16 items-center justify-between gap-2 border-b border-gray-200 bg-white px-3 sm:px-6 lg:left-64">
            <button
                type="button"
                onClick={onMenuToggle}
                aria-label={navigationOpen ? "Tutup navigasi" : "Buka navigasi"}
                aria-controls="role-navigation"
                aria-expanded={navigationOpen}
                className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-gray-700 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 lg:hidden"
            >
                <Menu size={21} aria-hidden="true" />
            </button>

            {/* Breadcrumb */}
            <div className="flex min-w-0 flex-1 items-center gap-2 text-sm text-gray-500 sm:gap-3">
                <span aria-hidden="true" className="shrink-0">⌂</span>

                <span aria-hidden="true" className="shrink-0">/</span>

                <span className="truncate font-medium text-gray-900">
                    {breadcrumb}
                </span>
            </div>

            {/* Pengguna dan logout */}
            <div className="flex shrink-0 items-center gap-2 sm:gap-4">
                <div className="flex items-center gap-3">
                    <div className="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 font-semibold text-[#003FB3]">
                        {displayName.charAt(0).toUpperCase()}
                    </div>

                    <div className="hidden max-w-40 flex-col sm:flex">
                        <span className="text-sm font-semibold text-gray-900">
                            <span className="truncate">{displayName}</span>
                        </span>

                        <span className="truncate text-xs text-gray-500">
                            {displayRole}
                        </span>
                    </div>
                </div>

                <div className="hidden h-8 w-px bg-gray-200 sm:block" />

                <button
                    type="button"
                    onClick={handleLogout}
                    disabled={loggingOut}
                    aria-label={loggingOut ? "Keluar..." : "Keluar"}
                    className="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-gray-200 px-2.5 text-sm font-medium text-gray-700 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-60 sm:px-3"
                >
                    {loggingOut ? (
                        <LoaderCircle
                            size={17}
                            className="animate-spin"
                            aria-hidden="true"
                        />
                    ) : (
                        <LogOut
                            size={17}
                            aria-hidden="true"
                        />
                    )}

                    <span className="hidden sm:inline">
                        {loggingOut ? "Keluar..." : "Keluar"}
                    </span>
                </button>
            </div>

            {logoutError && (
                <div
                    role="alert"
                    className="absolute right-3 top-[4.5rem] flex max-w-[calc(100vw-1.5rem)] items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 shadow-md sm:right-6 sm:max-w-sm"
                >
                    <CircleAlert
                        size={18}
                        className="mt-0.5 shrink-0"
                        aria-hidden="true"
                    />

                    <span>{logoutError}</span>
                </div>
            )}
        </header>
    );
}

export default Topbar;
import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import api from "../../../../config/api";

// Pintasan halaman Administrator
const QUICK_ACTIONS = [
    {
        path: "/admin/users",
        icon: "group",
        title: "Manajemen Pengguna",
        desc: "Kelola akun internal.",
    },
    {
        path: "/admin/accounts",
        icon: "account_balance_wallet",
        title: "Rekening Nasabah",
        desc: "Kelola rekening siswa.",
    },
    {
        path: "/admin/journal",
        icon: "menu_book",
        title: "Jurnal Akuntansi",
        desc: "Lihat catatan jurnal transaksi.",
    },
];

const formatNumber = (value) => {
    return Number(value ?? 0).toLocaleString("id-ID");
};

function AdminDashboard() {
    const [dashboard, setDashboard] = useState(null);
    const [isLoading, setIsLoading] = useState(true);
    const [loadError, setLoadError] = useState("");
    const [refreshVersion, setRefreshVersion] = useState(0);

    useEffect(() => {
        const controller = new AbortController();

        const fetchDashboard = async () => {
            try {
                const response = await api.get("/admin/dashboard", {
                    signal: controller.signal,
                });

                setDashboard(response.data?.data ?? null);
                setLoadError("");
            } catch (error) {
                if (controller.signal.aborted) return;

                console.error("Gagal mengambil statistik dashboard:", error);
                setLoadError(
                    error.response?.data?.message ||
                    "Gagal mengambil data dashboard dari server."
                );
            } finally {
                if (!controller.signal.aborted) {
                    setIsLoading(false);
                }
            }
        };

        void fetchDashboard();

        return () => controller.abort();
    }, [refreshVersion]);

    const handleRefresh = () => {
        setIsLoading(true);
        setLoadError("");
        setRefreshVersion((version) => version + 1);
    };

    const getStat = (key) => formatNumber(dashboard?.[key]);

    return (
        <div className="flex flex-col w-full py-space-md gap-space-lg">
            {/* Judul Halaman */}
            <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-space-md pb-space-md border-b border-outline-variant/30">
                <div className="flex flex-col gap-0.5">
                    <h1 className="font-headline-lg text-headline-lg font-w-headline-lg text-on-surface">
                        Dashboard Administrator
                    </h1>

                    <p className="font-body-sm text-body-sm font-w-body-sm text-secondary">
                        Kelola akun pengguna dan rekening siswa
                    </p>
                </div>

                <button
                    type="button"
                    onClick={handleRefresh}
                    disabled={isLoading}
                    className="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg border border-outline-variant/40 text-secondary hover:bg-surface-container-low transition-colors disabled:opacity-50"
                >
                    <span className="material-symbols-outlined text-[18px]">
                        refresh
                    </span>

                    {isLoading ? "Memuat..." : "Perbarui Data"}
                </button>
            </div>

            {/* Pesan Error */}
            {loadError && (
                <div
                    role="alert"
                    className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-lg border border-error/30 bg-error-container/30 p-4"
                >
                    <p className="text-sm text-on-surface">
                        {loadError}
                    </p>

                    <button
                        type="button"
                        onClick={handleRefresh}
                        disabled={isLoading}
                        className="px-3 py-2 rounded-lg border border-error/30 text-sm font-medium hover:bg-error-container disabled:opacity-50"
                    >
                        Coba Lagi
                    </button>
                </div>
            )}

            {/* Kartu Statistik */}
            <section className="grid grid-cols-1 md:grid-cols-2 gap-space-md">
                {/* Card: Pengguna Internal */}
                <div className="bg-surface-container-lowest p-space-md rounded border border-outline-variant/40 flex flex-col justify-between shadow-sm">
                    <div className="flex items-start justify-between">
                        <div className="flex flex-col gap-1">
                            <span className="font-label-sm text-label-sm font-w-label-sm text-secondary uppercase tracking-wider">
                                Pengguna Internal
                            </span>

                            <span className="font-body-md text-body-md font-w-body-md text-on-surface-variant font-medium">
                                Akun Administrator, Teller, &amp; Supervisor
                            </span>
                        </div>

                        <span className="p-1.5 bg-surface-container-low text-primary rounded">
                            <span className="material-symbols-outlined text-[20px]">
                                group
                            </span>
                        </span>
                    </div>

                    <div className="pt-space-md pb-space-xs flex items-baseline gap-space-xs">
                        <span className="font-headline-lg text-headline-lg font-mono-num text-on-surface font-semibold tracking-tight">
                            {getStat("total_users")}
                        </span>

                        <span className="font-body-sm text-body-sm font-w-body-sm text-secondary">
                            Akun Terdaftar
                        </span>
                    </div>

                    <div className="pt-space-xs border-t border-outline-variant/20 flex items-center justify-between font-label-sm text-label-sm font-w-label-sm text-secondary">
                        <span>
                            Teller:{" "}
                            <strong className="text-on-surface font-mono-num font-medium">
                                {getStat("total_tellers")}
                            </strong>
                        </span>

                        <span>
                            Supervisor:{" "}
                            <strong className="text-on-surface font-mono-num font-medium">
                                {getStat("total_supervisors")}
                            </strong>
                        </span>

                        <span>
                            Admin:{" "}
                            <strong className="text-on-surface font-mono-num font-medium">
                                {getStat("total_administrators")}
                            </strong>
                        </span>
                    </div>
                </div>

                {/* Card: Rekening Nasabah */}
                <div className="bg-surface-container-lowest p-space-md rounded border border-outline-variant/40 flex flex-col justify-between shadow-sm">
                    <div className="flex items-start justify-between">
                        <div className="flex flex-col gap-1">
                            <span className="font-label-sm text-label-sm font-w-label-sm text-secondary uppercase tracking-wider">
                                Rekening Nasabah
                            </span>

                            <span className="font-body-md text-body-md font-w-body-md text-on-surface-variant font-medium">
                                Total rekening siswa Bank Mini terdaftar
                            </span>
                        </div>

                        <span className="p-1.5 bg-surface-container-low text-primary rounded">
                            <span className="material-symbols-outlined text-[20px]">
                                account_balance_wallet
                            </span>
                        </span>
                    </div>

                    <div className="pt-space-md pb-space-xs flex items-baseline gap-space-xs">
                        <span className="font-headline-lg text-headline-lg font-mono-num text-on-surface font-semibold tracking-tight">
                            {getStat("total_customers")}
                        </span>

                        <span className="font-body-sm text-body-sm font-w-body-sm text-secondary">
                            Rekening Siswa
                        </span>
                    </div>

                    <div className="pt-space-xs border-t border-outline-variant/20 flex items-center justify-between font-label-sm text-label-sm font-w-label-sm text-secondary">
                        <span className="text-tertiary">
                            Aktif:{" "}
                            <strong className="font-mono-num font-medium">
                                {getStat("active_customers")}
                            </strong>
                        </span>

                        <span className="text-error">
                            Terblokir:{" "}
                            <strong className="font-mono-num font-medium">
                                {getStat("blocked_customers")}
                            </strong>
                        </span>
                    </div>
                </div>
            </section>

            {/* Tindakan Cepat Administrator */}
            <div className="bg-surface-container-lowest p-space-md rounded border border-outline-variant/40 shadow-sm flex flex-col gap-space-sm">
                <div className="flex items-center justify-between">
                    <span className="font-label-sm text-label-sm font-w-label-sm text-secondary uppercase tracking-wider font-semibold">
                        Tindakan Cepat Administrator
                    </span>

                    <span className="font-label-sm text-label-sm font-w-label-sm text-outline">
                        Pintasan Cepat
                    </span>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-space-sm">
                    {QUICK_ACTIONS.map((action) => (
                        <Link
                            key={action.path}
                            to={action.path}
                            className="group flex items-center gap-space-sm p-space-sm rounded border border-outline-variant/40 hover:bg-surface-container-low hover:border-primary-container transition-all text-left"
                        >
                            <div className="w-9 h-9 rounded bg-surface-container flex items-center justify-center text-primary group-hover:bg-primary-container group-hover:text-on-primary transition-colors">
                                <span className="material-symbols-outlined text-[20px]">
                                    {action.icon}
                                </span>
                            </div>

                            <div className="flex flex-col">
                                <span className="font-label-md text-label-md font-w-label-md text-on-surface font-medium leading-tight">
                                    {action.title}
                                </span>

                                <span className="font-body-sm text-body-sm font-w-body-sm text-secondary leading-tight">
                                    {action.desc}
                                </span>
                            </div>
                        </Link>
                    ))}
                </div>
            </div>
        </div>
    );
}

export default AdminDashboard;
import { useEffect, useState } from "react";
import {
    Activity,
    AlertCircle,
    ChevronLeft,
    ChevronRight,
    Eye,
    Filter,
    LoaderCircle,
    RefreshCw,
    Search,
    X,
} from "lucide-react";

import api from "../../../../config/api";

const ACTION_LABELS = {
    "auth.login": "Login",
    "auth.logout": "Logout",
    "transaction.deposit": "Setoran Tunai",
    "transaction.withdrawal": "Penarikan Tunai",
    "daily_report.approve": "Persetujuan Laporan",
    "daily_report.reject": "Penolakan Laporan",
};

const ROLE_LABELS = {
    administrator: "Administrator",
    teller: "Teller",
    supervisor: "Supervisor",
    nasabah: "Nasabah",
};

function formatDateTime(value) {
    if (!value) return "-";

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "long",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
    }).format(date);
}

function getActionLabel(action) {
    return ACTION_LABELS[action] || action || "-";
}

function getRoleLabel(role) {
    return ROLE_LABELS[role] || role || "-";
}

function getActionStyle(action) {
    if (action?.includes("login")) {
        return "bg-blue-50 text-blue-700";
    }

    if (action?.includes("logout")) {
        return "bg-gray-100 text-gray-700";
    }

    if (action?.includes("deposit")) {
        return "bg-green-50 text-green-700";
    }

    if (action?.includes("withdrawal")) {
        return "bg-amber-50 text-amber-700";
    }

    if (action?.includes("approve")) {
        return "bg-emerald-50 text-emerald-700";
    }

    if (action?.includes("reject")) {
        return "bg-red-50 text-red-700";
    }

    return "bg-purple-50 text-purple-700";
}

function ActivityLog() {
    const [logs, setLogs] = useState([]);
    const [pagination, setPagination] = useState({
        currentPage: 1,
        lastPage: 1,
        total: 0,
        perPage: 15,
    });

    const [filters, setFilters] = useState({
        username: "",
        role: "",
        action: "",
        date: "",
    });

    const [searchInput, setSearchInput] = useState("");
    const [page, setPage] = useState(1);

    const [selectedLog, setSelectedLog] = useState(null);
    const [isLoadingDetail, setIsLoadingDetail] = useState(false);

    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState("");
    const [detailError, setDetailError] = useState("");
    const [refreshVersion, setRefreshVersion] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        const params = { page };

        if (filters.username) params.username = filters.username;
        if (filters.role) params.role = filters.role;
        if (filters.action) params.action = filters.action;
        if (filters.date) params.date = filters.date;

        const fetchLogs = async () => {
            try {
                const response = await api.get("/web/activity-logs", {
                    params,
                    signal: controller.signal,
                });

                if (controller.signal.aborted) return;

                const paginator = response.data?.data;
                setLogs(Array.isArray(paginator?.data) ? paginator.data : []);
                setPagination({
                    currentPage: paginator?.current_page ?? 1,
                    lastPage: paginator?.last_page ?? 1,
                    total: paginator?.total ?? 0,
                    perPage: paginator?.per_page ?? 15,
                });
                setError("");
            } catch (err) {
                if (controller.signal.aborted) return;

                setLogs([]);
                setError(
                    err.response?.data?.message ||
                        "Gagal mengambil data aktivitas. Silakan coba lagi."
                );
            } finally {
                if (!controller.signal.aborted) {
                    setIsLoading(false);
                }
            }
        };

        void fetchLogs();

        return () => controller.abort();
    }, [filters, page, refreshVersion]);

    const handleRefresh = () => {
        setIsLoading(true);
        setError("");
        setRefreshVersion((version) => version + 1);
    };

    const handleSearch = (event) => {
        event.preventDefault();
        setIsLoading(true);
        setError("");

        setFilters((current) => ({
            ...current,
            username: searchInput.trim(),
        }));

        setPage(1);
    };

    const handleFilterChange = (key, value) => {
        setIsLoading(true);
        setError("");

        setFilters((current) => ({
            ...current,
            [key]: value,
        }));

        setPage(1);
    };

    const handleResetFilters = () => {
        setSearchInput("");
        setIsLoading(true);
        setError("");

        setFilters({
            username: "",
            role: "",
            action: "",
            date: "",
        });

        setPage(1);
    };

    const handlePageChange = (nextPage) => {
        setIsLoading(true);
        setError("");
        setPage(nextPage);
    };

    const handleViewDetail = async (log) => {
        setSelectedLog(null);
        setDetailError("");
        setIsLoadingDetail(true);

        try {
            const response = await api.get(
                `/web/activity-logs/${log.id}`
            );

            setSelectedLog(response.data?.data ?? log);
        } catch (err) {
            setSelectedLog(log);

            setDetailError(
                err.response?.data?.message ||
                    "Gagal mengambil detail aktivitas."
            );
        } finally {
            setIsLoadingDetail(false);
        }
    };

    const firstItem =
        pagination.total === 0
            ? 0
            : (pagination.currentPage - 1) * pagination.perPage + 1;

    const lastItem = Math.min(
        pagination.currentPage * pagination.perPage,
        pagination.total
    );

    return (
        <div className="flex w-full flex-col gap-6 py-6">
            {/* Header */}
            <div className="flex flex-col justify-between gap-4 border-b border-gray-200 pb-5 sm:flex-row sm:items-center">
                <div>
                    <div className="flex items-center gap-3">
                        <div className="rounded-xl bg-blue-50 p-3 text-blue-600">
                            <Activity size={25} />
                        </div>

                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">
                                Log Aktivitas
                            </h1>

                            <p className="mt-1 text-sm text-gray-500">
                                Pantau aktivitas pengguna dan transaksi
                                pada sistem E-Teller Bank Mini.
                            </p>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    onClick={handleRefresh}
                    disabled={isLoading}
                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <RefreshCw
                        size={16}
                        className={isLoading ? "animate-spin" : ""}
                    />
                    Muat Ulang
                </button>
            </div>

            {/* Error */}
            {error && (
                <div
                    role="alert"
                    className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"
                >
                    <AlertCircle size={20} className="shrink-0" />

                    <div className="flex-1">
                        <p>{error}</p>
                    </div>

                    <button
                        type="button"
                        onClick={handleRefresh}
                        className="font-medium underline"
                    >
                        Coba lagi
                    </button>
                </div>
            )}

            {/* Summary */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p className="text-sm text-gray-500">
                        Total Aktivitas
                    </p>

                    <p className="mt-2 text-3xl font-bold text-gray-900">
                        {isLoading ? "..." : pagination.total}
                    </p>

                    <p className="mt-1 text-xs text-gray-500">
                        Berdasarkan filter yang digunakan.
                    </p>
                </div>

                <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p className="text-sm text-gray-500">
                        Halaman Aktif
                    </p>

                    <p className="mt-2 text-3xl font-bold text-blue-600">
                        {isLoading
                            ? "..."
                            : `${pagination.currentPage} / ${pagination.lastPage}`}
                    </p>

                    <p className="mt-1 text-xs text-gray-500">
                        Menampilkan maksimal {pagination.perPage} aktivitas
                        per halaman.
                    </p>
                </div>
            </div>

            {/* Filters */}
            <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div className="mb-4 flex items-center gap-2">
                    <Filter size={19} className="text-blue-600" />

                    <h2 className="font-semibold text-gray-900">
                        Filter Aktivitas
                    </h2>
                </div>

                <form
                    onSubmit={handleSearch}
                    className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <div>
                        <label
                            htmlFor="activity-username"
                            className="mb-2 block text-sm font-medium text-gray-700"
                        >
                            Username
                        </label>

                        <div className="relative">
                            <Search
                                size={17}
                                className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"
                            />

                            <input
                                id="activity-username"
                                type="text"
                                value={searchInput}
                                onChange={(event) =>
                                    setSearchInput(event.target.value)
                                }
                                placeholder="Cari username..."
                                className="w-full rounded-lg border border-gray-300 py-2.5 pl-9 pr-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                            />
                        </div>
                    </div>

                    <div>
                        <label
                            htmlFor="activity-role"
                            className="mb-2 block text-sm font-medium text-gray-700"
                        >
                            Role
                        </label>

                        <select
                            id="activity-role"
                            value={filters.role}
                            onChange={(event) =>
                                handleFilterChange(
                                    "role",
                                    event.target.value
                                )
                            }
                            className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                        >
                            <option value="">Semua Role</option>
                            <option value="administrator">
                                Administrator
                            </option>
                            <option value="supervisor">Supervisor</option>
                            <option value="teller">Teller</option>
                            <option value="nasabah">Nasabah</option>
                        </select>
                    </div>

                    <div>
                        <label
                            htmlFor="activity-action"
                            className="mb-2 block text-sm font-medium text-gray-700"
                        >
                            Jenis Aktivitas
                        </label>

                        <select
                            id="activity-action"
                            value={filters.action}
                            onChange={(event) =>
                                handleFilterChange(
                                    "action",
                                    event.target.value
                                )
                            }
                            className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                        >
                            <option value="">Semua Aktivitas</option>
                            {Object.entries(ACTION_LABELS).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                )
                            )}
                        </select>
                    </div>

                    <div>
                        <label
                            htmlFor="activity-date"
                            className="mb-2 block text-sm font-medium text-gray-700"
                        >
                            Tanggal
                        </label>

                        <input
                            id="activity-date"
                            type="date"
                            value={filters.date}
                            onChange={(event) =>
                                handleFilterChange(
                                    "date",
                                    event.target.value
                                )
                            }
                            className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                        />
                    </div>

                    <div className="flex flex-wrap gap-2 sm:col-span-2 xl:col-span-4">
                        <button
                            type="submit"
                            className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-700"
                        >
                            <Search size={16} />
                            Cari Aktivitas
                        </button>

                        <button
                            type="button"
                            onClick={handleResetFilters}
                            className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                        >
                            <X size={16} />
                            Reset Filter
                        </button>
                    </div>
                </form>
            </div>

            {/* Table */}
            <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div className="border-b border-gray-200 px-5 py-4">
                    <h2 className="font-semibold text-gray-900">
                        Riwayat Aktivitas
                    </h2>

                    <p className="mt-1 text-sm text-gray-500">
                        Daftar aktivitas yang tercatat dalam sistem.
                    </p>
                </div>

                {isLoading ? (
                    <div className="flex flex-col items-center justify-center gap-3 px-5 py-16 text-gray-500">
                        <LoaderCircle
                            size={28}
                            className="animate-spin text-blue-600"
                        />

                        <p className="text-sm">
                            Mengambil data aktivitas...
                        </p>
                    </div>
                ) : logs.length === 0 ? (
                    <div className="flex flex-col items-center justify-center px-5 py-16 text-center">
                        <Activity
                            size={36}
                            className="mb-3 text-gray-300"
                        />

                        <p className="font-medium text-gray-800">
                            Tidak ada aktivitas ditemukan
                        </p>

                        <p className="mt-1 text-sm text-gray-500">
                            Coba ubah filter atau muat ulang data.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[900px] text-left text-sm">
                            <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="px-5 py-4">No.</th>
                                    <th className="px-5 py-4">Pengguna</th>
                                    <th className="px-5 py-4">Role</th>
                                    <th className="px-5 py-4">Aktivitas</th>
                                    <th className="px-5 py-4">Waktu</th>
                                    <th className="px-5 py-4 text-right">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y divide-gray-100">
                                {logs.map((log, index) => (
                                    <tr
                                        key={log.id}
                                        className="transition hover:bg-gray-50"
                                    >
                                        <td className="px-5 py-4 text-gray-500">
                                            {firstItem + index}
                                        </td>

                                        <td className="px-5 py-4">
                                            <p className="font-medium text-gray-900">
                                                {log.username || "-"}
                                            </p>

                                            <p className="mt-1 text-xs text-gray-500">
                                                ID: {log.user_id ?? "-"}
                                            </p>
                                        </td>

                                        <td className="px-5 py-4">
                                            <span className="inline-flex rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                                                {getRoleLabel(log.role)}
                                            </span>
                                        </td>

                                        <td className="px-5 py-4">
                                            <span
                                                className={`inline-flex rounded-full px-3 py-1 text-xs font-medium ${getActionStyle(log.action)}`}
                                            >
                                                {getActionLabel(log.action)}
                                            </span>

                                            <p className="mt-1 max-w-xs truncate text-xs text-gray-500">
                                                {log.description || "-"}
                                            </p>
                                        </td>

                                        <td className="whitespace-nowrap px-5 py-4 text-gray-600">
                                            {formatDateTime(log.created_at)}
                                        </td>

                                        <td className="px-5 py-4 text-right">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    handleViewDetail(log)
                                                }
                                                className="inline-flex items-center gap-2 rounded-lg border border-blue-200 px-3 py-2 text-xs font-medium text-blue-700 transition hover:bg-blue-50"
                                            >
                                                <Eye size={15} />
                                                Detail
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Pagination */}
                <div className="flex flex-col justify-between gap-3 border-t border-gray-200 px-5 py-4 sm:flex-row sm:items-center">
                    <p className="text-sm text-gray-500">
                        Menampilkan {firstItem}–{lastItem} dari{" "}
                        {pagination.total} aktivitas
                    </p>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => handlePageChange(page - 1)}
                            disabled={
                                isLoading || pagination.currentPage <= 1
                            }
                            className="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <ChevronLeft size={16} />
                            Sebelumnya
                        </button>

                        <span className="px-2 text-sm text-gray-600">
                            {pagination.currentPage} / {pagination.lastPage}
                        </span>

                        <button
                            type="button"
                            onClick={() => handlePageChange(page + 1)}
                            disabled={
                                isLoading ||
                                pagination.currentPage >= pagination.lastPage
                            }
                            className="inline-flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Berikutnya
                            <ChevronRight size={16} />
                        </button>
                    </div>
                </div>
            </div>

            {/* Detail Modal */}
            {selectedLog && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="activity-detail-title"
                        className="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl"
                    >
                        <div className="flex items-start justify-between gap-4 border-b border-gray-200 pb-4">
                            <div>
                                <h2
                                    id="activity-detail-title"
                                    className="text-xl font-bold text-gray-900"
                                >
                                    Detail Aktivitas
                                </h2>

                                <p className="mt-1 text-sm text-gray-500">
                                    Informasi lengkap aktivitas yang tercatat.
                                </p>
                            </div>

                            <button
                                type="button"
                                onClick={() => {
                                    setSelectedLog(null);
                                    setDetailError("");
                                }}
                                aria-label="Tutup detail aktivitas"
                                className="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                            >
                                <X size={20} />
                            </button>
                        </div>

                        {isLoadingDetail ? (
                            <div className="flex flex-col items-center gap-3 py-12 text-gray-500">
                                <LoaderCircle
                                    size={28}
                                    className="animate-spin text-blue-600"
                                />

                                <p className="text-sm">
                                    Memuat detail aktivitas...
                                </p>
                            </div>
                        ) : (
                            <div className="mt-5 space-y-4">
                                {detailError && (
                                    <p
                                        role="alert"
                                        className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800"
                                    >
                                        {detailError}
                                    </p>
                                )}

                                <DetailRow
                                    label="ID Aktivitas"
                                    value={selectedLog.id}
                                />

                                <DetailRow
                                    label="Username"
                                    value={selectedLog.username}
                                />

                                <DetailRow
                                    label="ID Pengguna"
                                    value={selectedLog.user_id}
                                />

                                <DetailRow
                                    label="Role"
                                    value={getRoleLabel(selectedLog.role)}
                                />

                                <DetailRow
                                    label="Jenis Aktivitas"
                                    value={getActionLabel(selectedLog.action)}
                                />

                                <DetailRow
                                    label="Kode Aktivitas"
                                    value={selectedLog.action}
                                />

                                <DetailRow
                                    label="Deskripsi"
                                    value={selectedLog.description}
                                />

                                <DetailRow
                                    label="Jenis Entitas"
                                    value={selectedLog.entity_type}
                                />

                                <DetailRow
                                    label="ID Entitas"
                                    value={selectedLog.entity_id}
                                />

                                <DetailRow
                                    label="Alamat IP"
                                    value={selectedLog.ip_address}
                                />

                                <DetailRow
                                    label="Waktu Aktivitas"
                                    value={formatDateTime(
                                        selectedLog.created_at
                                    )}
                                />

                                <div className="flex justify-end border-t border-gray-200 pt-4">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setSelectedLog(null);
                                            setDetailError("");
                                        }}
                                        className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-gray-800"
                                    >
                                        Tutup
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

function DetailRow({ label, value }) {
    return (
        <div className="grid grid-cols-1 gap-1 sm:grid-cols-[150px_1fr] sm:gap-4">
            <p className="text-sm text-gray-500">{label}</p>

            <p className="break-words text-sm font-medium text-gray-900">
                {value === null || value === undefined || value === ""
                    ? "-"
                    : String(value)}
            </p>
        </div>
    );
}

export default ActivityLog;
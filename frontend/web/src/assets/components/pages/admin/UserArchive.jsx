import { useCallback, useEffect, useState } from "react";
import api from "../../../../config/api";

const ROLE_LABELS = {
    teller: "Teller",
    nasabah: "Nasabah",
};

function formatBlockedAt(value) {
    if (!value) return "-";

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? "-" : date.toLocaleString("id-ID");
}

function UserArchive() {
    const [users, setUsers] = useState([]);
    const [roleFilter, setRoleFilter] = useState("");
    const [isLoading, setIsLoading] = useState(true);
    const [loadError, setLoadError] = useState("");
    const [successMessage, setSuccessMessage] = useState("");
    const [actionError, setActionError] = useState("");
    const [restoringId, setRestoringId] = useState(null);
    const [detailUser, setDetailUser] = useState(null);
    const [isLoadingDetail, setIsLoadingDetail] = useState(false);
    const [detailError, setDetailError] = useState("");

    const fetchArchivedUsers = useCallback(async (signal) => {
        try {
            const response = await api.get("/admin/users/archived", { signal });
            setUsers(Array.isArray(response.data?.data) ? response.data.data : []);
            setLoadError("");
        } catch (error) {
            if (signal?.aborted) return;
            setLoadError(
                error.response?.data?.message ||
                    "Gagal mengambil data arsip pengguna dari server."
            );
        } finally {
            if (!signal?.aborted) setIsLoading(false);
        }
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        const loadArchivedUsers = async () => {
            try {
                const response = await api.get("/admin/users/archived", {
                    signal: controller.signal,
                });
                if (controller.signal.aborted) return;

                setUsers(Array.isArray(response.data?.data) ? response.data.data : []);
                setLoadError("");
            } catch (error) {
                if (controller.signal.aborted) return;
                setLoadError(
                    error.response?.data?.message ||
                        "Gagal mengambil data arsip pengguna dari server."
                );
            } finally {
                if (!controller.signal.aborted) setIsLoading(false);
            }
        };

        void loadArchivedUsers();
        return () => controller.abort();
    }, []);

    const handleRestore = async (user) => {
        const name = user.full_name || user.name || user.username;
        if (!window.confirm(`Pulihkan akun "${name}" dan aktifkan kembali aksesnya?`)) {
            return;
        }

        setRestoringId(user.id);
        setIsLoading(true);
        setActionError("");

        try {
            const response = await api.put(`/admin/users/${user.id}/restore`);
            setSuccessMessage(response.data?.message || `Akun "${name}" berhasil dipulihkan.`);
            await fetchArchivedUsers();
        } catch (error) {
            setActionError(
                error.response?.data?.message || "Gagal memulihkan akun pengguna."
            );
        } finally {
            setRestoringId(null);
        }
    };

    const handleRefresh = () => {
        setIsLoading(true);
        void fetchArchivedUsers();
    };

    const handleShowDetail = async (user) => {
        setDetailUser(null);
        setDetailError("");
        setIsLoadingDetail(true);

        try {
            const response = await api.get(`/admin/users/${user.id}`);
            setDetailUser(response.data?.data ?? null);
            if (!response.data?.data) {
                setDetailError("Detail pengguna tidak tersedia.");
            }
        } catch (error) {
            setDetailError(
                error.response?.data?.message || "Gagal mengambil detail pengguna."
            );
        } finally {
            setIsLoadingDetail(false);
        }
    };

    const filteredUsers = users.filter(
        (user) => !roleFilter || user.role === roleFilter
    );

    return (
        <div className="flex w-full flex-col gap-space-lg py-space-md">
            <div className="flex flex-col gap-space-md md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 className="font-headline-lg text-headline-lg font-w-headline-lg text-on-surface tracking-tight">
                        Arsip Pengguna
                    </h1>
                    <p className="mt-0.5 font-body-md text-body-md font-w-body-md text-secondary">
                        Akun nasabah dan teller yang diblokir. Data rekening dan riwayat transaksi tetap tersimpan.
                    </p>
                </div>
                <span className="w-fit rounded-full bg-surface-container px-3 py-1 font-label-sm text-label-sm text-secondary">
                    Total {isLoading ? "..." : filteredUsers.length} akun
                </span>
            </div>

            {successMessage && (
                <div className="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                    <span>{successMessage}</span>
                    <button type="button" onClick={() => setSuccessMessage("")} aria-label="Tutup notifikasi">
                        <span className="material-symbols-outlined text-[18px]">close</span>
                    </button>
                </div>
            )}
            {actionError && (
                <div className="flex items-center justify-between rounded-lg border border-error/20 bg-error-container/40 p-3 text-sm text-error">
                    <span>{actionError}</span>
                    <button type="button" onClick={() => setActionError("")} aria-label="Tutup notifikasi">
                        <span className="material-symbols-outlined text-[18px]">close</span>
                    </button>
                </div>
            )}

            <div className="flex items-center gap-space-sm rounded-xl bg-surface-container-lowest p-space-md shadow-sm">
                <label htmlFor="archive-role-filter" className="font-label-md text-label-md text-secondary">
                    Filter peran
                </label>
                <select
                    id="archive-role-filter"
                    className="h-10 min-w-[170px] rounded-lg border border-outline-variant/30 bg-surface px-3 text-on-surface"
                    value={roleFilter}
                    onChange={(event) => setRoleFilter(event.target.value)}
                >
                    <option value="">Semua Peran</option>
                    <option value="nasabah">Nasabah</option>
                    <option value="teller">Teller</option>
                </select>
            </div>

            <div className="overflow-hidden rounded-xl bg-surface-container-lowest shadow-sm">
                <div className="w-full overflow-x-auto">
                    <table className="w-full border-collapse text-left">
                        <thead>
                            <tr className="bg-surface-container-low font-label-sm text-label-sm uppercase tracking-wider text-secondary">
                                <th className="px-4 py-3.5">Nama</th>
                                <th className="px-4 py-3.5">Username</th>
                                <th className="px-4 py-3.5">Email</th>
                                <th className="px-4 py-3.5">Peran</th>
                                <th className="px-4 py-3.5">Tanggal Arsip</th>
                                <th className="px-4 py-3.5 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-outline-variant/20 text-sm text-on-surface">
                            {isLoading ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-secondary">
                                        Memuat arsip pengguna...
                                    </td>
                                </tr>
                            ) : loadError ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-error">
                                        <div className="flex flex-col items-center gap-2">
                                            <span>{loadError}</span>
                                            <button
                                                type="button"
                                                className="rounded bg-surface-container px-3 py-1 text-xs font-semibold"
                                                onClick={handleRefresh}
                                            >
                                                Coba Lagi
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ) : filteredUsers.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-secondary">
                                        {users.length === 0
                                            ? "Belum ada pengguna yang diarsipkan."
                                            : "Tidak ada pengguna dengan peran tersebut di arsip."}
                                    </td>
                                </tr>
                            ) : (
                                filteredUsers.map((user) => (
                                    <tr key={user.id} className="hover:bg-surface-container-low">
                                        <td className="px-4 py-3.5 font-semibold">
                                            {user.full_name || user.name || "-"}
                                        </td>
                                        <td className="px-4 py-3.5 font-mono text-secondary">
                                            {user.username || "-"}
                                        </td>
                                        <td className="px-4 py-3.5 text-secondary">
                                            {user.email || "-"}
                                        </td>
                                        <td className="px-4 py-3.5">
                                            {ROLE_LABELS[user.role] || user.role || "-"}
                                        </td>
                                        <td className="px-4 py-3.5 text-secondary">
                                            {formatBlockedAt(user.blocked_at)}
                                        </td>
                                        <td className="px-4 py-3.5 text-right">
                                            <div className="inline-flex items-center gap-3">
                                                <button
                                                    type="button"
                                                    className="font-semibold text-primary hover:underline"
                                                    onClick={() => void handleShowDetail(user)}
                                                >
                                                    Detail
                                                </button>
                                                <button
                                                    type="button"
                                                    className="font-semibold text-tertiary hover:underline disabled:opacity-50"
                                                    disabled={restoringId === user.id}
                                                    onClick={() => void handleRestore(user)}
                                                >
                                                    {restoringId === user.id ? "Memulihkan..." : "Pulihkan"}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {(isLoadingDetail || detailError || detailUser) && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true">
                    <div className="w-full max-w-lg rounded-xl border border-outline-variant/30 bg-surface-container-lowest p-space-lg shadow-xl">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="font-headline-sm text-headline-sm font-w-headline-sm text-on-surface">
                                Detail Pengguna Arsip
                            </h2>
                            <button
                                type="button"
                                className="text-secondary hover:text-on-surface"
                                aria-label="Tutup detail"
                                onClick={() => {
                                    setDetailUser(null);
                                    setDetailError("");
                                }}
                            >
                                <span className="material-symbols-outlined">close</span>
                            </button>
                        </div>
                        {isLoadingDetail ? (
                            <p className="py-6 text-center text-secondary">Memuat detail pengguna...</p>
                        ) : detailError ? (
                            <p className="py-6 text-center text-error">{detailError}</p>
                        ) : detailUser ? (
                            <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div><dt className="text-xs text-secondary">Nama</dt><dd className="font-medium">{detailUser.full_name || detailUser.name || "-"}</dd></div>
                                <div><dt className="text-xs text-secondary">Username</dt><dd className="font-medium">{detailUser.username || "-"}</dd></div>
                                <div><dt className="text-xs text-secondary">Email</dt><dd className="font-medium">{detailUser.email || "-"}</dd></div>
                                <div><dt className="text-xs text-secondary">Peran</dt><dd className="font-medium">{ROLE_LABELS[detailUser.role] || detailUser.role || "-"}</dd></div>
                                <div><dt className="text-xs text-secondary">Status</dt><dd className="font-medium">Diblokir</dd></div>
                                <div><dt className="text-xs text-secondary">Tanggal Arsip</dt><dd className="font-medium">{formatBlockedAt(detailUser.blocked_at)}</dd></div>
                            </dl>
                        ) : null}
                    </div>
                </div>
            )}
        </div>
    );
}

export default UserArchive;

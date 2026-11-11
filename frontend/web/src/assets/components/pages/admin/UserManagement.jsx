import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import api from "../../../../config/api";

const ROLE_LABELS = {
    administrator: "Administrator",
    teller: "Teller",
    supervisor: "Supervisor",
    nasabah: "Nasabah",
};

const STATUS_LABELS = {
    active: "Aktif",
    aktif: "Aktif",
    blocked: "Diblokir",
    diblokir: "Diblokir",
    inactive: "Tidak Aktif",
    nonaktif: "Tidak Aktif",
};

const getRoleLabel = (role) => ROLE_LABELS[role] ?? role ?? "-";

const getStatusLabel = (status) => {
    const normalizedStatus = String(status ?? "").toLowerCase();

    return STATUS_LABELS[normalizedStatus] ?? status ?? "-";
};

const ROLE_BADGE = {
    Administrator: "bg-primary/10 text-primary",
    Teller: "bg-surface-variant text-on-surface-variant",
    Supervisor: "bg-secondary-fixed text-on-secondary-fixed",
    Nasabah: "bg-tertiary-fixed text-on-tertiary-fixed",
};

const INITIAL_FORM_STATE = {
    fullName: "",
    username: "",
    email: "",
    password: "",
    passwordConfirmation: "",
    role: "administrator",
};

async function requestUsers(signal) {
    const response = await api.get("/admin/users", { signal });
    const data = response.data?.data;

    return Array.isArray(data) ? data : [];
}

function UserManagement() {
    // State data pengguna dari API
    const [users, setUsers] = useState([]);
    const [isLoading, setIsLoading] = useState(true);
    const [loadError, setLoadError] = useState("");
    const [successMessage, setSuccessMessage] = useState("");
    const [actionError, setActionError] = useState("");
    const usersRequestRef = useRef(null);
    const [detailUser, setDetailUser] = useState(null);
    const [isLoadingDetail, setIsLoadingDetail] = useState(false);
    const [detailError, setDetailError] = useState("");

    // State filter dan pencarian
    const [searchQuery, setSearchQuery] = useState("");
    const [roleFilter, setRoleFilter] = useState("");
    const [statusFilter, setStatusFilter] = useState("");

    // State modal tambah / ubah pengguna
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingUserId, setEditingUserId] = useState(null);
    const [formData, setFormData] = useState(INITIAL_FORM_STATE);
    const [fieldErrors, setFieldErrors] = useState({});
    const [formError, setFormError] = useState("");
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [showPassword, setShowPassword] = useState(false);

    // Fungsi mengambil daftar user dari API Laravel
    const fetchUsers = useCallback(async () => {
        usersRequestRef.current?.abort();
        const controller = new AbortController();
        usersRequestRef.current = controller;

        try {
            const data = await requestUsers(controller.signal);

            if (controller.signal.aborted) return;

            setUsers(data);
            setLoadError("");
        } catch (error) {
            if (controller.signal.aborted) return;

            setLoadError(
                error.response?.data?.message ||
                "Gagal mengambil data pengguna dari server."
            );
        } finally {
            if (!controller.signal.aborted) {
                setIsLoading(false);
                usersRequestRef.current = null;
            }
        }
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        usersRequestRef.current = controller;

        const loadInitialUsers = async () => {
            try {
                const data = await requestUsers(controller.signal);
                if (controller.signal.aborted) return;

                setUsers(data);
                setLoadError("");
            } catch (error) {
                if (controller.signal.aborted) return;

                setLoadError(
                    error.response?.data?.message ||
                    "Gagal mengambil data pengguna dari server."
                );
            } finally {
                if (!controller.signal.aborted) {
                    setIsLoading(false);
                    usersRequestRef.current = null;
                }
            }
        };

        void loadInitialUsers();

        return () => usersRequestRef.current?.abort();
    }, []);

    const handleRefreshUsers = () => {
        setIsLoading(true);
        setLoadError("");
        void fetchUsers();
    };

    // Buka modal untuk tambah user baru
    const openAddModal = () => {
        setEditingUserId(null);
        setFormData(INITIAL_FORM_STATE);
        setFieldErrors({});
        setFormError("");
        setShowPassword(false);
        setIsModalOpen(true);
    };

    // Buka modal untuk ubah user yang sudah ada
    const openEditModal = (user) => {
        setEditingUserId(user.id);
        setFormData({
            fullName: user.full_name || user.name || "",
            username: user.username || "",
            email: user.email || "",
            password: "",
            passwordConfirmation: "",
            role: user.role || "teller",
        });
        setFieldErrors({});
        setFormError("");
        setShowPassword(false);
        setIsModalOpen(true);
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setEditingUserId(null);
        setFormData(INITIAL_FORM_STATE);
        setFieldErrors({});
        setFormError("");
        setShowPassword(false);
    };

    // Pintasan untuk mengisi password default pengujian
    const applyDefaultPassword = () => {
        setFormData((prev) => ({
            ...prev,
            password: "password123",
            passwordConfirmation: "password123",
        }));
        setFieldErrors((prev) => ({
            ...prev,
            password: "",
            passwordConfirmation: "",
        }));
    };

    // Validasi dan simpan pengguna ke API Laravel
    const handleSaveUser = async (e) => {
        if (e) e.preventDefault();

        setFormError("");
        setFieldErrors({});

        const errors = {};

        if (!formData.fullName.trim()) {
            errors.fullName = "Nama lengkap wajib diisi.";
        }

        if (!formData.username.trim()) {
            errors.username = "Username wajib diisi.";
        }

        if (!formData.email.trim()) {
            errors.email = "Email sekolah wajib diisi.";
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email.trim())) {
            errors.email = "Format email tidak valid.";
        }

        // Pada mode tambah, password wajib minimal 8 karakter
        if (!editingUserId) {
            if (!formData.password) {
                errors.password = "Password wajib diisi (minimal 8 karakter).";
            } else if (formData.password.length < 8) {
                errors.password = "Password minimal 8 karakter.";
            }

            if (formData.password !== formData.passwordConfirmation) {
                errors.passwordConfirmation = "Konfirmasi password tidak cocok.";
            }
        } else {
            // Pada mode ubah, jika password diisi maka harus minimal 8 karakter dan cocok
            if (formData.password) {
                if (formData.password.length < 8) {
                    errors.password = "Password minimal 8 karakter.";
                }
                if (formData.password !== formData.passwordConfirmation) {
                    errors.passwordConfirmation = "Konfirmasi password tidak cocok.";
                }
            }
        }

        if (Object.keys(errors).length > 0) {
            setFieldErrors(errors);
            return;
        }

        setIsSubmitting(true);

        try {
            if (editingUserId) {
                // Update user yang sudah ada
                const updatePayload = {
                    name: formData.fullName.trim(),
                    full_name: formData.fullName.trim(),
                    username: formData.username.trim(),
                    email: formData.email.trim(),
                    role: formData.role,
                };

                if (formData.password) {
                    updatePayload.password = formData.password;
                    updatePayload.password_confirmation = formData.passwordConfirmation;
                }

                const response = await api.put(`/admin/users/${editingUserId}`, updatePayload);

                setSuccessMessage(
                    response.data?.message || `Pengguna "${formData.fullName.trim()}" berhasil diperbarui.`
                );
            } else {
                // Tambah user baru (kontrak StoreUserRequest: name, full_name, username, email, password, password_confirmation, role)
                const createPayload = {
                    name: formData.fullName.trim(),
                    full_name: formData.fullName.trim(),
                    username: formData.username.trim(),
                    email: formData.email.trim(),
                    password: formData.password,
                    password_confirmation: formData.passwordConfirmation,
                    role: formData.role,
                };

                const response = await api.post("/admin/users", createPayload);
                setSuccessMessage(
                    response.data?.message || `Pengguna "${formData.fullName.trim()}" berhasil didaftarkan.`
                );
            }

            await fetchUsers();
            closeModal();
        } catch (error) {
            const validationErrors = error.response?.data?.errors;
            if (validationErrors) {
                const mapped = {};
                if (validationErrors.name || validationErrors.full_name) {
                    mapped.fullName = (validationErrors.full_name || validationErrors.name)[0];
                }
                if (validationErrors.username) {
                    mapped.username = validationErrors.username[0];
                }
                if (validationErrors.email) {
                    mapped.email = validationErrors.email[0];
                }
                if (validationErrors.password) {
                    mapped.password = validationErrors.password[0];
                }
                if (validationErrors.password_confirmation) {
                    mapped.passwordConfirmation = validationErrors.password_confirmation[0];
                }
                if (validationErrors.role) {
                    mapped.role = validationErrors.role[0];
                }
                setFieldErrors(mapped);
            }

            setFormError(
                error.response?.data?.message ||
                "Gagal menyimpan pengguna ke sistem. Periksa kembali isian formulir."
            );
        } finally {
            setIsSubmitting(false);
        }
    };

    // Toggle Aktif / Blokir pengguna
    const handleToggleStatus = async (user) => {
        const isBlocked = String(user.status ?? "").toLowerCase() === "blocked";
        const nextStatus = isBlocked ? "active" : "blocked";
        const actionLabel = isBlocked ? "mengaktifkan kembali" : "memblokir";

        if (!window.confirm(`Apakah Anda yakin ingin ${actionLabel} akun "${user.full_name || user.username}"?`)) {
            return;
        }

        try {
            await api.put(`/admin/users/${user.id}`, { status: nextStatus });
            setSuccessMessage(
                `Akun "${user.full_name || user.username}" berhasil diubah menjadi ${nextStatus === "active" ? "Aktif" : "Diblokir"}.`
            );
            setActionError("");
            await fetchUsers();
        } catch (error) {
            setActionError(error.response?.data?.message || "Gagal memblokir akun pengguna.");
        }
    };

    const handleArchiveUser = async (user) => {
        if (!window.confirm(`Blokir dan arsipkan akun "${user.full_name || user.username}"? Data rekening dan riwayat transaksi akan tetap tersimpan.`)) {
            return;
        }

        try {
            const response = await api.delete(`/admin/users/${user.id}`);
            setSuccessMessage(
                response.data?.message ||
                    `Akun "${user.full_name || user.username}" berhasil dipindahkan ke arsip.`
            );
            setActionError("");
            await fetchUsers();
        } catch (error) {
            setActionError(
                error.response?.data?.message ||
                "Gagal memblokir dan mengarsipkan akun pengguna."
            );
        }
    };

    const handleShowDetail = async (user) => {
        setDetailUser(null);
        setDetailError("");
        setIsLoadingDetail(true);

        try {
            const response = await api.get(`/admin/users/${user.id}`);
            setDetailUser(response.data?.data ?? null);
            if (!response.data?.data) setDetailError("Detail pengguna tidak tersedia.");
        } catch (error) {
            setDetailError(
                error.response?.data?.message || "Gagal mengambil detail pengguna."
            );
        } finally {
            setIsLoadingDetail(false);
        }
    };

    // Filter daftar pengguna berdasarkan input search, peran, dan status
    const filteredUsers = useMemo(() => {
        const query = searchQuery.trim().toLowerCase();

        return users.filter((user) => {
            const matchesQuery =
                !query ||
                (user.full_name ?? "").toLowerCase().includes(query) ||
                (user.name ?? "").toLowerCase().includes(query) ||
                (user.username ?? "").toLowerCase().includes(query) ||
                (user.email ?? "").toLowerCase().includes(query);

            const matchesRole =
                !roleFilter ||
                String(user.role ?? "").toLowerCase() === roleFilter.toLowerCase();

            const isBlocked = String(user.status ?? "").toLowerCase() === "blocked";
            const matchesStatus =
                !statusFilter ||
                (statusFilter === "aktif" && !isBlocked) ||
                (statusFilter === "diblokir" && isBlocked);

            return matchesQuery && matchesRole && matchesStatus;
        });
    }, [users, searchQuery, roleFilter, statusFilter]);

    return (
        <div className="flex flex-col w-full py-space-md gap-space-lg">
            {/* Header & Primary Actions */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-space-md">
                <div className="flex flex-col">
                    <div className="flex items-center gap-3">
                        <h1 className="font-headline-lg text-headline-lg font-w-headline-lg text-on-surface tracking-tight">
                            Manajemen Pengguna
                        </h1>
                        <span className="px-2.5 py-0.5 rounded-full bg-surface-container font-label-sm text-label-sm font-w-label-sm text-secondary font-medium">
                            Total {isLoading ? "..." : users.length} pengguna terdaftar
                        </span>
                    </div>
                    <p className="font-body-md text-body-md font-w-body-md text-secondary mt-0.5">
                        Kelola akun Administrator, Teller, Supervisor, dan Nasabah Bank Mini.
                    </p>
                </div>
                <div className="flex items-center gap-space-sm">
                    <button
                        className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-primary-container text-on-primary font-label-md text-label-md font-w-label-md hover:opacity-95 shadow-sm transition-all duration-150 cursor-pointer"
                        id="btn-tambah-user"
                        type="button"
                        onClick={openAddModal}
                    >
                        <span className="material-symbols-outlined text-[18px]">add</span>
                        <span>Tambah Pengguna</span>
                    </button>
                </div>
            </div>

            {/* Banner Notifikasi Sukses */}
            {successMessage && (
                <div className="p-3 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-lg flex items-center justify-between text-sm animate-in fade-in duration-200">
                    <div className="flex items-center gap-2">
                        <span className="material-symbols-outlined text-emerald-600 text-[20px]">
                            check_circle
                        </span>
                        <span>{successMessage}</span>
                    </div>
                    <button
                        type="button"
                        onClick={() => setSuccessMessage("")}
                        className="text-emerald-700 hover:text-emerald-900 p-1"
                    >
                        <span className="material-symbols-outlined text-[18px]">close</span>
                    </button>
                </div>
            )}

            {actionError && (
                <div className="p-3 bg-error-container/40 border border-error/20 text-error rounded-lg flex items-center justify-between text-sm">
                    <span>{actionError}</span>
                    <button type="button" onClick={() => setActionError("")} aria-label="Tutup notifikasi">
                        <span className="material-symbols-outlined text-[18px]">close</span>
                    </button>
                </div>
            )}

            {/* Filter & Search Toolbar */}
            <div className="bg-surface-container-lowest p-space-md rounded-xl shadow-sm flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-space-md">
                <div className="relative flex-1 min-w-[280px]">
                    <span className="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-secondary text-[20px] pointer-events-none">
                        search
                    </span>
                    <input
                        className="w-full h-10 pl-10 pr-4 bg-surface rounded-lg font-body-sm text-body-sm font-w-body-sm text-on-surface placeholder:text-outline focus:outline-none focus:bg-surface-container-lowest transition-colors border border-outline-variant/30"
                        id="search-input"
                        placeholder="Cari nama, username, atau email..."
                        type="text"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                    />
                </div>
                <div className="flex flex-wrap items-center gap-space-sm">
                    <div className="relative min-w-[150px]">
                        <select
                            className="w-full h-10 pl-3 pr-8 bg-surface rounded-lg font-label-md text-label-md font-w-label-md text-on-surface appearance-none focus:outline-none cursor-pointer border border-outline-variant/30"
                            value={roleFilter}
                            onChange={(e) => setRoleFilter(e.target.value)}
                        >
                            <option value="">Semua Peran</option>
                            <option value="administrator">Administrator</option>
                            <option value="teller">Teller</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="nasabah">Nasabah</option>
                        </select>
                        <span className="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 text-secondary text-[18px] pointer-events-none">
                            expand_more
                        </span>
                    </div>
                    <div className="relative min-w-[140px]">
                        <select
                            className="w-full h-10 pl-3 pr-8 bg-surface rounded-lg font-label-md text-label-md font-w-label-md text-on-surface appearance-none focus:outline-none cursor-pointer border border-outline-variant/30"
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                        >
                            <option value="">Semua Status</option>
                            <option value="aktif">Aktif</option>
                            <option value="diblokir">Diblokir</option>
                        </select>
                        <span className="material-symbols-outlined absolute right-2.5 top-1/2 -translate-y-1/2 text-secondary text-[18px] pointer-events-none">
                            expand_more
                        </span>
                    </div>
                </div>
            </div>

            {/* Master Table Card */}
            <div className="bg-surface-container-lowest rounded-xl shadow-sm overflow-hidden flex flex-col">
                <div className="overflow-x-auto w-full">
                    <table className="w-full text-left border-collapse">
                        <thead>
                            <tr className="bg-surface-container-low text-secondary font-label-sm text-label-sm font-w-label-sm uppercase tracking-wider">
                                <th className="py-3.5 px-4 font-semibold">Nama Lengkap</th>
                                <th className="py-3.5 px-4 font-semibold">Username</th>
                                <th className="py-3.5 px-4 font-semibold">Email</th>
                                <th className="py-3.5 px-4 font-semibold">Peran</th>
                                <th className="py-3.5 px-4 font-semibold">Status</th>
                                <th className="py-3.5 px-4 text-right font-semibold">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="font-body-sm text-body-sm font-w-body-sm divide-y-0 text-on-surface">
                            {isLoading ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-secondary">
                                        Memuat data pengguna...
                                    </td>
                                </tr>
                            ) : loadError ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-error">
                                        <div className="flex flex-col items-center justify-center gap-2">
                                            <span>{loadError}</span>
                                            <button
                                                type="button"
                                                onClick={handleRefreshUsers}
                                                className="px-3 py-1 bg-surface-container rounded text-xs font-semibold hover:bg-surface-container-high"
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
                                            ? "Belum ada data pengguna."
                                            : "Tidak ada pengguna yang cocok dengan kriteria pencarian."}
                                    </td>
                                </tr>
                            ) : (
                                filteredUsers.map((user, index) => {
                                    const roleLabel = getRoleLabel(user.role);
                                    const statusLabel = getStatusLabel(user.status);

                                    const canArchive = ["teller", "nasabah"].includes(user.role);
                                    const isBlocked = String(user.status ?? "").toLowerCase() === "blocked";
                                    const isLast = index === filteredUsers.length - 1;

                                    return (
                                        <tr
                                            key={user.id ?? user.username ?? index}
                                            className={`hover:bg-surface-container-low transition-colors duration-100 ${isLast
                                                    ? ""
                                                    : "border-b border-outline-variant/20"
                                                } ${isBlocked ? "bg-error-container/10" : ""}`.trim()}
                                        >
                                            <td className="py-3.5 px-4 font-semibold text-on-surface">
                                                {user.full_name || user.name || "-"}
                                            </td>

                                            <td className="py-3.5 px-4 font-mono-num text-mono-num font-w-mono-num text-secondary">
                                                {user.username || "-"}
                                            </td>

                                            <td className="py-3.5 px-4 text-secondary">
                                                {user.email || "-"}
                                            </td>

                                            <td className="py-3.5 px-4">
                                                <span
                                                    className={`inline-flex items-center px-2.5 py-0.5 rounded-full font-label-sm text-label-sm font-w-label-sm ${ROLE_BADGE[roleLabel] ||
                                                        "bg-surface-variant text-on-surface-variant"
                                                        } font-medium`}
                                                >
                                                    {roleLabel}
                                                </span>
                                            </td>

                                            <td className="py-3.5 px-4">
                                                <span
                                                    className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full font-label-sm text-label-sm font-w-label-sm font-semibold ${isBlocked ? "bg-error-container text-on-error-container" : "bg-tertiary-fixed text-on-tertiary-fixed"}`}
                                                >
                                                    <span className={`w-1.5 h-1.5 rounded-full ${isBlocked ? "bg-error" : "bg-tertiary"}`} />

                                                    {statusLabel}
                                                </span>
                                            </td>

                                            <td className="py-3.5 px-4 text-right">
                                                <div className="inline-flex items-center gap-2 justify-end">
                                                    <button
                                                        className="text-secondary hover:text-on-surface font-label-sm text-label-sm font-w-label-sm cursor-pointer"
                                                        type="button"
                                                        onClick={() => handleShowDetail(user)}
                                                    >
                                                        Detail
                                                    </button>

                                                    <span className="text-outline-variant">·</span>

                                                    <button
                                                        className="text-primary hover:underline font-label-sm text-label-sm font-w-label-sm font-semibold cursor-pointer"
                                                        type="button"
                                                        onClick={() => openEditModal(user)}
                                                    >
                                                        Ubah
                                                    </button>

                                                    <span className="text-outline-variant">·</span>

                                                    {canArchive ? (
                                                        <button
                                                            className="text-error hover:underline font-label-sm text-label-sm font-w-label-sm cursor-pointer"
                                                            type="button"
                                                            onClick={() => handleArchiveUser(user)}
                                                        >
                                                            Blokir/Arsipkan
                                                        </button>
                                                    ) : (
                                                        <button
                                                            className="text-secondary hover:text-on-surface font-label-sm text-label-sm font-w-label-sm cursor-pointer"
                                                            type="button"
                                                            onClick={() => handleToggleStatus(user)}
                                                        >
                                                            {isBlocked ? "Aktifkan" : "Blokir"}
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Table Pagination Footer */}
                <div className="px-4 py-3 flex items-center justify-between text-sm text-secondary border-t border-outline-variant/10">
                    <div>
                        <span>Menampilkan </span>
                        <span className="font-semibold text-on-surface font-mono-num font-w-mono-num">
                            {isLoading
                                ? "..."
                                : filteredUsers.length === 0
                                    ? "0"
                                    : `1 - ${filteredUsers.length}`}
                        </span>
                        <span> dari </span>
                        <span className="font-semibold text-on-surface font-mono-num font-w-mono-num">
                            {isLoading ? "..." : users.length}
                        </span>
                        <span> pengguna</span>
                    </div>
                </div>
            </div>

            {(isLoadingDetail || detailError || detailUser) && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    role="dialog"
                    aria-modal="true"
                >
                    <div className="w-full max-w-lg rounded-xl border border-outline-variant/30 bg-surface-container-lowest p-space-lg shadow-xl">
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="font-headline-sm text-headline-sm font-w-headline-sm text-on-surface">
                                Detail Pengguna
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
                                <div><dt className="text-xs text-secondary">Peran</dt><dd className="font-medium">{getRoleLabel(detailUser.role)}</dd></div>
                                <div><dt className="text-xs text-secondary">Status</dt><dd className="font-medium">{getStatusLabel(detailUser.status)}</dd></div>
                            </dl>
                        ) : null}
                    </div>
                </div>
            )}

            {/* Modal Tambah / Ubah Pengguna */}
            {isModalOpen && (
                <div
                    className="fixed inset-0 z-50 bg-black/40 backdrop-blur-xs flex items-center justify-center p-4 animate-in fade-in duration-150"
                    id="add-user-modal"
                    role="dialog"
                    aria-modal="true"
                >
                    <div className="bg-surface-container-lowest rounded-xl max-w-lg w-full p-space-lg shadow-xl flex flex-col gap-space-md border border-outline-variant/30 max-h-[92vh] overflow-y-auto">
                        <div className="flex items-center justify-between pb-space-xs border-b border-outline-variant/20">
                            <div>
                                <h3 className="font-headline-sm text-headline-sm font-w-headline-sm text-on-surface font-semibold">
                                    {editingUserId ? "Ubah Data Pengguna" : "Tambah Pengguna Bank Mini"}
                                </h3>
                                <p className="text-secondary text-xs mt-0.5">
                                    {editingUserId
                                        ? "Perbarui informasi akun"
                                        : "Daftarkan akun staf baru untuk operasional sistem Bank Mini"}
                                </p>
                            </div>
                            <button
                                className="text-secondary hover:text-on-surface p-1 rounded-lg hover:bg-surface-container cursor-pointer transition-colors"
                                id="btn-close-modal"
                                type="button"
                                onClick={closeModal}
                            >
                                <span className="material-symbols-outlined text-[20px]">
                                    close
                                </span>
                            </button>
                        </div>

                        {/* Error Alert Banner */}
                        {formError && (
                            <div className="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-xs flex items-start gap-2">
                                <span className="material-symbols-outlined text-[18px] shrink-0 mt-0.5 text-red-600">
                                    error
                                </span>
                                <span className="flex-1 font-medium">{formError}</span>
                            </div>
                        )}

                        <form onSubmit={handleSaveUser} className="flex flex-col gap-space-sm font-body-sm text-body-sm font-w-body-sm">
                            {/* Nama Lengkap */}
                            <div>
                                <label className="block font-label-md text-label-md font-w-label-md text-on-surface mb-1">
                                    Nama Lengkap <span className="text-red-500">*</span>
                                </label>
                                <input
                                    className={`w-full h-10 px-3 bg-surface rounded-lg text-on-surface focus:outline-none border transition-colors ${fieldErrors.fullName ? "border-red-500 focus:border-red-500" : "border-outline-variant/30 focus:border-primary"
                                        }`}
                                    placeholder="Contoh: Rahmat Hidayat"
                                    type="text"
                                    value={formData.fullName}
                                    onChange={(e) => {
                                        setFormData((prev) => ({ ...prev, fullName: e.target.value }));
                                        if (fieldErrors.fullName) setFieldErrors((prev) => ({ ...prev, fullName: "" }));
                                    }}
                                />
                                {fieldErrors.fullName && (
                                    <p className="text-red-500 text-xs mt-1 font-medium">{fieldErrors.fullName}</p>
                                )}
                            </div>

                            {/* Username */}
                            <div>
                                <label className="block font-label-md text-label-md font-w-label-md text-on-surface mb-1">
                                    Username <span className="text-red-500">*</span>
                                </label>
                                <input
                                    className={`w-full h-10 px-3 bg-surface rounded-lg font-mono-num text-mono-num font-w-mono-num text-on-surface focus:outline-none border transition-colors ${fieldErrors.username ? "border-red-500 focus:border-red-500" : "border-outline-variant/30 focus:border-primary"
                                        }`}
                                    placeholder="Contoh: rahmat.admin"
                                    type="text"
                                    value={formData.username}
                                    onChange={(e) => {
                                        setFormData((prev) => ({ ...prev, username: e.target.value }));
                                        if (fieldErrors.username) setFieldErrors((prev) => ({ ...prev, username: "" }));
                                    }}
                                />
                                {fieldErrors.username && (
                                    <p className="text-red-500 text-xs mt-1 font-medium">{fieldErrors.username}</p>
                                )}
                            </div>

                            {/* Email Sekolah */}
                            <div>
                                <label className="block font-label-md text-label-md font-w-label-md text-on-surface mb-1">
                                    Email<span className="text-red-500">*</span>
                                </label>
                                <input
                                    className={`w-full h-10 px-3 bg-surface rounded-lg text-on-surface focus:outline-none border transition-colors ${fieldErrors.email ? "border-red-500 focus:border-red-500" : "border-outline-variant/30 focus:border-primary"
                                        }`}
                                    placeholder="nama@bankmini.sch.id"
                                    type="email"
                                    value={formData.email}
                                    onChange={(e) => {
                                        setFormData((prev) => ({ ...prev, email: e.target.value }));
                                        if (fieldErrors.email) setFieldErrors((prev) => ({ ...prev, email: "" }));
                                    }}
                                />
                                {fieldErrors.email && (
                                    <p className="text-red-500 text-xs mt-1 font-medium">{fieldErrors.email}</p>
                                )}
                            </div>

                            {/* Password & Konfirmasi Password (Grid 2 Kolom) */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
                                <div>
                                    <div className="flex items-center justify-between mb-1">
                                        <label className="font-label-md text-label-md font-w-label-md text-on-surface">
                                            Password {editingUserId ? <span className="text-secondary text-xs">(Opsional)</span> : <span className="text-red-500">*</span>}
                                        </label>
                                        {!editingUserId && (
                                            <button
                                                type="button"
                                                onClick={applyDefaultPassword}
                                                className="text-[11px] text-primary hover:underline cursor-pointer"
                                                tabIndex={-1}
                                            >
                                                Default (password123)
                                            </button>
                                        )}
                                    </div>
                                    <div className="relative">
                                        <input
                                            className={`w-full h-10 pl-3 pr-9 bg-surface rounded-lg text-on-surface focus:outline-none border transition-colors ${fieldErrors.password ? "border-red-500 focus:border-red-500" : "border-outline-variant/30 focus:border-primary"
                                                }`}
                                            placeholder={editingUserId ? "Kosongkan jika tetap" : "Minimal 8 karakter"}
                                            type={showPassword ? "text" : "password"}
                                            value={formData.password}
                                            onChange={(e) => {
                                                setFormData((prev) => ({ ...prev, password: e.target.value }));
                                                if (fieldErrors.password) setFieldErrors((prev) => ({ ...prev, password: "" }));
                                            }}
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setShowPassword(!showPassword)}
                                            className="absolute right-2.5 top-1/2 -translate-y-1/2 text-secondary hover:text-on-surface cursor-pointer"
                                            tabIndex={-1}
                                        >
                                            <span className="material-symbols-outlined text-[18px]">
                                                {showPassword ? "visibility_off" : "visibility"}
                                            </span>
                                        </button>
                                    </div>
                                    {fieldErrors.password && (
                                        <p className="text-red-500 text-xs mt-1 font-medium">{fieldErrors.password}</p>
                                    )}
                                </div>

                                <div>
                                    <label className="block font-label-md text-label-md font-w-label-md text-on-surface mb-1">
                                        Konfirmasi Password {editingUserId ? "" : <span className="text-red-500">*</span>}
                                    </label>
                                    <input
                                        className={`w-full h-10 px-3 bg-surface rounded-lg text-on-surface focus:outline-none border transition-colors ${fieldErrors.passwordConfirmation ? "border-red-500 focus:border-red-500" : "border-outline-variant/30 focus:border-primary"
                                            }`}
                                        placeholder="Ulangi password"
                                        type={showPassword ? "text" : "password"}
                                        value={formData.passwordConfirmation}
                                        onChange={(e) => {
                                            setFormData((prev) => ({ ...prev, passwordConfirmation: e.target.value }));
                                            if (fieldErrors.passwordConfirmation) setFieldErrors((prev) => ({ ...prev, passwordConfirmation: "" }));
                                        }}
                                    />
                                    {fieldErrors.passwordConfirmation && (
                                        <p className="text-red-500 text-xs mt-1 font-medium">{fieldErrors.passwordConfirmation}</p>
                                    )}
                                </div>
                            </div>

                            <div>
                                <div>
                                    <label className="block font-label-md text-label-md font-w-label-md text-on-surface mb-1">
                                        Peran Akses <span className="text-red-500">*</span>
                                    </label>
                                    <select
                                        className="w-full h-10 px-3 bg-surface rounded-lg text-on-surface focus:outline-none border border-outline-variant/30 cursor-pointer"
                                        value={formData.role}
                                        onChange={(e) =>
                                            setFormData((prev) => ({ ...prev, role: e.target.value }))
                                        }
                                    >
                                        <option value="administrator">Administrator</option>
                                        <option value="teller">Teller</option>
                                        <option value="supervisor">Supervisor</option>
                                        <option value="nasabah">Nasabah</option>
                                    </select>
                                </div>
                            </div>

                            {/* Modal Footer Buttons */}
                            <div className="flex items-center justify-end gap-space-sm pt-space-xs mt-space-xs border-t border-outline-variant/20">
                                <button
                                    className="px-4 py-2 rounded-lg bg-surface text-secondary hover:text-on-surface font-label-md text-label-md font-w-label-md transition-colors cursor-pointer"
                                    id="btn-cancel-modal"
                                    type="button"
                                    onClick={closeModal}
                                    disabled={isSubmitting}
                                >
                                    Batal
                                </button>
                                <button
                                    className="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-primary-container text-on-primary font-label-md text-label-md font-w-label-md hover:opacity-95 shadow-sm transition-opacity cursor-pointer disabled:opacity-50"
                                    id="btn-save-user"
                                    type="submit"
                                    disabled={isSubmitting}
                                >
                                    {isSubmitting ? (
                                        <>
                                            <span className="inline-block w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                                            <span>Menyimpan...</span>
                                        </>
                                    ) : (
                                        <span>{editingUserId ? "Simpan Perubahan" : "Simpan Pengguna"}</span>
                                    )}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}

export default UserManagement;

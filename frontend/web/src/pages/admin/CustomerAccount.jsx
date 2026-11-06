import { useCallback, useEffect, useMemo, useState } from "react";
import api from "../../../../config/api";

const INITIAL_FORM = {
    student_number: "",
    student_name: "",
    class_id: "",
    opening_balance: "10000",
};

const INITIAL_EDIT_FORM = {
    student_name: "",
    class_id: "",
    email: "",
};

const formatRupiah = (amount) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(Number(amount) || 0);

const getErrorMessage = (err, fallback) => {
    const validationErrors = err?.response?.data?.errors;
    const firstValidationError = validationErrors
        ? Object.values(validationErrors).flat().find(Boolean)
        : null;

    return (
        firstValidationError ||
        err?.response?.data?.message ||
        err?.message ||
        fallback
    );
};

const getAccountNumber = (account) =>
    account?.account_number || account?.accountNumber || "-";

const getStudentName = (account) =>
    account?.student_name || account?.name || "-";

const getStudentNumber = (account) =>
    account?.student_number || account?.studentNumber || "-";

const isAccountBlocked = (account) => {
    const status = String(account?.status || "").toLowerCase();
    return status === "blocked" || status === "diblokir";
};

const getClassLabel = (account) => {
    const schoolClass = account?.school_class || account?.schoolClass;

    if (schoolClass?.name) {
        const majorCode = schoolClass?.major?.code;
        return majorCode
            ? `${schoolClass.name} (${majorCode})`
            : schoolClass.name;
    }

    return account?.classLabel || "-";
};

function CustomerAccount() {
    const [accounts, setAccounts] = useState([]);
    const [classes, setClasses] = useState([]);

    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const [isSaving, setIsSaving] = useState(false);
    const [isUpdating, setIsUpdating] = useState(false);
    const [busyAccountId, setBusyAccountId] = useState(null);

    const [error, setError] = useState("");
    const [success, setSuccess] = useState("");
    const [formError, setFormError] = useState("");
    const [editError, setEditError] = useState("");

    const [searchTerm, setSearchTerm] = useState("");
    const [selectedMajor, setSelectedMajor] = useState("");
    const [selectedStatus, setSelectedStatus] = useState("");

    const [detailAccount, setDetailAccount] = useState(null);
    const [editAccount, setEditAccount] = useState(null);

    const [form, setForm] = useState({ ...INITIAL_FORM });
    const [editForm, setEditForm] = useState({ ...INITIAL_EDIT_FORM });

    const reloadAccounts = useCallback(async () => {
        const response = await api.get("/admin/customers");
        setAccounts(Array.isArray(response?.data?.data) ? response.data.data : []);
    }, []);

    useEffect(() => {
        const controller = new AbortController();

        const loadInitialData = async () => {
            try {
                const [customerResponse, classResponse] = await Promise.all([
                    api.get("/admin/customers", {
                        signal: controller.signal,
                    }),
                    api.get("/admin/classes", {
                        signal: controller.signal,
                    }),
                ]);
                if (controller.signal.aborted) return;

                setAccounts(
                    Array.isArray(customerResponse?.data?.data)
                        ? customerResponse.data.data
                        : []
                );
                setClasses(
                    Array.isArray(classResponse?.data?.data)
                        ? classResponse.data.data
                        : []
                );
                setError("");
            } catch (err) {
                if (controller.signal.aborted) return;

                setError(
                    getErrorMessage(
                        err,
                        "Gagal mengambil data rekening dan kelas dari server."
                    )
                );
            } finally {
                if (!controller.signal.aborted) {
                    setIsLoading(false);
                }
            }
        };

        void loadInitialData();

        return () => controller.abort();
    }, []);

    const openCreateModal = () => {
        setForm({ ...INITIAL_FORM });
        setFormError("");
        setError("");
        setIsModalOpen(true);
    };

    const closeCreateModal = () => {
        if (isSaving) return;
        setIsModalOpen(false);
        setFormError("");
    };

    const handleInputChange = (event) => {
        const { name, value } = event.target;
        setForm((previous) => ({ ...previous, [name]: value }));
    };

    const handleFormSubmit = async (event) => {
        event.preventDefault();
        setFormError("");
        setError("");
        setSuccess("");

        const payload = {
            student_number: form.student_number.trim(),
            student_name: form.student_name.trim(),
            class_id: Number(form.class_id),
            opening_balance: Number(form.opening_balance),
        };

        if (!payload.student_number) {
            setFormError("Nomor Induk Siswa (NIS) wajib diisi.");
            return;
        }

        if (!payload.student_name) {
            setFormError("Nama siswa wajib diisi.");
            return;
        }

        if (!Number.isInteger(payload.class_id) || payload.class_id <= 0) {
            setFormError("Silakan pilih kelas terlebih dahulu.");
            return;
        }

        if (
            !Number.isSafeInteger(payload.opening_balance) ||
            payload.opening_balance < 10000
        ) {
            setFormError("Setoran awal minimal Rp10.000.");
            return;
        }

        try {
            setIsSaving(true);

            const response = await api.post("/admin/customers", payload);
            setSuccess(
                response?.data?.message || "Rekening nasabah berhasil dibuat."
            );

            await reloadAccounts();
            setForm({ ...INITIAL_FORM });
            setIsModalOpen(false);
        } catch (err) {
            setFormError(
                getErrorMessage(err, "Gagal membuat rekening nasabah.")
            );
        } finally {
            setIsSaving(false);
        }
    };

    const handleToggleStatus = async (account) => {
        const blocked = isAccountBlocked(account);
        const nextStatus = blocked ? "active" : "blocked";
        const actionLabel = blocked ? "mengaktifkan" : "memblokir";
        const accountNumber = getAccountNumber(account);

        if (
            !window.confirm(
                `Apakah Anda yakin ingin ${actionLabel} rekening ${accountNumber} atas nama ${getStudentName(account)}?`
            )
        ) {
            return;
        }

        setError("");
        setSuccess("");
        setBusyAccountId(account.id);

        try {
            const response = await api.put(`/admin/customers/${account.id}`, {
                status: nextStatus,
            });

            setSuccess(
                response?.data?.message ||
                `Rekening ${accountNumber} berhasil ${blocked ? "diaktifkan" : "diblokir"
                }.`
            );

            await reloadAccounts();
        } catch (err) {
            setError(
                getErrorMessage(err, `Gagal ${actionLabel} rekening nasabah.`)
            );
        } finally {
            setBusyAccountId(null);
        }
    };

    const openEditModal = (account) => {
        setEditError("");
        setError("");
        setEditAccount(account);
        setEditForm({
            student_name: account?.student_name || account?.name || "",
            class_id:
                account?.class_id ??
                account?.school_class?.id ??
                account?.schoolClass?.id ??
                "",
            email: account?.user?.email || "",
        });
    };

    const handleEditSubmit = async (event) => {
        event.preventDefault();
        setEditError("");
        setError("");
        setSuccess("");

        if (!editAccount?.id) {
            setEditError("Data rekening yang akan diubah tidak ditemukan.");
            return;
        }

        const hasLinkedUser = Boolean(editAccount?.user?.id);
        const payload = {
            student_name: editForm.student_name.trim(),
            class_id: Number(editForm.class_id),
        };

        if (!payload.student_name) {
            setEditError("Nama siswa wajib diisi.");
            return;
        }

        if (!Number.isInteger(payload.class_id) || payload.class_id <= 0) {
            setEditError("Kelas wajib dipilih.");
            return;
        }

        // Email pemulihan bersifat opsional. Jika dikosongkan, kirimkan null untuk menghapus email.
        if (hasLinkedUser) {
            const trimmedEmail = editForm.email.trim();
            payload.email = trimmedEmail === "" ? null : trimmedEmail;
        }

        try {
            setIsUpdating(true);
            const response = await api.put(
                `/admin/customers/${editAccount.id}`,
                payload
            );

            setSuccess(
                response?.data?.message ||
                "Data rekening nasabah berhasil diperbarui."
            );
            setEditAccount(null);
            await reloadAccounts();
        } catch (err) {
            setEditError(
                getErrorMessage(err, "Gagal memperbarui rekening nasabah.")
            );
        } finally {
            setIsUpdating(false);
        }
    };

    const filteredAccounts = useMemo(() => {
        const query = searchTerm.trim().toLowerCase();

        return accounts.filter((account) => {
            const searchableValues = [
                getStudentName(account),
                getStudentNumber(account),
                getAccountNumber(account),
            ];

            if (
                query &&
                !searchableValues.some((value) =>
                    String(value).toLowerCase().includes(query)
                )
            ) {
                return false;
            }

            const majorCode = String(
                account?.school_class?.major?.code ||
                account?.schoolClass?.major?.code ||
                account?.major_code ||
                ""
            ).toLowerCase();

            if (selectedMajor && majorCode !== selectedMajor.toLowerCase()) {
                return false;
            }

            const blocked = isAccountBlocked(account);
            if (selectedStatus === "aktif" && blocked) return false;
            if (selectedStatus === "diblokir" && !blocked) return false;

            return true;
        });
    }, [accounts, searchTerm, selectedMajor, selectedStatus]);

    const resetFilters = () => {
        setSearchTerm("");
        setSelectedMajor("");
        setSelectedStatus("");
    };

    const renderAlert = (message, type, onClose) => {
        if (!message) return null;

        const successType = type === "success";

        return (
            <div
                role={successType ? "status" : "alert"}
                className={`mt-4 flex items-center justify-between gap-3 rounded border p-3.5 text-sm ${successType
                        ? "border-green-200 bg-green-50 text-green-700"
                        : "border-red-200 bg-red-50 text-red-700"
                    }`}
            >
                <div className="flex items-center gap-2">
                    <span className="material-symbols-outlined text-[20px]">
                        {successType ? "check_circle" : "error"}
                    </span>
                    <span>{message}</span>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    className="shrink-0"
                    aria-label="Tutup pesan"
                >
                    <span className="material-symbols-outlined text-[18px]">
                        close
                    </span>
                </button>
            </div>
        );
    };

    return (
        <div className="flex w-full flex-col pb-12">
            <div className="flex flex-col justify-between gap-4 border-b border-outline-variant/30 py-6 lg:flex-row lg:items-center">
                <div>
                    <div className="mb-1 flex flex-wrap items-center gap-2">
                        <h1 className="font-headline-lg text-headline-lg font-w-headline-lg font-semibold tracking-tight text-on-surface">
                            Rekening Nasabah
                        </h1>
                        <span className="rounded-full bg-primary/10 px-2.5 py-0.5 font-label-sm text-label-sm font-w-label-sm font-semibold text-primary">
                            Total {accounts.length} rekening siswa terdaftar
                        </span>
                    </div>
                    <p className="mt-0.5 max-w-3xl font-body-sm text-body-sm font-w-body-sm text-secondary">
                        Kelola data rekening dan status rekening siswa Bank Mini
                        SMKN 1 Bantul.
                    </p>
                </div>

                <button
                    className="inline-flex shrink-0 items-center gap-2 rounded bg-primary px-4 py-2 font-label-md text-label-md font-w-label-md text-on-primary shadow-sm transition-colors hover:bg-primary-container"
                    id="btn-open-account-modal"
                    type="button"
                    onClick={openCreateModal}
                >
                    <span className="material-symbols-outlined text-[18px]">
                        add
                    </span>
                    <span>Tambah Rekening</span>
                </button>
            </div>

            {renderAlert(error, "error", () => setError(""))}
            {renderAlert(success, "success", () => setSuccess(""))}

            <div className="mb-0 mt-6 flex flex-col items-stretch justify-between gap-3 rounded-t-lg border border-outline-variant/40 bg-surface-container-lowest p-4 xl:flex-row xl:items-center">
                <div className="relative min-w-0 flex-1 xl:min-w-[320px]">
                    <span className="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[18px] text-outline">
                        search
                    </span>
                    <input
                        className="h-[38px] w-full rounded border border-outline-variant/70 bg-surface-container-lowest pl-9 pr-4 font-body-sm text-body-sm font-w-body-sm text-on-surface placeholder:text-outline focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        id="account-search-input"
                        placeholder="Cari nama siswa, NIS, atau nomor rekening..."
                        type="search"
                        value={searchTerm}
                        onChange={(event) => setSearchTerm(event.target.value)}
                    />
                </div>

                <div className="flex flex-wrap items-center gap-2.5">
                    <select
                        aria-label="Filter jurusan"
                        className="h-[38px] min-w-[170px] rounded border border-outline-variant/70 bg-surface-container-lowest px-3 text-body-sm text-on-surface focus:border-primary focus:outline-none"
                        value={selectedMajor}
                        onChange={(event) => setSelectedMajor(event.target.value)}
                    >
                        <option value="">Semua Jurusan</option>
                        <option value="AK">AK (Akuntansi)</option>
                        <option value="BR">BR (Bisnis Ritel)</option>
                        <option value="BD">BD (Bisnis Digital)</option>
                        <option value="MP">MP (Manajemen Perkantoran)</option>
                        <option value="DKV">DKV (Desain Komunikasi Visual)</option>
                        <option value="RPL">RPL (Rekayasa Perangkat Lunak)</option>
                        <option value="TKJ">TKJ (Teknik Komputer &amp; Jaringan)</option>
                    </select>

                    <select
                        aria-label="Filter status rekening"
                        className="h-[38px] min-w-[150px] rounded border border-outline-variant/70 bg-surface-container-lowest px-3 text-body-sm text-on-surface focus:border-primary focus:outline-none"
                        value={selectedStatus}
                        onChange={(event) => setSelectedStatus(event.target.value)}
                    >
                        <option value="">Semua Status</option>
                        <option value="aktif">Aktif</option>
                        <option value="diblokir">Diblokir</option>
                    </select>

                    {(searchTerm || selectedMajor || selectedStatus) && (
                        <button
                            type="button"
                            onClick={resetFilters}
                            className="inline-flex h-[38px] items-center gap-1 rounded border border-outline-variant/50 bg-surface-container px-3 font-label-sm text-label-sm text-secondary transition-colors hover:text-on-surface"
                        >
                            <span className="material-symbols-outlined text-[16px]">
                                restart_alt
                            </span>
                            Reset
                        </button>
                    )}
                </div>
            </div>

            <div className="overflow-hidden border-x border-b border-outline-variant/40 bg-surface-container-lowest">
                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-left">
                        <thead>
                            <tr className="select-none border-b border-outline-variant/30 bg-surface-container-low font-label-sm text-label-sm font-w-label-sm uppercase tracking-wider text-secondary">
                                <th className="whitespace-nowrap px-4 py-3.5">Nomor Rekening</th>
                                <th className="whitespace-nowrap px-4 py-3.5">Nama Siswa</th>
                                <th className="whitespace-nowrap px-4 py-3.5">Nomor Induk Siswa / NIS</th>
                                <th className="whitespace-nowrap px-4 py-3.5">Kelas / Jurusan</th>
                                <th className="whitespace-nowrap px-4 py-3.5 text-right">Saldo</th>
                                <th className="whitespace-nowrap px-4 py-3.5 text-center">Status</th>
                                <th className="whitespace-nowrap px-4 py-3.5 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-outline-variant/20 font-body-sm text-body-sm text-on-surface">
                            {isLoading ? (
                                <tr>
                                    <td colSpan={7} className="py-12 text-center text-secondary">
                                        <span className="material-symbols-outlined mr-2 inline-block animate-spin align-middle text-[28px] text-primary">
                                            progress_activity
                                        </span>
                                        Memuat data rekening nasabah...
                                    </td>
                                </tr>
                            ) : filteredAccounts.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="py-12 text-center text-secondary">
                                        <div className="flex flex-col items-center gap-2">
                                            <span className="material-symbols-outlined text-[36px] text-outline">
                                                search_off
                                            </span>
                                            <span className="font-medium text-on-surface">
                                                Tidak ada rekening nasabah ditemukan.
                                            </span>
                                            <span className="text-xs text-outline">
                                                Coba ubah kata kunci atau filter yang dipilih.
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                filteredAccounts.map((account) => {
                                    const blocked = isAccountBlocked(account);
                                    const accountNumber = getAccountNumber(account);

                                    return (
                                        <tr
                                            key={account.id ?? accountNumber}
                                            className="transition-colors hover:bg-surface-container-low/60"
                                        >
                                            <td className="whitespace-nowrap px-4 py-3.5 font-mono-num font-semibold text-primary">
                                                {accountNumber}
                                            </td>
                                            <td className="px-4 py-3.5 font-semibold">
                                                {getStudentName(account)}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3.5 font-mono-num text-secondary">
                                                {getStudentNumber(account)}
                                            </td>
                                            <td className="px-4 py-3.5">
                                                <span className="inline-flex rounded bg-surface-container px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant">
                                                    {getClassLabel(account)}
                                                </span>
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3.5 text-right font-mono-num font-semibold">
                                                {formatRupiah(account.balance)}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3.5 text-center">
                                                <span
                                                    className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-label-sm text-label-sm font-medium ${blocked
                                                            ? "border-slate-300 bg-slate-100 text-slate-700"
                                                            : "border-emerald-200 bg-emerald-50 text-emerald-800"
                                                        }`}
                                                >
                                                    <span
                                                        className={`h-1.5 w-1.5 rounded-full ${blocked ? "bg-slate-500" : "bg-emerald-600"
                                                            }`}
                                                    />
                                                    {blocked ? "Diblokir" : "Aktif"}
                                                </span>
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3.5 text-right">
                                                <div className="inline-flex items-center justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        className="font-label-md text-label-md text-primary hover:underline"
                                                        onClick={() => setDetailAccount(account)}
                                                    >
                                                        Lihat
                                                    </button>
                                                    <span className="text-outline-variant">|</span>
                                                    <button
                                                        type="button"
                                                        className="font-label-md text-label-md text-secondary hover:text-on-surface"
                                                        onClick={() => openEditModal(account)}
                                                    >
                                                        Ubah
                                                    </button>
                                                    <span className="text-outline-variant">|</span>
                                                    <button
                                                        type="button"
                                                        disabled={busyAccountId === account.id}
                                                        className={`font-label-md text-label-md hover:underline disabled:opacity-50 ${blocked
                                                                ? "text-emerald-700"
                                                                : "text-red-600"
                                                            }`}
                                                        onClick={() => void handleToggleStatus(account)}
                                                    >
                                                        {busyAccountId === account.id
                                                            ? "Memproses..."
                                                            : blocked
                                                                ? "Aktifkan"
                                                                : "Blokir"}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between gap-4 border-t border-outline-variant/30 bg-surface-container-low/50 p-4 font-body-sm text-body-sm text-secondary">
                    <div>
                        Menampilkan{" "}
                        <span className="font-mono-num font-semibold text-on-surface">
                            {filteredAccounts.length ? `1 - ${filteredAccounts.length}` : "0"}
                        </span>{" "}
                        dari{" "}
                        <span className="font-mono-num font-semibold text-on-surface">
                            {accounts.length}
                        </span>{" "}
                        rekening nasabah
                    </div>
                </div>
            </div>

            {/* Modal tambah rekening */}
            {isModalOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-inverse-surface/40 p-4 backdrop-blur-[2px]"
                    id="modal-new-account"
                    onMouseDown={(event) => {
                        if (event.target === event.currentTarget) closeCreateModal();
                    }}
                >
                    <div className="my-auto flex max-h-[95vh] w-full max-w-xl flex-col overflow-y-auto rounded-lg border border-outline-variant bg-surface-container-lowest shadow-2xl">
                        <div className="flex items-center justify-between border-b border-outline-variant/30 px-6 py-4">
                            <div className="flex items-center gap-2">
                                <span className="material-symbols-outlined text-[22px] text-primary">
                                    account_balance_wallet
                                </span>
                                <h2 className="font-headline-sm text-headline-sm font-semibold text-on-surface">
                                    Tambah Rekening Siswa Baru
                                </h2>
                            </div>
                            <button
                                type="button"
                                aria-label="Tutup modal"
                                disabled={isSaving}
                                className="text-secondary hover:text-on-surface disabled:opacity-50"
                                onClick={closeCreateModal}
                            >
                                <span className="material-symbols-outlined text-[20px]">close</span>
                            </button>
                        </div>

                        <form className="flex flex-col gap-4 p-6 font-body-sm text-body-sm" onSubmit={handleFormSubmit}>
                            {formError && (
                                <div role="alert" className="flex items-center gap-2 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                                    <span className="material-symbols-outlined text-[18px]">error</span>
                                    <span>{formError}</span>
                                </div>
                            )}

                            <div>
                                <label htmlFor="create-student-number" className="mb-1 block font-label-md text-on-surface">
                                    Nomor Siswa (NIS / Induk)*
                                </label>
                                <input
                                    id="create-student-number"
                                    name="student_number"
                                    value={form.student_number}
                                    onChange={handleInputChange}
                                    required
                                    maxLength={30}
                                    disabled={isSaving}
                                    autoComplete="off"
                                    className="h-9 w-full rounded border border-outline-variant/70 px-3 font-mono-num text-on-surface focus:border-primary focus:outline-none disabled:opacity-60"
                                    placeholder="Contoh: 17790"
                                />
                            </div>

                            <div>
                                <label htmlFor="create-student-name" className="mb-1 block font-label-md text-on-surface">
                                    Nama Siswa*
                                </label>
                                <input
                                    id="create-student-name"
                                    name="student_name"
                                    value={form.student_name}
                                    onChange={handleInputChange}
                                    required
                                    maxLength={100}
                                    disabled={isSaving}
                                    className="h-9 w-full rounded border border-outline-variant/70 px-3 text-on-surface focus:border-primary focus:outline-none disabled:opacity-60"
                                    placeholder="Nama lengkap siswa"
                                />
                            </div>

                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label htmlFor="create-class-id" className="mb-1 block font-label-md text-on-surface">
                                        Kelas / Jurusan*
                                    </label>
                                    <select
                                        id="create-class-id"
                                        name="class_id"
                                        value={form.class_id}
                                        onChange={handleInputChange}
                                        required
                                        disabled={isSaving}
                                        className="h-9 w-full cursor-pointer rounded border border-outline-variant/70 bg-surface-container-lowest px-2 text-on-surface focus:border-primary focus:outline-none disabled:opacity-60"
                                    >
                                        <option value="">Pilih kelas</option>
                                        {classes.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.name} — {item.major_name || item.major_code || item.major?.code || ""}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label htmlFor="create-opening-balance" className="mb-1 block font-label-md text-on-surface">
                                        Setoran Awal (IDR)*
                                    </label>
                                    <input
                                        id="create-opening-balance"
                                        name="opening_balance"
                                        value={form.opening_balance}
                                        onChange={handleInputChange}
                                        required
                                        type="number"
                                        min="10000"
                                        step="1000"
                                        disabled={isSaving}
                                        className="h-9 w-full rounded border border-outline-variant/70 px-3 text-right font-mono-num text-on-surface focus:border-primary focus:outline-none disabled:opacity-60"
                                    />
                                </div>
                            </div>

                            <div className="flex items-start gap-2 rounded border border-outline-variant/30 bg-surface-container-low p-3 font-label-sm text-label-sm text-secondary">
                                <span className="material-symbols-outlined text-[18px] text-primary">info</span>
                                <span>
                                    Akun login nasabah dan nomor rekening akan dibuat oleh backend sesuai aturan sistem.
                                </span>
                            </div>

                            <div className="flex justify-end gap-2 border-t border-outline-variant/30 pt-4">
                                <button
                                    type="button"
                                    className="rounded border border-outline-variant/70 px-4 py-2 text-secondary hover:bg-surface-container disabled:opacity-50"
                                    onClick={closeCreateModal}
                                    disabled={isSaving}
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    className="inline-flex items-center gap-2 rounded bg-primary px-4 py-2 text-on-primary hover:bg-primary-container disabled:opacity-50"
                                    disabled={isSaving || isLoading}
                                >
                                    {isSaving && (
                                        <span className="material-symbols-outlined animate-spin text-[16px]">
                                            progress_activity
                                        </span>
                                    )}
                                    {isSaving ? "Menyimpan..." : "Simpan Rekening"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal detail rekening */}
            {detailAccount && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-inverse-surface/40 p-4 backdrop-blur-[2px]"
                    onMouseDown={(event) => {
                        if (event.target === event.currentTarget) setDetailAccount(null);
                    }}
                >
                    <div className="my-auto flex max-h-[95vh] w-full max-w-lg flex-col overflow-y-auto rounded-lg border border-outline-variant bg-surface-container-lowest shadow-2xl">
                        <div className="flex items-center justify-between border-b border-outline-variant/30 bg-surface-container-low px-6 py-4">
                            <div className="flex items-center gap-2">
                                <span className="material-symbols-outlined text-[22px] text-primary">badge</span>
                                <h2 className="font-headline-sm text-headline-sm font-semibold text-on-surface">
                                    Detail Rekening Siswa
                                </h2>
                            </div>
                            <button type="button" aria-label="Tutup detail" className="text-secondary hover:text-on-surface" onClick={() => setDetailAccount(null)}>
                                <span className="material-symbols-outlined text-[20px]">close</span>
                            </button>
                        </div>

                        <div className="flex flex-col divide-y divide-outline-variant/20 p-6 font-body-sm text-body-sm text-on-surface">
                            <DetailRow label="Nomor Rekening" value={getAccountNumber(detailAccount)} valueClass="font-mono-num font-semibold text-primary" />
                            <DetailRow label="Nama Siswa" value={getStudentName(detailAccount)} valueClass="font-semibold" />
                            <DetailRow label="Nomor Induk Siswa (NIS)" value={getStudentNumber(detailAccount)} valueClass="font-mono-num" />
                            <DetailRow label="Email Nasabah" value={detailAccount?.user?.email || "Belum memiliki akun login"} valueClass="break-all text-right" />
                            <DetailRow label="Kelas / Jurusan" value={getClassLabel(detailAccount)} />
                            <DetailRow label="Saldo Rekening" value={formatRupiah(detailAccount.balance)} valueClass="font-mono-num font-bold text-emerald-700" />
                            <div className="flex items-center justify-between gap-4 py-3">
                                <span className="text-secondary">Status</span>
                                <StatusBadge blocked={isAccountBlocked(detailAccount)} />
                            </div>
                        </div>

                        <div className="flex justify-end border-t border-outline-variant/30 bg-surface-container-low px-6 py-3">
                            <button type="button" className="rounded bg-surface-container px-4 py-2 text-on-surface hover:bg-surface-container-high" onClick={() => setDetailAccount(null)}>
                                Tutup
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Modal edit rekening */}
            {editAccount && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-inverse-surface/40 p-4 backdrop-blur-[2px]"
                    onMouseDown={(event) => {
                        if (event.target === event.currentTarget && !isUpdating) setEditAccount(null);
                    }}
                >
                    <div className="my-auto flex max-h-[95vh] w-full max-w-lg flex-col overflow-y-auto rounded-lg border border-outline-variant bg-surface-container-lowest shadow-2xl">
                        <div className="flex items-center justify-between border-b border-outline-variant/30 px-6 py-4">
                            <div className="flex items-center gap-2">
                                <span className="material-symbols-outlined text-[22px] text-primary">edit</span>
                                <h2 className="font-headline-sm text-headline-sm font-semibold text-on-surface">
                                    Ubah Data Rekening Nasabah
                                </h2>
                            </div>
                            <button type="button" aria-label="Tutup edit" disabled={isUpdating} className="text-secondary hover:text-on-surface disabled:opacity-50" onClick={() => setEditAccount(null)}>
                                <span className="material-symbols-outlined text-[20px]">close</span>
                            </button>
                        </div>

                        <form className="flex flex-col gap-4 p-6 font-body-sm text-body-sm" onSubmit={handleEditSubmit}>
                            {editError && (
                                <div role="alert" className="flex items-center gap-2 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                                    <span className="material-symbols-outlined text-[18px]">error</span>
                                    <span>{editError}</span>
                                </div>
                            )}

                            <div>
                                <label htmlFor="edit-account-number" className="mb-1 block font-label-md text-on-surface">Nomor Rekening</label>
                                <input id="edit-account-number" disabled className="h-9 w-full cursor-not-allowed rounded border border-outline-variant/40 bg-surface-container-low px-3 font-mono-num text-secondary" value={getAccountNumber(editAccount)} />
                            </div>

                            <div>
                                <label htmlFor="edit-student-name" className="mb-1 block font-label-md text-on-surface">Nama Siswa*</label>
                                <input
                                    id="edit-student-name"
                                    required
                                    maxLength={100}
                                    disabled={isUpdating}
                                    className="h-9 w-full rounded border border-outline-variant/70 px-3 text-on-surface focus:border-primary focus:outline-none disabled:opacity-60"
                                    value={editForm.student_name}
                                    onChange={(event) => setEditForm((previous) => ({ ...previous, student_name: event.target.value }))}
                                />
                            </div>

                            <div>
                                <label htmlFor="edit-customer-email" className="mb-1 block font-label-md text-on-surface">Email Pemulihan Nasabah (Opsional)</label>
                                <input
                                    id="edit-customer-email"
                                    type="email"
                                    autoComplete="email"
                                    maxLength={255}
                                    disabled={!editAccount?.user?.id || isUpdating}
                                    className="h-9 w-full rounded border border-outline-variant/70 px-3 text-on-surface focus:border-primary focus:outline-none disabled:bg-surface-container-low disabled:text-outline"
                                    value={editForm.email}
                                    onChange={(event) => setEditForm((previous) => ({ ...previous, email: event.target.value }))}
                                    placeholder="nama@email.com (kosongkan jika belum ada)"
                                />
                                {editAccount?.user?.id ? (
                                    <p className="mt-1 text-xs text-outline">
                                        Digunakan untuk pemulihan password jika nasabah lupa password. Kosongkan untuk menghapus email pemulihan.
                                    </p>
                                ) : (
                                    <p className="mt-1 text-xs text-amber-700">
                                        Rekening ini belum terhubung dengan akun login nasabah. Email belum dapat diperbarui dari formulir ini.
                                    </p>
                                )}
                            </div>

                            <div>
                                <label htmlFor="edit-class-id" className="mb-1 block font-label-md text-on-surface">Kelas / Jurusan*</label>
                                <select
                                    id="edit-class-id"
                                    required
                                    disabled={isUpdating}
                                    className="h-9 w-full cursor-pointer rounded border border-outline-variant/70 bg-surface-container-lowest px-2 text-on-surface focus:border-primary focus:outline-none disabled:opacity-60"
                                    value={editForm.class_id}
                                    onChange={(event) => setEditForm((previous) => ({ ...previous, class_id: event.target.value }))}
                                >
                                    <option value="">Pilih kelas</option>
                                    {classes.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name} — {item.major_name || item.major_code || item.major?.code || ""}
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1 text-xs text-outline">
                                    Pastikan kelas yang dipilih sesuai dengan data siswa.
                                </p>
                            </div>

                            <div className="flex justify-end gap-2 border-t border-outline-variant/30 pt-4">
                                <button type="button" className="rounded border border-outline-variant/70 px-4 py-2 text-secondary hover:bg-surface-container disabled:opacity-50" onClick={() => setEditAccount(null)} disabled={isUpdating}>
                                    Batal
                                </button>
                                <button type="submit" className="inline-flex items-center gap-2 rounded bg-primary px-4 py-2 text-on-primary hover:bg-primary-container disabled:opacity-50" disabled={isUpdating}>
                                    {isUpdating && <span className="material-symbols-outlined animate-spin text-[16px]">progress_activity</span>}
                                    {isUpdating ? "Menyimpan..." : "Simpan Perubahan"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}

function DetailRow({ label, value, valueClass = "" }) {
    return (
        <div className="flex items-start justify-between gap-4 py-3">
            <span className="shrink-0 text-secondary">{label}</span>
            <span className={`text-right ${valueClass}`}>{value ?? "-"}</span>
        </div>
    );
}

function StatusBadge({ blocked }) {
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 font-label-sm text-label-sm font-medium ${blocked
                    ? "border-slate-300 bg-slate-100 text-slate-700"
                    : "border-emerald-200 bg-emerald-50 text-emerald-800"
                }`}
        >
            <span className={`h-1.5 w-1.5 rounded-full ${blocked ? "bg-slate-500" : "bg-emerald-600"}`} />
            {blocked ? "Diblokir" : "Aktif"}
        </span>
    );
}

export default CustomerAccount;

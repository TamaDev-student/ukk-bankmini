import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import api from "../../../../config/api";

const formatRupiah = (value) => {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value) || 0);
};

export default function StudentMaster() {
    // State Data Siswa
    const [students, setStudents] = useState([]);
    const [pagination, setPagination] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: 0,
        from: 0,
        to: 0,
    });
    const [isLoading, setIsLoading] = useState(true);
    const [loadError, setLoadError] = useState("");

    // State Statistik
    const [summary, setSummary] = useState({
        total_students: 0,
        grade_x_count: 0,
        grade_xi_count: 0,
        grade_xii_count: 0,
        with_account_count: 0,
        without_account_count: 0,
    });

    // State Filter & Search
    const [search, setSearch] = useState("");
    const [gradeLevel, setGradeLevel] = useState("");
    const [classId, setClassId] = useState("");
    const [accountStatus, setAccountStatus] = useState("");
    const [classesList, setClassesList] = useState([]);

    // State Checkbox Pemilihan Siswa
    const [selectedStudents, setSelectedStudents] = useState([]);

    // State Modal Import
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);
    const [importFile, setImportFile] = useState(null);
    const [academicYearInput, setAcademicYearInput] = useState("2026");
    const [isParsing, setIsParsing] = useState(false);
    const [previewData, setPreviewData] = useState(null);
    const [importError, setImportError] = useState("");
    const [isConfirmingImport, setIsConfirmingImport] = useState(false);
    const [importSuccessResult, setImportSuccessResult] = useState(null);

    // State Modal Registrasi Nasabah
    const [isRegisterModalOpen, setIsRegisterModalOpen] = useState(false);
    const [studentsToRegister, setStudentsToRegister] = useState([]);
    const [registerForm, setRegisterForm] = useState({});
    const [globalOpeningBalance, setGlobalOpeningBalance] = useState("10000");
    const [isRegistering, setIsRegistering] = useState(false);
    const [registerError, setRegisterError] = useState("");
    const [registrationResults, setRegistrationResults] = useState(null);

    // Fetch classes list untuk filter
    useEffect(() => {
        let isMounted = true;
        const fetchClasses = async () => {
            try {
                const res = await api.get("/admin/classes");
                if (isMounted) {
                    setClassesList(Array.isArray(res.data?.data) ? res.data.data : []);
                }
            } catch {
                // Ignore fallback
            }
        };
        void fetchClasses();
        return () => {
            isMounted = false;
        };
    }, []);

    // Load Summary
    const loadSummary = useCallback(async () => {
        try {
            const res = await api.get("/admin/students/summary");
            if (res.data?.data) {
                setSummary(res.data.data);
            }
        } catch (err) {
            console.error("Gagal memuat ringkasan siswa:", err);
        }
    }, []);

    // Load Students List
    const loadStudents = useCallback(async (page = 1) => {
        setIsLoading(true);
        setLoadError("");
        try {
            const params = {
                page,
                per_page: pagination.per_page,
            };
            if (search.trim()) params.search = search.trim();
            if (gradeLevel) params.grade_level = gradeLevel;
            if (classId) params.class_id = classId;
            if (accountStatus) params.account_status = accountStatus;

            const res = await api.get("/admin/students", { params });
            const p = res.data?.data;
            if (p) {
                setStudents(Array.isArray(p.data) ? p.data : []);
                setPagination({
                    current_page: p.current_page || 1,
                    last_page: p.last_page || 1,
                    per_page: p.per_page || 15,
                    total: p.total || 0,
                    from: p.from || 0,
                    to: p.to || 0,
                });
            }
        } catch (err) {
            setLoadError(
                err.response?.data?.message ??
                err.message ??
                "Gagal memuat daftar master data siswa."
            );
        } finally {
            setIsLoading(false);
        }
    }, [pagination.per_page, search, gradeLevel, classId, accountStatus]);

    // Initial load & filter change debounce
    useEffect(() => {
        const timer = setTimeout(() => {
            void loadSummary();
            void loadStudents(1);
        }, 200);
        return () => clearTimeout(timer);
    }, [loadSummary, loadStudents]);

    // Handle check all on current page (only unregistered and class configured)
    const unregisteredOnPage = useMemo(() => {
        return students.filter((s) => !s.has_account && s.is_class_configured !== false);
    }, [students]);

    const isAllChecked = useMemo(() => {
        if (unregisteredOnPage.length === 0) return false;
        return unregisteredOnPage.every((s) =>
            selectedStudents.some((sel) => sel.id === s.id)
        );
    }, [unregisteredOnPage, selectedStudents]);

    const toggleCheckAll = () => {
        if (isAllChecked) {
            // Remove current page unregistered
            setSelectedStudents((prev) =>
                prev.filter((sel) => !unregisteredOnPage.some((s) => s.id === sel.id))
            );
        } else {
            // Add all unregistered on page that aren't already selected
            const newToAdd = unregisteredOnPage.filter(
                (s) => !selectedStudents.some((sel) => sel.id === s.id)
            );
            setSelectedStudents((prev) => [...prev, ...newToAdd]);
        }
    };

    const toggleCheckStudent = (student) => {
        if (student.has_account || student.is_class_configured === false) return;
        setSelectedStudents((prev) => {
            const exists = prev.some((s) => s.id === student.id);
            if (exists) {
                return prev.filter((s) => s.id !== student.id);
            }
            return [...prev, student];
        });
    };

    // Open Single Register
    const openSingleRegister = (student) => {
        setStudentsToRegister([student]);
        setRegisterForm({
            [student.id]: {
                email: `${student.student_number.toLowerCase()}@siswa.bankmini.test`,
                opening_balance: "10000",
            },
        });
        setRegisterError("");
        setRegistrationResults(null);
        setIsRegisterModalOpen(true);
    };

    // Open Batch Register for selected
    const openBatchRegister = () => {
        if (selectedStudents.length === 0) return;
        setStudentsToRegister(selectedStudents);
        const initialForm = {};
        selectedStudents.forEach((st) => {
            initialForm[st.id] = {
                email: `${st.student_number.toLowerCase()}@siswa.bankmini.test`,
                opening_balance: globalOpeningBalance || "10000",
            };
        });
        setRegisterForm(initialForm);
        setRegisterError("");
        setRegistrationResults(null);
        setIsRegisterModalOpen(true);
    };

    const applyGlobalBalance = () => {
        const val = globalOpeningBalance || "10000";
        setRegisterForm((prev) => {
            const updated = { ...prev };
            Object.keys(updated).forEach((id) => {
                updated[id] = { ...updated[id], opening_balance: val };
            });
            return updated;
        });
    };

    // Execute Batch Registration
    const handleProcessRegistration = async () => {
        setIsRegistering(true);
        setRegisterError("");
        try {
            const payload = studentsToRegister.map((st) => {
                const formData = registerForm[st.id] || {};
                return {
                    student_id: st.id,
                    email: formData.email ? formData.email.trim() : "",
                    opening_balance: parseInt(formData.opening_balance || "10000", 10),
                };
            });

            // Validasi lokal sebelum kirim
            for (const item of payload) {
                if (!item.email) {
                    throw new Error("Semua siswa yang didaftarkan wajib memiliki email pribadi.");
                }
                if (item.opening_balance < 10000) {
                    throw new Error("Setoran awal setiap siswa minimal Rp10.000.");
                }
            }

            const res = await api.post("/admin/students/batch-create-accounts", {
                students: payload,
            });

            setRegistrationResults(res.data?.data || null);
            setSelectedStudents([]);
            void loadSummary();
            void loadStudents(pagination.current_page);
        } catch (err) {
            setRegisterError(
                err.response?.data?.message ??
                err.message ??
                "Terjadi kesalahan saat memproses registrasi rekening."
            );
        } finally {
            setIsRegistering(false);
        }
    };

    // Modal Import Handlers
    const openImportModal = () => {
        setImportFile(null);
        setPreviewData(null);
        setImportError("");
        setImportSuccessResult(null);
        setIsImportModalOpen(true);
    };

    const handleUploadPreview = async (e) => {
        e.preventDefault();
        if (!importFile) {
            setImportError("Pilih file Excel (.xlsx) atau CSV terlebih dahulu.");
            return;
        }

        setIsParsing(true);
        setImportError("");
        try {
            const formData = new FormData();
            formData.append("file", importFile);
            if (academicYearInput) {
                formData.append("academic_year", academicYearInput);
            }

            const res = await api.post("/admin/students/import/preview", formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });

            setPreviewData(res.data?.data || null);
        } catch (err) {
            setImportError(
                err.response?.data?.message ??
                err.message ??
                "Gagal membaca file Excel. Pastikan format tabel sesuai."
            );
        } finally {
            setIsParsing(false);
        }
    };

    const handleConfirmImport = async () => {
        if (!previewData?.preview_token) return;
        setIsConfirmingImport(true);
        setImportError("");
        try {
            const res = await api.post("/admin/students/import/confirm", {
                preview_token: previewData.preview_token,
            });
            setImportSuccessResult(res.data?.data || { imported_count: previewData.summary.valid_count });
            void loadSummary();
            void loadStudents(1);
        } catch (err) {
            setImportError(
                err.response?.data?.message ??
                err.message ??
                "Gagal menyimpan data siswa ke master database."
            );
        } finally {
            setIsConfirmingImport(false);
        }
    };

    return (
        <div className="space-y-6">
            {/* Page Header */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900 flex items-center gap-2">
                        <span className="material-symbols-outlined text-primary text-3xl">school</span>
                        Master Data Siswa
                    </h1>
                    <p className="text-sm text-slate-500 mt-1">
                        Basis data seluruh siswa sekolah. Impor data dari daftar kelas dan daftarkan siswa terpilih menjadi nasabah.
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <button
                        type="button"
                        onClick={() => {
                            void loadSummary();
                            void loadStudents(pagination.current_page);
                        }}
                        className="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-50 transition shadow-xs"
                    >
                        <span className="material-symbols-outlined text-base">refresh</span>
                        Muat Ulang
                    </button>
                    <button
                        type="button"
                        onClick={openImportModal}
                        className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-primary text-white text-sm font-semibold hover:bg-primary/90 transition shadow-sm"
                    >
                        <span className="material-symbols-outlined text-base">upload_file</span>
                        Import Data Siswa
                    </button>
                </div>
            </div>

            {/* Statistik Summary Cards */}
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
                <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
                    <div className="flex items-center justify-between text-slate-500 mb-2">
                        <span className="text-xs font-semibold uppercase tracking-wider">Total Siswa</span>
                        <span className="material-symbols-outlined text-blue-600 text-xl">group</span>
                    </div>
                    <div className="text-2xl font-bold text-slate-900">{summary.total_students.toLocaleString()}</div>
                    <div className="text-xs text-slate-400 mt-1">Siswa Terdaftar di Master</div>
                </div>

                <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
                    <div className="flex items-center justify-between text-slate-500 mb-2">
                        <span className="text-xs font-semibold uppercase tracking-wider">Kelas X</span>
                        <span className="material-symbols-outlined text-indigo-600 text-xl">looks_one</span>
                    </div>
                    <div className="text-2xl font-bold text-slate-900">{summary.grade_x_count.toLocaleString()}</div>
                    <div className="text-xs text-slate-400 mt-1">Tingkat Kelas 10</div>
                </div>

                <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
                    <div className="flex items-center justify-between text-slate-500 mb-2">
                        <span className="text-xs font-semibold uppercase tracking-wider">Kelas XI</span>
                        <span className="material-symbols-outlined text-purple-600 text-xl">looks_two</span>
                    </div>
                    <div className="text-2xl font-bold text-slate-900">{summary.grade_xi_count.toLocaleString()}</div>
                    <div className="text-xs text-slate-400 mt-1">Tingkat Kelas 11</div>
                </div>

                <div className="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
                    <div className="flex items-center justify-between text-slate-500 mb-2">
                        <span className="text-xs font-semibold uppercase tracking-wider">Kelas XII</span>
                        <span className="material-symbols-outlined text-violet-600 text-xl">looks_3</span>
                    </div>
                    <div className="text-2xl font-bold text-slate-900">{summary.grade_xii_count.toLocaleString()}</div>
                    <div className="text-xs text-slate-400 mt-1">Tingkat Kelas 12</div>
                </div>

                <div className="bg-emerald-50/60 p-4 rounded-2xl border border-emerald-200/80 shadow-xs">
                    <div className="flex items-center justify-between text-emerald-800 mb-2">
                        <span className="text-xs font-bold uppercase tracking-wider">Punya Rekening</span>
                        <span className="material-symbols-outlined text-emerald-600 text-xl">check_circle</span>
                    </div>
                    <div className="text-2xl font-bold text-emerald-950">{summary.with_account_count.toLocaleString()}</div>
                    <div className="text-xs text-emerald-700/80 mt-1">Nasabah E-Teller Aktif</div>
                </div>

                <div className="bg-amber-50/60 p-4 rounded-2xl border border-amber-200/80 shadow-xs">
                    <div className="flex items-center justify-between text-amber-800 mb-2">
                        <span className="text-xs font-bold uppercase tracking-wider">Belum Rekening</span>
                        <span className="material-symbols-outlined text-amber-600 text-xl">account_balance_wallet</span>
                    </div>
                    <div className="text-2xl font-bold text-amber-950">{summary.without_account_count.toLocaleString()}</div>
                    <div className="text-xs text-amber-700/80 mt-1">Dapat Didaftarkan</div>
                </div>
            </div>

            {/* Filter & Action Toolbar */}
            <div className="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-xs space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    {/* Search */}
                    <div className="relative">
                        <span className="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xl">
                            search
                        </span>
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari NIS atau nama siswa..."
                            className="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none transition focus:bg-white focus:border-primary focus:ring-2 focus:ring-primary/20"
                        />
                    </div>

                    {/* Filter Tingkat */}
                    <div>
                        <select
                            value={gradeLevel}
                            onChange={(e) => setGradeLevel(e.target.value)}
                            className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none transition focus:bg-white focus:border-primary focus:ring-2 focus:ring-primary/20"
                        >
                            <option value="">Semua Tingkat</option>
                            <option value="X">Kelas X</option>
                            <option value="XI">Kelas XI</option>
                            <option value="XII">Kelas XII</option>
                        </select>
                    </div>

                    {/* Filter Kelas Rombel */}
                    <div>
                        <select
                            value={classId}
                            onChange={(e) => setClassId(e.target.value)}
                            className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none transition focus:bg-white focus:border-primary focus:ring-2 focus:ring-primary/20"
                        >
                            <option value="">Semua Rombel Kelas</option>
                            {classesList.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    {/* Filter Status Nasabah */}
                    <div>
                        <select
                            value={accountStatus}
                            onChange={(e) => setAccountStatus(e.target.value)}
                            className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-slate-50 text-sm outline-none transition focus:bg-white focus:border-primary focus:ring-2 focus:ring-primary/20"
                        >
                            <option value="">Semua Status</option>
                            <option value="unregistered">Belum Terdaftar</option>
                            <option value="registered">Sudah Terdaftar</option>
                        </select>
                    </div>

                    {/* Reset Button */}
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => {
                                setSearch("");
                                setGradeLevel("");
                                setClassId("");
                                setAccountStatus("");
                            }}
                            className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition"
                        >
                            Reset Filter
                        </button>
                    </div>
                </div>

                {/* Floating Batch Action Bar */}
                {selectedStudents.length > 0 && (
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-3 p-3.5 bg-blue-50 border border-blue-200 rounded-xl animate-fade-in">
                        <div className="flex items-center gap-2 text-sm text-blue-900 font-semibold">
                            <span className="material-symbols-outlined text-blue-700">check_box</span>
                            <span>{selectedStudents.length} siswa dipilih</span>
                        </div>
                        <div className="flex items-center gap-2 w-full sm:w-auto">
                            <button
                                type="button"
                                onClick={() => setSelectedStudents([])}
                                className="px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-200/60 rounded-lg transition"
                            >
                                Batal Pilihan
                            </button>
                            <button
                                type="button"
                                onClick={openBatchRegister}
                                className="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-white text-xs font-bold rounded-lg hover:bg-primary/90 transition shadow-xs"
                            >
                                <span className="material-symbols-outlined text-base">person_add</span>
                                Daftarkan sebagai Nasabah ({selectedStudents.length})
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* Error Message */}
            {loadError && (
                <div className="p-4 bg-red-50 border border-red-200 rounded-xl text-sm text-red-800 flex items-center gap-2">
                    <span className="material-symbols-outlined text-xl">error</span>
                    <span>{loadError}</span>
                </div>
            )}

            {/* Students Table */}
            <div className="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-left border-collapse">
                        <thead>
                            <tr className="bg-slate-50/80 text-slate-600 text-xs font-bold uppercase tracking-wider border-b border-slate-200">
                                <th className="py-3.5 px-4 w-12 text-center">
                                    <input
                                        type="checkbox"
                                        checked={isAllChecked}
                                        onChange={toggleCheckAll}
                                        disabled={unregisteredOnPage.length === 0}
                                        className="h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary cursor-pointer disabled:opacity-40"
                                    />
                                </th>
                                <th className="py-3.5 px-4">NIS</th>
                                <th className="py-3.5 px-4">Nama Siswa</th>
                                <th className="py-3.5 px-4">Kelas</th>
                                <th className="py-3.5 px-4">Jurusan</th>
                                <th className="py-3.5 px-4">Angkatan</th>
                                <th className="py-3.5 px-4">Kelulusan</th>
                                <th className="py-3.5 px-4">Status Nasabah</th>
                                <th className="py-3.5 px-4 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="text-sm divide-y divide-slate-100 text-slate-700">
                            {isLoading ? (
                                <tr>
                                    <td colSpan={9} className="px-4 py-12 text-center text-slate-500">
                                        <div className="flex flex-col items-center justify-center gap-2">
                                            <span className="material-symbols-outlined animate-spin text-3xl text-primary">progress_activity</span>
                                            <span>Memuat data master siswa...</span>
                                        </div>
                                    </td>
                                </tr>
                            ) : students.length === 0 ? (
                                <tr>
                                    <td colSpan={9} className="px-4 py-12 text-center text-slate-500">
                                        <div className="flex flex-col items-center justify-center gap-2">
                                            <span className="material-symbols-outlined text-4xl text-slate-300">school</span>
                                            <span className="font-semibold text-slate-700">Belum ada data siswa ditemukan</span>
                                            <span className="text-xs text-slate-400">
                                                Gunakan tombol "Import Data Siswa" di bagian atas untuk mengunggah daftar absensi siswa.
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                students.map((student) => {
                                    const isSelected = selectedStudents.some((s) => s.id === student.id);
                                    return (
                                        <tr
                                            key={student.id}
                                            className={`hover:bg-slate-50/70 transition ${isSelected ? "bg-blue-50/50" : ""}`}
                                        >
                                            <td className="py-3.5 px-4 text-center">
                                                <input
                                                    type="checkbox"
                                                    checked={isSelected}
                                                    disabled={student.has_account || student.is_class_configured === false}
                                                    onChange={() => toggleCheckStudent(student)}
                                                    title={student.is_class_configured === false ? "Kelas belum terkonfigurasi pada rombel resmi" : ""}
                                                    className="h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary cursor-pointer disabled:opacity-30 disabled:cursor-not-allowed"
                                                />
                                            </td>
                                            <td className="py-3.5 px-4 font-mono font-bold text-slate-900">
                                                {student.student_number}
                                            </td>
                                            <td className="py-3.5 px-4 font-semibold text-slate-900">
                                                {student.student_name}
                                            </td>
                                            <td className="py-3.5 px-4">
                                                {student.is_class_configured === false ? (
                                                    <span
                                                        title={student.class_warning || "Kelas belum terkonfigurasi di sistem sekolah."}
                                                        className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-300"
                                                    >
                                                        <span className="material-symbols-outlined text-xs">warning</span>
                                                        {student.class_name} (Belum Dikonfigurasi)
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                                                        {student.class_name}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-3.5 px-4 text-slate-600">
                                                {student.major_name}
                                            </td>
                                            <td className="py-3.5 px-4 font-mono text-slate-600">
                                                {student.cohort_year}
                                            </td>
                                            <td className="py-3.5 px-4 font-mono text-slate-600">
                                                {student.graduation_year}
                                            </td>
                                            <td className="py-3.5 px-4">
                                                {student.has_account ? (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                        <span className="material-symbols-outlined text-sm">check_circle</span>
                                                        Sudah Terdaftar
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                                        <span className="material-symbols-outlined text-sm">schedule</span>
                                                        Belum Terdaftar
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-3.5 px-4 text-right">
                                                {student.has_account ? (
                                                    <Link
                                                        to={`/admin/accounts?search=${encodeURIComponent(student.student_number)}`}
                                                        className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800"
                                                    >
                                                        <span>Rekening</span>
                                                        <span className="material-symbols-outlined text-xs">arrow_forward</span>
                                                    </Link>
                                                ) : student.is_class_configured === false ? (
                                                    <span
                                                        title={student.class_warning || "Konfigurasikan rombel kelas terlebih dahulu sebelum mendaftar nasabah."}
                                                        className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 text-slate-400 text-xs font-semibold cursor-not-allowed"
                                                    >
                                                        <span className="material-symbols-outlined text-xs">lock</span>
                                                        Belum Siap
                                                    </span>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        onClick={() => openSingleRegister(student)}
                                                        className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 text-xs font-bold transition"
                                                    >
                                                        <span className="material-symbols-outlined text-sm">person_add</span>
                                                        Daftarkan
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {pagination.total > 0 && (
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-100 text-xs text-slate-500">
                        <div>
                            Menampilkan <span className="font-semibold text-slate-700">{pagination.from}</span> sampai{" "}
                            <span className="font-semibold text-slate-700">{pagination.to}</span> dari{" "}
                            <span className="font-semibold text-slate-700">{pagination.total.toLocaleString()}</span> siswa
                        </div>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                disabled={pagination.current_page <= 1 || isLoading}
                                onClick={() => loadStudents(pagination.current_page - 1)}
                                className="px-3 py-1.5 rounded-lg border border-slate-200 bg-white font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition"
                            >
                                Sebelumnya
                            </button>
                            <span className="px-2 font-semibold text-slate-700">
                                {pagination.current_page} / {pagination.last_page}
                            </span>
                            <button
                                type="button"
                                disabled={pagination.current_page >= pagination.last_page || isLoading}
                                onClick={() => loadStudents(pagination.current_page + 1)}
                                className="px-3 py-1.5 rounded-lg border border-slate-200 bg-white font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition"
                            >
                                Berikutnya
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* ========================================================================= */}
            {/* MODAL 1: IMPORT MASTER DATA SISWA                                          */}
            {/* ========================================================================= */}
            {isImportModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
                    <div className="bg-white rounded-3xl shadow-2xl max-w-4xl w-full max-h-[92vh] flex flex-col overflow-hidden animate-scale-up">
                        {/* Header Modal */}
                        <div className="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="p-2.5 bg-blue-50 text-blue-600 rounded-2xl">
                                    <span className="material-symbols-outlined text-2xl">upload_file</span>
                                </div>
                                <div>
                                    <h2 className="text-lg font-bold text-slate-900">Import Master Data Siswa</h2>
                                    <p className="text-xs text-slate-500">
                                        Unggah file Excel daftar absensi untuk menyimpan seluruh identitas siswa ke master data.
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setIsImportModalOpen(false)}
                                className="p-2 text-slate-400 hover:text-slate-600 rounded-xl hover:bg-slate-100 transition"
                            >
                                <span className="material-symbols-outlined text-xl">close</span>
                            </button>
                        </div>

                        {/* Body Modal */}
                        <div className="p-6 overflow-y-auto flex-1 space-y-5">
                            {importSuccessResult ? (
                                <div className="text-center py-8 space-y-4">
                                    <div className="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto">
                                        <span className="material-symbols-outlined text-4xl">check_circle</span>
                                    </div>
                                    <h3 className="text-xl font-bold text-slate-900">Import Berhasil Selesai!</h3>
                                    <p className="text-sm text-slate-600 max-w-md mx-auto">
                                        Berhasil menyimpan <span className="font-bold text-emerald-700">{importSuccessResult.imported_count} siswa</span> ke tabel Master Data Siswa.
                                    </p>
                                    <div className="bg-slate-50 p-4 rounded-2xl border border-slate-200 max-w-md mx-auto text-xs text-slate-500 text-left space-y-1">
                                        <div className="font-semibold text-slate-700 mb-1">Prinsip Keamanan:</div>
                                        <div>✓ Tidak ada akun User login yang dibuat otomatis.</div>
                                        <div>✓ Tidak ada akun nasabah atau nomor rekening baru yang dicatat.</div>
                                        <div>✓ Tidak ada saldo atau mutasi pembukuan yang dipengaruhi.</div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setIsImportModalOpen(false)}
                                        className="px-6 py-2.5 bg-primary text-white text-sm font-semibold rounded-xl hover:bg-primary/90 transition"
                                    >
                                        Selesai & Tutup
                                    </button>
                                </div>
                            ) : !previewData ? (
                                <form onSubmit={handleUploadPreview} className="space-y-4">
                                    <div className="bg-blue-50 border border-blue-200 rounded-2xl p-4 text-xs text-blue-900 space-y-1.5 leading-relaxed">
                                        <div className="font-bold text-blue-950 flex items-center gap-1.5">
                                            <span className="material-symbols-outlined text-base">info</span>
                                            File Sumber Daftar Absensi
                                        </div>
                                        <div>
                                            Sistem hanya mengekstrak <strong>NIS</strong>, <strong>Nama Siswa</strong>, dan <strong>Informasi Kelas</strong> dari dokumen.
                                        </div>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                                            Pilih File Excel (.xlsx) atau CSV
                                        </label>
                                        <div className="border-2 border-dashed border-slate-300 rounded-2xl p-6 text-center hover:border-primary transition bg-slate-50/50">
                                            <input
                                                type="file"
                                                accept=".xlsx,.csv"
                                                onChange={(e) => setImportFile(e.target.files?.[0] || null)}
                                                className="hidden"
                                                id="student-file-input"
                                            />
                                            <label htmlFor="student-file-input" className="cursor-pointer flex flex-col items-center">
                                                <span className="material-symbols-outlined text-4xl text-slate-400 mb-2">
                                                    cloud_upload
                                                </span>
                                                <span className="text-sm font-semibold text-primary hover:underline">
                                                    {importFile ? importFile.name : "Klik untuk memilih file Excel"}
                                                </span>
                                                <span className="text-xs text-slate-400 mt-1">
                                                    Format .xlsx atau .csv (Maksimal 15 MB)
                                                </span>
                                            </label>
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                                Tahun Awal Pelajaran
                                            </label>
                                            <input
                                                type="number"
                                                min="2020"
                                                max="2035"
                                                value={academicYearInput}
                                                onChange={(e) => setAcademicYearInput(e.target.value)}
                                                placeholder="Contoh: 2026"
                                                className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                                            />
                                            <span className="text-[11px] text-slate-400 mt-1 block">
                                                Digunakan untuk generate tahun angkatan (contoh: 2026 untuk TA 2026-2027).
                                            </span>
                                        </div>
                                    </div>

                                    {importError && (
                                        <div className="p-3.5 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs flex items-center gap-2">
                                            <span className="material-symbols-outlined text-lg">error</span>
                                            <span>{importError}</span>
                                        </div>
                                    )}

                                    <div className="flex justify-end gap-2 pt-2">
                                        <button
                                            type="button"
                                            onClick={() => setIsImportModalOpen(false)}
                                            className="px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition"
                                        >
                                            Batal
                                        </button>
                                        <button
                                            type="submit"
                                            disabled={!importFile || isParsing}
                                            className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-primary text-white text-sm font-bold hover:bg-primary/90 transition disabled:opacity-50"
                                        >
                                            {isParsing ? (
                                                <>
                                                    <span className="material-symbols-outlined animate-spin text-base">progress_activity</span>
                                                    Membaca & Memvalidasi...
                                                </>
                                            ) : (
                                                <>
                                                    <span>Lanjut ke Pratinjau</span>
                                                    <span className="material-symbols-outlined text-base">arrow_forward</span>
                                                </>
                                            )}
                                        </button>
                                    </div>
                                </form>
                            ) : (
                                <div className="space-y-4">
                                    {/* Preview Summary */}
                                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div className="p-3 bg-slate-50 border border-slate-200 rounded-xl">
                                            <div className="text-xs text-slate-500 font-semibold">Total Siswa Terdeteksi</div>
                                            <div className="text-xl font-bold text-slate-900 mt-0.5">
                                                {previewData.summary.total_found.toLocaleString()}
                                            </div>
                                        </div>
                                        <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl">
                                            <div className="text-xs text-emerald-700 font-semibold">Siap Diimpor ke Master</div>
                                            <div className="text-xl font-bold text-emerald-900 mt-0.5">
                                                {previewData.summary.valid_count.toLocaleString()}
                                            </div>
                                        </div>
                                        <div className="p-3 bg-amber-50 border border-amber-200 rounded-xl">
                                            <div className="text-xs text-amber-700 font-semibold">Kelas Belum Ada di Rombel</div>
                                            <div className="text-xl font-bold text-amber-900 mt-0.5">
                                                {(previewData.summary.unconfigured_class_count || 0).toLocaleString()}
                                            </div>
                                        </div>
                                        <div className="p-3 bg-red-50 border border-red-200 rounded-xl">
                                            <div className="text-xs text-red-700 font-semibold">Duplikasi NIS</div>
                                            <div className="text-xl font-bold text-red-900 mt-0.5">
                                                {previewData.summary.duplicate_count.toLocaleString()}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Warning alert if unconfigured classes present */}
                                    {previewData.summary.unconfigured_class_count > 0 && (
                                        <div className="p-3 bg-amber-50/90 border border-amber-200 text-amber-900 rounded-xl text-xs flex items-start gap-2">
                                            <span className="material-symbols-outlined text-base text-amber-700 mt-0.5">warning</span>
                                            <div>
                                                <span className="font-bold">
                                                    {previewData.summary.unconfigured_class_count} siswa memiliki kelas yang belum terkonfigurasi di sistem (misal: XII AK 4).
                                                </span>
                                                <span className="block text-amber-800 mt-0.5">
                                                    Seluruh siswa tetap disimpan ke Master Data Siswa dengan data kelas asal, namun belum dapat didaftarkan sebagai nasabah sebelum rombel kelas dikonfigurasi.
                                                </span>
                                            </div>
                                        </div>
                                    )}

                                    {/* Preview Table */}
                                    <div className="border border-slate-200 rounded-xl overflow-hidden">
                                        <div className="max-h-80 overflow-y-auto">
                                            <table className="w-full text-left text-xs">
                                                <thead className="bg-slate-50 sticky top-0 border-b border-slate-200 text-slate-600 font-bold uppercase">
                                                    <tr>
                                                        <th className="py-2.5 px-3">#</th>
                                                        <th className="py-2.5 px-3">NIS</th>
                                                        <th className="py-2.5 px-3">Nama Siswa</th>
                                                        <th className="py-2.5 px-3">Kelas di Dokumen</th>
                                                        <th className="py-2.5 px-3">Angkatan</th>
                                                        <th className="py-2.5 px-3">Kelulusan</th>
                                                        <th className="py-2.5 px-3">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100">
                                                    {previewData.rows.slice(0, 100).map((r, i) => (
                                                        <tr key={i} className={!r.is_valid ? "bg-red-50/50" : ""}>
                                                            <td className="py-2 px-3 text-slate-400">{i + 1}</td>
                                                            <td className="py-2 px-3 font-mono font-bold text-slate-900">{r.student_number}</td>
                                                            <td className="py-2 px-3 font-medium text-slate-900">{r.student_name}</td>
                                                            <td className="py-2 px-3 text-slate-600">{r.raw_class}</td>
                                                            <td className="py-2 px-3 font-mono text-slate-600">{r.cohort_year}</td>
                                                            <td className="py-2 px-3 font-mono text-slate-600">{r.graduation_year}</td>
                                                            <td className="py-2 px-3">
                                                                {r.is_valid ? (
                                                                    r.is_class_configured ? (
                                                                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                                                            Siap Impor
                                                                        </span>
                                                                    ) : (
                                                                        <span
                                                                            className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800"
                                                                            title={r.warning_message || "Kelas belum terkonfigurasi di sistem"}
                                                                        >
                                                                            <span className="material-symbols-outlined text-[12px]">warning</span>
                                                                            Kelas Belum Ada
                                                                        </span>
                                                                    )
                                                                ) : (
                                                                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-800" title={r.error_message}>
                                                                        {r.error_message || "Bermasalah"}
                                                                    </span>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                        {previewData.rows.length > 100 && (
                                            <div className="p-2 bg-slate-50 text-center text-xs text-slate-500 border-t border-slate-200">
                                                Menampilkan 100 baris pertama dari total {previewData.rows.length} siswa.
                                            </div>
                                        )}
                                    </div>

                                    {importError && (
                                        <div className="p-3.5 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs flex items-center gap-2">
                                            <span className="material-symbols-outlined text-lg">error</span>
                                            <span>{importError}</span>
                                        </div>
                                    )}

                                    <div className="flex items-center justify-between pt-2">
                                        <button
                                            type="button"
                                            onClick={() => setPreviewData(null)}
                                            className="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                        >
                                            ← Upload Ulang
                                        </button>
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                onClick={() => setIsImportModalOpen(false)}
                                                className="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                            >
                                                Batal
                                            </button>
                                            <button
                                                type="button"
                                                disabled={previewData.summary.valid_count === 0 || isConfirmingImport}
                                                onClick={handleConfirmImport}
                                                className="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-primary text-white text-xs font-bold hover:bg-primary/90 transition disabled:opacity-50 shadow-sm"
                                            >
                                                {isConfirmingImport ? (
                                                    <>
                                                        <span className="material-symbols-outlined animate-spin text-base">progress_activity</span>
                                                        Menyimpan Data...
                                                    </>
                                                ) : (
                                                    <>
                                                        <span className="material-symbols-outlined text-base">check</span>
                                                        Konfirmasi Import ({previewData.summary.valid_count} Siswa)
                                                    </>
                                                )}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* ========================================================================= */}
            {/* MODAL 2: REGISTRASI NASABAH DARI MASTER SISWA                             */}
            {/* ========================================================================= */}
            {isRegisterModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
                    <div className="bg-white rounded-3xl shadow-2xl max-w-4xl w-full max-h-[92vh] flex flex-col overflow-hidden animate-scale-up">
                        {/* Header */}
                        <div className="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="p-2.5 bg-emerald-50 text-emerald-600 rounded-2xl">
                                    <span className="material-symbols-outlined text-2xl">person_add</span>
                                </div>
                                <div>
                                    <h2 className="text-lg font-bold text-slate-900">Registrasi Nasabah Bank Mini</h2>
                                    <p className="text-xs text-slate-500">
                                        Membuat akun rekening dan kredensial login nasabah untuk siswa terpilih.
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setIsRegisterModalOpen(false)}
                                className="p-2 text-slate-400 hover:text-slate-600 rounded-xl hover:bg-slate-100 transition"
                            >
                                <span className="material-symbols-outlined text-xl">close</span>
                            </button>
                        </div>

                        {/* Body */}
                        <div className="p-6 overflow-y-auto flex-1 space-y-4">
                            {registrationResults ? (
                                <div className="space-y-4">
                                    <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl text-emerald-900 flex items-start gap-3">
                                        <span className="material-symbols-outlined text-2xl text-emerald-600 mt-0.5">check_circle</span>
                                        <div>
                                            <div className="font-bold text-base">Registrasi Nasabah Selesai!</div>
                                            <div className="text-xs text-emerald-700 mt-1">
                                                {registrationResults.success_count} rekening berhasil dibuat. Berikan password sementara berikut kepada masing-masing siswa untuk login pertama kali.
                                            </div>
                                        </div>
                                    </div>

                                    {registrationResults.failures?.length > 0 && (
                                        <div className="p-4 bg-amber-50 border border-amber-200 rounded-2xl text-amber-900 text-xs space-y-1">
                                            <div className="font-bold">{registrationResults.failure_count} Siswa Gagal Didaftarkan:</div>
                                            {registrationResults.failures.map((f, idx) => (
                                                <div key={idx}>• {f.student_name} ({f.student_number}): {f.reason}</div>
                                            ))}
                                        </div>
                                    )}

                                    {/* Credentials Table */}
                                    <div className="border border-slate-200 rounded-2xl overflow-hidden">
                                        <div className="max-h-72 overflow-y-auto">
                                            <table className="w-full text-left text-xs">
                                                <thead className="bg-slate-50 text-slate-600 font-bold uppercase border-b border-slate-200">
                                                    <tr>
                                                        <th className="py-2.5 px-3">Nama Siswa</th>
                                                        <th className="py-2.5 px-3">No. Rekening</th>
                                                        <th className="py-2.5 px-3">Username (NIS)</th>
                                                        <th className="py-2.5 px-3">Password Sementara</th>
                                                        <th className="py-2.5 px-3">Saldo Awal</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100 font-mono">
                                                    {registrationResults.created_accounts?.map((acc, idx) => (
                                                        <tr key={idx} className="hover:bg-slate-50">
                                                            <td className="py-2.5 px-3 font-sans font-semibold text-slate-900">{acc.student_name}</td>
                                                            <td className="py-2.5 px-3 font-bold text-blue-700">{acc.account_number}</td>
                                                            <td className="py-2.5 px-3 text-slate-700">{acc.student_number}</td>
                                                            <td className="py-2.5 px-3">
                                                                <span className="px-2 py-1 bg-amber-50 border border-amber-200 text-amber-900 rounded font-bold">
                                                                    {acc.temporary_password}
                                                                </span>
                                                            </td>
                                                            <td className="py-2.5 px-3 font-sans text-emerald-700 font-semibold">
                                                                {formatRupiah(acc.opening_balance)}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <div className="flex justify-end pt-2">
                                        <button
                                            type="button"
                                            onClick={() => setIsRegisterModalOpen(false)}
                                            className="px-6 py-2.5 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition shadow-sm"
                                        >
                                            Selesai & Tutup
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-2xl flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
                                        <div className="text-slate-600">
                                            Mendaftarkan <span className="font-bold text-slate-900">{studentsToRegister.length} siswa</span>. Data identitas diambil dari master.
                                        </div>
                                        {studentsToRegister.length > 1 && (
                                            <div className="flex items-center gap-2">
                                                <span className="text-slate-500 font-medium">Terapkan Setoran:</span>
                                                <input
                                                    type="number"
                                                    min="10000"
                                                    step="5000"
                                                    value={globalOpeningBalance}
                                                    onChange={(e) => setGlobalOpeningBalance(e.target.value)}
                                                    className="w-24 px-2 py-1 text-xs border border-slate-200 rounded-lg text-right font-mono"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={applyGlobalBalance}
                                                    className="px-2.5 py-1 bg-slate-200 hover:bg-slate-300 font-bold rounded-lg text-slate-700 transition"
                                                >
                                                    Samakan
                                                </button>
                                            </div>
                                        )}
                                    </div>

                                    {/* Table of students to register */}
                                    <div className="border border-slate-200 rounded-2xl overflow-hidden max-h-80 overflow-y-auto">
                                        <table className="w-full text-left text-xs">
                                            <thead className="bg-slate-50 sticky top-0 border-b border-slate-200 text-slate-600 font-bold uppercase">
                                                <tr>
                                                    <th className="py-2.5 px-3">NIS</th>
                                                    <th className="py-2.5 px-3">Nama Siswa</th>
                                                    <th className="py-2.5 px-3">Kelas</th>
                                                    <th className="py-2.5 px-3">Email Pribadi Siswa</th>
                                                    <th className="py-2.5 px-3">Setoran Awal (Rp)</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100">
                                                {studentsToRegister.map((st) => (
                                                    <tr key={st.id} className="hover:bg-slate-50">
                                                        <td className="py-2 px-3 font-mono font-bold text-slate-900">{st.student_number}</td>
                                                        <td className="py-2 px-3 font-semibold text-slate-900">{st.student_name}</td>
                                                        <td className="py-2 px-3 text-slate-600">{st.class_name}</td>
                                                        <td className="py-2 px-3">
                                                            <input
                                                                type="email"
                                                                required
                                                                value={registerForm[st.id]?.email || ""}
                                                                onChange={(e) => {
                                                                    const val = e.target.value;
                                                                    setRegisterForm((prev) => ({
                                                                        ...prev,
                                                                        [st.id]: { ...prev[st.id], email: val },
                                                                    }));
                                                                }}
                                                                placeholder="email@siswa.com"
                                                                className="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs outline-none focus:border-primary"
                                                            />
                                                        </td>
                                                        <td className="py-2 px-3">
                                                            <input
                                                                type="number"
                                                                min="10000"
                                                                step="5000"
                                                                required
                                                                value={registerForm[st.id]?.opening_balance || "10000"}
                                                                onChange={(e) => {
                                                                    const val = e.target.value;
                                                                    setRegisterForm((prev) => ({
                                                                        ...prev,
                                                                        [st.id]: { ...prev[st.id], opening_balance: val },
                                                                    }));
                                                                }}
                                                                className="w-28 px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs font-mono text-right outline-none focus:border-primary"
                                                            />
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    {registerError && (
                                        <div className="p-3.5 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs flex items-center gap-2">
                                            <span className="material-symbols-outlined text-lg">error</span>
                                            <span>{registerError}</span>
                                        </div>
                                    )}

                                    <div className="flex justify-end gap-2 pt-2">
                                        <button
                                            type="button"
                                            onClick={() => setIsRegisterModalOpen(false)}
                                            className="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                        >
                                            Batal
                                        </button>
                                        <button
                                            type="button"
                                            disabled={isRegistering}
                                            onClick={handleProcessRegistration}
                                            className="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-white text-xs font-bold rounded-xl hover:bg-primary/90 transition shadow-sm disabled:opacity-50"
                                        >
                                            {isRegistering ? (
                                                <>
                                                    <span className="material-symbols-outlined animate-spin text-base">progress_activity</span>
                                                    Memproses Pembuatan Akun...
                                                </>
                                            ) : (
                                                <>
                                                    <span className="material-symbols-outlined text-base">person_add</span>
                                                    Proses Registrasi ({studentsToRegister.length} Rekening)
                                                </>
                                            )}
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

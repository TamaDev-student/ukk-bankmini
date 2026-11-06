import { useEffect, useState } from "react";
import {
    AlertCircle,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    LoaderCircle,
    Upload,
    X,
} from "lucide-react";

import api from "../../../../config/api";

const errorMessage = async (error) => {
    let responseData = error?.response?.data;

    if (responseData instanceof Blob) {
        responseData = await responseData.text();
    } else if (responseData instanceof ArrayBuffer) {
        responseData = new TextDecoder().decode(responseData);
    }

    if (typeof responseData === "string") {
        try {
            responseData = JSON.parse(responseData);
        } catch {
            responseData = null;
        }
    }

    const fieldLabels = {
        file: "File",
        nis: "NIS",
        student_number: "NIS",
        student_name: "Nama Lengkap Siswa",
        grade_level: "Tingkat Kelas",
        major_name: "Jurusan",
        rombel: "Kelas",
        email: "Email Pribadi",
        opening_balance: "Setoran Awal (Rp)",
    };
    const validationMessages = [];
    const collectMessages = (value, path = "") => {
        if (typeof value === "string" && value.trim()) {
            const field = path.split(".").pop();
            const row = path.match(/(?:rows?|data)\.(\d+)(?:\.|$)/)?.[1];
            const label = fieldLabels[field] || (field ? "Data Excel" : "");
            validationMessages.push(
                `${row ? `Baris ${row}: ` : ""}${label ? `${label}: ` : ""}${value.trim()}`
            );
            return;
        }
        if (Array.isArray(value)) {
            value.forEach((item) => collectMessages(item, path));
            return;
        }
        if (value && typeof value === "object") {
            Object.entries(value).forEach(([key, item]) =>
                collectMessages(item, path ? `${path}.${key}` : key)
            );
        }
    };

    collectMessages(responseData?.errors);

    const responseStatus = error?.response?.status;
    const responseMessage =
        typeof responseData?.message === "string" &&
        !/SQLSTATE|exception|stack trace| in .+:\d+/i.test(responseData.message)
            ? responseData.message
            : null;

    return (
        (validationMessages.length > 0
            ? `Import gagal. Ditemukan kesalahan pada file atau data Excel:\n${validationMessages.join("\n")}`
            : null) ||
        (responseStatus === 422
            ? (responseMessage &&
                !/^the given data was invalid\.?$/i.test(responseMessage)
                ? responseMessage
                : null)
            : null) ||
        (responseStatus === 422
            ? "Import gagal. Periksa file Excel dan pastikan menggunakan template terbaru."
            : null) ||
        (responseStatus >= 500
            ? "Terjadi kesalahan server saat memproses file. Silakan coba kembali atau hubungi administrator."
            : responseMessage) ||
        (responseStatus ? `Permintaan gagal (${responseStatus}).` : error?.message) ||
        "Terjadi kesalahan."
    );
};

const formatRupiah = (value) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value) || 0);

function CustomerImport({ onClose, onImported }) {
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState(null);
    const [result, setResult] = useState(null);
    const [classes, setClasses] = useState([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");

    useEffect(() => {
        api.get("/admin/classes")
            .then((response) =>
                setClasses(
                    Array.isArray(response.data?.data)
                        ? response.data.data
                        : []
                )
            )
            .catch(async (requestError) => setError(await errorMessage(requestError)));
    }, []);

    const downloadTemplate = async () => {
        setBusy(true);
        setError("");
        try {
            const response = await api.get("/admin/customers/import/template", {
                responseType: "blob",
            });
            const url = URL.createObjectURL(response.data);
            const link = document.createElement("a");
            link.href = url;
            link.download = "template-import-nasabah.xlsx";
            link.click();
            URL.revokeObjectURL(url);
        } catch (requestError) {
            setError(await errorMessage(requestError));
        } finally {
            setBusy(false);
        }
    };

    const handleFileChange = (event) => {
        const selectedFile = event.target.files?.[0] ?? null;
        setError("");
        setPreview(null);
        setResult(null);

        if (!selectedFile) {
            setFile(null);
            return;
        }

        if (!selectedFile.name.toLowerCase().endsWith(".xlsx")) {
            setFile(null);
            setError("Pilih file Excel dengan format .xlsx.");
            event.target.value = "";
            return;
        }

        if (selectedFile.size > 5 * 1024 * 1024) {
            setFile(null);
            setError("Ukuran file maksimal 5 MB.");
            event.target.value = "";
            return;
        }

        setFile(selectedFile);
    };

    const inspectFile = async (event) => {
        event.preventDefault();
        if (!file) return;

        setBusy(true);
        setError("");
        setPreview(null);
        setResult(null);
        try {
            const body = new FormData();
            body.append("file", file);
            const response = await api.post(
                "/admin/customers/import/preview",
                body
            );
            setPreview(response.data.data);
        } catch (requestError) {
            setError(await errorMessage(requestError));
        } finally {
            setBusy(false);
        }
    };

    const confirmImport = async () => {
        if (!preview?.import_token || preview.valid_rows < 1) return;

        setBusy(true);
        setError("");
        try {
            const response = await api.post(
                "/admin/customers/import/confirm",
                { import_token: preview.import_token }
            );
            const importResult = response.data.data;
            setResult(importResult);
            setPreview(null);
            onImported?.(importResult);
        } catch (requestError) {
            setError(await errorMessage(requestError));
        } finally {
            setBusy(false);
        }
    };

    const close = () => {
        if (!busy) onClose?.();
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/50 p-3 backdrop-blur-[2px] sm:p-6"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) close();
            }}
        >
            <section
                aria-labelledby="customer-import-title"
                aria-modal="true"
                className="my-auto flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl"
                role="dialog"
            >
                <header className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                    <div className="flex items-start gap-3">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700">
                            <FileSpreadsheet size={21} />
                        </span>
                        <div>
                            <h2
                                className="text-lg font-semibold text-slate-900"
                                id="customer-import-title"
                            >
                                Import Nasabah
                            </h2>
                            <p className="mt-1 text-sm text-slate-500">
                                Unggah, periksa, lalu konfirmasi data dari
                                template Excel.
                            </p>
                        </div>
                    </div>
                    <button
                        aria-label="Tutup import nasabah"
                        className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-800 disabled:opacity-50"
                        disabled={busy}
                        onClick={close}
                        type="button"
                    >
                        <X size={19} />
                    </button>
                </header>

                <div className="overflow-y-auto p-5 sm:p-6">
                    <div className="grid gap-4 lg:grid-cols-[0.85fr_1.15fr]">
                        <div className="space-y-4">
                            <section className="rounded-lg border border-slate-200 p-4">
                                <h3 className="font-semibold text-slate-900">
                                    1. Unduh template
                                </h3>
                                <ol className="mt-2 list-decimal space-y-1 pl-5 text-sm leading-5 text-slate-600">
                                    <li>Unduh template Excel.</li>
                                    <li>
                                        Isi NIS, Nama Lengkap Siswa, Tingkat
                                        Kelas, Jurusan, Kelas, Email Pribadi,
                                        dan Setoran Awal (Rp).
                                    </li>
                                    <li>
                                        Simpan NIS sebagai teks agar angka nol
                                        di awal tidak hilang.
                                    </li>
                                    <li>
                                        Gunakan nama jurusan lengkap dari sheet
                                        Referensi Kelas. Tingkat kelas harus X,
                                        XI, atau XII.
                                    </li>
                                    <li>
                                        Isi kolom Kelas dengan nomor rombel.
                                        RPL, TKJ, DKV, MP, LPS, dan BR memiliki
                                        kelas 1-2; AKL 1-3; BD hanya 1.
                                    </li>
                                    <li>
                                        Kombinasi tingkat, jurusan, dan kelas
                                        harus tersedia pada sheet Referensi
                                        Kelas. Kelas baru tidak dibuat otomatis.
                                    </li>
                                    <li>
                                        Email wajib diisi, tetapi belum otomatis
                                        terverifikasi.
                                    </li>
                                    <li>
                                        Setoran Awal (Rp) minimal Rp10.000,
                                        masukkan angka tanpa simbol mata uang.
                                    </li>
                                    <li>
                                        Baca sheet Petunjuk Pengisian untuk
                                        arti setiap kolom.
                                    </li>
                                    <li>Simpan file dalam format .xlsx.</li>
                                    <li>
                                        Unggah kembali melalui halaman Rekening
                                        Nasabah.
                                    </li>
                                </ol>
                                <button
                                    className="mt-3 inline-flex items-center gap-2 rounded-lg border border-blue-300 px-3 py-2 text-sm font-semibold text-blue-800 hover:bg-blue-50 disabled:opacity-50"
                                    disabled={busy}
                                    onClick={downloadTemplate}
                                    type="button"
                                >
                                    <Download size={16} />
                                    Unduh Template Excel
                                </button>
                            </section>

                            <section className="rounded-lg border border-slate-200 p-4">
                                <h3 className="font-semibold text-slate-900">
                                    2. Upload dan periksa
                                </h3>
                                <form
                                    action="/api/admin/customers/import/preview"
                                    className="mt-3 space-y-3"
                                    encType="multipart/form-data"
                                    method="post"
                                    onSubmit={inspectFile}
                                >
                                    <label
                                        className="flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 p-4 text-center transition hover:border-blue-400 hover:bg-blue-50/50"
                                        htmlFor="customer-import-file"
                                    >
                                        <Upload
                                            className="text-slate-500"
                                            size={22}
                                        />
                                        <span className="mt-2 text-sm font-medium text-slate-700">
                                            {file
                                                ? file.name
                                                : "Pilih file Excel .xlsx"}
                                        </span>
                                        <span className="mt-1 text-xs text-slate-500">
                                            Maksimal 5 MB
                                        </span>
                                        <input
                                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                            className="sr-only"
                                            disabled={busy}
                                            id="customer-import-file"
                                            name="file"
                                            onChange={handleFileChange}
                                            type="file"
                                        />
                                    </label>
                                    {file && (
                                        <button
                                            className="text-xs font-medium text-slate-600 underline"
                                            disabled={busy}
                                            onClick={() => {
                                                setFile(null);
                                                setPreview(null);
                                            }}
                                            type="button"
                                        >
                                            Hapus file
                                        </button>
                                    )}
                                    <button
                                        className="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
                                        disabled={busy || !file}
                                        type="submit"
                                    >
                                        {busy ? (
                                            <LoaderCircle
                                                className="animate-spin"
                                                size={17}
                                            />
                                        ) : (
                                            <CheckCircle2 size={17} />
                                        )}
                                        {busy ? "Memeriksa..." : "Periksa File"}
                                    </button>
                                </form>
                            </section>

                            <details className="rounded-lg border border-slate-200 px-4 py-3">
                                <summary className="cursor-pointer text-sm font-semibold text-slate-700">
                                    Referensi kelas terdaftar
                                </summary>
                                {classes.length ? (
                                    <ul className="mt-3 grid gap-1 text-sm text-slate-600 sm:grid-cols-2">
                                        {classes.map((schoolClass) => (
                                            <li key={schoolClass.id}>
                                                <span className="font-medium text-slate-800">
                                                    {schoolClass.name}
                                                </span>
                                                {schoolClass.major_name
                                                    ? ` (${schoolClass.major_name})`
                                                    : ""}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="mt-3 text-sm text-slate-500">
                                        Referensi kelas tidak tersedia.
                                    </p>
                                )}
                            </details>
                        </div>

                        <div className="min-w-0 space-y-4">
                            {error && (
                                <div
                                    className="flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800"
                                    role="alert"
                                >
                                    <AlertCircle
                                        className="mt-0.5 shrink-0"
                                        size={17}
                                    />
                                    <p>{error}</p>
                                </div>
                            )}

                            {preview && (
                                <section className="overflow-hidden rounded-lg border border-slate-200">
                                    <div className="border-b border-slate-200 bg-slate-50 p-4">
                                        <h3 className="font-semibold text-slate-900">
                                            3. Preview dan validasi
                                        </h3>
                                        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                            <CountSummary
                                                label="Diperiksa"
                                                value={preview.checked_rows}
                                            />
                                            <CountSummary
                                                label="Valid"
                                                value={preview.valid_rows}
                                                tone="emerald"
                                            />
                                            <CountSummary
                                                label="Bermasalah"
                                                value={preview.failed_rows}
                                                tone="rose"
                                            />
                                            <CountSummary
                                                label="Duplikat"
                                                value={preview.duplicate_rows}
                                                tone="amber"
                                            />
                                        </div>
                                        <p className="mt-3 text-xs leading-5 text-slate-600">
                                            Hanya baris valid yang dapat
                                            dikonfirmasi. Baris bermasalah tidak
                                            akan disimpan.
                                        </p>
                                    </div>
                                    <div className="max-h-80 overflow-auto p-4">
                                        <ImportTable
                                            title="Data dan hasil validasi"
                                            headers={[
                                                "Baris",
                                                "NIS",
                                                "Nama Lengkap Siswa",
                                                "Tingkat Kelas",
                                                "Jurusan",
                                                "Kelas",
                                                "Email Pribadi",
                                                "Setoran Awal (Rp)",
                                                "Status validasi",
                                            ]}
                                        >
                                            {preview.preview_rows.map((row) => (
                                                <tr
                                                    className="border-t border-slate-100 align-top"
                                                    key={row.row}
                                                >
                                                    <td className="px-3 py-2">
                                                        {row.row}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {row.nis}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {row.student_name}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {row.grade_level}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {row.major_name}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {row.rombel}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {row.email}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-2">
                                                        {formatRupiah(row.opening_balance)}
                                                    </td>
                                                    <td className="min-w-52 px-3 py-2">
                                                        <span
                                                            className={
                                                                row.status === "valid"
                                                                    ? "font-semibold text-emerald-700"
                                                                    : "font-semibold text-rose-700"
                                                            }
                                                        >
                                                            {row.status === "valid"
                                                                ? "Valid"
                                                                : "Perlu diperbaiki"}
                                                        </span>
                                                        {row.messages.length > 0 && (
                                                            <ul className="mt-1 list-disc pl-4 text-xs font-normal text-rose-700">
                                                                {row.messages.map(
                                                                    (message, index) => (
                                                                        <li key={`${row.row}-${index}`}>
                                                                            {message}
                                                                        </li>
                                                                    )
                                                                )}
                                                            </ul>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </ImportTable>
                                    </div>
                                    {preview.valid_rows > 0 && (
                                        <div className="border-t border-slate-200 p-4">
                                            <button
                                                className="w-full rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50"
                                                disabled={busy}
                                                onClick={confirmImport}
                                                type="button"
                                            >
                                                {busy
                                                    ? "Mengimpor..."
                                                    : `4. Konfirmasi Import ${preview.valid_rows} Nasabah`}
                                            </button>
                                        </div>
                                    )}
                                </section>
                            )}

                            {result && (
                                <section
                                    className="rounded-lg border border-emerald-200 bg-emerald-50 p-4"
                                    role="status"
                                >
                                    <h3 className="font-semibold text-emerald-900">
                                        Import selesai
                                    </h3>
                                    <div className="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                                        <CountSummary
                                            label="Diperiksa"
                                            value={result.checked_rows}
                                        />
                                        <CountSummary
                                            label="Berhasil"
                                            value={result.imported_rows}
                                            tone="emerald"
                                        />
                                        <CountSummary
                                            label="Gagal"
                                            value={result.failed_rows}
                                            tone="rose"
                                        />
                                        <CountSummary
                                            label="Duplikat"
                                            value={result.duplicate_rows}
                                            tone="amber"
                                        />
                                    </div>
                                    {result.issues?.length > 0 && (
                                        <ul className="mt-3 space-y-1 text-sm text-rose-800">
                                            {result.issues.map(
                                                (issue, index) => (
                                                    <li
                                                        key={`${issue.row}-${index}`}
                                                    >
                                                        Baris {issue.row}:{" "}
                                                        {issue.messages.join(
                                                            " "
                                                        )}
                                                    </li>
                                                )
                                            )}
                                        </ul>
                                    )}
                                    {result.initial_credentials?.length > 0 && (
                                        <div className="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3">
                                            <h4 className="font-semibold text-amber-950">
                                                Kredensial sementara sekali tampil
                                            </h4>
                                            <p className="mt-1 text-xs text-amber-900">
                                                Serahkan kredensial kepada masing-masing nasabah melalui kanal aman.
                                                Password tidak dikirim ke email yang belum terverifikasi dan tidak
                                                dapat ditampilkan kembali setelah jendela ini ditutup.
                                            </p>
                                            <div className="mt-3 max-h-72 space-y-2 overflow-y-auto">
                                                {result.initial_credentials.map((credential) => (
                                                    <article
                                                        className="rounded border border-amber-200 bg-white p-3 text-sm"
                                                        key={credential.username}
                                                    >
                                                        <p className="font-medium text-slate-900">
                                                            {credential.student_name} ({credential.username})
                                                        </p>
                                                        <p className="mt-1 break-all font-mono text-slate-800">
                                                            {credential.temporary_password}
                                                        </p>
                                                    </article>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </section>
                            )}
                        </div>
                    </div>
                </div>

                <footer className="flex justify-end border-t border-slate-200 px-5 py-3 sm:px-6">
                    <button
                        className="rounded-lg px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50"
                        disabled={busy}
                        onClick={close}
                        type="button"
                    >
                        {result ? "Selesai" : "Tutup"}
                    </button>
                </footer>
            </section>
        </div>
    );
}

function CountSummary({ label, value, tone = "slate" }) {
    const colors = {
        slate: "bg-white text-slate-800",
        emerald: "bg-white text-emerald-800",
        rose: "bg-white text-rose-800",
        amber: "bg-white text-amber-800",
    };

    return (
        <div className={`rounded-md border border-slate-200 px-3 py-2 ${colors[tone]}`}>
            <p className="text-xs text-slate-500">{label}</p>
            <p className="mt-0.5 font-semibold">{value}</p>
        </div>
    );
}

function ImportTable({ title, headers, children }) {
    return (
        <div>
            <h4 className="mb-2 text-sm font-semibold text-slate-800">
                {title}
            </h4>
            <div className="overflow-x-auto rounded-md border border-slate-200">
                <table className="w-full min-w-max text-left text-xs">
                    <thead className="bg-slate-50 text-slate-600">
                        <tr>
                            {headers.map((header) => (
                                <th
                                    className="whitespace-nowrap px-3 py-2 font-semibold"
                                    key={header}
                                >
                                    {header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="text-slate-700">{children}</tbody>
                </table>
            </div>
        </div>
    );
}

export default CustomerImport;

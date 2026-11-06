import { useCallback, useEffect, useState } from "react";
import { FileSpreadsheet, FileText } from "lucide-react";
import api from "../../config/api";
import { downloadReportPdf } from "../../utils/reportPdf";

const INITIAL_FILTERS = {
  tanggal_transaksi: "",
  nama_teller: "",
  nama_nasabah: "",
  nomor_rekening: "",
};

const formatCurrency = (value) =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
  }).format(Number(value ?? 0));

const formatDate = (value) => {
  if (!value) return "-";

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return new Intl.DateTimeFormat("id-ID", {
    dateStyle: "medium",
  }).format(date);
};

const getNested = (object, path, fallback = "-") => {
  const value = path
    .split(".")
    .reduce((current, key) => current?.[key], object);

  return value === null || value === undefined || value === ""
    ? fallback
    : value;
};

const getTransaction = (journal) => journal?.transaction ?? {};

const getTransactionType = (journal) => {
  const type = String(
    getTransaction(journal).transaction_type ?? ""
  ).toLowerCase();

  const transactionTypes = {
    deposit: "Setoran",
    withdrawal: "Penarikan",
  };

  return transactionTypes[type] ?? (type || "-");
};

const getJournalPosition = (journal) => {
  const type = String(journal?.type ?? "").toLowerCase();

  if (type === "debit") return "Debit";
  if (type === "credit") return "Kredit";

  return "-";
};

const getJournalAmount = (journal, position) => {
  const journalPosition = String(journal?.type ?? "").toLowerCase();

  if (journalPosition !== position) {
    return null;
  }

  return journal?.amount;
};

const getErrorMessage = (error) => {
  const responseData = error?.response?.data;

  if (responseData?.message) {
    return responseData.message;
  }

  if (responseData?.errors) {
    const validationMessages = Object.values(responseData.errors)
      .flat()
      .filter(Boolean);

    if (validationMessages.length > 0) {
      return validationMessages.join(" ");
    }
  }

  if (error?.code === "ERR_NETWORK") {
    return "Tidak dapat terhubung ke server. Periksa koneksi dan status backend.";
  }

  return error?.message || "Terjadi kesalahan saat memuat data jurnal.";
};

const getTransactionTypeBadgeClass = (journal) => {
  const type = String(
    getTransaction(journal).transaction_type ?? ""
  ).toLowerCase();

  if (type === "withdrawal") {
    return "bg-secondary/15 text-secondary";
  }

  if (type === "deposit") {
    return "bg-primary-container text-on-primary";
  }

  return "bg-surface-container text-on-surface-variant";
};

function AccountingJournal() {
  const [journals, setJournals] = useState([]);

  const [filters, setFilters] = useState({ ...INITIAL_FILTERS });
  const [appliedFilters, setAppliedFilters] = useState({
    ...INITIAL_FILTERS,
  });

  const [selectedJournal, setSelectedJournal] = useState(null);
  const [isInspectorOpen, setIsInspectorOpen] = useState(false);

  const [isLoading, setIsLoading] = useState(true);
  const [isExporting, setIsExporting] = useState(false);
  const [isPdfExporting, setIsPdfExporting] = useState(false);
  const [loadError, setLoadError] = useState("");
  const [exportError, setExportError] = useState("");
  const [exportSuccess, setExportSuccess] = useState("");

  const [pageSize, setPageSize] = useState(10);
  const [currentPage, setCurrentPage] = useState(1);

  const loadJournals = useCallback(async (activeFilters = {}) => {
    setIsLoading(true);
    setLoadError("");

    try {
      const params = Object.fromEntries(
        Object.entries(activeFilters).filter(([, value]) => {
          return String(value ?? "").trim() !== "";
        })
      );

      const response = await api.get("/supervisor/journals", {
        params,
      });

      /*
       * Kode ini mengikuti kontrak respons pada komponen sebelumnya:
       * response.data.data harus berupa array record jurnal.
       *
       * Jika backend menggunakan Laravel paginator atau struktur
       * respons lain, bagian ini harus disesuaikan dengan respons aktual.
       */
      const rows = response.data?.data;

      if (!Array.isArray(rows)) {
        throw new Error(
          "Format data jurnal dari server tidak sesuai. Periksa respons endpoint."
        );
      }

      setJournals(rows);

      setSelectedJournal((previous) => {
        if (rows.length === 0) {
          return null;
        }

        return (
          rows.find((row) => row.id === previous?.id) ?? rows[0]
        );
      });

      setIsInspectorOpen(rows.length > 0);
      setCurrentPage(1);
    } catch (error) {
      setLoadError(getErrorMessage(error));
      setJournals([]);
      setSelectedJournal(null);
      setIsInspectorOpen(false);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    Promise.resolve().then(() => loadJournals(INITIAL_FILTERS));
  }, [loadJournals]);

  const handleFilterChange = (event) => {
    const { name, value } = event.target;

    setFilters((previous) => ({
      ...previous,
      [name]: value,
    }));
  };

  const applyFilters = (event) => {
    event.preventDefault();

    const nextFilters = { ...filters };

    setAppliedFilters(nextFilters);
    loadJournals(nextFilters);
  };

  const resetFilters = () => {
    const resetValues = { ...INITIAL_FILTERS };

    setFilters(resetValues);
    setAppliedFilters(resetValues);
    loadJournals(resetValues);
  };

  const exportJournals = async () => {
    setIsExporting(true);
    setExportError("");
    setExportSuccess("");

    try {
      const params = Object.fromEntries(
        Object.entries(appliedFilters).filter(([, value]) => {
          return String(value ?? "").trim() !== "";
        })
      );
      const response = await api.get("/supervisor/journals/export", {
        params,
        responseType: "blob",
      });
      const url = URL.createObjectURL(response.data);
      const link = document.createElement("a");
      link.href = url;
      link.download = params.tanggal_transaksi
        ? `jurnal-akuntansi-${params.tanggal_transaksi}.xlsx`
        : "jurnal-akuntansi.xlsx";
      link.click();
      URL.revokeObjectURL(url);
      setExportSuccess("File Excel jurnal akuntansi berhasil diunduh.");
    } catch (error) {
      setExportError(getErrorMessage(error));
    } finally {
      setIsExporting(false);
    }
  };

  const exportJournalsPdf = () => {
    setIsPdfExporting(true);
    setExportError("");
    setExportSuccess("");

    try {
      const debitTotal = journals
        .filter((journal) => String(journal.type).toLowerCase() === "debit")
        .reduce((total, journal) => total + Number(journal.amount ?? 0), 0);
      const creditTotal = journals
        .filter((journal) => String(journal.type).toLowerCase() === "credit")
        .reduce((total, journal) => total + Number(journal.amount ?? 0), 0);
      const period = appliedFilters.tanggal_transaksi
        ? `Tanggal transaksi: ${appliedFilters.tanggal_transaksi}`
        : "Semua tanggal sesuai filter yang diterapkan";

      downloadReportPdf({
        title: "Jurnal Akuntansi",
        subtitle: period,
        filename: `jurnal-akuntansi-${appliedFilters.tanggal_transaksi || "semua-periode"}.pdf`,
        summary: [
          ["Jumlah baris jurnal", journals.length],
          ["Total debit", formatCurrency(debitTotal)],
          ["Total kredit", formatCurrency(creditTotal)],
          ["Selisih debit-kredit", formatCurrency(debitTotal - creditTotal)],
        ],
        notes: ["Kode akun tidak tersedia pada struktur data jurnal saat ini."],
        columns: [
          "No.",
          "Tanggal",
          "Referensi",
          "Keterangan transaksi",
          "Kode akun",
          "Nama akun",
          "Debit (Rp)",
          "Kredit (Rp)",
        ],
        rows: journals.map((journal, index) => {
          const transaction = getTransaction(journal);
          const position = String(journal.type ?? "").toLowerCase();

          return [
            index + 1,
            formatDate(transaction.transaction_date),
            transaction.id ?? journal.transaction_id ?? "-",
            `${getTransactionType(journal)} transaksi #${transaction.id ?? journal.transaction_id ?? "-"}`,
            "-",
            journal.account_name ?? "-",
            position === "debit" ? formatCurrency(journal.amount) : "",
            position === "credit" ? formatCurrency(journal.amount) : "",
          ];
        }),
      });
      setExportSuccess("File PDF jurnal akuntansi berhasil diunduh.");
    } catch (error) {
      setExportError(error?.message || "File jurnal gagal diekspor.");
    } finally {
      setIsPdfExporting(false);
    }
  };

  const pageCount = Math.max(
    1,
    Math.ceil(journals.length / pageSize)
  );

  const safePage = Math.min(currentPage, pageCount);
  const startIndex = (safePage - 1) * pageSize;

  const visibleJournals = journals.slice(
    startIndex,
    startIndex + pageSize
  );

  const inspectJournal = (journal) => {
    setSelectedJournal(journal);
    setIsInspectorOpen(true);

    window.setTimeout(() => {
      document
        .getElementById("inspection-drawer")
        ?.scrollIntoView({
          behavior: "smooth",
          block: "nearest",
        });
    }, 0);
  };

  const selectedTransaction = getTransaction(selectedJournal);

  const selectedTransactionType = selectedJournal
    ? getTransactionType(selectedJournal)
    : "-";

  const selectedJournalPosition = selectedJournal
    ? getJournalPosition(selectedJournal)
    : "-";

  const tellerName = getNested(
    selectedTransaction,
    "user.name"
  );

  const customerName = getNested(
    selectedTransaction,
    "customer.student_name"
  );

  const accountNumber = getNested(
    selectedTransaction,
    "customer.account_number"
  );

  return (
    <div className="flex w-full flex-col gap-space-lg pb-12">
      {/* Header */}
      <div className="flex flex-col justify-between gap-space-md md:flex-row md:items-center">
        <div className="flex flex-col gap-space-xs">
          <h1 className="font-headline-lg text-headline-lg font-w-headline-lg tracking-tight text-on-surface">
            Jurnal Akuntansi
          </h1>

          <p className="font-body-md text-body-md font-w-body-md text-on-surface-variant">
            Lihat catatan jurnal transaksi yang tersimpan di sistem.
          </p>
        </div>

        <button
          className="flex h-9 items-center justify-center gap-space-xs rounded-lg bg-primary-container px-space-md font-label-lg text-label-lg font-w-label-lg text-on-primary shadow-sm transition-colors hover:bg-primary disabled:cursor-not-allowed disabled:opacity-60"
          type="button"
          onClick={() => loadJournals(appliedFilters)}
          disabled={isLoading}
        >
          <span className="material-symbols-outlined text-[18px]">
            refresh
          </span>

          {isLoading ? "Memuat..." : "Muat Ulang"}
        </button>
      </div>


      {/* Filter */}
      <form
        onSubmit={applyFilters}
        className="flex flex-col items-stretch justify-between gap-space-md rounded-xl bg-surface-container-lowest p-space-md shadow-sm xl:flex-row xl:items-center"
      >
        <div className="grid flex-1 grid-cols-1 gap-space-md sm:grid-cols-2 lg:grid-cols-4">
          <div className="flex flex-col gap-1">
            <label
              className="font-label-sm text-label-sm font-w-label-sm uppercase tracking-wider text-on-surface-variant"
              htmlFor="filter-date"
            >
              Tanggal Transaksi
            </label>

            <input
              className="h-9 w-full rounded-lg border border-outline-variant/30 bg-surface-container-lowest px-space-md font-body-sm text-body-sm text-on-surface focus:bg-surface-container-low focus:outline-none"
              id="filter-date"
              name="tanggal_transaksi"
              type="date"
              value={filters.tanggal_transaksi}
              onChange={handleFilterChange}
            />
          </div>

          <div className="flex flex-col gap-1">
            <label
              className="font-label-sm text-label-sm font-w-label-sm uppercase tracking-wider text-on-surface-variant"
              htmlFor="filter-teller"
            >
              Nama Teller
            </label>

            <input
              className="h-9 w-full rounded-lg border border-outline-variant/30 bg-surface-container-lowest px-space-md font-body-sm text-body-sm text-on-surface focus:bg-surface-container-low focus:outline-none"
              id="filter-teller"
              name="nama_teller"
              placeholder="Cari nama teller..."
              type="text"
              value={filters.nama_teller}
              onChange={handleFilterChange}
            />
          </div>

          <div className="flex flex-col gap-1">
            <label
              className="font-label-sm text-label-sm font-w-label-sm uppercase tracking-wider text-on-surface-variant"
              htmlFor="filter-customer"
            >
              Nama Nasabah
            </label>

            <input
              className="h-9 w-full rounded-lg border border-outline-variant/30 bg-surface-container-lowest px-space-md font-body-sm text-body-sm text-on-surface placeholder:text-outline focus:bg-surface-container-low focus:outline-none"
              id="filter-customer"
              name="nama_nasabah"
              placeholder="Cari nama nasabah..."
              type="text"
              value={filters.nama_nasabah}
              onChange={handleFilterChange}
            />
          </div>

          <div className="flex flex-col gap-1">
            <label
              className="font-label-sm text-label-sm font-w-label-sm uppercase tracking-wider text-on-surface-variant"
              htmlFor="filter-account"
            >
              Nomor Rekening
            </label>

            <input
              className="h-9 w-full rounded-lg border border-outline-variant/30 bg-surface-container-lowest px-space-md font-body-sm text-body-sm text-on-surface placeholder:text-outline focus:bg-surface-container-low focus:outline-none"
              id="filter-account"
              name="nomor_rekening"
              placeholder="Cari nomor rekening..."
              type="text"
              value={filters.nomor_rekening}
              onChange={handleFilterChange}
            />
          </div>
        </div>

        <div className="flex items-end gap-space-sm self-end pt-2 xl:self-center xl:pt-4">
          <button
            className="flex h-9 items-center gap-space-xs rounded-lg bg-primary-container px-space-lg font-label-lg text-label-lg font-w-label-lg font-medium text-on-primary shadow-sm transition-colors hover:bg-primary disabled:cursor-not-allowed disabled:opacity-60"
            type="submit"
            disabled={isLoading}
          >
            <span className="material-symbols-outlined text-[18px]">
              filter_alt
            </span>

            Terapkan Filter
          </button>

          <button
            className="flex h-9 items-center gap-space-xs rounded-lg bg-surface-container-low px-space-md font-label-lg text-label-lg font-w-label-lg text-on-surface-variant transition-colors hover:bg-surface-container hover:text-on-surface disabled:cursor-not-allowed disabled:opacity-60"
            type="button"
            onClick={resetFilters}
            disabled={isLoading}
          >
            <span className="material-symbols-outlined text-[18px]">
              refresh
            </span>

            Reset
          </button>
        </div>
      </form>

      {/* Tabel jurnal */}
      <div className="flex flex-col overflow-hidden rounded-xl bg-surface-container-lowest shadow-sm">
        <div className="flex flex-col justify-between gap-space-xs bg-surface-container-low px-space-lg py-space-md sm:flex-row sm:items-center">
          <div className="flex items-center gap-space-sm">
            <span className="material-symbols-outlined text-[20px] text-primary">
              menu_book
            </span>

            <span className="font-title-sm text-title-sm font-w-title-sm text-on-surface">
              Daftar Record Jurnal
            </span>
          </div>

          <span className="font-label-sm text-label-sm font-w-label-sm text-on-surface-variant">
            {journals.length} record ditemukan
          </span>
          <div className="flex flex-wrap gap-2">
            <button
              className="inline-flex items-center gap-2 rounded-lg bg-primary-container px-3 py-2 text-sm font-medium text-on-primary disabled:cursor-not-allowed disabled:opacity-60"
              disabled={isLoading || isExporting || isPdfExporting}
              onClick={exportJournals}
              type="button"
            >
              <FileSpreadsheet size={16} />
              {isExporting ? "Mengekspor..." : "Export Excel"}
            </button>
            <button
              className="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-800 disabled:cursor-not-allowed disabled:opacity-60"
              disabled={isLoading || isExporting || isPdfExporting}
              onClick={exportJournalsPdf}
              type="button"
            >
              <FileText size={16} />
              {isPdfExporting ? "Mengekspor..." : "Export PDF"}
            </button>
          </div>
        </div>

        {loadError && (
          <div
            className="m-space-md rounded-lg border border-error/30 bg-error-container/30 p-space-md text-sm text-on-surface"
            role="alert"
          >
            <p className="font-semibold">Gagal memuat jurnal</p>

            <p className="mt-1">{loadError}</p>

            <button
              className="mt-2 font-semibold text-primary underline disabled:opacity-60"
              type="button"
              onClick={() => loadJournals(appliedFilters)}
              disabled={isLoading}
            >
              Coba lagi
            </button>
          </div>
        )}

        {exportError && (
          <div
            className="m-space-md rounded-lg border border-error/30 bg-error-container/30 p-space-md text-sm text-on-surface"
            role="alert"
          >
            <p className="font-semibold">Gagal mengekspor jurnal</p>

            <p className="mt-1">{exportError}</p>

            <button
              className="mt-2 font-semibold text-primary underline disabled:opacity-60"
              type="button"
              onClick={exportJournals}
              disabled={isExporting}
            >
              Coba lagi
            </button>
          </div>
        )}
        {exportSuccess && (
          <div
            className="m-space-md rounded-lg border border-emerald-300 bg-emerald-50 p-space-md text-sm text-emerald-800"
            role="status"
          >
            {exportSuccess}
          </div>
        )}

        <div className="w-full overflow-x-auto">
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="bg-surface-container font-label-sm text-label-sm font-w-label-sm uppercase tracking-wider text-on-surface-variant">
                <th className="px-space-md py-space-sm font-semibold">
                  Tanggal
                </th>

                <th className="px-space-md py-space-sm font-semibold">
                  Nama Teller
                </th>

                <th className="px-space-md py-space-sm font-semibold">
                  Nama Nasabah
                </th>

                <th className="px-space-md py-space-sm font-semibold">
                  Nomor Rekening
                </th>

                <th className="px-space-md py-space-sm font-semibold">
                  Jenis Transaksi
                </th>

                <th className="px-space-md py-space-sm font-semibold">
                  Nama Akun
                </th>

                <th className="px-space-md py-space-sm text-right font-semibold">
                  Debit
                </th>

                <th className="px-space-md py-space-sm text-right font-semibold">
                  Kredit
                </th>

                <th className="px-space-md py-space-sm text-center font-semibold">
                  Aksi
                </th>
              </tr>
            </thead>

            <tbody className="divide-y divide-outline-variant/20 font-body-sm text-body-sm font-w-body-sm text-on-surface">
              {isLoading ? (
                <tr>
                  <td
                    className="px-space-md py-space-lg text-center text-on-surface-variant"
                    colSpan={9}
                  >
                    Memuat data jurnal...
                  </td>
                </tr>
              ) : !loadError && visibleJournals.length === 0 ? (
                <tr>
                  <td
                    className="px-space-md py-space-lg text-center text-on-surface-variant"
                    colSpan={9}
                  >
                    Tidak ada data jurnal yang sesuai dengan filter.
                  </td>
                </tr>
              ) : (
                visibleJournals.map((journal) => {
                  const transaction = getTransaction(journal);
                  const transactionType = getTransactionType(journal);
                  const isSelected = selectedJournal?.id === journal.id;

                  return (
                    <tr
                      key={journal.id}
                      className={`transition-colors hover:bg-surface-container-low/60 ${isSelected
                        ? "bg-primary-container/10"
                        : "bg-surface-container-lowest"
                        }`}
                    >
                      <td className="whitespace-nowrap px-space-md py-space-sm">
                        {formatDate(transaction.transaction_date)}
                      </td>

                      <td className="px-space-md py-space-sm">
                        {getNested(transaction, "user.name")}
                      </td>

                      <td className="px-space-md py-space-sm">
                        {getNested(transaction, "customer.student_name")}
                      </td>

                      <td className="whitespace-nowrap px-space-md py-space-sm font-mono text-primary">
                        {getNested(transaction, "customer.account_number")}
                      </td>

                      <td className="px-space-md py-space-sm">
                        <span
                          className={`inline-flex whitespace-nowrap rounded px-2 py-1 text-xs font-medium ${getTransactionTypeBadgeClass(
                            journal
                          )}`}
                        >
                          {transactionType}
                        </span>
                      </td>

                      <td className="px-space-md py-space-sm">
                        {journal.account_name ?? "-"}
                      </td>

                      <td className="whitespace-nowrap px-space-md py-space-sm text-right font-mono font-semibold">
                        {getJournalAmount(journal, "debit") !== null
                          ? formatCurrency(
                            getJournalAmount(journal, "debit")
                          )
                          : "-"}
                      </td>

                      <td className="whitespace-nowrap px-space-md py-space-sm text-right font-mono font-semibold">
                        {getJournalAmount(journal, "credit") !== null
                          ? formatCurrency(
                            getJournalAmount(journal, "credit")
                          )
                          : "-"}
                      </td>

                      <td className="px-space-md py-space-sm text-center">
                        <button
                          className="inline-flex items-center gap-1 rounded-lg px-2 py-1 font-medium text-primary transition-colors hover:bg-primary-container/30"
                          type="button"
                          onClick={() => inspectJournal(journal)}
                          aria-label={`Lihat detail jurnal ${journal.id}`}
                        >
                          <span className="material-symbols-outlined text-[17px]">
                            visibility
                          </span>

                          Detail
                        </button>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        <div className="flex flex-col items-center justify-between gap-space-md bg-surface-container-lowest p-space-md sm:flex-row">
          <div className="flex flex-wrap items-center gap-space-sm font-label-md text-label-md font-w-label-md text-on-surface-variant">
            <span>
              Menampilkan{" "}
              <strong className="font-semibold text-on-surface">
                {journals.length === 0
                  ? 0
                  : `${startIndex + 1}–${Math.min(
                    startIndex + pageSize,
                    journals.length
                  )}`}
              </strong>{" "}
              dari{" "}
              <strong className="font-semibold text-on-surface">
                {journals.length}
              </strong>{" "}
              record jurnal
            </span>

            <label className="flex items-center gap-1.5">
              <span>Baris:</span>

              <select
                className="h-7 rounded border border-outline-variant/30 bg-surface-container-low px-2 text-label-sm font-medium text-on-surface focus:outline-none"
                value={pageSize}
                onChange={(event) => {
                  setPageSize(Number(event.target.value));
                  setCurrentPage(1);
                }}
              >
                <option value={10}>10</option>
                <option value={20}>20</option>
                <option value={50}>50</option>
              </select>
            </label>
          </div>

          <div className="flex items-center gap-2">
            <button
              className="flex h-8 items-center justify-center rounded bg-surface-container-low px-3 text-on-surface-variant hover:bg-surface-container disabled:cursor-not-allowed disabled:opacity-40"
              type="button"
              disabled={safePage <= 1}
              onClick={() =>
                setCurrentPage((page) => Math.max(1, page - 1))
              }
            >
              <span className="material-symbols-outlined text-[16px]">
                chevron_left
              </span>

              Sebelumnya
            </button>

            <span className="whitespace-nowrap text-sm text-on-surface-variant">
              Halaman {safePage} dari {pageCount}
            </span>

            <button
              className="flex h-8 items-center justify-center rounded bg-surface-container-low px-3 text-on-surface-variant hover:bg-surface-container disabled:cursor-not-allowed disabled:opacity-40"
              type="button"
              disabled={safePage >= pageCount}
              onClick={() =>
                setCurrentPage((page) =>
                  Math.min(pageCount, page + 1)
                )
              }
            >
              Berikutnya

              <span className="material-symbols-outlined text-[16px]">
                chevron_right
              </span>
            </button>
          </div>
        </div>
      </div>

      {/* Panel detail jurnal */}
      {isInspectorOpen && selectedJournal && (
        <section
          id="inspection-drawer"
          className="flex flex-col gap-space-md rounded-xl bg-surface-container-lowest p-space-lg shadow-md"
        >
          <div className="-mx-space-lg -mt-space-lg flex items-center justify-between gap-space-md rounded-t-xl bg-surface-container-low px-space-lg py-space-md">
            <div className="flex min-w-0 items-center gap-space-sm">
              <span className="h-3 w-3 shrink-0 rounded-full bg-primary" />

              <h2 className="font-title-md text-title-md font-w-title-md text-on-surface">
                Detail Record Jurnal #{selectedJournal.id}
              </h2>
            </div>

            <button
              className="flex shrink-0 items-center gap-1 rounded bg-surface-container px-space-md py-1 font-label-md text-label-md text-on-surface transition-colors hover:bg-surface-variant"
              onClick={() => setIsInspectorOpen(false)}
              type="button"
            >
              <span className="material-symbols-outlined text-[16px]">
                close
              </span>

              Tutup Panel
            </button>
          </div>

          <div className="grid grid-cols-1 gap-space-lg pt-space-xs md:grid-cols-3">
            {/* Metadata transaksi */}
            <div className="flex flex-col gap-space-sm rounded-lg bg-surface-container-low p-space-md">
              <div className="mb-1 flex items-center gap-1.5 text-primary">
                <span className="material-symbols-outlined text-[18px]">
                  receipt_long
                </span>

                <span className="font-label-sm text-label-sm font-w-label-sm font-semibold uppercase tracking-wider">
                  Metadata Transaksi
                </span>
              </div>

              <div className="flex justify-between gap-3 text-body-sm">
                <span className="text-on-surface-variant">
                  ID Transaksi
                </span>

                <span className="break-all text-right font-medium text-on-surface">
                  {selectedJournal.transaction_id ??
                    selectedTransaction.id ??
                    "-"}
                </span>
              </div>

              <div className="flex justify-between gap-3 text-body-sm">
                <span className="text-on-surface-variant">
                  Tanggal
                </span>

                <span className="text-right font-medium text-on-surface">
                  {formatDate(selectedTransaction.transaction_date)}
                </span>
              </div>

              <div className="flex justify-between gap-3 text-body-sm">
                <span className="text-on-surface-variant">
                  Jenis Transaksi
                </span>

                <span className="text-right font-medium text-on-surface">
                  {selectedTransactionType}
                </span>
              </div>

              <div className="flex justify-between gap-3 text-body-sm">
                <span className="text-on-surface-variant">
                  Teller
                </span>

                <span className="text-right font-medium text-on-surface">
                  {tellerName}
                </span>
              </div>

              <div className="flex justify-between gap-3 text-body-sm">
                <span className="text-on-surface-variant">
                  Nasabah
                </span>

                <span className="text-right font-medium text-on-surface">
                  {customerName}
                </span>
              </div>

              <div className="flex justify-between gap-3 text-body-sm">
                <span className="text-on-surface-variant">
                  Nomor Rekening
                </span>

                <span className="text-right font-mono font-medium text-primary">
                  {accountNumber}
                </span>
              </div>
            </div>

            {/* Informasi jurnal */}
            <div className="flex flex-col gap-space-sm rounded-lg bg-surface-container-low p-space-md">
              <div className="mb-1 flex items-center gap-1.5 text-secondary">
                <span className="material-symbols-outlined text-[18px]">
                  account_balance
                </span>

                <span className="font-label-sm text-label-sm font-w-label-sm font-semibold uppercase tracking-wider">
                  Informasi Record Jurnal
                </span>
              </div>

              <div className="flex justify-between gap-3 text-body-sm">
                <span className="text-on-surface-variant">
                  Nama Akun
                </span>

                <span className="text-right font-medium text-on-surface">
                  {selectedJournal.account_name ?? "-"}
                </span>
              </div>

              <div className="flex justify-between gap-3 text-body-sm">
                <span className="text-on-surface-variant">
                  Posisi Jurnal
                </span>

                <span className="text-right font-medium text-on-surface">
                  {selectedJournalPosition}
                </span>
              </div>

              <div className="mt-2 flex items-center justify-between gap-3 rounded-lg bg-surface-container-lowest p-space-md">
                <span className="text-on-surface-variant">
                  Nominal
                </span>

                <span className="font-mono text-lg font-semibold text-primary">
                  {formatCurrency(selectedJournal.amount)}
                </span>
              </div>
            </div>

            {/* Identitas record */}
            <div className="flex flex-col gap-space-sm rounded-lg bg-surface-container-low p-space-md">
              <div className="mb-1 flex items-center gap-1.5 text-tertiary">
                <span className="material-symbols-outlined text-[18px]">
                  verified
                </span>

                <span className="font-label-sm text-label-sm font-w-label-sm font-semibold uppercase tracking-wider">
                  Identitas Record
                </span>
              </div>

              <div className="flex justify-between gap-3 text-body-sm">
                <span className="text-on-surface-variant">
                  ID Jurnal
                </span>

                <span className="font-mono font-medium text-on-surface">
                  {selectedJournal.id ?? "-"}
                </span>
              </div>

              <div className="flex justify-between gap-3 text-body-sm">
                <span className="text-on-surface-variant">
                  ID Transaksi
                </span>

                <span className="font-mono font-medium text-on-surface">
                  {selectedJournal.transaction_id ?? "-"}
                </span>
              </div>

              <p className="mt-2 rounded-lg bg-surface-container-lowest p-space-md text-body-sm text-on-surface-variant">
                Detail ini menampilkan data jurnal yang dikembalikan
                backend, termasuk identitas record, transaksi terkait,
                nama akun, posisi Debit atau Kredit, dan nominal.
              </p>
            </div>
          </div>
        </section>
      )}
    </div>
  );
}

export default AccountingJournal;
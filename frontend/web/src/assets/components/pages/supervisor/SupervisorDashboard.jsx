import { useEffect, useState } from "react";
import api from "../../../../config/api";

import {
  RefreshCw,
  ArrowDownToLine,
  ArrowUpFromLine,
  ArrowLeftRight,
  ClipboardList,
  AlertCircle,
} from "lucide-react";

const formatRupiah = (value) =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
  }).format(Number(value) || 0);

const formatTanggal = (value) => {
  if (!value) return "-";

  return new Intl.DateTimeFormat("id-ID", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  }).format(new Date(value));
};

function SupervisorDashboard() {
  const [dailyReport, setDailyReport] = useState(null);
  const [dailyReports, setDailyReports] = useState([]);

  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState("");
  const [refreshVersion, setRefreshVersion] = useState(0);

  const today = new Date();
  const todayString = [
    today.getFullYear(),
    String(today.getMonth() + 1).padStart(2, "0"),
    String(today.getDate()).padStart(2, "0"),
  ].join("-");

  const [selectedDate, setSelectedDate] = useState(todayString);

  useEffect(() => {
    const controller = new AbortController();

    const fetchDashboard = async () => {
      try {
        const [dailyReportResponse, dailyReportsResponse] = await Promise.all([
          api.get("/supervisor/daily-report", {
            params: { date: selectedDate },
            signal: controller.signal,
          }),
          api.get("/supervisor/daily-reports", {
            signal: controller.signal,
          }),
        ]);
        if (controller.signal.aborted) return;

        setDailyReport(dailyReportResponse.data?.data ?? null);
        setDailyReports(dailyReportsResponse.data?.data ?? []);
        setError("");
      } catch (err) {
        if (controller.signal.aborted) return;

        console.error("Gagal memuat Dashboard Supervisor:", err);
        setError(
          err.response?.data?.message ||
            "Gagal memuat data Dashboard Supervisor."
        );
      } finally {
        if (!controller.signal.aborted) {
          setIsLoading(false);
        }
      }
    };

    void fetchDashboard();

    return () => controller.abort();
  }, [refreshVersion, selectedDate]);

  const handleRefresh = () => {
    setIsLoading(true);
    setError("");
    setRefreshVersion((version) => version + 1);
  };

  const handleDateChange = (event) => {
    setIsLoading(true);
    setError("");
    setSelectedDate(event.target.value);
  };

  const summaryCards = [
    {
      title: "Total Transaksi",
      value: dailyReport?.total_transactions ?? 0,
      icon: ArrowLeftRight,
      description: "Transaksi pada tanggal terpilih",
      isCurrency: false,
    },
    {
      title: "Total Setoran",
      value: dailyReport?.total_deposit ?? 0,
      icon: ArrowDownToLine,
      description: "Akumulasi setoran",
      isCurrency: true,
    },
    {
      title: "Total Penarikan",
      value: dailyReport?.total_withdrawal ?? 0,
      icon: ArrowUpFromLine,
      description: "Akumulasi penarikan",
      isCurrency: true,
    },
    {
      title: "Menunggu Persetujuan",
      value: dailyReports.length,
      icon: ClipboardList,
      description: "Laporan berstatus submitted",
      isCurrency: false,
    },
  ];

  return (
    <div className="flex flex-col w-full gap-6 py-6">
      <div className="flex flex-col gap-4 border-b border-outline-variant/30 pb-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-on-surface">
            Dashboard Supervisor
          </h1>

          <p className="mt-1 text-sm text-secondary">
            Ringkasan transaksi dan laporan harian teller.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <label
            htmlFor="dashboard-date"
            className="text-sm text-on-surface"
          >
            Tanggal:
          </label>

          <input
            id="dashboard-date"
            type="date"
            value={selectedDate}
            onChange={handleDateChange}
            className="rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface"
          />

          <button
            type="button"
            onClick={handleRefresh}
            disabled={isLoading}
            className="inline-flex items-center gap-2 rounded-lg border border-outline-variant px-3 py-2 text-sm text-on-surface hover:bg-surface-container disabled:opacity-50"
          >
            <RefreshCw
              size={16}
              className={isLoading ? "animate-spin" : ""}
            />
            Muat Ulang
          </button>
        </div>
      </div>

      {error && (
        <div
          role="alert"
          className="flex items-start gap-3 rounded-xl border border-red-300 bg-red-50 p-4 text-red-700"
        >
          <AlertCircle size={20} className="mt-0.5 shrink-0" />

          <div>
            <p className="font-medium">
              Gagal memuat Dashboard Supervisor
            </p>
            <p className="mt-1 text-sm">{error}</p>
          </div>
        </div>
      )}

      <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {summaryCards.map((card) => {
          const Icon = card.icon;

          return (
            <article
              key={card.title}
              className="rounded-xl border border-outline-variant/30 bg-surface p-5"
            >
              <div className="flex items-center justify-between gap-3">
                <p className="text-sm text-secondary">{card.title}</p>
                <Icon size={20} className="text-primary" />
              </div>

              <p className="mt-4 text-2xl font-semibold text-on-surface">
                {isLoading
                  ? "Memuat..."
                  : card.isCurrency
                    ? formatRupiah(card.value)
                    : card.value}
              </p>

              <p className="mt-2 text-xs text-secondary">
                {card.description}
              </p>
            </article>
          );
        })}
      </section>

      <section className="overflow-hidden rounded-xl border border-outline-variant/30 bg-surface">
        <div className="border-b border-outline-variant/30 p-5">
          <h2 className="text-lg font-semibold text-on-surface">
            Transaksi Harian
          </h2>

          <p className="mt-1 text-sm text-secondary">
            Tanggal laporan: {formatTanggal(dailyReport?.date)}
          </p>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-surface-container text-secondary">
              <tr>
                <th className="px-5 py-3 font-medium">Waktu</th>
                <th className="px-5 py-3 font-medium">Nasabah</th>
                <th className="px-5 py-3 font-medium">Nomor Rekening</th>
                <th className="px-5 py-3 font-medium">Teller</th>
                <th className="px-5 py-3 font-medium">Jenis Transaksi</th>
                <th className="px-5 py-3 text-right font-medium">
                  Nominal
                </th>
              </tr>
            </thead>

            <tbody className="divide-y divide-outline-variant/20">
              {isLoading ? (
                <tr>
                  <td
                    colSpan={6}
                    className="px-5 py-10 text-center text-secondary"
                  >
                    Memuat transaksi...
                  </td>
                </tr>
              ) : (dailyReport?.transactions ?? []).length === 0 ? (
                <tr>
                  <td
                    colSpan={6}
                    className="px-5 py-10 text-center text-secondary"
                  >
                    Tidak ada transaksi pada tanggal ini.
                  </td>
                </tr>
              ) : (
                dailyReport.transactions.map((transaction) => (
                  <tr key={transaction.id}>
                    <td className="whitespace-nowrap px-5 py-4 text-on-surface">
                      {transaction.transaction_date
                        ? new Date(
                            transaction.transaction_date
                          ).toLocaleTimeString("id-ID", {
                            hour: "2-digit",
                            minute: "2-digit",
                          })
                        : "-"}
                    </td>

                    <td className="px-5 py-4 text-on-surface">
                      {transaction.customer?.student_name ?? "-"}
                    </td>

                    <td className="px-5 py-4 text-on-surface">
                      {transaction.customer?.account_number ?? "-"}
                    </td>

                    <td className="px-5 py-4 text-on-surface">
                      {transaction.user?.full_name ??
                        transaction.user?.name ??
                        "-"}
                    </td>

                    <td className="px-5 py-4">
                      <span
                        className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                          transaction.transaction_type === "deposit"
                            ? "bg-green-100 text-green-700"
                            : "bg-orange-100 text-orange-700"
                        }`}
                      >
                        {transaction.transaction_type === "deposit"
                          ? "Setoran"
                          : transaction.transaction_type === "withdrawal"
                            ? "Penarikan"
                            : transaction.transaction_type ?? "-"}
                      </span>
                    </td>

                    <td className="whitespace-nowrap px-5 py-4 text-right font-medium text-on-surface">
                      {formatRupiah(transaction.amount)}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {!isLoading && (
          <div className="border-t border-outline-variant/30 px-5 py-3 text-sm text-secondary">
            {(dailyReport?.transactions ?? []).length} transaksi ditemukan
          </div>
        )}
      </section>

      <section className="overflow-hidden rounded-xl border border-outline-variant/30 bg-surface">
        <div className="border-b border-outline-variant/30 p-5">
          <h2 className="text-lg font-semibold text-on-surface">
            Laporan Menunggu Persetujuan
          </h2>

          <p className="mt-1 text-sm text-secondary">
            Daftar laporan harian yang telah dikirim teller.
          </p>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-surface-container text-secondary">
              <tr>
                <th className="px-5 py-3 font-medium">Tanggal Laporan</th>
                <th className="px-5 py-3 font-medium">Teller</th>
                <th className="px-5 py-3 font-medium">Status</th>
              </tr>
            </thead>

            <tbody className="divide-y divide-outline-variant/20">
              {isLoading ? (
                <tr>
                  <td
                    colSpan={3}
                    className="px-5 py-10 text-center text-secondary"
                  >
                    Memuat laporan...
                  </td>
                </tr>
              ) : dailyReports.length === 0 ? (
                <tr>
                  <td
                    colSpan={3}
                    className="px-5 py-10 text-center text-secondary"
                  >
                    Tidak ada laporan yang menunggu persetujuan.
                  </td>
                </tr>
              ) : (
                dailyReports.map((report) => (
                  <tr key={report.id}>
                    <td className="px-5 py-4 text-on-surface">
                      {formatTanggal(report.report_date)}
                    </td>

                    <td className="px-5 py-4 text-on-surface">
                      {report.teller?.full_name ??
                        report.teller?.name ??
                        "-"}
                    </td>

                    <td className="px-5 py-4">
                      <span className="rounded-full bg-yellow-100 px-2.5 py-1 text-xs font-medium text-yellow-800">
                        Menunggu Persetujuan
                      </span>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {!isLoading && (
          <div className="border-t border-outline-variant/30 px-5 py-3 text-sm text-secondary">
            {dailyReports.length} laporan ditemukan
          </div>
        )}
      </section>
    </div>
  );
}

export default SupervisorDashboard;
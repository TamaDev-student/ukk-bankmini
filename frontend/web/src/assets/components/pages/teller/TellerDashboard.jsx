import { useCallback, useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
  Activity,
  ArrowDownLeft,
  ArrowUpRight,
  ExternalLink,
  Lock,
  QrCode,
  RefreshCw,
  Search,
  Wallet,
  X,
} from "lucide-react";

import { api } from "../../../../config/api";
import { useAuth } from "../../../../context/AuthContext";
import QrScannerModal from "../../common/QrScannerModal";

const formatRupiah = (value) =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
  }).format(Number(value) || 0);

const formatTanggal = (date) =>
  new Intl.DateTimeFormat("id-ID", {
    dateStyle: "full",
  }).format(date);

export default function TellerDashboard() {
  const { user } = useAuth();
  const navigate = useNavigate();

  const [summary, setSummary] = useState(null);
  const [reports, setReports] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [identifiedCustomer, setIdentifiedCustomer] = useState(null);
  const [isFromQr, setIsFromQr] = useState(false);
  const [isQrModalOpen, setIsQrModalOpen] = useState(false);

  const loadDashboardData = useCallback(async () => {
    const [reportResponse, dailyReportsResponse] = await Promise.all([
      api.get("/teller/daily-report"),
      api.get("/teller/daily-reports"),
    ]);

    return {
      summary: reportResponse.data.data,
      reports: dailyReportsResponse.data.data ?? [],
    };
  }, []);

  useEffect(() => {
    let active = true;

    loadDashboardData()
      .then(({ summary: nextSummary, reports: nextReports }) => {
        if (!active) return;
        setSummary(nextSummary);
        setReports(nextReports);
        setLoading(false);
      })
      .catch((err) => {
        if (!active) return;
        setError(
          err.response?.data?.message ?? "Gagal mengambil data Dashboard Teller."
        );
        setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [loadDashboardData]);

  const fetchDashboard = useCallback(async () => {
    try {
      const { summary: nextSummary, reports: nextReports } =
        await loadDashboardData();
      setSummary(nextSummary);
      setReports(nextReports);
    } catch (err) {
      setError(
        err.response?.data?.message ?? "Gagal mengambil data Dashboard Teller."
      );
    } finally {
      setLoading(false);
    }
  }, [loadDashboardData]);

  const handleRefreshDashboard = () => {
    setLoading(true);
    setError("");
    void fetchDashboard();
  };

  const handleQrSuccess = (customer) => {
    if (isFromQr) return;

    setIsQrModalOpen(false);
    setIdentifiedCustomer(customer);
    setIsFromQr(true);
  };

  const handleChangeQrCustomer = () => {
    const confirmed = window.confirm(
      "Apakah Anda yakin ingin mengganti nasabah?\n\nIdentitas nasabah yang terkunci akan dibersihkan."
    );
    if (!confirmed) return;

    setIdentifiedCustomer(null);
    setIsFromQr(false);
    setIsQrModalOpen(false);
  };

  const cards = [
    {
      title: "Total Transaksi",
      value: summary?.total_transactions ?? 0,
      icon: Activity,
      type: "number",
    },
    {
      title: "Total Setoran",
      value: summary?.total_deposit ?? 0,
      icon: ArrowDownLeft,
      type: "currency",
    },
    {
      title: "Total Penarikan",
      value: summary?.total_withdrawal ?? 0,
      icon: ArrowUpRight,
      type: "currency",
    },
    {
      title: "Laporan Hari Ini",
      value: reports.some(
        (report) =>
          report.report_date === new Date().toLocaleDateString("en-CA")
      )
        ? "Sudah dibuat"
        : "Belum dibuat",
      icon: Wallet,
      type: "status",
    },
  ];

  return (
    <main className="space-y-6 p-4 md:p-6">
      {/* Modal QR Scanner */}
      <QrScannerModal
        isOpen={isQrModalOpen}
        onClose={() => setIsQrModalOpen(false)}
        onSuccess={handleQrSuccess}
        title="Pindai QR Nasabah (Dashboard)"
      />

      {/* Header */}
      <section className="flex flex-col justify-between gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:items-center">
        <div>
          <p className="text-sm text-slate-500">Layanan Teller</p>
          <h1 className="mt-1 text-2xl font-bold text-slate-900">
            Dashboard Teller
          </h1>
          <p className="mt-2 text-sm text-slate-500">
            Selamat datang,{" "}
            <span className="font-semibold text-slate-700">
              {user?.full_name ?? user?.name ?? user?.username ?? "Teller"}
            </span>
          </p>
          <p className="mt-1 text-xs text-slate-500">
            {formatTanggal(new Date())}
          </p>
        </div>

        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
          <button
            type="button"
            onClick={() => setIsQrModalOpen(true)}
            disabled={isFromQr}
            className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
          >
            <QrCode size={18} />
            Scan QR Nasabah
          </button>
          <button
            type="button"
            onClick={handleRefreshDashboard}
            disabled={loading}
            className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 sm:w-auto"
          >
            <RefreshCw size={16} className={loading ? "animate-spin" : ""} />
            Perbarui Data
          </button>
        </div>
      </section>

      {/* Error Banner */}
      {error && (
        <div
          role="alert"
          className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700"
        >
          {error}
          <button
            type="button"
            onClick={fetchDashboard}
            className="ml-2 font-semibold underline"
          >
            Coba lagi
          </button>
        </div>
      )}

      {/* ============================================================== */}
      {/* SHORTCUT PENCARIAN & IDENTIFIKASI NASABAH (REVISI 1) */}
      {/* ============================================================== */}
      <section className="rounded-xl border border-blue-100 bg-gradient-to-r from-blue-50/70 to-indigo-50/50 p-4 shadow-xs sm:p-5">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h2 className="flex items-center gap-2 text-base font-bold text-slate-900">
                <Search size={18} className="shrink-0 text-blue-600" />
                Identifikasi Nasabah
            </h2>
            <p className="mt-0.5 text-xs text-slate-600">
              Pindai QR untuk identifikasi, atau cari satu rekening tertentu.
            </p>
          </div>

        </div>

        <Link
          to="/teller/find-customer"
          className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 sm:w-auto"
        >
          <Search size={17} />
          Cari satu rekening tertentu
        </Link>

        {/* Tampilan Identitas Nasabah Terpilih */}
        {identifiedCustomer && (
          <div className="mt-4 rounded-xl border border-blue-200 bg-white p-4 shadow-sm">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <div className="flex min-w-0 items-center gap-3.5">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-600 text-white font-bold text-base shadow-xs">
                  {identifiedCustomer.student_name?.charAt(0)?.toUpperCase() || "N"}
                </div>
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <h3 className="break-words font-bold text-base text-slate-900">
                      {identifiedCustomer.student_name}
                    </h3>
                    {isFromQr && (
                      <span className="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-bold text-blue-700">
                        <Lock size={12} /> Nasabah Terpilih — Terkunci
                      </span>
                    )}
                    <span
                      className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                        identifiedCustomer.status === "active"
                          ? "bg-emerald-100 text-emerald-700"
                          : "bg-red-100 text-red-700"
                      }`}
                    >
                      {identifiedCustomer.status === "active" ? "Aktif" : "Diblokir"}
                    </span>
                  </div>
                  <p className="mt-0.5 break-words text-xs text-slate-500">
                    No. Rekening: <span className="font-semibold text-slate-700">{identifiedCustomer.account_number}</span> • Saldo: <span className="font-bold text-emerald-700">{formatRupiah(identifiedCustomer.balance)}</span>
                  </p>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  onClick={() =>
                    navigate("/teller/deposit", {
                      state: {
                        customer: identifiedCustomer,
                        fromQr: isFromQr,
                        locked: isFromQr,
                      },
                    })
                  }
                  disabled={identifiedCustomer.status !== "active"}
                  className="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50 sm:w-auto"
                >
                  <ArrowDownLeft size={15} />
                  Setoran Tunai
                </button>

                <button
                  type="button"
                  onClick={() =>
                    navigate("/teller/withdrawal", {
                      state: {
                        customer: identifiedCustomer,
                        fromQr: isFromQr,
                        locked: isFromQr,
                      },
                    })
                  }
                  disabled={identifiedCustomer.status !== "active"}
                  className="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-50 sm:w-auto"
                >
                  <ArrowUpRight size={15} />
                  Penarikan Tunai
                </button>

                <Link
                  to={`/teller/customers/${encodeURIComponent(identifiedCustomer.account_number)}`}
                  className="inline-flex w-full items-center justify-center gap-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 sm:w-auto"
                >
                  <ExternalLink size={14} />
                  Detail
                </Link>

                {isFromQr ? (
                  <button
                    type="button"
                    onClick={handleChangeQrCustomer}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-white px-3 py-2 text-xs font-semibold text-blue-800 hover:bg-blue-50"
                  >
                    <Lock size={14} />
                    Ganti Nasabah / Scan Ulang
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={() => setIdentifiedCustomer(null)}
                    className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    title="Tutup Identitas"
                  >
                    <X size={16} />
                  </button>
                )}
              </div>
            </div>
          </div>
        )}
      </section>

      {/* Ringkasan Transaksi */}
      <section>
        <h2 className="mb-4 text-lg font-semibold text-slate-900">
          Ringkasan Transaksi
        </h2>

        {loading && !summary ? (
          <p className="text-sm text-slate-500">Memuat data transaksi...</p>
        ) : (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {cards.map((card) => {
              const Icon = card.icon;
              let value = card.value;

              if (card.type === "currency") {
                value = formatRupiah(card.value);
              }

              return (
                <article
                  key={card.title}
                  className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
                >
                  <div className="flex items-center justify-between gap-3">
                    <p className="text-sm text-slate-500">{card.title}</p>
                    <div className="rounded-lg bg-blue-50 p-2 text-blue-600">
                      <Icon size={20} />
                    </div>
                  </div>
                  <p className="mt-4 break-words text-lg font-bold text-slate-900 sm:text-xl">
                    {value}
                  </p>
                </article>
              );
            })}
          </div>
        )}
      </section>

    </main>
  );
}

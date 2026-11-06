import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";

import {
    ArrowLeft,
    CreditCard,
    UserRound,
    Wallet,
    RefreshCw,
} from "lucide-react";

import { api } from "../../../../config/api";

const formatRupiah = (value) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value) || 0);

export default function CustomerDetail() {
    const { accountNumber } = useParams();

    const [customer, setCustomer] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    useEffect(() => {
        let cancelled = false;

        const fetchCustomer = async () => {
            setLoading(true);
            setError("");
            setCustomer(null);

            try {
                const response = await api.get(
                    `/teller/customers/${encodeURIComponent(accountNumber)}`
                );

                if (!cancelled) {
                    setCustomer(response.data.data);
                }
            } catch (err) {
                if (!cancelled) {
                    setError(
                        err.response?.data?.message ??
                        "Gagal mengambil detail nasabah."
                    );
                }
            } finally {
                if (!cancelled) {
                    setLoading(false);
                }
            }
        };

        if (accountNumber) fetchCustomer();

        return () => {
            cancelled = true;
        };
    }, [accountNumber]);

    if (!accountNumber) {
        return (
            <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-5 text-red-700">
                Nomor rekening tidak ditemukan.
            </div>
        );
    }

    if (loading) {
        return (
            <div className="flex items-center justify-center gap-2 p-10 text-gray-500">
                <RefreshCw size={18} className="animate-spin" />
                Mengambil detail nasabah...
            </div>
        );
    }

    if (error || !customer) {
        return (
            <div className="space-y-4">
                <Link
                    to="/teller/find-customer"
                    className="inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-700"
                >
                    <ArrowLeft size={17} />
                    Kembali ke Cari Nasabah
                </Link>

                <div
                    role="alert"
                    className="rounded-xl border border-red-200 bg-red-50 p-5 text-red-700"
                >
                    {error || "Data nasabah tidak ditemukan."}
                </div>
            </div>
        );
    }

    const schoolClass = customer.school_class ?? customer.schoolClass;
    const majorCode =
        schoolClass?.major?.code ?? customer.major_code ?? "";
    const className = schoolClass?.name
        ? `${schoolClass.name}${majorCode ? ` (${majorCode})` : ""}`
        : customer.class_id ?? "-";

    const isActive = customer.status === "active";

    return (
        <div className="space-y-6">
            {/* Navigasi kembali */}
            <Link
                to="/teller/find-customer"
                className="inline-flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-700"
            >
                <ArrowLeft size={17} />
                Kembali ke Cari Nasabah
            </Link>

            {/* Header */}
            <div>
                <h1 className="text-2xl font-bold text-gray-900">
                    Detail Nasabah
                </h1>

                <p className="mt-1 text-sm text-gray-500">
                    Informasi rekening nasabah berdasarkan data sistem.
                </p>
            </div>

            {/* Profil */}
            <section className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div className="flex items-center gap-4 border-b border-gray-100 pb-5">
                    <div className="flex h-14 w-14 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                        <UserRound size={28} />
                    </div>

                    <div className="min-w-0 flex-1">
                        <h2 className="text-lg font-bold text-gray-900">
                            {customer.student_name || "Nama belum tersedia"}
                        </h2>

                    </div>

                    <span
                        className={`rounded-full px-3 py-1 text-xs font-semibold ${isActive
                            ? "bg-green-100 text-green-700"
                            : customer.status === "blocked"
                                ? "bg-red-100 text-red-700"
                                : "bg-gray-100 text-gray-600"
                            }`}
                    >
                        {isActive
                            ? "Aktif"
                            : customer.status === "blocked"
                                ? "Diblokir"
                                : customer.status || "Tidak diketahui"}
                    </span>
                </div>

                {/* Informasi rekening */}
                <div className="grid gap-6 pt-5 sm:grid-cols-2">
                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm text-gray-500">
                            <CreditCard size={17} />
                            Nomor Rekening
                        </div>

                        <p className="font-semibold text-gray-900">
                            {customer.account_number}
                        </p>
                    </div>

                    <div>
                        <div className="mb-2 flex items-center gap-2 text-sm text-gray-500">
                            <UserRound size={17} />
                            Kelas
                        </div>

                        <p className="font-semibold text-gray-900">
                            {className}
                        </p>
                    </div>

                    <div className="sm:col-span-2">
                        <div className="mb-2 flex items-center gap-2 text-sm text-gray-500">
                            <Wallet size={17} />
                            Saldo Rekening
                        </div>

                        <p className="text-2xl font-bold text-gray-900">
                            {formatRupiah(customer.balance)}
                        </p>
                    </div>
                </div>
            </section>

            {/* Aksi transaksi */}
            <section className="rounded-xl border border-gray-200 bg-white p-5 mb-4 shadow-sm">
                <h2 className="font-semibold text-gray-900">
                    Transaksi Nasabah
                </h2>

                <p className="mt-1 text-sm text-gray-500">
                    Pilih transaksi yang akan dilakukan setelah memeriksa identitas
                    dan status rekening.
                </p>

                <div className="mt-4 flex flex-wrap gap-3">
                    <Link
                        to="/teller/deposit"
                        state={{ customer, locked: true }}
                        className="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700"
                    >
                        Setoran Tunai
                    </Link>

                    <Link
                        to="/teller/withdrawal"
                        state={{ customer, locked: true }}
                        className="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Penarikan Tunai
                    </Link>
                </div>
            </section>
        </div>
    );
}
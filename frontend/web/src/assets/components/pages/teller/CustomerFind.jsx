import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { AlertCircle, LoaderCircle, QrCode, Search } from "lucide-react";

import { api } from "../../../../config/api";
import QrScannerModal from "../../common/QrScannerModal";

export default function CustomerFind() {
    const navigate = useNavigate();
    const [accountNumber, setAccountNumber] = useState("");
    const [loading, setLoading] = useState(false);
    const [isQrModalOpen, setIsQrModalOpen] = useState(false);
    const [error, setError] = useState("");

    const openCustomer = (customer) => {
        setIsQrModalOpen(false);
        navigate(`/teller/customers/${encodeURIComponent(customer.account_number)}`);
    };

    const handleSearch = async (event) => {
        event.preventDefault();
        const account = accountNumber.trim();

        if (!account || !/^[A-Za-z0-9]+$/.test(account)) {
            setError("Masukkan nomor rekening yang valid.");
            return;
        }

        setLoading(true);
        setError("");

        try {
            const response = await api.get(
                `/teller/customers/${encodeURIComponent(account)}`
            );
            const customer = response.data?.data;

            if (!customer?.account_number) {
                throw new Error("Data rekening tidak ditemukan dalam respons server.");
            }

            openCustomer(customer);
        } catch (requestError) {
            setError(
                requestError.response?.data?.message ??
                requestError.message ??
                "Gagal mencari rekening. Silakan coba kembali."
            );
        } finally {
            setLoading(false);
        }
    };

    return (
        <main className="mx-auto max-w-3xl space-y-6">
            <QrScannerModal
                isOpen={isQrModalOpen}
                onClose={() => setIsQrModalOpen(false)}
                onSuccess={openCustomer}
                title="Pindai QR Nasabah"
            />

            <header>
                <h1 className="text-2xl font-bold text-gray-900">Identifikasi Nasabah</h1>
                <p className="mt-1 text-sm text-gray-500">
                    Scan QR sebagai metode utama, atau cari satu rekening berdasarkan nomor rekening.
                </p>
            </header>

            <section className="space-y-5 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <div className="flex items-start gap-3">
                    <div className="rounded-lg bg-blue-50 p-2.5 text-blue-600">
                        <QrCode size={22} />
                    </div>
                    <div>
                        <h2 className="font-semibold text-gray-900">Scan QR nasabah</h2>
                        <p className="mt-1 text-sm text-gray-500">
                            Sistem memvalidasi rekening melalui backend sebelum menampilkan identitasnya.
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    onClick={() => {
                        setError("");
                        setIsQrModalOpen(true);
                    }}
                    className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700"
                >
                    <QrCode size={17} />
                    Scan QR Code
                </button>

                <form onSubmit={handleSearch} className="space-y-3 border-t border-gray-100 pt-5">
                    <div>
                        <label htmlFor="account-number-search" className="mb-2 block text-sm font-medium text-gray-700">
                            Pencarian alternatif berdasarkan nomor rekening
                        </label>
                        <div className="flex flex-col gap-3 sm:flex-row">
                            <input
                                id="account-number-search"
                                type="search"
                                value={accountNumber}
                                onChange={(event) => setAccountNumber(event.target.value)}
                                placeholder="Masukkan satu nomor rekening"
                                maxLength={64}
                                disabled={loading}
                                className="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
                            />
                            <button
                                type="submit"
                                disabled={loading}
                                className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {loading
                                    ? <LoaderCircle size={17} className="animate-spin" />
                                    : <Search size={17} />}
                                Cari rekening
                            </button>
                        </div>
                    </div>
                </form>

                {error && (
                    <div role="alert" className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        <AlertCircle size={18} className="mt-0.5 shrink-0" />
                        <p>{error}</p>
                    </div>
                )}
            </section>
        </main>
    );
}

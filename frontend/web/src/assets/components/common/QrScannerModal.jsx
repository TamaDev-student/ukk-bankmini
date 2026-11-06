import { useCallback, useEffect, useRef, useState } from "react";
import { Html5Qrcode } from "html5-qrcode";
import { LoaderCircle, QrCode, X } from "lucide-react";
import { api } from "../../../config/api";

/**
 * QrScannerModal — Komponen pemindaian QR Code nasabah.
 *
 * Memvalidasi nomor rekening langsung ke backend Laravel (/teller/customers/{accountNumber})
 * dan mengembalikan data nasabah yang sah.
 */
export default function QrScannerModal({
    isOpen,
    onClose,
    onSuccess,
    scanPurpose,
    title = "Pindai QR Code Nasabah",
}) {
    const [scanError, setScanError] = useState("");
    const [lookingUp, setLookingUp] = useState(false);
    const scannerRef = useRef(null);
    const processingScanRef = useRef(false);

    const stopScanner = useCallback(async () => {
        const scanner = scannerRef.current;
        if (!scanner) return;

        try {
            if (scanner.isScanning) {
                await scanner.stop();
            }
        } catch (error) {
            console.error("Gagal menghentikan scanner:", error);
        }

        try {
            scanner.clear();
        } catch {
            // Container mungkin sudah unmounted
        }

        scannerRef.current = null;
    }, []);

    const handleScannedAccount = useCallback(
        async (decodedText) => {
            if (processingScanRef.current) return;

            const accountNumber = decodedText.trim();
            if (!accountNumber || !/^[A-Za-z0-9]+$/.test(accountNumber)) {
                setScanError("QR Code tidak valid. Pastikan memindai QR Code rekening nasabah.");
                return;
            }

            processingScanRef.current = true;
            setLookingUp(true);
            setScanError("");

            try {
                const customerUrl = `/teller/customers/${encodeURIComponent(accountNumber)}`;
                const response = scanPurpose === "cash_deposit"
                    ? await api.post(`${customerUrl}/scan-token`)
                    : await api.get(customerUrl);

                const customer = scanPurpose === "cash_deposit"
                    ? response.data?.data?.customer
                    : response.data?.data;
                const scanToken = scanPurpose === "cash_deposit"
                    ? response.data?.data?.scan_token
                    : undefined;
                if (!customer?.account_number) {
                    throw new Error("Data nasabah tidak ditemukan dalam respons server.");
                }
                if (customer.status !== "active") {
                    throw new Error("Rekening nasabah tidak aktif.");
                }
                if (scanPurpose === "cash_deposit" && !scanToken) {
                    throw new Error("Backend tidak mengembalikan token scan QR setoran.");
                }

                await stopScanner();
                onSuccess(customer, scanToken);
            } catch (error) {
                const status = error.response?.status;
                if (status === 404) {
                    setScanError("Nomor rekening pada QR Code tidak ditemukan di database.");
                } else if (status === 401 || status === 403) {
                    setScanError(
                        error.response?.data?.message ?? "Akses ditolak atau sesi Teller telah berakhir."
                    );
                } else if (status === 410) {
                    setScanError("Token QR kedaluwarsa. Silakan pindai ulang.");
                } else {
                    setScanError(
                        error.response?.data?.message ??
                        error.message ??
                        "Gagal mengambil data nasabah dari server."
                    );
                }
                processingScanRef.current = false;
            } finally {
                setLookingUp(false);
            }
        },
        [onSuccess, scanPurpose, stopScanner]
    );

    useEffect(() => {
        if (!isOpen) {
            stopScanner();
            return;
        }

        let isMounted = true;
        processingScanRef.current = false;

        const initScanner = async () => {
            // Tunggu elemen dom ter-render
            await new Promise((resolve) => setTimeout(resolve, 150));
            if (!isMounted) return;

            try {
                const element = document.getElementById("qr-reader-container");
                if (!element) return;

                const scanner = new Html5Qrcode("qr-reader-container");
                scannerRef.current = scanner;

                await scanner.start(
                    { facingMode: "environment" },
                    {
                        fps: 10,
                        qrbox: { width: 240, height: 240 },
                        aspectRatio: 1,
                    },
                    (decodedText) => {
                        void handleScannedAccount(decodedText);
                    },
                    () => {
                        // Frame belum mendeteksi QR
                    }
                );
            } catch {
                if (isMounted) {
                    setScanError("Kamera tidak dapat diakses. Periksa izin kamera browser Anda.");
                }
            }
        };

        void initScanner();

        return () => {
            isMounted = false;
            void stopScanner();
        };
    }, [isOpen, handleScannedAccount, stopScanner]);

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs">
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="qr-scanner-title"
                className="max-h-[calc(100dvh-2rem)] w-full max-w-md overflow-y-auto rounded-2xl bg-white shadow-2xl"
            >
                {/* Header */}
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                    <div className="flex items-center gap-2 font-semibold text-gray-900">
                        <QrCode size={20} className="text-blue-600" />
                        <span id="qr-scanner-title">{title}</span>
                    </div>

                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Tutup pemindai QR"
                        className="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                    >
                        <X size={20} />
                    </button>
                </div>

                {/* Body */}
                <div className="p-5">
                    <p className="mb-4 text-center text-xs text-gray-500">
                        Arahkan kamera ke QR Code rekening nasabah pada aplikasi Customer Mobile.
                    </p>

                    <div className="relative mx-auto aspect-square w-full max-w-[280px] overflow-hidden rounded-xl border border-gray-200 bg-black">
                        <div id="qr-reader-container" className="h-full w-full" />

                        {lookingUp && (
                            <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-black/75 text-white">
                                <LoaderCircle size={28} className="animate-spin text-blue-400" />
                                <span className="text-xs font-medium">
                                    Memvalidasi data nasabah...
                                </span>
                            </div>
                        )}
                    </div>

                    {scanError && (
                        <div
                            role="alert"
                            className="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700"
                        >
                            {scanError}
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="border-t border-gray-100 bg-gray-50 px-5 py-3 text-right">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                    >
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    );
}

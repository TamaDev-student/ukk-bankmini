/**
 * navigation.js — Konfigurasi navigasi sidebar per role.
 *
 * Setiap role memiliki:
 *   - basePath  : prefix URL untuk role tersebut
 *   - brand     : info identitas di sidebar header
 *   - user      : info user default (sementara, nanti dari auth)
 *   - items     : daftar menu navigasi
 *
 * Ikon menggunakan nama Material Symbols Outlined.
 */

export const navigationConfig = {
    admin: {
        basePath: "/admin",
        user: {
            name: "Admin Utama",
            role: "Super Admin",
        },
        breadcrumb: "Operasional Bank",
        items: [
            { path: "dashboard", label: "Dashboard", icon: "grid_view" },
            { path: "users", label: "Manajemen Pengguna", icon: "group" },
            { path: "students", label: "Master Data Siswa", icon: "school" },
            { path: "accounts", label: "Rekening Nasabah", icon: "account_balance_wallet" },
            { path: "transaction-reports", label: "Laporan Transaksi", icon: "summarize" },
            { path: "journal", label: "Jurnal Akuntansi", icon: "menu_book" },
            { path: "activity-log", label: "Log Aktivitas", icon: "history" },
        ],
    },

    teller: {
        basePath: "/teller",
        user: {
            name: "Teller",
            role: "Petugas Teller",
        },
        breadcrumb: "Layanan Teller",
        items: [
            { path: "dashboard", label: "Dashboard Teller", icon: "grid_view" },
            { path: "cash-reconciliation", label: "Rekonsiliasi Kas", icon: "account_balance" },
            { path: "find-customer", label: "Identifikasi Nasabah", icon: "person_search" },
            { path: "deposit", label: "Setoran Tunai", icon: "savings" },
            { path: "withdrawal", label: "Penarikan Tunai", icon: "payments" },
            { path: "daily-report", label: "Laporan Harian", icon: "summarize" },
        ],
    },

    supervisor: {
        basePath: "/supervisor",
        user: {
            name: "Supervisor",
            role: "Pengawas",
        },
        breadcrumb: "Pengawasan Operasional",
        items: [
            { path: "dashboard", label: "Dashboard", icon: "grid_view" },
            { path: "review-report", label: "Laporan Harian", icon: "fact_check" },
            { path: "transaction-reports", label: "Laporan Transaksi", icon: "summarize" },
            { path: "activity-log", label: "Log Aktivitas", icon: "history" },
            { path: "journal", label: "Jurnal Akuntansi", icon: "menu_book" },
        ],
    },
};

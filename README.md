# 🏫 E-Teller Bank Mini Sekolah

Sistem Informasi E-Teller Bank Mini Sekolah adalah aplikasi perbankan mini berbasis *client-server* yang dirancang untuk mengawal integritas data operasional loket sekolah dengan implementasi logika bisnis dan **Role-Based Access Control (RBAC)** yang ketat. Proyek ini dibangun sebagai penyelesaian **Uji Kompetensi Keahlian (UKK) Rekayasa Perangkat Lunak 2026/2027 (Paket 1)**.

---

## 🌟 Fitur Utama

Sistem ini didesain sesuai dengan spesifikasi operasional standar (SOP) Bank Mini dengan pembatasan hak akses (RBAC) yang mutlak:

### 1. Hak Akses (Role-Based Access Control)
Terdapat 4 *role* utama yang dikelola melalui gerbang login terpusat (menggunakan *Session/Token* dan *Middleware*):
- 👨‍💻 **Administrator (Sistem Utama & Konfigurasi)**
  - Mengelola *user management* (membuat akun Teller/Supervisor).
  - Mengatur master data buku rekening secara otomatis.
  - Membaca dan mengawasi tabel jurnal akuntansi secara penuh (*back-end audit*).
  - ⛔ *Batasan:* Tidak diizinkan menginput transaksi nasabah di loket.
- 👩‍💼 **Teller (Front-End Transaction Operator)**
  - Pencarian instan nasabah menggunakan QR Code / manual.
  - Eksekusi setoran tunai (menambah saldo, cetak struk).
  - Eksekusi penarikan tunai (validasi sisa saldo > Rp 10.000, validasi PIN 6-digit nasabah).
  - Membuat draf laporan penutupan kas harian berdasarkan kecocokan uang fisik.
  - ⛔ *Batasan:* Tidak dapat memanipulasi (edit/delete) transaksi yang sudah tersimpan, tidak bisa meng-approve laporan sendiri.
- 🕵️‍♂️ **Supervisor (Data Validator & Controller)**
  - Melakukan verifikasi kecocokan slip transaksi dengan sistem.
  - Memberikan *approval* (persetujuan) atas Laporan Harian Teller.
  - Mengotorisasi pemindahan kas dari laci Teller ke Brankas Utama.
  - Membuka modul resolusi untuk melacak selisih kas (*real-time log*).
  - ⛔ *Batasan:* Tidak diizinkan melayani transaksi nasabah secara mandiri di loket.
- 🎓 **Nasabah / Siswa (End-User Access)**
  - Cek saldo dan riwayat mutasi tabungan secara mandiri (Read-Only).
  - Melakukan otorisasi keamanan dengan input PIN 6-digit saat penarikan.
  - ⛔ *Batasan:* Tidak memiliki akses ke data internal bank maupun nasabah lain.

### 2. Double-Entry Accounting
Setiap transaksi yang berhasil secara otomatis memicu penulisan **2 baris jurnal akuntansi** (Debet dan Kredit) di *back-end* guna menjaga keseimbangan (balance) kas dan tabungan sekolah.

### 3. Keamanan Tingkat Lanjut (Database & Application Level)
- **Database Engine:** `InnoDB` dengan tipe data `BIGINT` untuk nominal presisi.
- **Foreign Key Constraints:** Menggunakan `ON DELETE RESTRICT` pada tabel master (Users & Nasabah) untuk mencegah penghapusan jejak audit (Audit Trail).
- **Check Constraints:** Diberlakukan pembatasan nominal (`> 0`) di level *database* untuk menghalau injeksi data siluman atau transaksi minus.
- **Database Transaction (ACID):** Penerapan `DB::transaction()` dengan fungsi *Rollback* untuk mencegah *overdraft* dan anomali saat kegagalan sistem di tengah proses.
- **Middleware Guard:** Penguncian mutlak pada URL/API (mencegah manipulasi URL oleh user level rendah).

---

## 🛠️ Arsitektur & Teknologi (Tech Stack)
- **Bahasa & Framework:** PHP (Laravel)
- **Database:** MySQL / PostgreSQL
- **Keamanan:** Laravel Sanctum (API Auth), Middleware RBAC, Password Hashing, Enkripsi PIN.

---

## 📊 Skema Database Terstruktur (3NF)

Sistem telah dinormalisasi hingga tahap ke-3 (3NF) dengan penamaan tabel Bahasa Inggris standar:
- `users` (id, username, password, role, dll)
- `majors` (jurusan)
- `classes` (kelas)
- `customers` / nasabah (id, no_rekening, pin_keamanan, balance, dll)
- `transactions` (id, customer_id, user_id, tanggal, jenis_trans, nominal)
- `journals` (id, transaction_id, kode_akun, posisi, jumlah)

---

## 🚀 Alur Rekonsiliasi dan Penutupan Kas Harian

Backend menghitung saldo kas sistem dari saldo sistem laporan sebelumnya yang telah disetujui (atau konfigurasi awal pada hari operasional pertama), lalu menambahkan setoran berhasil dan mengurangi penarikan berhasil:
> **Saldo Awal Sistem + Total Setoran Berhasil − Total Penarikan Berhasil = Saldo Akhir Sistem**

Transaksi pending dan gagal/ditolak dicatat terpisah dan tidak memengaruhi saldo sistem. Teller memasukkan kas fisik awal dan akhir; backend menyimpan selisih masing-masing tanpa mengubah saldo sistem. Pengiriman Teller menghasilkan status menunggu persetujuan, sedangkan persetujuan hanya dapat dilakukan Supervisor.

Pada operasional pertama, Teller mencatat saldo sistem sesuai modal yang benar-benar diterima. Nilai ini wajib diisi sebelum transaksi dimulai dan tidak dapat diubah oleh Teller setelah disimpan. Koreksi hanya dapat dilakukan Supervisor sebelum transaksi dimulai, dengan alasan dan riwayat audit.

Penutupan otomatis memakai konfigurasi server `BANK_CASH_CLOSE_TIME` (default operasional proyek `23:59`, zona `Asia/Jakarta`). Penutupan ini mencatat saldo sistem dan snapshot transaksi tanpa mengarang kas fisik atau selisih akhir, dengan status `auto_closed_unverified`. Agar jadwal berjalan, lingkungan produksi harus menjalankan Laravel Scheduler setiap menit (`php artisan schedule:run` dari cron atau layanan scheduler).

---
*Dibuat untuk memenuhi spesifikasi ketat Uji Kompetensi Keahlian (UKK) Rekayasa Perangkat Lunak 2026/2027.*

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g. `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion.
- Use explicit return type declarations and type hints for all method parameters.
- Use TitleCase for Enum keys.
- Prefer PHPDoc blocks over inline comments.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using Laravel Cloud, which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` unless specified in `vite.config.js`. Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` for version-specific Inertia documentation and updated code examples.
- Activate `inertia-react-development` when working with Inertia client-side patterns.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files.
- Pass `--no-interaction` to all Artisan commands.
- For APIs, default to Eloquent API Resources and API versioning unless existing API routes do not, then follow existing convention.
- Prefer named routes and `route()` when generating links.
- Use feature tests for most test coverage.
- Check existing factories/states before manually creating test models.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If PHP files are modified, run `vendor/bin/pint --dirty --format agent` before finalizing changes.

=== pest/core rules ===

# Pest

- This project uses Pest for testing.
- Create tests with `php artisan make:test --pest {name}`.
- Run tests with `php artisan test --compact`.
- Do not delete tests without approval.

=== inertia-react/core rules ===

# Inertia + React

- Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>

# Project-Specific Instructions — E-Teller Bank Mini Sekolah

> These project-specific rules supplement the Laravel Boost guidelines above. If a project-specific rule conflicts with a generic guideline, preserve Laravel/project conventions and ask before overriding a foundational Laravel Boost safety rule.

## 1. Project Context

Nama: **Sistem Informasi E-Teller Bank Mini Sekolah**

Tujuan: backend aplikasi Bank Mini sekolah berbasis client-server dengan fokus pada integritas transaksi, RBAC, audit trail, jurnal double-entry, dan pelaporan harian.

### Source of Truth

**Soal UKK**
- Acuan minimum kompetensi, fitur wajib, keamanan, RBAC, database, dan rubrik penilaian.

**Hasil wawancara pengguna**
- Kebutuhan nyata Bank Mini dan detail implementasi.
- Boleh memperinci atau berbeda dari contoh/redaksi Soal UKK selama tetap mengacu pada tujuan dan ketentuan UKK.

**Jangan menganggap perbedaan otomatis sebagai konflik.**
Jika requirement tidak menentukan sesuatu, tandai `UNDEFINED/PENDING` dan jangan membuat keputusan bisnis baru tanpa persetujuan developer.

## 2. Stack

- Laravel 13.x
- PHP 8.4
- MySQL
- Laravel Sanctum
- Local development: Herd / Laragon
- API prefix: `/api`
- API testing: Postman
- Tinker: debugging/service testing jika memang sesuai aturan testing
- Frontend React dikerjakan setelah backend stabil

Backend-first.

## 3. Agent Workflow

Sebelum perubahan:
1. Baca source yang relevan.
2. Jika `.ai/rules` ada, ikuti Laravel Boost rules untuk path yang akan disentuh.
3. Cocokkan dengan requirement.
4. Tunjukkan file yang akan dibuat/diubah.

Saat developer meminta **coding manual**:
- Jangan mengedit file otomatis.
- Tampilkan kode lengkap/perubahan yang perlu dimasukkan.
- Jelaskan bagian yang diganti/ditambahkan.
- Tunggu developer melakukan coding manual dan menjalankan test.

Setelah implementasi:
1. Jalankan test yang relevan.
2. Cek route dengan `php artisan route:list` bila route berubah.
3. Jalankan Pint bila PHP berubah.
4. Catat hasil testing.
5. Audit ulang terhadap requirement.
6. Baru lanjut UC berikutnya.

Jangan merusak fitur existing.

## 4. Role & RBAC

Role staff yang digunakan:
- `administrator`
- `teller`
- `supervisor`

Nasabah/siswa adalah actor eksternal. Jangan membuat role `nasabah` di enum `users` tanpa requirement baru.

### Administrator
- Manajemen user staff.
- Manajemen/pembuatan rekening nasabah.
- Audit jurnal akuntansi back-end.
- Tidak melakukan transaksi teller harian.

### Teller
- Cari rekening/nasabah.
- Setoran.
- Penarikan.
- Laporan harian.
- Transaksi koreksi sesuai requirement.
- Tidak boleh edit/hapus transaksi tersimpan.
- Tidak boleh approve laporan sendiri.

### Supervisor
- Approval laporan harian.
- Real-time/audit log.
- Tidak melakukan transaksi teller.
- Approval satu per satu.
- Approval tidak didelegasikan ke kepala sekolah; bila berhalangan, approval dilakukan hari berikutnya.

### Nasabah
- Saldo sendiri.
- Mutasi sendiri.
- PIN 6 digit untuk otorisasi penarikan.
- Tidak mengakses data internal, jurnal, nasabah lain, atau modul transaksi.

Semua endpoint privat wajib menggunakan authentication + authorization sesuai role.

## 5. Business Rules

### Minimum balance
- Sisa saldo setelah penarikan >= Rp10.000.
- Saldo tepat Rp10.000 tidak dapat ditarik.
- Validasi wajib di backend.

### PIN
- 6 digit.
- Simpan hashed.
- Jangan pernah expose plaintext/hash PIN melalui response/log/dokumentasi.

### Transactions
- Teller tidak boleh edit/hapus transaksi tersimpan.
- Setiap transaksi berhasil menghasilkan 2 journal entries.

Deposit:
- Debit `Kas`
- Credit `Tabungan Nasabah`

Withdrawal:
- Debit `Tabungan Nasabah`
- Credit `Kas`

Update saldo + transaction + journals harus atomik dengan `DB::transaction()`.

## 6. Database Integrity

Requirement UKK:
- 3NF.
- InnoDB.
- Rupiah `INT`/`BIGINT`.
- Master FK penting menggunakan `ON DELETE RESTRICT`.
- Nominal transaksi > 0 via DB constraint.

### users
Relevant:
- username unique
- name
- full_name
- email
- password
- role enum `administrator|teller|supervisor`
- status enum `active|blocked`, default `active`

Password hidden/hashed sesuai model existing.

### customers
Relevant:
- account_number
- student_number
- student_name
- class_id
- status
- security_pin
- balance

security_pin hidden + hashed.

### majors
Initial structure:
- id
- name
- timestamps

UC-03 change:
- add `code` unique.
- `name` = nama lengkap jurusan.

`Major.php` current `major_id` in `$fillable` is a confirmed error because migration `majors` has no `major_id`.
Implementation:
- remove `major_id`
- add `code`

### classes / school_classes
Class relates to major through current source relation/`major_id`.

## 7. Account Number

From interview:
`[KODE_JURUSAN][NOMOR_URUT]`

3-digit zero-padding.

Known codes:
`AK`, `BR`, `BD`, `MP`, `DKV`, `RPL`, `TKJ`

Decision:
- sequence is per major.
- examples: `RPL001`, `RPL002`, `TKJ001`, `AK010`.

Backend generates account number. Client does not send account_number on create.

`account_number` and `student_number` are immutable through customer update.

### Concurrency
Do not assume `DB::transaction()` alone prevents race conditions.

Chosen design:
1. Start `DB::transaction()`.
2. `Major` row for the selected major is locked with `lockForUpdate()`.
3. Determine next sequence for that major.
4. Generate account number.
5. Insert customer.
6. Commit.
7. Keep unique DB constraint on account_number as final protection.

Implementation must be reviewed for real concurrency correctness.

### Pending
Cross-major class change is still `UNDEFINED/PENDING`.
Do not invent rules for whether a customer may move between majors, whether account_number changes, or whether the update should be rejected.

## 8. Use Cases

1. UC-01 Login — Administrator, Teller, Supervisor, Nasabah
2. UC-02 Manajemen User — Administrator
3. UC-03 Manajemen Rekening — Administrator
4. UC-04 Cari Rekening Nasabah — Teller
5. UC-05 Penarikan Tunai — Teller, Nasabah
6. UC-06 Setoran Tunai — Teller, Nasabah
7. UC-07 Cek Saldo — Nasabah
8. UC-08 Cek Mutasi — Nasabah
9. UC-09 Cek Laporan Harian — Teller
10. UC-10 Pembuatan & Pengiriman Laporan Harian — Teller
11. UC-11 Approval Laporan Harian — Supervisor
12. UC-12 Jurnal Akuntansi — Administrator, Supervisor
13. UC-13 Real-time Log — Supervisor

Current include relationships:
- UC-02 include Login
- UC-03 include Login
- UC-04 include Login
- UC-05 include Login + Cari Rekening
- UC-06 include Login + Cari Rekening
- UC-07 include Login
- UC-08 include Login
- UC-09 include Login
- UC-10 include Login
- UC-11 include Login + Cek Laporan Harian
- UC-12 include Login
- UC-13 include Login

Do not add new relations without requirement basis.

## 9. UC-02 Current Design

Actor: Administrator.
Authorization: `auth:sanctum` + `role:administrator`.

Endpoints:
```text
GET    /api/admin/users
POST   /api/admin/users
GET    /api/admin/users/{id}
PUT    /api/admin/users/{id}
DELETE /api/admin/users/{id}
```

Create fields:
- name
- username
- full_name
- email
- password
- password_confirmation
- role

Create rules:
- username unique
- email unique
- password min 8 + confirmed
- role in administrator/teller/supervisor
- status defaults active

Update may change:
- name
- username
- full_name
- email
- password
- role
- status

Username may be changed.
Status only `active|blocked`.

DELETE user:
- Administrator has D from UKK.
- DB FK RESTRICT protects referenced transactions.
- API should return HTTP 409 with clear message if referenced.
- `blocked` is a separate non-delete path.

## 10. UC-03 Current Design

Actor: Administrator.
Authorization: `auth:sanctum` + `role:administrator`.

Endpoints:
```text
GET  /api/admin/customers
POST /api/admin/customers
GET  /api/admin/customers/{id}
PUT  /api/admin/customers/{id}
```

**There is NO DELETE customer endpoint.**
UKK RBAC for Pembuatan & Manajemen Rekening is C,R,U only.

Create:
- student_number required, string, max 30, unique
- student_name required, string, max 100
- class_id required, integer, exists
- security_pin required, digits:6

Server fills:
- account_number automatically
- balance = 0
- status = active

Update:
- student_name
- class_id
- status
- account_number and student_number are not editable

Cross-major class change: UNDEFINED/PENDING.

## 11. Existing Implemented Features — Do Not Break

Already verified at least once:
- Supervisor login.
- Deposit.
- Withdrawal.
- Minimum balance Rp10.000.
- Double-entry journals.
- `/api/supervisor/journals`.
- Journal filters: date, teller, customer, account number.
- Teller blocked with 403 from supervisor journal endpoint.
- Daily report workflow exists, but full reporting path is not yet considered completely verified.

Current journal structure:
- journals: id, transaction_id, account_name, type, amount, timestamps
- Journal belongsTo Transaction
- Transaction hasMany Journal

Current transaction service:
Deposit:
- increment balance
- create transaction
- debit Kas
- credit Tabungan Nasabah

Withdrawal:
- check minimum balance
- decrement balance
- create transaction
- debit Tabungan Nasabah
- credit Kas

Do not alter these while implementing UC-02 unless required and explicitly approved.

## 12. Daily Report Context

`daily_reports` concept includes:
- id
- user_id
- report_date
- opening_balance
- total_deposit
- total_withdrawal
- system_balance
- physical_balance
- status
- approved_by

Workflow:
Teller submit → Supervisor review → approve/reject.

Supervisor reviews one by one.
If cash discrepancy is unresolved, report may be pending.

## 13. API Safety

- Never trust frontend-only role checks.
- Private routes require Sanctum + role middleware.
- Check `php artisan route:list` after route changes.
- Avoid duplicate routes.
- Avoid redundant nested `auth:sanctum` groups unless justified by existing convention.
- Never expose password, PIN, tokens, or secrets.
- Preserve existing working route behavior.

## 14. Pending Decisions

### C-1 — Official major names
Codes are confirmed:
`AK, BR, BD, MP, DKV, RPL, TKJ`

Use official school names when available. Never invent names.

### C-4 — Moving class_id across majors
Still undefined:
- allowed or not
- account number changes or remains
- update rejected or accepted

Do not decide silently.

## 15. Current Next Step

Implement **UC-02 Manajemen User** first.

Expected process:
1. Inspect relevant source.
2. Identify exact files.
3. Show code for manual implementation.
4. Developer codes manually.
5. Test endpoints in Postman.
6. Fix issues.
7. Only then proceed to UC-03.

Do not implement UC-03 before UC-02 is stable unless explicitly requested.

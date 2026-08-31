# plnjbt-dashboard

Aplikasi web pengganti Looker Studio untuk Bidang Perencanaan PLN UIT JBT:
data dan fitur dashboard diolah sendiri oleh karyawan, tanpa bergantung pada
developer eksternal.

Dibangun sebagai proyek magang dengan konsep IBS (Intelligent Business
Intelligence System). Prinsip inti: configuration over hardcoding - dashboard,
widget, dan visualisasi dikelola lewat konfigurasi di database, bukan perubahan
kode, sehingga tim internal tetap bisa menambah, mengubah, atau menghapus
grafik secara mandiri setelah developer magang selesai.

## Masalah yang Dijawab

Looker Studio punya keterbatasan untuk kebutuhan internal Bidang Perencanaan:
wajib online, hak akses terikat akun Google pembuat, dan tidak ada pembersihan
data otomatis. Aplikasi ini menggantikannya: staf divisi mengunggah data
Excel/CSV, sistem memvalidasi otomatis, dan visual dashboard dikelola bidang
teknis IT - semuanya di satu tempat, dengan jejak audit.

## Model Hak Akses (4 Peran)

| Peran | Kewenangan Utama |
| :--- | :--- |
| Admin | Kelola user, role, dataset, dashboard, audit log, konfigurasi sistem |
| Management | Penonton utama: lihat dashboard, filter, ekspor laporan |
| Editor Dashboard (bidang IT) | Buat/ubah visual semua sub-bidang dalam Bidang Perencanaan; akses data READ-ONLY |
| Staf Divisi | Unggah data untuk divisinya sendiri; lihat semua dashboard (transparan) |

Koreksi data berlaku langsung tanpa alur approval; setiap perubahan data
(unggah ulang file maupun edit sederhana) tercatat di audit log.

## Tech Stack

- Laravel 12 + Blade + Alpine.js
- Tailwind CSS 4 (via Vite plugin)
- MySQL 8

## Roadmap MVP

1. Auth (login/logout/session/password hashing)
2. RBAC (roles + permissions granular, otorisasi di backend)
3. Dataset Management (CRUD + status dataset)
4. Excel/CSV Import (secure upload, schema detection, column mapping)
5. Data Validation (FATAL/WARNING severity)
6. Dashboard Builder (widget CRUD, layout persisten)
7. Visualization Engine (KPI card, bar, line, table - render dari config DB)
8. Dashboard Filtering (tanggal, dropdown, lintas widget)
9. Audit Log (append-only)
10. Rate limiting + API security hardening

Fase lanjutan (di luar MVP): AI assistance, forecasting, analitik lanjutan.

## Setup Development

```bash
composer install
npm install
cp .env.example .env   # sesuaikan kredensial MySQL
php artisan key:generate
php artisan migrate
npm run dev
php artisan serve
```

## Struktur Dokumentasi

Dokumen perancangan (PRD, model hak akses, arsitektur data, analisis Looker
Studio) dikelola di vault Obsidian terpisah, tidak disimpan di repo ini.

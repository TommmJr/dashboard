# Dokumentasi Handover & SOP Operasional Sistem IBS / plnjbt-dashboard

Direktori ini memuat dokumentasi teknis komprehensif, arsitektur sistem, aturan bisnis kalkulasi, kamus data, serta standar operasional prosedur (SOP) bagi tim IT PLN UIT JBT dan PIC operasional Perencanaan Pengusahaan.

## Daftar Dokumen Handover

| No | Dokumen | Target Pembaca | Deskripsi Ringkas |
|---|---|---|---|
| 01 | [Runbook Instalasi & Lingkungan Sistem](01-environment-and-installation-runbook.md) | Tim Pengembang / IT / DevOps | Spesifikasi PHP 8.4, MySQL 8.4, arsitektur database 2-user, runbook fresh deployment, dan hardening grant audit. |
| 01a | [Arsitektur Sistem & Alur Pipeline Impor](01-system-architecture-and-import-pipeline.md) | Tim Pengembang / IT | Topologi kontainer, alur pipeline dual-write impor, transaksi all-or-nothing, chronological guard, dan konsolidasi audit log. |
| 02 | [Kamus Data & Relasi Tabel](02-data-dictionary-and-table-relations.md) | DBA / Tim IT | Kamus 20 tabel database, pemisahan model terstruktur vs legacy, status lifecycle dataset, dan kontrak query latest dataset. |
| 03 | [Aturan Bisnis & Kontrak Perhitungan KPI](03-kpi-business-rules-and-calculation-contracts.md) | Analis Sistem / IT / PIC | Kontrak formula KPI, normalisasi skala rasio vs persen, unweighted mean, penanganan baris daun vs induk, dan rincian intentional divergence reader. |
| 04 | [SOP Operasional Impor Bulanan & Troubleshooting](04-monthly-import-sop-and-troubleshooting.md) | PIC Perencanaan Pengusahaan / Staf Divisi | Panduan 1-klik impor berkas bulanan, penanganan bendera period_conflict, mitigasi status FAILED, dan verifikasi data pasca-impor. |
| 05 | [Panduan Administrator, Hak Akses & Backup-Restore](05-administrator-guide-and-deployment-runbook.md) | Administrator Sistem / Tim IT | Pengelolaan RBAC 4 role, guard anti-lockout, hardening grant MySQL dua-user, prosedur backup-restore database, dan disaster recovery. |
| 06 | [Batasan Sistem & Rencana Lanjutan](06-system-limitations-and-future-roadmap.md) | Tim IT / Manajemen / PIC | Status domain Aset (unmounted prototype dan prasyarat Faza C-E MANSET), dependensi legacy yang dipertahankan sebagai jaring rollback, serta rekomendasi masa depan. |

## Matriks Pemangku Kepentingan (Stakeholders)

- **PIC Bidang Perencanaan Pengusahaan:** Pengguna utama pengunggah berkas realisasi kinerja bulanan (Rujukan: Dokumen 03 dan Dokumen 04).
- **Administrator Sistem & IT PLN UIT JBT:** Pengelola akun pengguna, hak akses database, backup rutin, dan infrastruktur server Docker (Rujukan: Dokumen 01, Dokumen 02, dan Dokumen 05).
- **Manajemen & Evaluator Kinerja:** Pemantau ringkasan eksekutif dan tren capaian kinerja organisasi (Rujukan: Dokumen 03 dan Dokumen 06).

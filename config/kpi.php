<?php

/*
 * Read-model KPI: sumber data untuk reader dashboard yang SUDAH dimigrasi.
 *
 * 'legacy'     -> performance_metrics (perilaku lama persis; jalur rollback).
 * 'structured' -> performance_observations + org_units (default sejak Fase 4.3
 *                 Tahap B).
 *
 * Default 'structured' (Fase 4.3 Tahap B, persetujuan eksplisit user; BUKAN
 * sign-off mentor). Flag ini tetap menjadi jaring rollback: set
 * KPI_READ_MODEL=legacy di environment lalu `php artisan config:clear` (atau
 * `config:cache` ulang) dan restart container untuk kembali ke jalur legacy.
 * Reader aktif (R1-R7, R9, R10) memiliki cabang structured dan legacy di
 * belakang flag ini; R8 targetVsRealisasi telah dipensiunkan (unmounted pada
 * Sesi 95 dan dihapus dari DashboardController pada Fase 4.3 Tahap C) serta
 * digantikan oleh target-realisasi-per-indikator.
 *
 * Status Fase 4.3 Tahap C (Deprecation Bertahap):
 * - R8 (`targetVsRealisasi`) telah dihapus dari `DashboardController`; blade
 *   `widgets/target-vs-realisasi.blade.php` dipertahankan khusus untuk guard
 *   formatter `ChartTooltipFormatTest`.
 * - R1 (`/api/performance-data`) sengaja berstatus HYBRID: `series` dan
 *   `kpi.average_nko` membaca `performance_observations` (structured), tetapi
 *   `kpi.metric_count` dan `kpi.ready_datasets` tetap membaca
 *   `performance_metrics` / `datasets` untuk menjaga kontrak bisnis
 *   Keputusan Bisnis 1 Pak Gama (2026-10-25, Opsi C: 41 baris flat metrik vs
 *   30 observasi indikator pada TJBT).
 * - Reader legacy R1-R7/R9/R10, tabel `performance_metrics`, dan dual-write
 *   import TETAP dipertahankan untuk rollback selama validasi staging dan
 *   masa penerimaan rollout production belum terpenuhi, serta karena masih
 *   dipakai oleh fitur aktif lain (upload generik non-KPI, R5 fallback
 *   non-KPI, `datasets.show`, `datasets.export`, edit metrik, audit log, dan
 *   laporan ringkasan dataset).
 */
return [
    'read_model' => env('KPI_READ_MODEL', 'structured'),

    /*
     * Unit yang boleh TAMPIL di dashboard KPI.
     *
     * Provenance: keputusan USER (2026-10-12), BUKAN sign-off formal mentor/Pak
     * Gama. Dasar = kuotasi percakapan user yang dikonfirmasi user sendiri:
     * "yang ditampilkan hanya unit kita saja, unit induk. kalau unit lainnya itu
     * beda lagi."
     *
     * Kuotasi itu ambigu antara "hanya TJBT" dan "seluruh kelas Unit Induk"
     * (7 sheet ber-B4 "Unit Induk ..."). Scope yang dipakai proyek ini = TJBT
     * SAJA; pemilihan itu adalah INTERPRETASI scope oleh user, bukan kuotasi
     * eksplisit, dan bukan persetujuan formal mentor.
     *
     * Batas = TAMPILAN saja (read-path): unit lain tetap diimpor, disimpan, dan
     * TIDAK dihapus; hanya tidak ditampilkan di dashboard (legacy maupun
     * structured). Impor unit lain TIDAK ditolak.
     *
     * `null` (atau array kosong) = TANPA pembatasan (perilaku lama), disediakan
     * sebagai jaring rollback. Identitas unit case-sensitive: 'tjbt' bukan
     * 'TJBT'.
     *
     * @var list<string>|null
     */
    'visible_units' => ['TJBT'],
];

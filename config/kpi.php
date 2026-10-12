<?php

/*
 * Read-model KPI: sumber data untuk reader dashboard yang SUDAH dimigrasi.
 *
 * 'legacy'     -> performance_metrics (default; perilaku lama persis).
 * 'structured' -> performance_observations + org_units.
 *
 * Default WAJIB 'legacy'. Flag ini murni jaring rollback bedah: hanya reader
 * yang benar-benar sudah dimigrasi yang membacanya. Per unit 5B hanya dua
 * reader itu:
 *
 *   R2 DashboardController::availableUnits
 *   R4 DashboardController::latestReadyPeriod
 *
 * Reader lain (NKO, kinerja, dataset-status, chart API) TIDAK membaca flag ini
 * dan tetap membaca performance_metrics berapa pun nilainya.
 *
 * PERINGATAN: 'structured' BELUM aman untuk production. NKO tidak punya sumber
 * terstruktur (StructuredKpiImporter sengaja mengecualikan baris NKO), dan
 * reader NKO belum dimigrasi. Jangan set 'structured' sebelum semua reader
 * punya sumber terstruktur.
 */
return [
    'read_model' => env('KPI_READ_MODEL', 'legacy'),

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

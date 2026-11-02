<?php

/*
 * Read-model KPI: sumber data untuk reader dashboard yang SUDAH dimigrasi.
 *
 * 'legacy'     -> performance_metrics (default; perilaku lama persis).
 * 'structured' -> performance_observations + org_units.
 *
 * Default WAJIB 'legacy'. Flag ini merupakan jaring rollback: reader aktif
 * (R1-R7, R9, R10) telah memiliki cabang implementasi structured di belakang
 * flag ini, sedangkan R8 targetVsRealisasi telah dipensiunkan (unmounted pada
 * Sesi 95) dan digantikan oleh target-realisasi-per-indikator.
 *
 * PERINGATAN: 'structured' BELUM aman untuk production. Reader legacy tetap
 * aktif sebagai default sampai pengujian paritas data riil menyeluruh selesai
 * dan proses deprecation legacy resmi dijalankan (Fase 4.3).
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

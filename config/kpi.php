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
];

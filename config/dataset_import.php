<?php

/*
 * Konfigurasi impor dataset (Tahap 4).
 *
 * SATU-SATUNYA tempat mendefinisikan mapping kolom template Excel/CSV.
 * Kolom final akan menyesuaikan sampel data mentor; ketika itu terjadi,
 * cukup ubah array 'columns' di sini (urutan = urutan kolom di file),
 * tanpa menyentuh service/controller.
 *
 * Aturan validasi per kolom mengikuti konvensi nama rule di
 * App\Services\DatasetImportService (lihat constant VALIDATION_*).
 */
return [

    // Ukuran maksimum file unggahan dalam kilobyte (rule max: Laravel = KB).
    'max_size_kb' => 5120,

    // Extension yang diterima form upload.
    'extensions' => ['xlsx', 'csv'],

    /*
     * Definisi kolom template generik.
     *
     * - header   : teks header persis seperti di baris pertama file.
     * - required : kolom wajib; kosong -> baris FATAL (di-skip).
     * - rule     : validator kustom per kolom di DatasetImportService.
     */
    'columns' => [
        ['header' => 'metric_name', 'required' => true,  'rule' => 'string'],
        ['header' => 'value',       'required' => true,  'rule' => 'numeric'],
        ['header' => 'period',      'required' => true,  'rule' => 'date'],
    ],
];

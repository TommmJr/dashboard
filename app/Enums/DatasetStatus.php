<?php

namespace App\Enums;

/**
 * Status lifecycle dataset (single source of truth).
 * Menggantikan konstanta STATUS_* di App\Models\Dataset.
 */
enum DatasetStatus: string
{
    case UPLOADING = 'UPLOADING';
    case VALIDATING = 'VALIDATING';
    case READY = 'READY';
    case FAILED = 'FAILED';
    case ARCHIVED = 'ARCHIVED';

    /**
     * Label Bahasa Indonesia untuk UI (badge, filter). Nilai enum
     * tetap kode Inggris untuk query/export; label hanya tampilan
     * agar terbaca pengguna non-IT. Kosakata mengikuti mockup
     * dashboard (Berhasil/Gagal) yang di-acc mentor.
     */
    public function label(): string
    {
        return match ($this) {
            self::UPLOADING => 'Mengunggah',
            self::VALIDATING => 'Divalidasi',
            self::READY => 'Berhasil',
            self::FAILED => 'Gagal',
            self::ARCHIVED => 'Diarsipkan',
        };
    }
}

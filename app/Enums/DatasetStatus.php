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
}

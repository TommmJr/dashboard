<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class AssetSyncException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        $sanitizedMessage = self::sanitize($message);
        parent::__construct($sanitizedMessage, $code, $previous);
    }

    /**
     * Sanitasi pesan error agar tidak membocorkan ID spreadsheet atau URL Google Sheets rahasia.
     */
    public static function sanitize(?string $message): string
    {
        if ($message === null || $message === '') {
            return '';
        }

        // Mask Google Sheets URLs
        $masked = preg_replace(
            '#https?://docs\.google\.com/spreadsheets/d/[a-zA-Z0-9_\-]+[^\s"\'<>]*#i',
            '[REDACTED_URL]',
            $message
        ) ?? $message;

        // Mask any remaining URLs
        $masked = preg_replace(
            '#https?://[a-zA-Z0-9.\-_]+[^\s"\'<>]*#i',
            '[REDACTED_URL]',
            $masked
        ) ?? $masked;

        // Mask configured sheet ID if present
        $sheetId = config('asset.sheet_id');
        if (! empty($sheetId) && is_string($sheetId)) {
            $masked = str_replace($sheetId, '[REDACTED_SHEET_ID]', $masked);
        }

        // Mask configured sheet GIDs if present
        $gid1a = config('asset.sheet_gid_1a');
        if (! empty($gid1a) && is_string($gid1a)) {
            $masked = str_replace('gid='.$gid1a, 'gid=[REDACTED_GID]', $masked);
        }

        $gid1b = config('asset.sheet_gid_1b');
        if (! empty($gid1b) && is_string($gid1b)) {
            $masked = str_replace('gid='.$gid1b, 'gid=[REDACTED_GID]', $masked);
        }

        return $masked;
    }
}

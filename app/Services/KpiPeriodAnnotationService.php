<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\DatasetFreshness;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Penyimpanan terstruktur non-DDL untuk catatan konteks bisnis (anotasi eksekutif)
 * per periode pada grafik tren NKO.
 *
 * Menyimpan data di file JSON privat dengan penguncian berkas eksklusif (LOCK_EX)
 * dan mencatat setiap perubahan ke tabel audit_logs yang append-only.
 */
class KpiPeriodAnnotationService
{
    public const MAX_NOTE_LENGTH = 300;

    /**
     * Periksa apakah pengguna berhak membuat, memperbarui, atau menghapus anotasi periode.
     */
    public static function canMutate(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $user->loadMissing('role', 'division');

        if ($user->hasRole(Role::ADMIN) || $user->hasRole(Role::MANAGEMENT)) {
            return true;
        }

        return $user->hasRole(Role::STAFF_DIVISI)
            && $user->division?->slug === 'perencanaan-pengusahaan';
    }

    /**
     * Periksa apakah pengguna berhak membaca anotasi periode pada grafik tren.
     */
    public static function canView(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::canMutate($user)) {
            return true;
        }

        return $user->hasRole(Role::EDITOR_DASHBOARD);
    }

    /**
     * Kanonisasi format periode YYYY-MM atau YYYY-MM-DD (tanggal kalender sah) ke YYYY-MM.
     */
    public static function canonicalizePeriod(string $rawPeriod): ?string
    {
        $trimmed = trim($rawPeriod);
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])(?:-(0[1-9]|[12]\d|3[01]))?$/', $trimmed, $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];

        if (isset($matches[3])) {
            $day = (int) $matches[3];
            if (! checkdate($month, $day, $year)) {
                return null;
            }
        }

        return sprintf('%04d-%02d', $year, $month);
    }

    /**
     * Sanitasi teks catatan menjadi teks polos tanpa tag HTML/script dan spasi berlebih.
     */
    public static function sanitizeNote(string $rawNote): string
    {
        $withoutBlocks = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', '', $rawNote) ?? $rawNote;
        $stripped = strip_tags($withoutBlocks);
        $normalized = preg_replace('/\s+/u', ' ', trim($stripped)) ?? trim($stripped);

        return $normalized;
    }

    /**
     * Ambil seluruh anotasi periode yang tersimpan, opsional disaring rentang tanggal.
     *
     * @return list<array{
     *     period: string,
     *     period_date: string,
     *     period_label: string,
     *     note: string,
     *     author_id: int|null,
     *     author_name: string,
     *     updated_at: string,
     *     updated_at_label: string
     * }>
     */
    public function all(?string $startDate = null, ?string $endDate = null): array
    {
        $path = $this->storagePath();
        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            flock($handle, LOCK_SH);
            $raw = stream_get_contents($handle);
            $records = $this->decodeRecords($raw === false ? '' : $raw);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $this->formatAndFilter($records, $startDate, $endDate);
    }

    /**
     * Simpan atau perbarui catatan untuk satu periode kanonis dan catat ke AuditLog.
     *
     * @return array{
     *     created: bool,
     *     annotation: array{
     *         period: string,
     *         period_date: string,
     *         period_label: string,
     *         note: string,
     *         author_id: int|null,
     *         author_name: string,
     *         updated_at: string,
     *         updated_at_label: string
     *     },
     *     annotations: list<array{
     *         period: string,
     *         period_date: string,
     *         period_label: string,
     *         note: string,
     *         author_id: int|null,
     *         author_name: string,
     *         updated_at: string,
     *         updated_at_label: string
     *     }>
     * }
     */
    public function upsert(
        string $canonicalPeriod,
        string $sanitizedNote,
        User $user,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        $now = Carbon::now(DatasetFreshness::TIMEZONE);
        $entry = [
            'period' => $canonicalPeriod,
            'note' => $sanitizedNote,
            'author_id' => $user->id,
            'author_name' => $user->name,
            'updated_at' => $now->toIso8601String(),
        ];

        $previous = null;
        $records = $this->withExclusiveLock(function (array $current) use ($canonicalPeriod, $entry, &$previous): array {
            $previous = $current[$canonicalPeriod] ?? null;
            $current[$canonicalPeriod] = $entry;

            return $current;
        });

        $isCreate = $previous === null;

        AuditLog::record(
            $isCreate ? AuditLog::ACTION_CREATE : AuditLog::ACTION_UPDATE,
            null,
            $isCreate ? null : [
                'period' => $canonicalPeriod,
                'note' => (string) ($previous['note'] ?? ''),
            ],
            [
                'period' => $canonicalPeriod,
                'note' => $sanitizedNote,
            ],
            $user->id,
        );

        return [
            'created' => $isCreate,
            'annotation' => $this->formatEntry($canonicalPeriod, $entry),
            'annotations' => $this->formatAndFilter($records, $startDate, $endDate),
        ];
    }

    /**
     * Hapus catatan periode terkanonisasi bila ada dan catat ke AuditLog.
     *
     * @return list<array{
     *     period: string,
     *     period_date: string,
     *     period_label: string,
     *     note: string,
     *     author_id: int|null,
     *     author_name: string,
     *     updated_at: string,
     *     updated_at_label: string
     * }>|null
     */
    public function delete(
        string $canonicalPeriod,
        User $user,
        ?string $startDate = null,
        ?string $endDate = null,
    ): ?array {
        $removed = null;
        $records = $this->withExclusiveLock(function (array $current) use ($canonicalPeriod, &$removed): array {
            if (! array_key_exists($canonicalPeriod, $current)) {
                return $current;
            }

            $removed = $current[$canonicalPeriod];
            unset($current[$canonicalPeriod]);

            return $current;
        });

        if ($removed === null) {
            return null;
        }

        AuditLog::record(
            AuditLog::ACTION_DELETE,
            null,
            [
                'period' => $canonicalPeriod,
                'note' => (string) ($removed['note'] ?? ''),
            ],
            null,
            $user->id,
        );

        return $this->formatAndFilter($records, $startDate, $endDate);
    }

    private function storagePath(): string
    {
        $configured = config('kpi.annotations_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : storage_path('app/private/kpi-period-annotations.json');
    }

    /**
     * @param  callable(array<string, array<string, mixed>>): array<string, array<string, mixed>>  $mutator
     * @return array<string, array<string, mixed>>
     */
    private function withExclusiveLock(callable $mutator): array
    {
        $path = $this->storagePath();
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Gagal membuat direktori penyimpanan anotasi periode KPI.');
        }

        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Gagal membuka penyimpanan anotasi periode KPI.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Gagal mengunci penyimpanan anotasi periode KPI.');
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $current = $this->decodeRecords($raw === false ? '' : $raw);
            $updated = $mutator($current);

            $encoded = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $encoded);
            fflush($handle);

            return $updated;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function decodeRecords(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            return [];
        }

        $valid = [];
        foreach ($decoded as $key => $item) {
            if (! is_string($key) || ! is_array($item)) {
                continue;
            }
            $canonical = self::canonicalizePeriod($key);
            if ($canonical === null) {
                continue;
            }
            $valid[$canonical] = $item;
        }

        return $valid;
    }

    /**
     * @param  array<string, array<string, mixed>>  $records
     * @return list<array{
     *     period: string,
     *     period_date: string,
     *     period_label: string,
     *     note: string,
     *     author_id: int|null,
     *     author_name: string,
     *     updated_at: string,
     *     updated_at_label: string
     * }>
     */
    private function formatAndFilter(array $records, ?string $startDate = null, ?string $endDate = null): array
    {
        $startMonth = is_string($startDate) && $startDate !== '' ? self::canonicalizePeriod($startDate) : null;
        $endMonth = is_string($endDate) && $endDate !== '' ? self::canonicalizePeriod($endDate) : null;

        $items = [];
        foreach ($records as $period => $entry) {
            if ($startMonth !== null && strcmp($period, $startMonth) < 0) {
                continue;
            }
            if ($endMonth !== null && strcmp($period, $endMonth) > 0) {
                continue;
            }

            $items[] = $this->formatEntry($period, $entry);
        }

        usort($items, static fn (array $a, array $b): int => strcmp($b['period'], $a['period']));

        return $items;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{
     *     period: string,
     *     period_date: string,
     *     period_label: string,
     *     note: string,
     *     author_id: int|null,
     *     author_name: string,
     *     updated_at: string,
     *     updated_at_label: string
     * }
     */
    private function formatEntry(string $canonicalPeriod, array $entry): array
    {
        $periodDate = Carbon::createFromFormat('!Y-m', $canonicalPeriod, DatasetFreshness::TIMEZONE)
            ->endOfMonth()
            ->format('Y-m-d');

        $rawUpdatedAt = isset($entry['updated_at']) && is_string($entry['updated_at']) ? $entry['updated_at'] : '';
        try {
            $updatedCarbon = $rawUpdatedAt !== ''
                ? Carbon::parse($rawUpdatedAt)->timezone(DatasetFreshness::TIMEZONE)
                : Carbon::now(DatasetFreshness::TIMEZONE);
        } catch (\Throwable) {
            $updatedCarbon = Carbon::now(DatasetFreshness::TIMEZONE);
        }

        $authorId = isset($entry['author_id']) && is_numeric($entry['author_id']) ? (int) $entry['author_id'] : null;
        $authorName = isset($entry['author_name']) && is_string($entry['author_name']) && trim($entry['author_name']) !== ''
            ? trim($entry['author_name'])
            : 'Pengguna';

        return [
            'period' => $canonicalPeriod,
            'period_date' => $periodDate,
            'period_label' => DatasetFreshness::formatMonthYear($periodDate),
            'note' => self::sanitizeNote((string) ($entry['note'] ?? '')),
            'author_id' => $authorId,
            'author_name' => $authorName,
            'updated_at' => $updatedCarbon->toIso8601String(),
            'updated_at_label' => DatasetFreshness::formatDateTimeWib($updatedCarbon),
        ];
    }
}

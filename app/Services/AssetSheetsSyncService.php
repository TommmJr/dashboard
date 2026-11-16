<?php

namespace App\Services;

use App\Exceptions\AssetSyncException;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AssetSheetsSyncService
{
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly AssetDualUploadService $uploadService,
        private readonly AssetStockConverter $converter1b,
        private readonly AssetUitReader $reader1a,
        private readonly PublicDnsResolver $dnsResolver,
    ) {}

    /**
     * Jalankan sinkronisasi data aset dari Google Sheets.
     *
     * @param  bool|User  $dryRunOrUser  Jika true mode dry-run, atau instance User untuk atribusi dataset
     * @param  string|null  $period  Periode data eksplisit Y-m-d (opsional)
     * @param  User|null  $user  Pengguna untuk atribusi dataset/audit (opsional, fallback ke Admin)
     * @return array{status: string, period: string, rows_1a: int, rows_1b: int, total_rows: int, dataset: ?Dataset}
     *
     * @throws AssetSyncException
     */
    public function sync(bool|User $dryRun = false, ?string $period = null, ?User $user = null): array
    {
        if ($dryRun instanceof User) {
            $user = $dryRun;
            $dryRun = false;
        } else {
            $dryRun = (bool) $dryRun;
        }

        // 1. Dynamic division resolution via slug (dilarang hardcode ID)
        $assetDivisionId = Division::where('slug', 'managemen-aset')->value('id');
        if (! $assetDivisionId) {
            throw new AssetSyncException("Divisi 'managemen-aset' tidak ditemukan di database.");
        }

        // 2. User resolution
        $uploader = $user ?? User::whereRelation('role', 'slug', Role::ADMIN)->first();
        if (! $uploader) {
            throw new AssetSyncException('Tidak dapat menemukan pengguna Admin untuk atribusi impor dataset aset.');
        }

        // 3. Konfigurasi kredensial Google Sheets
        $sheetId = (string) config('asset.sheet_id');
        $gid1a = (string) config('asset.sheet_gid_1a');
        $gid1b = (string) config('asset.sheet_gid_1b');

        if (empty($sheetId) || empty($gid1a) || empty($gid1b)) {
            throw new AssetSyncException('Konfigurasi Google Sheets (ASET_SHEET_ID, ASET_SHEET_GID_1A, ASET_SHEET_GID_1B) belum lengkap.');
        }

        $url1a = $this->buildExportUrl($sheetId, $gid1a);
        $url1b = $this->buildExportUrl($sheetId, $gid1b);

        $tempFiles = [];

        try {
            // 4. Unduh payload CSV dari Google Sheets
            try {
                $raw1a = $this->fetchCsv($url1a);
                $raw1b = $this->fetchCsv($url1b);
            } catch (AssetSyncException $e) {
                $this->recordFailedDataset((int) $assetDivisionId, $uploader->id, $e->getMessage());
                throw $e;
            } catch (Throwable $e) {
                $sanitized = AssetSyncException::sanitize($e->getMessage());
                $this->recordFailedDataset((int) $assetDivisionId, $uploader->id, "Unexpected fetch failure: {$sanitized}");
                throw $e;
            }

            // 5. Simpan ke berkas sementara dengan ekstensi .csv
            $temp1a = tempnam(sys_get_temp_dir(), 'asset_sync_1a_').'.csv';
            $temp1b = tempnam(sys_get_temp_dir(), 'asset_sync_1b_').'.csv';
            $tempFiles = [$temp1a, $temp1b];

            file_put_contents($temp1a, $this->sanitizeCsvNumbers($raw1a));
            file_put_contents($temp1b, $this->sanitizeCsvNumbers($raw1b));

            // 6. Pre-flight Header Validation & Parsing
            $targetPeriod = $period ?: config('asset.period');

            try {
                $rows1a = $this->reader1a->read($temp1a, $targetPeriod);
                $resolvedPeriod = $targetPeriod ?? ($rows1a[0]['period'] ?? $rows1a[0][2] ?? null);

                if (empty($rows1a)) {
                    throw new RuntimeException('Tab 1a kosong atau tidak memuat baris data yang valid.');
                }

                $rows1b = $this->converter1b->extract($temp1b, $resolvedPeriod);

                if (empty($rows1b)) {
                    throw new RuntimeException('Tab 1b kosong atau tidak memuat baris data yang valid.');
                }
            } catch (RuntimeException $e) {
                $sanitized = AssetSyncException::sanitize($e->getMessage());
                $this->recordFailedDataset((int) $assetDivisionId, $uploader->id, "Header mismatch or structure failure: {$sanitized}");
                throw new AssetSyncException("Sanity guard failure: {$sanitized}", 0, $e);
            }

            // Hitung deduplikasi metrik (seperti di AssetDualUploadService)
            $deduped = [];
            foreach ($rows1b as $row) {
                $key = mb_strtolower((string) $row[0]).'|'.((string) $row[2]);
                $deduped[$key] = true;
            }
            foreach ($rows1a as $row) {
                $name = (string) ($row['metric_name'] ?? $row[0]);
                $p = (string) ($row['period'] ?? $row[2]);
                $deduped[mb_strtolower($name).'|'.$p] = true;
            }
            $newCount = count($deduped);

            // 7. Sanity Guard: Row Count Drop Guard (>20% drop dibanding dataset READY terakhir)
            $previousReadyDataset = Dataset::where('division_id', $assetDivisionId)
                ->where('status', Dataset::STATUS_READY)
                ->latest('id')
                ->first();

            $priorCount = $previousReadyDataset ? $previousReadyDataset->metrics()->count() : 0;

            if ($priorCount > 0 && $newCount < (int) floor($priorCount * 0.80)) {
                $retentionPercent = round(($newCount / $priorCount) * 100, 1);
                $dropPercent = round(100 - $retentionPercent, 1);
                $msg = "Sanity guard failure: Metric row count dropped by {$dropPercent}% (previous: {$priorCount}, new: {$newCount}). Minimum retention threshold is 80% of prior READY dataset.";
                $this->recordFailedDataset((int) $assetDivisionId, $uploader->id, $msg);
                throw new AssetSyncException($msg);
            }

            // 8. Sanity Guard: Cross-Check Reconciliation (1a vs sum of 1b ULTGs)
            try {
                $this->reconcileCrossCheck($rows1a, $rows1b);
            } catch (AssetSyncException $e) {
                $this->recordFailedDataset((int) $assetDivisionId, $uploader->id, $e->getMessage());
                throw $e;
            }

            // 9. Dry Run Guard: Jika dry-run, jangan simpan dataset, kembalikan status DRY_RUN
            if ($dryRun) {
                return [
                    'status' => 'DRY_RUN',
                    'period' => $resolvedPeriod,
                    'rows_1a' => count($rows1a),
                    'rows_1b' => count($rows1b),
                    'total_rows' => $newCount,
                    'dataset' => null,
                ];
            }

            // 10. Delegasi Ingestion ke AssetDualUploadService
            $uploadResult = $this->uploadService->upload($temp1b, $temp1a, $uploader, $resolvedPeriod);

            return [
                'status' => 'SUCCESS',
                'period' => $resolvedPeriod,
                'rows_1a' => $uploadResult['rows_1a'],
                'rows_1b' => $uploadResult['rows_1b'],
                'total_rows' => $uploadResult['total_rows'],
                'dataset' => $uploadResult['dataset'],
            ];
        } finally {
            foreach ($tempFiles as $tempFile) {
                if (file_exists($tempFile)) {
                    @unlink($tempFile);
                }
            }
        }
    }

    /**
     * Bangun URL Google Sheets CSV export dengan validasi host ketat.
     */
    public function buildExportUrl(string $sheetId, string $gid): string
    {
        if (! preg_match('/^[a-zA-Z0-9_\-]+$/', $sheetId) || ! preg_match('/^[0-9]+$/', $gid)) {
            throw new AssetSyncException('Format ID spreadsheet atau GID Google Sheets tidak valid.');
        }

        $url = "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";
        $this->validateUrl($url);

        return $url;
    }

    /**
     * Validasi skema, host, port, dan ketiadaan userinfo URL awal untuk mencegah SSRF.
     */
    public function validateUrl(string $url): void
    {
        if ($this->parseValidHttpsHost($url) !== 'docs.google.com') {
            throw new AssetSyncException('Host atau skema URL tidak diizinkan. Hanya HTTPS ke docs.google.com yang diperbolehkan.');
        }
    }

    /**
     * Validasi URL tujuan redirect agar hanya mengarah ke docs.google.com atau subdomain *.googleusercontent.com via HTTPS.
     */
    private function validateRedirectUrl(string $url): void
    {
        $host = $this->parseValidHttpsHost($url);

        if ($host === null || ! $this->isAllowedRedirectHost($host)) {
            throw new AssetSyncException('Redirect ke host atau skema yang tidak diizinkan.');
        }
    }

    private function parseValidHttpsHost(string $url): ?string
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = $parts['port'] ?? null;
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '.'));

        if ($scheme !== 'https'
            || $host === ''
            || ($port !== null && (int) $port !== 443)
        ) {
            return null;
        }

        return $host;
    }

    private function isAllowedRedirectHost(string $host): bool
    {
        if ($host === 'docs.google.com') {
            return true;
        }

        if ($host === '' || str_contains($host, '..') || preg_match('/^[a-z0-9.\-]+$/', $host) !== 1) {
            return false;
        }

        return strlen($host) > strlen('.googleusercontent.com')
            && str_ends_with($host, '.googleusercontent.com');
    }

    private function resolveRedirectTarget(string $currentUrl, string $location): string
    {
        if ($location === '' || preg_match('/[\r\n\x00]/', $location) === 1 || str_starts_with($location, '//')) {
            throw new AssetSyncException('Redirect ke host atau skema yang tidak diizinkan.');
        }

        if (str_starts_with($location, '/')) {
            $host = strtolower((string) parse_url($currentUrl, PHP_URL_HOST));

            return "https://{$host}{$location}";
        }

        return $location;
    }

    private function resolveAndPinHostIp(string $host): string
    {
        $ips = $this->dnsResolver->resolve($host);

        if ($ips === []) {
            throw new AssetSyncException('Resolusi DNS host tujuan gagal atau tidak menghasilkan alamat IP yang valid.');
        }

        foreach ($ips as $ip) {
            if (! $this->dnsResolver->isPublicIp($ip)) {
                throw new AssetSyncException('Resolusi DNS host tujuan ditolak karena mengarah ke alamat IP non-publik.');
            }
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ip;
            }
        }

        $first = $ips[0];

        return str_contains($first, ':') ? "[{$first}]" : $first;
    }

    /**
     * Eksekusi HTTP GET untuk mengunduh berkas CSV dengan validasi redirect, DNS publik, IP pinning, timeout, dan Content-Type check.
     */
    private function fetchCsv(string $url): string
    {
        $this->validateUrl($url);

        $timeout = (int) config('asset.timeout', 15);
        $retries = (int) config('asset.retries', 2);
        $currentUrl = $url;
        $response = null;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if ($hop > 0) {
                $this->validateRedirectUrl($currentUrl);
            }

            $host = strtolower((string) parse_url($currentUrl, PHP_URL_HOST));
            $pinIp = $this->resolveAndPinHostIp($host);

            $options = ['allow_redirects' => false];
            if (defined('CURLOPT_RESOLVE')) {
                $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:443:{$pinIp}"]];
            }

            try {
                $response = Http::timeout($timeout)
                    ->connectTimeout(5)
                    ->withOptions($options)
                    ->retry($retries, 500, function ($exception) {
                        return $exception instanceof ConnectionException
                            || ($exception instanceof RequestException && $exception->response->serverError());
                    }, throw: false)
                    ->get($currentUrl);
            } catch (ConnectionException|RequestException $e) {
                $sanitized = AssetSyncException::sanitize($e->getMessage());
                throw new AssetSyncException("Gagal menghubungi Google Sheets: {$sanitized}", 0, $e);
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                if ($hop >= self::MAX_REDIRECTS) {
                    throw new AssetSyncException('Jumlah HTTP redirect melebihi batas maksimum (3).');
                }

                $location = trim((string) $response->header('Location'));
                if ($location === '') {
                    throw new AssetSyncException('Respons HTTP redirect tidak menyertakan header Location yang valid.');
                }

                $currentUrl = $this->resolveRedirectTarget($currentUrl, $location);
                $this->validateRedirectUrl($currentUrl);

                continue;
            }

            break;
        }

        if ($response === null || $response->status() !== 200) {
            $status = $response?->status() ?? 0;
            throw new AssetSyncException("Gagal mengunduh berkas Google Sheets: HTTP status {$status}.");
        }

        $contentType = (string) $response->header('Content-Type');
        if (! str_contains($contentType, 'text/csv') && ! str_contains($contentType, 'text/plain')) {
            throw new AssetSyncException("Respons Google Sheets bukan format CSV yang valid (Content-Type: {$contentType}).");
        }

        return $response->body();
    }

    /**
     * Hilangkan pemisah ribuan (koma) pada angka berkutip di CSV agar dapat dibaca sebagai angka numerik.
     */
    private function sanitizeCsvNumbers(string $csv): string
    {
        return (string) preg_replace_callback('/"(\s*\d{1,3}(?:,\d{3})*(?:\.\d+)?\s*)"/', function ($matches) {
            return trim(str_replace([',', ' '], '', $matches[1]));
        }, $csv);
    }

    /**
     * Rekonsiliasi cross-check antara Tab 1a (total UIT) dan Tab 1b (jumlah seluruh ULTG).
     *
     * @param  array<int, array<string, mixed>>  $rows1a
     * @param  array<int, array{0: string, 1: float, 2: string}>  $rows1b
     *
     * @throws AssetSyncException
     */
    private function reconcileCrossCheck(array $rows1a, array $rows1b): void
    {
        // Deteksi apakah Tab 1b memuat trafo 150/20
        $hasTrafo150_20In1b = false;
        foreach ($rows1b as $row) {
            if (str_contains((string) ($row[0] ?? ''), '150/20')) {
                $hasTrafo150_20In1b = true;
                break;
            }
        }

        $totals1a = [
            'Tower' => 0.0,
            'Transmisi' => 0.0,
            'Trafo MVA' => 0.0,
        ];

        foreach ($rows1a as $row) {
            $name = (string) ($row['metric_name'] ?? $row[0] ?? '');
            $val = (float) ($row['value'] ?? $row[1] ?? 0.0);

            if (str_contains($name, 'Tower')) {
                $totals1a['Tower'] += $val;
            } elseif (str_contains($name, 'Transmisi') && (str_contains(strtolower($name), 'kms') || str_contains($name, 'SU'))) {
                $totals1a['Transmisi'] += $val;
            } elseif (str_contains($name, 'Trafo') && str_contains($name, 'MVA')) {
                if (! str_contains($name, '150/20') || $hasTrafo150_20In1b) {
                    $totals1a['Trafo MVA'] += $val;
                }
            }
        }

        $sums1b = [
            'Tower' => 0.0,
            'Transmisi' => 0.0,
            'Trafo MVA' => 0.0,
        ];

        foreach ($rows1b as $row) {
            $name = (string) ($row[0] ?? '');
            $val = (float) ($row[1] ?? 0.0);

            if (str_contains($name, 'Tower')) {
                $sums1b['Tower'] += $val;
            } elseif (str_contains($name, 'Transmisi') && str_contains(strtolower($name), 'kms')) {
                $sums1b['Transmisi'] += $val;
            } elseif (str_contains($name, 'Trafo') && str_contains($name, 'MVA')) {
                $sums1b['Trafo MVA'] += $val;
            }
        }

        foreach (['Tower', 'Transmisi', 'Trafo MVA'] as $category) {
            $val1a = $totals1a[$category];
            $sum1b = $sums1b[$category];

            if ($val1a > 0.0 || $sum1b > 0.0) {
                $delta = abs($sum1b - $val1a);
                if ($delta > 0.001) {
                    throw new AssetSyncException(
                        "Sanity guard failure: Cross-check reconciliation mismatch for '{$category}'. Tab 1a: {$val1a}, Tab 1b sum: {$sum1b}, delta: {$delta}."
                    );
                }
            }
        }
    }

    /**
     * Buat record Dataset dengan status STATUS_FAILED dan pesan error yang disanitasi.
     */
    private function recordFailedDataset(int $divisionId, int $userId, string $errorMessage): Dataset
    {
        return Dataset::create([
            'division_id' => $divisionId,
            'user_id' => $userId,
            'filename' => 'sync_failed_'.Str::random(16).'.csv',
            'original_name' => 'Google Sheets Sync (Failed)',
            'mime_type' => 'text/csv',
            'size' => 0,
            'status' => Dataset::STATUS_FAILED,
            'error_message' => AssetSyncException::sanitize($errorMessage),
        ]);
    }
}

<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Reader rekapitulasi data aset register UIT JBT (Tab 1a).
 *
 * Membaca data tingkat UIT JBT secara read-only dari file CSV atau XLSX.
 * Mengabaikan blok perbandingan tahunan (annual blocks) dan hanya mengekstrak
 * satu kolom bulan yang dikonfigurasi (misal 's.d. OKT').
 *
 * Format metric_name yang dihasilkan:
 * "ASET | UIT | {kelompok} | {satuan}"
 */
class AssetUitReader
{
    public const PREFIX = 'ASET | UIT | ';

    /**
     * Ekstrak data aset tingkat UIT dari berkas Tab 1a.
     *
     * @param  string  $sourcePath  Path ke file CSV atau XLSX
     * @param  string|null  $period  Periode Y-m-d (mis. '2026-10-31'). Jika null, dideteksi dari judul.
     * @param  string|null  $monthLabel  Label bulan (mis. 's.d. OKT', 'OKT'). Jika null, dideteksi dari period.
     * @param  int|null  $targetYear  Tahun data (mis. 2026). Jika null, dideteksi dari period.
     * @return array<int, array{metric_name: string, value: float, period: string, 0: string, 1: float, 2: string}>
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function read(
        string $sourcePath,
        ?string $period = null,
        ?string $monthLabel = null,
        ?int $targetYear = null
    ): array {
        if (! file_exists($sourcePath)) {
            throw new InvalidArgumentException("Berkas Tab 1a tidak ditemukan: {$sourcePath}");
        }

        $rows = $this->loadRows($sourcePath);

        if (count($rows) < 6) {
            throw new RuntimeException("Struktur berkas Tab 1a tidak valid: jumlah baris tidak mencukupi ({$sourcePath}).");
        }

        // Resolusi periode Y-m-d
        $parsedPeriod = $this->resolvePeriod($rows, $period);

        // Resolusi tahun target
        $targetYear ??= (int) Carbon::parse($parsedPeriod)->format('Y');

        // Resolusi label bulan target
        $monthPattern = $this->buildMonthPattern($parsedPeriod, $monthLabel);

        // Cari baris header tahun dan bulan
        [$yearRowIdx, $monthRowIdx] = $this->locateHeaderRows($rows, $targetYear);

        // Cari kolom bulan target di bawah tahun target
        $targetColIdx = $this->locateTargetMonthColumn(
            $rows[$yearRowIdx],
            $rows[$monthRowIdx],
            $targetYear,
            $monthPattern,
            $monthLabel
        );

        return $this->extractDataRows($rows, $monthRowIdx + 1, $targetColIdx, $parsedPeriod);
    }

    /**
     * Alias untuk method read() agar selaras dengan konvensi converter.
     *
     * @return array<int, array{metric_name: string, value: float, period: string, 0: string, 1: float, 2: string}>
     */
    public function extract(
        string $sourcePath,
        ?string $period = null,
        ?string $monthLabel = null,
        ?int $targetYear = null
    ): array {
        return $this->read($sourcePath, $period, $monthLabel, $targetYear);
    }

    /**
     * Muat baris-baris dari file CSV atau Excel.
     *
     * @return array<int, array<int, mixed>>
     */
    private function loadRows(string $sourcePath): array
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            $handle = fopen($sourcePath, 'r');
            if ($handle === false) {
                throw new InvalidArgumentException("Gagal membuka berkas CSV: {$sourcePath}");
            }

            $rows = [];
            while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rows[] = $data;
            }
            fclose($handle);

            return $rows;
        }

        $reader = IOFactory::createReaderForFile($sourcePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($sourcePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    /**
     * Resolusi periode efektif Y-m-d dari parameter atau subtitle header.
     */
    private function resolvePeriod(array $rows, ?string $period): string
    {
        if ($period !== null) {
            return Carbon::parse($period)->format('Y-m-d');
        }

        // Cek baris 1-4 untuk mendeteksi teks periode seperti 'sd OKTOBER 2026'
        $monthMap = [
            'januari' => 1, 'jan' => 1,
            'februari' => 2, 'feb' => 2,
            'maret' => 3, 'mar' => 3,
            'april' => 4, 'apr' => 4,
            'mei' => 5,
            'juni' => 6, 'jun' => 6,
            'juli' => 7, 'jul' => 7,
            'agustus' => 8, 'agu' => 8, 'ags' => 8,
            'september' => 9, 'sep' => 9,
            'oktober' => 10, 'okt' => 10,
            'november' => 11, 'nop' => 11, 'nov' => 11,
            'desember' => 12, 'des' => 12,
        ];

        for ($r = 0; $r < min(5, count($rows)); $r++) {
            $lineText = implode(' ', array_filter(array_map('strval', $rows[$r])));

            if (preg_match('/s\.?d\.?\s+([A-Za-z]+)\s+(\d{4})/i', $lineText, $matches)) {
                $mName = strtolower($matches[1]);
                $yVal = (int) $matches[2];

                if (isset($monthMap[$mName])) {
                    return Carbon::createFromDate($yVal, $monthMap[$mName], 1)
                        ->endOfMonth()
                        ->format('Y-m-d');
                }
            }
        }

        throw new RuntimeException('Periode tidak dapat dideteksi dari header Tab 1a. Berikan parameter period secara eksplisit.');
    }

    /**
     * Susun regex pola pencarian kolom bulan target.
     */
    private function buildMonthPattern(string $parsedPeriod, ?string $monthLabel): string
    {
        if ($monthLabel !== null && trim($monthLabel) !== '') {
            $clean = preg_replace('/^s\.?d\.?\s*/i', '', trim($monthLabel));

            return '/s\.?\s*d\.?\s*'.preg_quote($clean, '/').'/i';
        }

        $monthNum = (int) Carbon::parse($parsedPeriod)->format('n');
        $codeMap = [
            1 => 'JAN', 2 => 'FEB', 3 => 'MAR', 4 => 'APR',
            5 => 'MEI', 6 => 'JUN', 7 => 'JUL', 8 => 'AGU',
            9 => 'SEP', 10 => 'OKT', 11 => 'NOP|NOV', 12 => 'DES',
        ];

        $code = $codeMap[$monthNum] ?? 'OKT';

        return '/s\.?\s*d\.?\s*('.$code.')/i';
    }

    /**
     * Cari posisi baris header tahun dan bulan.
     *
     * @return array{0: int, 1: int}
     */
    private function locateHeaderRows(array $rows, int $targetYear): array
    {
        $maxScan = min(15, count($rows) - 1);
        $yearStr = (string) $targetYear;

        for ($r = 0; $r < $maxScan; $r++) {
            $row = $rows[$r];
            $hasYear = false;

            foreach ($row as $val) {
                if (trim((string) $val) === $yearStr) {
                    $hasYear = true;
                    break;
                }
            }

            if ($hasYear && isset($rows[$r + 1])) {
                // Periksa apakah baris berikutnya mengandung label s.d. bulan
                $nextRow = $rows[$r + 1];
                foreach ($nextRow as $val) {
                    if (preg_match('/s\.?\s*d\.?\s*[A-Za-z]+/i', (string) $val)) {
                        return [$r, $r + 1];
                    }
                }
            }
        }

        throw new RuntimeException("Header untuk tahun {$targetYear} tidak ditemukan pada Tab 1a.");
    }

    /**
     * Cari kolom bulan target di bawah tahun target, mengecualikan blok tahunan.
     */
    private function locateTargetMonthColumn(
        array $yearRow,
        array $monthRow,
        int $targetYear,
        string $monthPattern,
        ?string $monthLabel
    ): int {
        $yearStr = (string) $targetYear;
        $yearColStart = null;
        $yearColEnd = null;

        // Cari letak kolom awal tahun target
        foreach ($yearRow as $colIdx => $val) {
            if (trim((string) $val) === $yearStr) {
                $yearColStart = $colIdx;
                break;
            }
        }

        if ($yearColStart === null) {
            throw new RuntimeException("Kolom untuk tahun {$targetYear} tidak ditemukan.");
        }

        // Cari batas akhir rentang tahun target (sampai tahun/label berikutnya di yearRow)
        $totalCols = max(count($yearRow), count($monthRow));
        for ($c = $yearColStart + 1; $c < $totalCols; $c++) {
            $val = trim((string) ($yearRow[$c] ?? ''));
            if ($val !== '') {
                $yearColEnd = $c - 1;
                break;
            }
        }
        $yearColEnd ??= $totalCols - 1;

        // Scan baris bulan di antara $yearColStart dan $yearColEnd
        for ($c = $yearColStart; $c <= $yearColEnd; $c++) {
            $mVal = trim((string) ($monthRow[$c] ?? ''));

            if ($mVal === '') {
                continue;
            }

            // Abaikan kolom ringkasan tahunan (misal '2020', '2021', dst)
            if (preg_match('/^\d{4}$/', $mVal)) {
                continue;
            }

            if (preg_match($monthPattern, $mVal)
                || ($monthLabel !== null && strcasecmp($mVal, $monthLabel) === 0)
            ) {
                return $c;
            }
        }

        $labelInfo = $monthLabel ?? 'pola bulan target';
        throw new RuntimeException("Kolom bulan '{$labelInfo}' di bawah tahun {$targetYear} tidak ditemukan pada Tab 1a.");
    }

    /**
     * Ekstrak baris-baris data dari tabel.
     *
     * @return array<int, array{metric_name: string, value: float, period: string, 0: string, 1: float, 2: string}>
     */
    private function extractDataRows(
        array $rows,
        int $startRowIdx,
        int $targetColIdx,
        string $period
    ): array {
        $records = [];
        $currentKelompok = null;

        for ($r = $startRowIdx; $r < count($rows); $r++) {
            $row = $rows[$r];

            $colA = trim((string) ($row[0] ?? ''));
            $colB = trim((string) ($row[1] ?? ''));

            if ($colA !== '') {
                $currentKelompok = $colA;
            }

            if ($currentKelompok === null || $currentKelompok === '') {
                continue;
            }

            $rawVal = $row[$targetColIdx] ?? null;
            if ($rawVal === null) {
                continue;
            }

            $valStr = trim((string) $rawVal);

            // Lewati sel kosong, strip/dash, atau error (#DIV/0!, #REF!, dll)
            if ($valStr === '' || $valStr === '-' || str_starts_with($valStr, '#')) {
                continue;
            }

            // Bersihkan ribuan koma dan spasi
            $compact = str_replace([',', ' '], '', $valStr);

            if (! is_numeric($compact)) {
                continue;
            }

            $value = (float) $compact;
            $satuan = $colB;

            $metricName = $satuan !== ''
                ? self::PREFIX."{$currentKelompok} | {$satuan}"
                : self::PREFIX.$currentKelompok;

            $records[] = [
                'metric_name' => $metricName,
                'value' => $value,
                'period' => $period,
                0 => $metricName,
                1 => $value,
                2 => $period,
            ];
        }

        return $records;
    }
}

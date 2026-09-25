<?php

namespace App\Console\Commands;

use App\Services\AssetCsvConverter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('aset:csv-to-xlsx {source : Direktori berisi CSV ekspor aset} {--output= : Direktori keluaran XLSX (default: storage/app/asset-xlsx)}')]
#[Description('Konversi verbatim CSV ekspor aset menjadi XLSX satu sheet per berkas, tanpa mengubah data')]
class ConvertAssetCsv extends Command
{
    /** Memory limit minimum untuk register besar (DATA TOWER ~20rb baris). */
    private const MEMORY_NEEDED = '2G';

    /**
     * Jalankan konversi CSV -> XLSX (Faza A arsitektur aset, task.md 3.0f).
     *
     * Alur jalur REKAP: CSV ekspor workbook aset -> XLSX verbatim (command
     * ini) -> `aset:convert` -> template generik -> upload `datasets.store`
     * (divisi Data Aset). Sengaja tidak ada endpoint/route baru — command
     * hanya menyiapkan berkas; data tidak diubah (konversi format saja).
     */
    public function handle(AssetCsvConverter $converter): int
    {
        $source = (string) $this->argument('source');

        if (! is_dir($source)) {
            $this->components->error("Direktori sumber tidak ditemukan: {$source}");

            return self::FAILURE;
        }

        $output = (string) $this->option('output');
        $output = $output !== '' ? $output : storage_path('app/asset-xlsx');

        $this->raiseMemoryLimit();

        try {
            $results = $converter->convertDirectory($source, $output);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $result) {
            $this->components->info(sprintf(
                '%s: %d baris, %d sel numerik (sha256 %s)',
                $result['source'],
                $result['rows'],
                $result['numeric_cells'],
                $result['sha256'],
            ));
        }

        $this->components->info(count($results).' file dikonversi ke '.$output);

        return self::SUCCESS;
    }

    /**
     * Naikkan memory limit hanya bila belum cukup; jangan diturunkan
     * (limit -1/unlimited atau sudah lebih besar dibiarkan).
     */
    private function raiseMemoryLimit(): void
    {
        $current = (string) ini_get('memory_limit');

        if ($current === '' || $current === '-1') {
            return;
        }

        if ($this->toBytes($current) >= $this->toBytes(self::MEMORY_NEEDED)) {
            return;
        }

        ini_set('memory_limit', self::MEMORY_NEEDED);
    }

    /**
     * Konversi notasi shorthand PHP ("512M", "2G") menjadi byte.
     */
    private function toBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtoupper(substr($value, -1));
        $n = (int) $value;

        return match ($unit) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }
}

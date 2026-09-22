<?php

namespace App\Console\Commands;

use App\Services\AssetStockConverter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

#[Signature('aset:convert {file : Path rekap aset KIN26 - STOP ONLY (.xlsx)} {--period= : Periode data format Y-m-d, mis. 2026-08-31} {--output= : Path file keluaran}')]
#[Description('Konversi rekap aset KIN26 - STOP ONLY menjadi template impor generik (metric_name, value, period)')]
class ConvertAssetReport extends Command
{
    /**
     * Jalankan konversi rekap aset ke file template yang bisa diunggah UI.
     *
     * Alur: file hasil command ini diunggah lewat form upload dataset yang
     * sudah ada (datasets.store) dengan divisi Data Aset dipilih manual.
     * Sengaja tidak ada endpoint/route baru — command hanya menyiapkan file.
     */
    public function handle(AssetStockConverter $converter): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->components->error("Berkas tidak ditemukan: {$file}");

            return self::FAILURE;
        }

        $period = (string) $this->option('period');

        if (! $this->isValidPeriod($period)) {
            $this->components->error('--period wajib diisi format Y-m-d, mis. --period=2026-08-31');

            return self::FAILURE;
        }

        try {
            $rows = $converter->extract($file, $period);
        } catch (RuntimeException $e) {
            // Layout rekap berubah: gagal terang-terangan, jangan menebak.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->components->error('Tidak ada baris yang bisa diekstrak. Cek struktur berkas.');

            return self::FAILURE;
        }

        $this->components->info(count($rows)." baris diekstrak untuk periode {$period}.");

        $path = $converter->write($rows);

        $output = (string) $this->option('output');

        if ($output !== '') {
            if (! @rename($path, $output)) {
                @unlink($path);
                $this->components->error("Gagal menulis berkas keluaran: {$output}");

                return self::FAILURE;
            }

            $path = $output;
        }

        $this->components->info("Template siap diunggah lewat menu Upload Dataset: {$path}");

        return self::SUCCESS;
    }

    /**
     * Periode harus tanggal Y-m-d yang benar-benar ada (anti silent-rolling).
     */
    private function isValidPeriod(string $period): bool
    {
        if ($period === '') {
            return false;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $period);
        } catch (Throwable) {
            return false;
        }

        return $date !== false && $date->format('Y-m-d') === $period;
    }
}

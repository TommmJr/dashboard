<?php

namespace App\Console\Commands;

use App\Services\KpiReportConverter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

#[Signature('kpi:convert {file : Path laporan KPI (.xlsx)} {--period= : Periode data format Y-m-d, mis. 2026-08-31} {--unit=* : Batasi ke kode unit tertentu (boleh diulang, mis. --unit=TJBT)} {--output= : Path file keluaran (satu berkas gabungan)} {--split= : Tulis satu berkas per unit ke direktori ini}')]
#[Description('Konversi laporan KPI hierarkis menjadi template impor generik (metric_name, value, period)')]
class ConvertKpiReport extends Command
{
    /**
     * Jalankan konversi laporan KPI ke file template yang bisa diunggah UI.
     */
    public function handle(KpiReportConverter $converter): int
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

        $units = array_values(array_filter(
            (array) $this->option('unit'),
            fn ($unit) => is_string($unit) && $unit !== '',
        ));

        try {
            $rows = $converter->extract($file, $period, $units);
        } catch (RuntimeException $e) {
            // Layout laporan berubah: gagal terang-terangan, jangan menebak.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->components->error('Tidak ada baris yang bisa diekstrak. Cek kode unit di --unit atau struktur berkas.');

            return self::FAILURE;
        }

        $this->components->info(count($rows)." baris diekstrak untuk periode {$period}.");

        $split = (string) $this->option('split');

        if ($split !== '') {
            if (! is_dir($split) && ! @mkdir($split, 0775, true) && ! is_dir($split)) {
                $this->components->error("Tidak bisa membuat direktori: {$split}");

                return self::FAILURE;
            }

            foreach ($converter->writeSplit($rows, $split) as $unit => $unitPath) {
                $this->line("  {$unit} -> {$unitPath}");
            }

            $this->components->info('Unggah tiap berkas ke divisi unitnya lewat menu Upload Dataset.');

            return self::SUCCESS;
        }

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

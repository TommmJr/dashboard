<?php

namespace Tests\Feature;

use App\Models\PerformanceObservation;
use App\Services\KpiReportConverter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * Ekstraksi KPI terstruktur (Unit 4A): metadata sumber, tipe target/realisasi,
 * dan hierarki baris dipertahankan. Fixture meniru TJBT (layout standar
 * K/L/M/N/O) dan TRANS (bergeser J/K/L/M/N), plus REKAP dan sheet bulan legacy.
 */
class KpiStructuredExtractionTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_hierarki_grup_dan_anak_dari_kolom_nomor(): void
    {
        $rows = $this->tjbt();

        $parent = $this->row($rows, 'TJBT', 10);
        $this->assertTrue($parent['is_group']);
        $this->assertNull($parent['parent_row']);
        $this->assertSame(20.0, $parent['bobot_value']);

        foreach ([11, 12] as $childRow) {
            $child = $this->row($rows, 'TJBT', $childRow);
            $this->assertFalse($child['is_group']);
            $this->assertSame(10, $child['parent_row']);
        }
    }

    public function test_kpi_independen_bernomor_tetapi_bukan_grup(): void
    {
        $row = $this->row($this->tjbt(), 'TJBT', 17);

        // A terisi (3) tetapi C terisi (1): KPI independen, bukan kepala grup.
        $this->assertFalse($row['is_group']);
        $this->assertNull($row['parent_row']);
        $this->assertSame('1', $row['aggregation_code']);
        $this->assertSame(12.0, $row['bobot_value']);
    }

    public function test_parent_tanpa_bobot_dan_anak_berbobot_band(): void
    {
        $rows = $this->tjbt();

        $parent = $this->row($rows, 'TJBT', 35);
        $this->assertTrue($parent['is_group']);
        $this->assertNull($parent['bobot']);
        $this->assertNull($parent['bobot_value']);

        foreach ([36, 37, 38] as $childRow) {
            $child = $this->row($rows, 'TJBT', $childRow);
            $this->assertFalse($child['is_group']);
            $this->assertSame(35, $child['parent_row']);
            $this->assertSame('Max -3', $child['bobot']);
            $this->assertNull($child['bobot_value']);
            // Band "Max -3" ambigu, jadi batas TIDAK diarang.
            $this->assertNull($child['bobot_min']);
            $this->assertNull($child['bobot_max']);
        }
    }

    public function test_baris_seksi_dan_legenda_tidak_menjadi_baris(): void
    {
        $rows = $this->tjbt();
        $labels = array_column($rows, 'label');

        $this->assertNotContains('PERFORMANCE INDICATORS', $labels);
        $this->assertNotContains('TOTAL BOBOT', $labels);
        $this->assertNotContains('Target Tercapai (Pencapaian >= 100)', $labels);
        $this->assertNotContains('Belum Dilakukan Penilaian', $labels);
        $this->assertNotContains('INDIKATOR KINERJA KUNCI', $labels);
    }

    public function test_target_serial_tanggal_dikenali_sebagai_date(): void
    {
        $target = $this->row($this->tjbt(), 'TJBT', 33)['target'];

        $this->assertSame(KpiReportConverter::TYPE_DATE, $target['type']);
        $this->assertSame('2026-05-31', $target['date']);
        $this->assertNull($target['value']);
    }

    public function test_realisasi_serial_tanggal_dikenali_sebagai_date(): void
    {
        $realisasi = $this->row($this->tjbt(), 'TJBT', 33)['realisasi'];

        $this->assertSame(KpiReportConverter::TYPE_DATE, $realisasi['type']);
        $this->assertSame('2026-05-21', $realisasi['date']);
    }

    public function test_target_dan_realisasi_formula_memakai_cached_value(): void
    {
        $row = $this->row($this->tjbt(), 'TJBT', 11);

        $this->assertSame(KpiReportConverter::TYPE_NUMERIC, $row['target']['type']);
        $this->assertSame(99.5, $row['target']['value']);
        $this->assertSame(99.75, $row['realisasi']['value']);
    }

    public function test_target_teks_dipertahankan_sebagai_text(): void
    {
        $target = $this->row($this->tjbt(), 'TJBT', 12)['target'];

        $this->assertSame(KpiReportConverter::TYPE_TEXT, $target['type']);
        $this->assertSame('Sesuai Buku Prioritas KKP 2025', $target['text']);
        $this->assertNull($target['value']);
    }

    public function test_formula_source_dipertahankan_apa_adanya(): void
    {
        $expected = '= [1 - (Σ (MVA terganggu x waktu) / (MVA x waktu))] x 100%';

        $this->assertSame($expected, $this->row($this->tjbt(), 'TJBT', 11)['formula']);
    }

    public function test_nomor_baris_sumber_dipertahankan(): void
    {
        foreach ($this->tjbt() as $row) {
            $this->assertIsInt($row['row']);
            $this->assertSame('TJBT', $row['org_unit']);
        }

        $this->assertSame(11, $this->row($this->tjbt(), 'TJBT', 11)['row']);
    }

    public function test_sheet_period_dibaca_dari_banner(): void
    {
        $rows = $this->fixture();

        $this->assertSame('S.D. AGUSTUS 2026', $this->row($rows, 'TJBT', 10)['sheet_period']);
        $this->assertSame('S.D. FEBRUARI 2025', $this->row($rows, 'TRANS', 10)['sheet_period']);
    }

    public function test_period_pemanggil_tidak_ditimpa_sheet_period(): void
    {
        $rows = $this->fixture();

        foreach ($rows as $row) {
            $this->assertSame('2026-08', $row['period']);
        }

        // Banner TJBT berbeda dari period; kalau tertimpa, assertion di atas gagal.
        $this->assertSame('S.D. AGUSTUS 2026', $this->row($rows, 'TJBT', 10)['sheet_period']);
    }

    public function test_kode_agregasi_3_berarti_higher_is_better(): void
    {
        $row = $this->row($this->tjbt(), 'TJBT', 11);

        $this->assertSame('3', $row['aggregation_code']);
        $this->assertSame(KpiReportConverter::POLARITY_HIGHER_IS_BETTER, $row['polarity']);
    }

    public function test_kode_agregasi_1_berarti_lower_is_better(): void
    {
        $row = $this->row($this->tjbt(), 'TJBT', 14);

        $this->assertSame('1', $row['aggregation_code']);
        $this->assertSame(KpiReportConverter::POLARITY_LOWER_IS_BETTER, $row['polarity']);
    }

    public function test_total_bobot_tjbt_terverifikasi_90(): void
    {
        // Sumber: F39 = SUM(F10:F38) = 90 pada workbook asli (20+20+12+12+10+0+8+8).
        // NKO (F40=100) adalah konstanta scaling, bukan bobot: dikecualikan.
        $sum = 0.0;
        foreach ($this->tjbt() as $row) {
            if ($row['bobot_value'] === null) {
                continue;
            }
            if (str_contains($row['label'], 'NILAI KINERJA ORGANISASI')) {
                continue;
            }
            $sum += $row['bobot_value'];
        }

        $this->assertSame(90.0, $sum);
    }

    public function test_satuan_raw_dipertahankan(): void
    {
        $rows = $this->tjbt();

        $this->assertSame('%', $this->row($rows, 'TJBT', 11)['satuan']);
        $this->assertSame('jam/100kms', $this->row($rows, 'TJBT', 14)['satuan']);
        $this->assertSame('Dokumen Legal', $this->row($rows, 'TJBT', 30)['satuan']);
    }

    public function test_jenis_formula_pencapaian_diklasifikasikan(): void
    {
        $rows = $this->tjbt();

        $this->assertSame(KpiReportConverter::PENCAPAIAN_FORMULA_AVERAGE, $this->row($rows, 'TJBT', 10)['pencapaian_formula_kind']);
        $this->assertSame(KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO, $this->row($rows, 'TJBT', 11)['pencapaian_formula_kind']);
        $this->assertSame(KpiReportConverter::PENCAPAIAN_FORMULA_DATE_DELTA, $this->row($rows, 'TJBT', 14)['pencapaian_formula_kind']);
    }

    public function test_rekap_dan_sheet_bulan_legacy_dilewati(): void
    {
        $rows = $this->fixture();

        $units = array_unique(array_column($rows, 'org_unit'));

        $this->assertNotContains('REKAP', $units);
        $this->assertNotContains('JAN', $units);
    }

    public function test_kolom_bergeser_trans_dibaca_dari_header(): void
    {
        // TRANS menggeser kolom satu ke kiri: TARGET=J, REALISASI=K,
        // PENCAPAIAN=L, NILAI=M, KET=N. Membaca posisi tetap -> salah senyap.
        $row = $this->row($this->fixture(), 'TRANS', 10);

        $this->assertSame(50.98, $row['target']['value']);
        $this->assertSame(54.76, $row['realisasi']['value']);
        $this->assertSame(10.74, $row['nilai']['value']);
        $this->assertSame(KpiReportConverter::PENCAPAIAN_FORMULA_POLARITY_RATIO, $row['pencapaian_formula_kind']);
        $this->assertSame(KpiReportConverter::TYPE_TEXT, $row['ket']['type']);
        $this->assertSame('Catatan penting', $row['ket']['text']);
    }

    public function test_vocabulary_tipe_selaras_dengan_model_observation(): void
    {
        // Output terstruktur adalah sumber PerformanceObservation; kalau
        // kosakata tipe menyimpang, pemetaan Unit 4B akan salah senyap.
        $this->assertSame(PerformanceObservation::TYPE_NUMERIC, KpiReportConverter::TYPE_NUMERIC);
        $this->assertSame(PerformanceObservation::TYPE_DATE, KpiReportConverter::TYPE_DATE);
        $this->assertSame(PerformanceObservation::TYPE_TEXT, KpiReportConverter::TYPE_TEXT);
        $this->assertSame(PerformanceObservation::TYPE_EMPTY, KpiReportConverter::TYPE_EMPTY);
    }

    public function test_header_hilang_tetap_gagal(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('RUSAK');
        $sheet->setCellValue('B10', 'Indikator Tanpa Kolom');
        $sheet->setCellValue('N10', 40.74);
        $path = $this->save($spreadsheet);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Sheet 'RUSAK'");

        app(KpiReportConverter::class)->extractStructured($path, '2026-08');
    }

    public function test_hasil_terstruktur_konsisten_untuk_seluruh_fixture(): void
    {
        // Kunci bentuk output: setiap baris wajib punya kunci lengkap ini.
        $keys = [
            'org_unit', 'row', 'label', 'aggregation_code', 'polarity', 'formula',
            'satuan', 'bobot', 'bobot_value', 'bobot_min', 'bobot_max', 'is_group',
            'parent_row', 'pencapaian_formula_kind', 'target', 'realisasi',
            'pencapaian', 'nilai', 'ket', 'period', 'sheet_period',
        ];

        foreach ($this->fixture() as $row) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $row);
            }

            foreach (['target', 'realisasi', 'pencapaian', 'nilai', 'ket'] as $typed) {
                $this->assertSame(['value', 'text', 'date', 'type'], array_keys($row[$typed]));
            }
        }
    }

    // ----------------------------------------------------------------- helpers

    /** @return list<array<string, mixed>> */
    private function fixture(): array
    {
        return app(KpiReportConverter::class)->extractStructured($this->makeStructuredReport(), '2026-08');
    }

    /** @return list<array<string, mixed>> */
    private function tjbt(): array
    {
        return array_values(array_filter(
            $this->fixture(),
            fn (array $row): bool => $row['org_unit'] === 'TJBT',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function row(array $rows, string $unit, int $rowNumber): array
    {
        foreach ($rows as $row) {
            if ($row['org_unit'] === $unit && $row['row'] === $rowNumber) {
                return $row;
            }
        }

        $this->fail("Baris {$unit} r{$rowNumber} tidak ditemukan.");
    }

    /**
     * Fixture dua sheet: TJBT (layout standar, hierarki lengkap) dan TRANS
     * (bergeser satu kolom), plus REKAP dan sheet bulan legacy yang dilewati.
     */
    private function makeStructuredReport(): string
    {
        $spreadsheet = new Spreadsheet;

        $rekap = $spreadsheet->getActiveSheet();
        $rekap->setTitle('REKAP');
        $rekap->setCellValue('B3', 'NILAI KINERJA ORGANISASI');
        $rekap->setCellValue('N3', 999);

        $jan = $spreadsheet->createSheet();
        $jan->setTitle('JAN');
        $jan->setCellValue('B10', 'Indikator Bulan Legacy');
        $jan->setCellValue('N10', 5);
        $this->setHeaders($jan, 'K', 'L', 'M', 'N', 'O', 'S.D. JANUARI 2025');

        $tjbt = $spreadsheet->createSheet();
        $tjbt->setTitle('TJBT');
        $tjbt->setCellValue('B6', 'INDIKATOR KINERJA KUNCI');
        $tjbt->setCellValue('D6', 'FORMULA');
        $tjbt->setCellValue('E6', 'SATUAN');
        $tjbt->setCellValue('F6', 'BOBOT');
        $this->setHeaders($tjbt, 'K', 'L', 'M', 'N', 'O', 'S.D. AGUSTUS 2026');

        // Seksi blok, bukan indikator.
        $tjbt->setCellValue('B9', 'PERFORMANCE INDICATORS');

        // Grup + anak berbobot numerik.
        $tjbt->setCellValue('A10', 1);
        $tjbt->setCellValue('B10', 'Faktor Ketersediaan Trafo dan Transmisi');
        $tjbt->setCellValue('F10', 20);
        $tjbt->setCellValue('M10', '=AVERAGE(M11:M12)');
        $tjbt->setCellValue('N10', '=TRUNC((M10*F10),2)');

        $tjbt->setCellValue('B11', 'a. Faktor Ketersediaan Trafo');
        $tjbt->setCellValue('C11', 3);
        $this->text($tjbt, 'D11', '= [1 - (Σ (MVA terganggu x waktu) / (MVA x waktu))] x 100%');
        $tjbt->setCellValue('E11', '%');
        $tjbt->setCellValue('K11', '=I11');
        $tjbt->setCellValue('L11', '=J11');
        $tjbt->setCellValue('I11', 99.5);
        $tjbt->setCellValue('J11', 99.75);
        $tjbt->setCellValue('M11', '=IF($C11=3,L11/K11,2-(L11/K11))');
        $tjbt->setCellValue('O11', '=M11');

        $tjbt->setCellValue('B12', 'b. Faktor Ketersediaan Transmisi');
        $tjbt->setCellValue('C12', 3);
        $tjbt->setCellValue('E12', '%');
        $tjbt->setCellValue('K12', 'Sesuai Buku Prioritas KKP 2025');
        $tjbt->setCellValue('L12', 99.94);
        $tjbt->setCellValue('M12', 1);

        $tjbt->setCellValue('A13', 2);
        $tjbt->setCellValue('B13', 'Keandalan Line Transmisi');
        $tjbt->setCellValue('F13', 20);
        $tjbt->setCellValue('M13', '=AVERAGE(M14:M16)');

        $tjbt->setCellValue('B14', 'a. TLOD');
        $tjbt->setCellValue('C14', 1);
        $tjbt->setCellValue('E14', 'jam/100kms');
        $tjbt->setCellValue('K14', 1.129);
        $tjbt->setCellValue('L14', 1.2);
        $tjbt->setCellValue('M14', '=IF((1-(1%/7*($L14-$K14))<=0),0,1)');

        $tjbt->setCellValue('A17', 3);
        $tjbt->setCellValue('B17', 'Emergency Response Time');
        $tjbt->setCellValue('C17', 1);
        $tjbt->setCellValue('E17', 'Jam');
        $tjbt->setCellValue('F17', 12);
        $tjbt->setCellValue('K17', 3);
        $tjbt->setCellValue('L17', 3.5);
        $tjbt->setCellValue('M17', '=IF($C17=1,2-(L17/K17),L17/K17)');

        $tjbt->setCellValue('A18', 4);
        $tjbt->setCellValue('B18', 'Pengendalian Kinerja Proteksi');
        $tjbt->setCellValue('F18', 12);
        $tjbt->setCellValue('M18', '=AVERAGE(M19:M21)');
        $tjbt->setCellValue('B19', 'a. Security Index');
        $tjbt->setCellValue('C19', 3);
        $tjbt->setCellValue('E19', '%');
        $tjbt->setCellValue('K19', 1);
        $tjbt->setCellValue('L19', 1);
        $tjbt->setCellValue('M19', '=IF($C19=3,L19/K19,2-(L19/K19))');

        $tjbt->setCellValue('A22', 5);
        $tjbt->setCellValue('B22', 'Pengendalian Penggunaan Anggaran');
        $tjbt->setCellValue('F22', 10);
        $tjbt->setCellValue('M22', '=AVERAGE(M23:M26)');
        $tjbt->setCellValue('B23', 'a. Anggaran Operasi');
        $tjbt->setCellValue('C23', 1);
        $tjbt->setCellValue('E23', '%');
        $tjbt->setCellValue('K23', 1);
        $tjbt->setCellValue('L23', 1);
        $tjbt->setCellValue('M23', '=IF($C23=1,2-(L23/K23),L23/K23)');

        $tjbt->setCellValue('A27', 6);
        $tjbt->setCellValue('B27', 'Penyelesaian Tahapan Proyek RUPTL');
        $tjbt->setCellValue('F27', 0);
        $tjbt->setCellValue('M27', '=AVERAGE(M28:M29)');
        $tjbt->setCellValue('B28', 'a. Kajian Kelayakan');
        $tjbt->setCellValue('C28', 3);
        $tjbt->setCellValue('E28', 'Dokumen');
        $tjbt->setCellValue('K28', 1);
        $tjbt->setCellValue('L28', 1);
        $tjbt->setCellValue('M28', '=IF($C28=3,L28/K28,2-(L28/K28))');

        $tjbt->setCellValue('A30', 7);
        $tjbt->setCellValue('B30', 'Penyelesaian Dokumen Legal Aset');
        $tjbt->setCellValue('C30', 3);
        $tjbt->setCellValue('E30', 'Dokumen Legal');
        $tjbt->setCellValue('F30', 8);
        $tjbt->setCellValue('K30', 1);
        $tjbt->setCellValue('L30', 1);
        $tjbt->setCellValue('M30', '=IF($C30=3,L30/K30,2-(L30/K30))');

        $tjbt->setCellValue('A31', 8);
        $tjbt->setCellValue('B31', 'Tata Kelola Aset');
        $tjbt->setCellValue('F31', 8);
        $tjbt->setCellValue('M31', '=AVERAGE(M32:M34)');
        $tjbt->setCellValue('B32', 'a. Maturity Level');
        $tjbt->setCellValue('C32', 3);
        $tjbt->setCellValue('E32', '%');
        $tjbt->setCellValue('K32', 1);
        $tjbt->setCellValue('L32', 1);
        $tjbt->setCellValue('M32', '=IF($C32=3,L32/K32,2-(L32/K32))');

        // Anak dengan target/realisasi berupa serial tanggal.
        $tjbt->setCellValue('B33', 'b. Kualitas Data');
        $tjbt->setCellValue('C33', 1);
        $tjbt->setCellValue('E33', 'Waktu');
        $tjbt->setCellValue('K33', 46173); // 2026-05-31
        $tjbt->setCellValue('L33', 46163); // 2026-05-21
        $tjbt->setCellValue('M33', '=IF($C33=1,2-(L33/K33),L33/K33)');

        // Grup tanpa bobot + anak berbobot band.
        $tjbt->setCellValue('A35', 9);
        $tjbt->setCellValue('B35', 'Kepatuhan, Maturity Level, dan Tata Kelola Perusahaan');
        $tjbt->setCellValue('B36', 'a. Implementasi Maturity Level');
        $tjbt->setCellValue('F36', 'Max -3');
        $tjbt->setCellValue('B37', 'b. Kepatuhan');
        $tjbt->setCellValue('F37', 'Max -3');
        $tjbt->setCellValue('B38', 'c. Tata Kelola Perusahaan');
        $tjbt->setCellValue('F38', 'Max -3');

        $tjbt->setCellValue('B39', 'TOTAL BOBOT');
        $tjbt->setCellValue('F39', '=SUM(F10:F38)');
        $tjbt->setCellValue('B40', 'NILAI KINERJA ORGANISASI (NKO)');
        $tjbt->setCellValue('F40', 100);
        $tjbt->setCellValue('N40', 105.5);

        $tjbt->setCellValue('A42', 'Keterangan :');
        $tjbt->setCellValue('B43', 'Target Tercapai (Pencapaian >= 100)');
        $tjbt->setCellValue('B44', 'Belum Dilakukan Penilaian');

        $trans = $spreadsheet->createSheet();
        $trans->setTitle('TRANS');
        $this->setHeaders($trans, 'J', 'K', 'L', 'M', 'N', 'S.D. FEBRUARI 2025');
        $trans->setCellValue('B9', 'KPI BERSAMA KORPORAT');
        $trans->setCellValue('A10', 1);
        $trans->setCellValue('B10', 'Penjualan Tenaga Listrik');
        $trans->setCellValue('C10', 3);
        $trans->setCellValue('E10', 'TWh');
        $trans->setCellValue('F10', 10);
        $trans->setCellValue('J10', 50.98);
        $trans->setCellValue('K10', 54.76);
        $trans->setCellValue('L10', '=IF($C10=3,K10/J10,2-(K10/J10))');
        $trans->setCellValue('M10', '=TRUNC((L10*F10),2)');
        $trans->setCellValue('N10', 'Catatan penting');
        $trans->setCellValue('B26', 'TOTAL BOBOT');
        $trans->setCellValue('F26', '=+F10');
        $trans->setCellValue('B27', 'NILAI KINERJA ORGANISASI (NKO)');
        $trans->setCellValue('F27', 100);
        $trans->setCellValue('M27', 100.74);

        return $this->save($spreadsheet);
    }

    /**
     * Tulis header dua baris: banner di baris 6 kolom target, label kolom di
     * baris 7 (sama seperti laporan mentor).
     */
    private function setHeaders(Worksheet $sheet, string $target, string $realisasi, string $pencapaian, string $nilai, string $ket, string $banner): void
    {
        $sheet->setCellValue($target.'6', $banner);
        $sheet->setCellValue($target.'7', 'TARGET');
        $sheet->setCellValue($realisasi.'7', 'REALISASI');
        $sheet->setCellValue($pencapaian.'7', 'PENCAPAIAN');
        $sheet->setCellValue($nilai.'7', 'NILAI');
        $sheet->setCellValue($ket.'7', 'KET');
    }

    /**
     * Tulis teks yang diawali "=" sebagai string, bukan formula.
     */
    private function text(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function save(Spreadsheet $spreadsheet): string
    {
        $path = $this->tempFile(tempnam(sys_get_temp_dir(), 'kpi_structured_').'.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(true);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function tempFile(string $path): string
    {
        $this->tempFiles[] = $path;

        return $path;
    }
}

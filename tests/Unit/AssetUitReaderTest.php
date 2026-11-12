<?php

namespace Tests\Unit;

use App\Services\AssetUitReader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AssetUitReaderTest extends TestCase
{
    private AssetUitReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new AssetUitReader;
    }

    public function test_ekstrak_data_valid_dari_fixture_tab1a(): void
    {
        $fixturePath = dirname(__DIR__).'/Fixtures/asset_tab1a_sample.csv';
        $this->assertFileExists($fixturePath);

        $results = $this->reader->read($fixturePath);

        $this->assertNotEmpty($results);

        // Map results by metric_name
        $byName = collect($results)->keyBy('metric_name');

        // Verifikasi prefix ASET | UIT |
        foreach ($results as $row) {
            $this->assertStringStartsWith(AssetUitReader::PREFIX, $row['metric_name']);
            $this->assertSame('2026-10-31', $row['period']);
            $this->assertIsFloat($row['value']);

            // Verifikasi kompatibilitas positional
            $this->assertSame($row['metric_name'], $row[0]);
            $this->assertSame($row['value'], $row[1]);
            $this->assertSame($row['period'], $row[2]);
        }

        // Spot-check kategori utama
        $this->assertTrue($byName->has('ASET | UIT | Trafo 500/150 KV | MVA'));
        $this->assertSame(20500.0, $byName->get('ASET | UIT | Trafo 500/150 KV | MVA')['value']);

        $this->assertTrue($byName->has('ASET | UIT | Transmisi 500 KV | SU (kms)'));
        $this->assertSame(4213.5, $byName->get('ASET | UIT | Transmisi 500 KV | SU (kms)')['value']);

        $this->assertTrue($byName->has('ASET | UIT | Tower 500 KV | SU'));
        $this->assertSame(6336.0, $byName->get('ASET | UIT | Tower 500 KV | SU')['value']);

        // Verifikasi baris dash '-' dilewati
        $this->assertFalse($byName->has('ASET | UIT | Transmisi 500 KV | SK (kms)'));
        $this->assertFalse($byName->has('ASET | UIT | Tower 500 KV | SK'));
    }

    public function test_gagal_jika_berkas_tidak_ada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Berkas Tab 1a tidak ditemukan');

        $this->reader->read('/path/to/non_existent_file.csv');
    }

    public function test_gagal_jika_header_tahun_tidak_ditemukan(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_invalid_year_').'.csv';
        $content = <<<'CSV'
REKAPITULASI DATA ASET REGISTER UIT JBT,,,,
REKONSILIASI DATA ASET REGISTER sd OKTOBER 2026,,,,
,,,,
ASET,,UIT,,
Foo,Bar,Baz,Qux
s.d. JAN,s.d. FEB,s.d. MAR,s.d. APR
Trafo,BANK,10,20
CSV;
        file_put_contents($tempPath, $content);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Header untuk tahun 2026 tidak ditemukan');

            $this->reader->read($tempPath, period: '2026-10-31', targetYear: 2026);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    public function test_gagal_jika_kolom_bulan_tidak_ditemukan(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_missing_month_').'.csv';
        $content = <<<'CSV'
REKAPITULASI DATA ASET REGISTER UIT JBT,,,,
REKONSILIASI DATA ASET REGISTER sd OKTOBER 2026,,,,
,,,,
ASET,,UIT,,
,,2026,,
,,s.d. JAN,s.d. FEB,s.d. MAR
Trafo,BANK,10,20,30
CSV;
        file_put_contents($tempPath, $content);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Kolom bulan');

            $this->reader->read($tempPath, period: '2026-10-31', monthLabel: 's.d. DES', targetYear: 2026);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    public function test_mengabaikan_blok_tahunan_dan_hanya_membaca_kolom_bulan_target(): void
    {
        $fixturePath = dirname(__DIR__).'/Fixtures/asset_tab1a_sample.csv';
        $results = $this->reader->read($fixturePath, period: '2026-10-31', monthLabel: 's.d.OKT', targetYear: 2026);

        $byName = collect($results)->keyBy('metric_name');

        // Tower 500 KV SU pada s.d.OKT adalah 6336.0, sedangkan blok tahunan 2020-2025 bernilai 6000-6320
        $this->assertSame(6336.0, $byName->get('ASET | UIT | Tower 500 KV | SU')['value']);
        $this->assertNotEquals(6000.0, $byName->get('ASET | UIT | Tower 500 KV | SU')['value']);
        $this->assertNotEquals(6320.0, $byName->get('ASET | UIT | Tower 500 KV | SU')['value']);
    }

    public function test_parsing_angka_dengan_koma_dan_melewati_dash_dan_error_cell(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'test_parse_comma_').'.csv';
        $content = <<<'CSV'
REKAPITULASI DATA ASET REGISTER UIT JBT,,,,
REKONSILIASI DATA ASET REGISTER sd OKTOBER 2026,,,,
,,,,
ASET,,UIT,,
,,2026,
,,s.d.OKT,
Trafo 500/150 KV,MVA,"20,500.0",
,Unit,"  1,234 ",
,Bank,-,
,Error,#DIV/0!,
,Ref,#REF!,
Tower,SU,"6,336",
CSV;
        file_put_contents($tempPath, $content);

        try {
            $results = $this->reader->read($tempPath, period: '2026-10-31', monthLabel: 's.d.OKT', targetYear: 2026);

            $this->assertCount(3, $results);

            $byName = collect($results)->keyBy('metric_name');

            $this->assertSame(20500.0, $byName->get('ASET | UIT | Trafo 500/150 KV | MVA')['value']);
            $this->assertSame(1234.0, $byName->get('ASET | UIT | Trafo 500/150 KV | Unit')['value']);
            $this->assertSame(6336.0, $byName->get('ASET | UIT | Tower | SU')['value']);

            // Error cells dan '-' dilewati
            $this->assertFalse($byName->has('ASET | UIT | Trafo 500/150 KV | Bank'));
            $this->assertFalse($byName->has('ASET | UIT | Trafo 500/150 KV | Error'));
            $this->assertFalse($byName->has('ASET | UIT | Trafo 500/150 KV | Ref'));
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }
}

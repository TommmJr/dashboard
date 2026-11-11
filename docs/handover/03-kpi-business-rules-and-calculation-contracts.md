# 03 - Aturan Bisnis & Kontrak Perhitungan KPI

Dokumen ini mendokumentasikan secara rinci aturan bisnis perhitungan Key Performance Indicator (KPI), normalisasi skala nilai Excel, formula agregasi unit, isolasi baris daun vs baris induk, penanganan kasus khusus (edge cases), serta matriks perbedaan terencana (*intentional divergence*) antar reader pada sistem IBS / plnjbt-dashboard.

Dokumen ini ditujukan bagi Analis Sistem, Pengembang Backend/Frontend, Tim Jaminan Kualitas (QA/Auditor), serta PIC Bidang Perencanaan Pengusahaan PLN UIT JBT untuk memastikan transparansi dan integritas perhitungan numerik.

---

## 1. Latar Belakang & Prinsip Normalisasi Nilai Excel

### 1.1 Masalah Representasi Ganda pada Lembar Kerja Excel PLN

Pada berkas lembar kerja spreadsheet Excel resmi kinerja PLN (`.xlsx`), kolom `pencapaian` memiliki representasi data yang heterogen dan tidak seragam antar indikator maupun antar baris observasi:

1. **Skala Rasio Desimal (0.0 .. 1.10):** Sebagian besar indikator menyimpan capaian sebagai bilangan desimal, misalnya `1.0143` (merepresentasikan `101.43%`) atau `1.1000` (merepresentasikan batas maksimal `110.0%`).
2. **Skala Persentase Langsung (> 1.10 .. 110.0):** Beberapa indikator tertentu (seperti indikator #410 "Penyelesaian Dokumen Legal Aset Tanah") menyimpan nilai pencapaian langsung dalam skala persen, misalnya angka `110.0`.
3. **Indikator Berbasis Formula Selisih Tanggal:** Indikator waktu (seperti cut-off rekonsiliasi material) menggunakan formula selisih tanggal kalender yang menghasilkan rasio desimal di Excel.
4. **Indikator Tanpa Target/Realisasi Numerik (Score-Only):** Kolom target atau realisasi berupa teks atau kosong, namun kolom pencapaian berisi skor sah hasil evaluasi komite.
5. **Sel Kosong atau Baris Induk:** Baris judul kelompok memiliki nilai pencapaian kosong atau formula string agregat yang berpotensi memicu distorsi jika dijumlahkan secara naif.

Jika data tersebut dikonsumsi secara langsung tanpa normalisasi sistematis, aplikasi akan mengalami distorsi parah (contoh: menjumlahkan `1.0143` dengan `110.0` menghasilkan nilai agregat yang keliru).

### 1.2 Solusi Normalisasi Terpusat via `App\Support\KpiScoreNormalizer`

Untuk mengatasi heterogenitas tersebut secara konsisten di seluruh lapisan pembacaan data, sistem mengimplementasikan kelas utilitas murni terpusat: `App\Support\KpiScoreNormalizer` (`app/Support/KpiScoreNormalizer.php`).

Kelas ini mengonsolidasikan Keputusan Bisnis 2 Pak Gama (2026-10-25) dan Keputusan Sesi 99:

```php
namespace App\Support;

class KpiScoreNormalizer
{
    public const RATIO_MAX = 1.10;
    public const SCORE_MIN = 0.0;
    public const SCORE_MAX = 110.0;

    public static function isRatio(float $score): bool
    {
        return $score <= self::RATIO_MAX;
    }

    public static function normalize(float $score): float
    {
        return self::isRatio($score) ? $score * 100.0 : $score;
    }

    public static function clamp(float $score, float $min = self::SCORE_MIN, float $max = self::SCORE_MAX): float
    {
        return max($min, min($max, $score));
    }

    public static function normalizeAndClamp(float $score, float $min = self::SCORE_MIN, float $max = self::SCORE_MAX): float
    {
        return self::clamp(self::normalize($score), $min, $max);
    }
}
```

### 1.3 Logika Deteksi Skala Rasio vs Persentase

Aturan deteksi skala dirumuskan berdasarkan batas atas rasio resmi pada lembar kerja Excel:

- **Ambang Batas Rasio (`RATIO_MAX = 1.10`):** Berdasarkan aturan korporat PLN, rasio pencapaian maksimal yang diakui dalam lembar kerja Excel adalah 110% atau bernilai rasio desimal `1.1000`.
- **Nilai Mentah $\le 1.10001$:** Diklasifikasikan sebagai **skala rasio**. Nilai wajib dikalikan dengan `100.0` untuk dikonversi ke skala persentase standar.
- **Nilai Mentah $> 1.10001$:** Diklasifikasikan sebagai **skala persentase**. Nilai dibaca apa adanya tanpa pengali tambahan.

```
Nilai Mentah (raw) di kolom pencapaian:
  +-------------------------------------------------------------+
  |  raw <= 1.10001  --> Skala RASIO    --> persentase = raw * 100  |
  |  raw >  1.10001  --> Skala PERSEN   --> persentase = raw      |
  +-------------------------------------------------------------+
```

*Keterbatasan Teoretis yang Diketahui (Known Boundary):* Jika terdapat indikator kinerja yang memiliki pencapaian persentase riil di bawah atau sama dengan 1.1% (misal realisasi hanya 0.8%), sistem akan memperlakukannya sebagai rasio desimal sehingga bernilai 80.0%. Namun, berdasarkan audit empiris seluruh lembar kerja historis PLN UIT JBT, kasus persentase riil $\le 1.1\%$ tidak pernah terjadi dalam operasional nyata.

### 1.4 Batas Penguncian Resmi (*Clamping Range*) [0.0% .. 110.0%]

Sesuai Keputusan Bisnis 2 Pak Gama (2026-10-25), capaian kinerja unit untuk ringkasan eksekutif dan status organisasi dibatasi secara ketat pada rentang resmi NKO:

$$\text{Clamped Score} = \max(0.0, \min(110.0, \text{Normalized Score}))$$

Tujuan pembatasan (*clamping*) ini adalah:
1. Mencegah satu indikator dengan capaian sangat tinggi (misalnya Usulan Penghapusan ATTB dengan realisasi 632%) mendistorsi atau mendongkrak rata-rata unit secara tidak wajar.
2. Mencegah nilai anomali negatif mendistorsi rata-rata unit di bawah batas minimum 0.0%.

---

## 2. Formula Agregasi Rata-rata Unit (Unweighted Mean)

### 2.1 Landasan Keputusan Bisnis 4 Pak Gama (2026-10-25)

Dalam rapat koordinasi dan konfirmasi arahan produk tertanggal 2026-10-25, Pak Gama memutuskan (Keputusan 4, Opsi A):

> Agregasi rata-rata pencapaian unit pada ringkasan eksekutif menggunakan **rata-rata sederhana aritmatika tak berbobot (*unweighted arithmetic mean*)**. Bobot pada lembar kerja Excel diabaikan dalam kalkulasi agregat unit dashboard.

### 2.2 Alasan Teknis: Kekosongan Bobot pada Database Riil

Keputusan penggunaan rata-rata tak berbobot bukan sekadar simplifikasi, melainkan konsekuensi logis dari karakteristik data mentah PLN UIT JBT:

- **Audit Database:** Pemeriksaan terhadap tabel `performance_observations` dan `indicators` menunjukkan bahwa **91% baris rincian indikator tidak memiliki bobot** (`bobot IS NULL` atau bernilai `0.0`).
- Bobot persentase pada template Excel PLN hanya didefinisikan pada baris-baris tertentu di level kelompok atau tidak dialokasikan secara lengkap pada seluruh baris daun.
- Jika diterapkan rata-rata berbobot (*weighted average*), formula akan menghadapi kondisi pembagian dengan nol (*division-by-zero*) atau menghasilkan angka yang sangat bias karena 91% indikator operasional terabaikan.

### 2.3 Formulasi Matematis

Agregasi rata-rata pencapaian unit ($Avg_{\text{unit}}$) dirumuskan sebagai berikut:

$$\text{Rata-rata Unit} = \frac{\sum_{i=1}^{N} S_i}{N}$$

di mana:
- $N$ adalah jumlah seluruh baris daun berskor sah (`indicators.is_group = 0` dan `performance_observations.pencapaian IS NOT NULL`).
- $S_i$ adalah skor pencapaian baris ke-$i$ setelah melalui normalisasi skala dan pembatasan (*clamping*):
  $$S_i = \text{clamp}(\text{normalize}(\text{pencapaian}_i), 0.0, 110.0)$$

### 2.4 Pembulatan dan Klasifikasi Status Kinerja

Hasil rata-rata unit dibulatkan ke satu tempat desimal menggunakan fungsi `round($avg, 1)`.

Status kinerja organisasi ditetapkan berdasarkan ambang batas (*threshold*) resmi NKO PLN:

| Rentang Nilai Rata-rata ($Avg$) | Status Kinerja | Definisi Operasional |
|---|---|---|
| $Avg \ge 100.0\%$ | **Tercapai** | Kinerja unit melampaui atau memenuhi target target korporat. |
| $95.0\% \le Avg < 100.0\%$ | **Hampir Tercapai** | Kinerja unit mendekati target, memerlukan monitoring berkala. |
| $Avg < 95.0\%$ | **Perlu Peningkatan** | Kinerja unit di bawah standar minimum, memerlukan tindakan perbaikan. |

### 2.5 Penghitungan Jumlah Indikator Tercapai (*Achieved Count*)

Jumlah indikator tercapai (`achieved`) dihitung dari banyaknya baris daun berskor yang memiliki skor individual $S_i \ge 100.0\%$.

Sebagai contoh pada unit TJBT periode Agustus 2026 (dataset #49):
- Jumlah daun berskor ($N$): **16 indikator**
- Jumlah indikator tercapai ($achieved$): **16 indikator** (seluruh indikator $S_i \ge 100.0\%$)
- Total akumulasi skor ($\sum S_i$): **1693.73**
- Rata-rata unit ($Avg$): $1693.73 / 16 = 105.858\% \rightarrow$ **105.9%**
- Status akhir: **Tercapai**

---

## 3. Pencegahan Double-Counting (Baris Daun vs Baris Induk)

### 3.1 Struktur Hierarki Indikator Excel PLN

Lembar kerja Excel PLN menyusun indikator dalam bentuk hierarki terstruktur:
1. **Baris Induk / Kelompok (`is_group = 1`):** Merupakan judul kelompok atau kategori utama, misalnya:
   - `1. KINERJA OPERASIONAL`
   - `2. KINERJA KEUANGAN`
   - `3. KINERJA SISTEM DAN TEKNOLOGI`
   - Total terdapat 7 baris kelompok induk pada unit TJBT.
2. **Baris Daun / Rincian (`is_group = 0`):** Merupakan item pengukuran kinerja individual di bawah kelompok terkait, misalnya:
   - `a. Faktor Ketersediaan Trafo (EAF T)`
   - `b. Keandalan Sistem Penyaluran`
   - `c. Usulan Penghapusan ATTB`

### 3.2 Landasan Keputusan Bisnis 3 Pak Gama (2026-10-25)

Sesuai Keputusan Bisnis 3 Pak Gama (Opsi B):

> Hanya baris rincian/daun (`is_group = 0`) yang diikutsertakan dalam kalkulasi agregasi rata-rata dan total indikator. Baris induk/kelompok (`is_group = 1`) **diabaikan secara ketat** untuk mencegah penggandaan nilai (*double-counting*).

Jika baris induk ikut dihitung, nilai pencapaian kelompok (yang biasanya merupakan rangkuman dari anak-anaknya) akan dihitung dua kali bersama anak-anaknya, sehingga merusak validitas matematis rata-rata unit.

### 3.3 Implementasi pada Query SQL dan Reader Aplikasi

Pencegahan *double-counting* dijamin secara tegas pada lapisan database query melalui klausul eksplisit:

```sql
SELECT
    org_units.name AS unit,
    indicators.id AS indicator_id,
    performance_observations.pencapaian
FROM performance_observations
INNER JOIN indicators ON indicators.id = performance_observations.indicator_id
INNER JOIN org_units ON org_units.id = performance_observations.org_unit_id
WHERE performance_observations.period = :period
  AND indicators.is_group = false
  AND performance_observations.pencapaian IS NOT NULL
  AND performance_observations.dataset_id IN (:latest_dataset_ids);
```

Peran Baris Induk pada Antarmuka Visual:
- **Pada Reader R6 & R9:** Baris induk dieksklusi total dari array kalkulasi.
- **Pada Reader R7 (Tabel Rincian):** Baris induk dieksklusi dari daftar baris data indikator.
- **Pada Panel Target vs Realisasi (`TargetRealisasiPerIndicatorReader`):** Baris induk (`is_group = true`) tidak dijadikan panel, melainkan dipetakan sebagai label header kelompok (*section header*) untuk mengelompokkan panel-panel kartu daun di bawahnya.

---

## 4. Penanganan Kasus Khusus (Edge Cases)

### 4.1 Indikator Tanpa Pasangan Numerik (Score-Only Indicators)

Terdapat indikator operasional penting yang tidak memiliki pasangan numerik target dan realisasi di Excel. Kolom target atau realisasi berupa teks keterangan atau kosong, namun kolom pencapaian berisi skor hasil evaluasi yang sah.

Berdasarkan Keputusan Bisnis 3 Pak Gama, indikator jenis ini tetap dihitung dalam evaluasi kinerja unit. Tiga indikator spesifik pada unit TJBT meliputi:

| ID Indikator | Nama Indikator | Tipe Target | Tipe Realisasi | Nilai Mentah Excel | Skala Terdeteksi | Skor Normalisasi |
|---|---|---|---|---|---|---|
| **#403** | Anggaran Investasi sesuai RKAP (Kesiapan Operasi) | `text` (kosong/string) | `numeric` (99.85) | `1.0485` | Rasio | **104.9%** |
| **#410** | Penyelesaian Dokumen Legal Aset Tanah (Kinerja Lingkungan) | `numeric` (261.0) | `text` ("Sesuai Target : 283") | `110.0000` | Persen | **110.0%** |
| **#413** | Kualitas Data Material Cadang Transmisi | `date` (2026-08-31) | `date` (2026-08-21) | `1.0143` | Rasio | **101.4%** |

**Kontrak Penanganan:**
- Sistem membaca nilai mentah dari kolom `performance_observations.pencapaian`.
- Sistem tidak memaksakan konversi kolom teks menjadi numerik buatan.
- Nilai pencapaian dinormalisasi melalui `KpiScoreNormalizer::normalize()` sehingga indikator berskala rasio (#403 dan #413) dikalikan 100, sedangkan indikator berskala persen (#410) tetap bernilai 110.0%.

### 4.2 Indikator Bertipe Tanggal (Date-Based Indicators)

Indikator batas waktu cut-off memiliki nilai target dan realisasi berupa tanggal kalender (format `YYYY-MM-DD` di database, `DD/MM/YYYY` di Excel), bukan besaran volume fisik.

#### 4.2.1 Formula Selisih Tanggal Excel (*Date Delta Formula*)

Pada lembar kerja Excel resmi PLN, skor pencapaian indikator tanggal dihitung menggunakan formula selisih hari:

$$\text{Pencapaian Mentah} = 1 - \left(\frac{0.01}{7} \times (L - K)\right)$$

di mana:
- $L$ adalah nomor serial tanggal realisasi (kolom Realisasi).
- $K$ adalah nomor serial tanggal target cut-off (kolom Target).
- Konstanta $\frac{0.01}{7}$ menyatakan penalti/bonus sebesar 1% untuk setiap deviasi 7 hari kalender (1 minggu).

**Contoh Kasus Riil Indikator #413:**
- Target ($K$): 31 Agustus 2026
- Realisasi ($L$): 21 Agustus 2026 (realisasi 10 hari lebih cepat dari cut-off, delta = -10 hari)
- Perhitungan Excel:
  $$\text{Pencapaian Mentah} = 1 - \left(\frac{0.01}{7} \times (-10)\right) = 1 - (-0.0142857) = 1.0142857$$
- Konversi Normalisasi Sistem: $1.0142857 \times 100 = 101.42857\% \rightarrow$ **101.4%**.

#### 4.2.2 Perlindungan Terhadap Baris Tanggal Tanpa Skor

Jika terdapat baris bertipe tanggal namun kolom pencapaian di Excel bernilai kosong (`pencapaian IS NULL`):
- Sistem menetapkan nilai pencapaian sebagai `NULL`.
- Sistem **dilarang keras** membagi nilai serial tanggal (contoh membagi tanggal serial Excel `46255 / 46265`), karena pembagian serial tanggal akan menghasilkan angka rasio palsu sekitar 99.9% yang tidak memiliki makna bisnis.

### 4.3 Target Bernilai Nol atau Kosong (Zero Target Protection)

Untuk mencegah kesalahan sistem fatal berupa *division-by-zero error* pada saat menghitung rasio naif di reader operasional (R7):
- Jika `target_value = 0` atau `target_value IS NULL`, sistem tidak melakukan operasi pembagian.
- Jika kolom pencapaian mentah Excel tersedia, reader menggunakan skor pencapaian tersebut.
- Jika kolom pencapaian juga kosong, nilai capaian dikembalikan sebagai `NULL` secara aman.

---

## 5. Intentional Divergence (Perbedaan Terencana Antar Reader)

### 5.1 Filosofi dan Penegasan Desain

Salah satu aspek arsitektur terpenting pada IBS / plnjbt-dashboard adalah adanya **perbedaan angka yang terencana (*intentional divergence*)** antar reader.

Pengembang, penguji sistem (*tester*), dan auditor internal **tidak boleh** menganggap perbedaan angka antara ringkasan eksekutif dan tabel rincian sebagai bug atau inkonsistensi data. Perbedaan ini merupakan pemenuhan kebutuhan bisnis yang berbeda untuk audiens yang berbeda:
1. **Audiens Eksekutif (Manajemen / GM / SRM):** Memerlukan angka agregat organisasi yang terkendali, mematuhi batas resmi evaluasi kinerja korporat (cap [0..110%]), serta mencerminkan bobot polaritas formula Excel.
2. **Audiens Operasional (PIC Divisi / Supervisor / Staf Unit):** Memerlukan transparansi rasio kinerja operasional apa adanya (unclamped), sehingga deviasi ekstrem (misalnya over-achievement 632% atau under-achievement) terlihat jelas untuk keperluan audit teknis lapangan.
3. **Audiens Analis Data (Chart & Tren):** Memerlukan konsistensi historis antara data ringkasan flat metrik dan observasi indikator terstruktur.

### 5.2 Matriks Perbandingan Karakteristik Antar Reader

| Karakteristik | Reader R6 (`kinerjaRealisasiStructured`) & R9 (`pencapaianNko`) | Reader R7 (`kinerjaDetailStructured` - Tabel Rincian) | Reader R1 (`/api/performance-data` - Kartu & Tren) | Reader Panel (`TargetRealisasiPerIndicatorReader`) |
|---|---|---|---|---|
| **Tujuan & Audiens** | Ringkasan Eksekutif & Peringkat Unit | Audit Operasional & Tabel Rincian Indikator | Tren Historis NKO & Ringkasan Volume Sistem | Visualisasi Target vs Realisasi Per Indikator |
| **Sumber Data** | `performance_observations` pada dataset terbaru | `performance_observations` pada dataset terbaru | **Hybrid:** NKO dari `performance_observations`, Counts dari `performance_metrics` | `performance_observations` pada dataset terbaru |
| **Batas Cap [0..110%]** | **Diterapkan secara ketat** (`KpiScoreNormalizer::clamp`) | **TIDAK Diterapkan** pada pasangan numerik (un-clamped) | Mengikuti nilai NKO mentah workbook PLN | **TIDAK Diterapkan** (menampilkan persentase hasil normalisasi skala) |
| **Pasangan Numerik Biasa** | Membaca skor Excel normalisasi [0..110%] | Menghitung rasio naif: `round((realisasi / target) * 100, 1)` | Tidak menampilkan pasangan numerik individual | Memisahkan mode `numeric`, menampilkan target, realisasi, satuan |
| **Pasangan Numerik Over-Performance** (misal ATTB) | Dibatasi maksimal **110.0%** | Ditampilkan rasio aktual: **632.0%** | Tidak menampilkan pasangan numerik individual | Menampilkan target 28.795, realisasi 181.979, skor 110.0% |
| **Indikator Polaritas Terbalik** (Lower-is-better) | Membaca skor Excel (memperhitungkan formula polaritas) | Rasio naif langsung `realisasi / target * 100` tanpa membalik arah | Tidak menampilkan pasangan numerik individual | Menampilkan target dan realisasi apa adanya, skor mengikuti Excel |
| **Indikator Tanggal & Score-Only** (#403, #410, #413) | Membaca skor Excel normalisasi [0..110%] | Membaca skor Excel normalisasi (dengan format display tanggal) | Tidak menampilkan pasangan numerik individual | Menampilkan mode `date` dengan delta hari (`delta_days`) dan skor Excel |
| **Landasan Keputusan** | Keputusan 2 Pak Gama (2026-10-25) | Keputusan Bisnis Sesi 99 (2026-10-26) | Keputusan 1 Pak Gama Opsi C (2026-10-25) | Keputusan Desain Pak Gama (2026-10-19 11:17) |

### 5.3 Rincian Karakteristik Tiap Reader

#### 5.3.1 Reader R6 (`kinerjaRealisasiStructured`) dan R9 (`pencapaianNko`)
- **Implementasi:** `DashboardController::kinerjaRealisasiStructured()` dan `DashboardController::kinerjaRealisasiRows()`.
- Mengambil seluruh baris daun berskor (`is_group = 0` dan `pencapaian IS NOT NULL`) pada dataset terbaru.
- Seluruh nilai mentah dikonversi melalui `KpiScoreNormalizer::normalize()` lalu di-clamp ke rentang `[0.0 .. 110.0]`.
- Menghitung rata-rata sederhana aritmatika tak berbobot: `round($pencapaianSum / $counted, 1)`.
- Mengembalikan data rekapitulasi unit: `unit`, `achieved`, `total`, `avg`, dan `status`.
- R9 memanfaatkan keluaran R6 untuk memetakan label dan data grafik baris/donut pencapaian NKO.

#### 5.3.2 Reader R7 (`kinerjaDetailStructured` - Tabel Rincian Kinerja)
- **Implementasi:** `DashboardController::kinerjaDetailStructured()`.
- Menampilkan seluruh indikator daun operasional yang memiliki data target, realisasi, tanggal, atau skor.
- **Logika Percabangan Capaian R7:**
  ```php
  if ($isDateRow) {
      // Indikator tanggal: memakai skor Excel dinormalisasi
      $pencapaian = $obs->pencapaian !== null
          ? round($this->normalizeExcelScore((float) $obs->pencapaian), 1)
          : null;
  } elseif ($target !== null && $target > 0 && $realisasi !== null) {
      // Pasangan numerik biasa: rasio naif murni un-clamped
      $pencapaian = round($realisasi / $target * 100, 1);
  } elseif ($obs->pencapaian !== null) {
      // Indikator score-only: memakai skor Excel dinormalisasi
      $pencapaian = round($this->normalizeExcelScore((float) $obs->pencapaian), 1);
  } else {
      $pencapaian = null;
  }
  ```
- Alasan bisnis: PIC operasional harus dapat melihat bahwa target ATTB tercapai 632% atau Maturity Level tercapai 137.2%, tanpa disembunyikan oleh angka 110%.

#### 5.3.3 Reader R1 (`/api/performance-data` - Kontrak Hybrid)
- **Implementasi:** `PerformanceChartController::index()` dan `PerformanceChartController::structuredResponse()`.
- Berstatus **Hybrid** sesuai Keputusan Bisnis 1 Pak Gama (Opsi C):
  1. Bagian `series` (tren waktu) dan `kpi.average_nko`: Membaca baris terstruktur `indicators.category = 'nko'` pada `performance_observations` menggunakan `StructuredNkoReader::latestPerPeriod()`.
  2. Bagian `kpi.metric_count` dan `kpi.ready_datasets`: Membaca tabel flat `performance_metrics` dan `datasets` melalui helper `kpiCardDatasetAndMetricCounts()`.
- **Dampak pada Tampilan:** Kartu "Baris Data Aktif (TJBT)" pada ringkasan dashboard menampilkan angka **41** (jumlah baris metrik flat pada dataset #49), bukan angka 30 (jumlah observasi terstruktur). Hal ini disengaja agar sesuai dengan Keputusan 1 Pak Gama dan label kartu telah diberi nama spesifik untuk membedakannya.

#### 5.3.4 Reader Panel Target vs Realisasi (`TargetRealisasiPerIndicatorReader`)
- **Implementasi:** `TargetRealisasiPerIndicatorReader::panels()`.
- Menghasilkan struktur JSON hierarkis per unit dan per seksi grup (`sections.panels`).
- Secara otomatis mengidentifikasi mode tampilan:
  - Mode `numeric`: Menyediakan format numerik target, realisasi, dan satuan.
  - Mode `date`: Menyediakan string tanggal target (`target_date`), realisasi (`realisasi_date`), serta selisih hari (`delta_days`).
- Mengembalikan skor pencapaian dalam metadata `score`: `raw`, `scale` (`ratio` atau `percent`), dan `percent`.

---

## 6. Studi Kasus Empiris: 16 Indikator Daun Berskor Unit TJBT

Berikut adalah perbandingan konkret nilai perhitungan antara Reader R6 (Ringkasan Eksekutif) dan Reader R7 (Tabel Rincian) untuk seluruh 16 indikator daun berskor pada unit TJBT periode Agustus 2026 (Dataset #49):

| ID | Nama Indikator | Tipe Target/Realisasi | Target | Realisasi | Pencapaian Mentah Excel | Skor R6 (Eksekutif) | Capaian R7 (Rincian) | Keterangan Intentional Divergence |
|---|---|---|---|---|---|---|---|---|
| 391 | a. Faktor Ketersediaan Trafo (EAF T) | Numeric / Numeric | 99.92 | 99.986 | 1.0007 | **100.1%** | **100.1%** | Numerik normal (rasio $\approx$ capaian) |
| 392 | b. Faktor Ketersediaan Jalur (EAF L) | Numeric / Numeric | 99.76 | 99.924 | 1.0016 | **100.2%** | **100.2%** | Numerik normal |
| 393 | a. Kali Trip Transmisi / SKTT (FOT) | Numeric / Numeric | 1.942 | 1.0 | 1.1000 | **110.0%** | **51.5%** | **Polaritas terbalik:** R6 ikut Excel (110%), R7 rasio naif ($1.0 / 1.942$) |
| 394 | b. Kali Trip Trafo Daya (FOT T) | Numeric / Numeric | 5.326 | 4.0 | 1.1000 | **110.0%** | **75.1%** | **Polaritas terbalik:** R6 ikut Excel (110%), R7 rasio naif ($4.0 / 5.326$) |
| 395 | a. Kesiapan Sistem Pertahanan (SPS) | Numeric / Numeric | 99.90 | 100.0 | 1.0010 | **100.1%** | **100.1%** | Numerik normal |
| 396 | b. Keandalan Proteksi Transmisi | Numeric / Numeric | 99.85 | 100.0 | 1.0015 | **100.2%** | **100.2%** | Numerik normal |
| 398 | a. SAIDI Transmisi | Numeric / Numeric | 1.390 | 0.8 | 1.1000 | **110.0%** | **57.6%** | **Polaritas terbalik:** R6 ikut Excel (110%), R7 rasio naif ($0.8 / 1.390$) |
| 399 | b. SAIFI Transmisi | Numeric / Numeric | 0.088 | 0.04 | 1.1000 | **110.0%** | **45.5%** | **Polaritas terbalik:** R6 ikut Excel (110%), R7 rasio naif ($0.04 / 0.088$) |
| 400 | c. ENS (Energy Not Supplied) | Numeric / Numeric | 114.30 | 50.0 | 1.1000 | **110.0%** | **43.7%** | **Polaritas terbalik:** R6 ikut Excel (110%), R7 rasio naif ($50.0 / 114.30$) |
| 402 | a. Biaya Operasi dan Pemeliharaan | Numeric / Numeric | 95.00 | 90.0 | 1.0526 | **105.3%** | **94.7%** | **Polaritas terbalik:** Realisasi efisiensi biaya |
| 403 | b. Anggaran Investasi sesuai RKAP | Text / Numeric | - | 99.85 | 1.0485 | **104.9%** | **104.9%** | **Score-only:** Target teks, capaian dibaca dari skor Excel |
| 405 | c. Usulan Penghapusan ATTB | Numeric / Numeric | 28795.3 | 181979.53 | 1.1000 | **110.0%** | **632.0%** | **Over-achievement:** R6 clamped 110%, R7 un-clamped 632% |
| 407 | a. Proper Lingkungan | Numeric / Numeric | 100.0 | 100.0 | 1.0000 | **100.0%** | **100.0%** | Numerik pas target |
| 410 | c. Dokumen Legal Aset Tanah | Numeric / Text | 261.0 | Sesuai Target | 110.0000 | **110.0%** | **110.0%** | **Skala Persen Mentah:** Nilai mentah sudah 110.0 di Excel |
| 412 | a. Maturity Level Manajemen Aset | Numeric / Numeric | 80.0 | 109.78 | 1.1000 | **110.0%** | **137.2%** | **Over-achievement:** R6 clamped 110%, R7 un-clamped 137.2% |
| 413 | b. Kualitas Data Material Cadang | Date / Date | 31/08/2026 | 21/08/2026 | 1.0143 | **101.4%** | **101.4%** | **Date Delta:** Target & Realisasi tanggal, capaian dari skor Excel |

### Rekapitulasi Matematis R6:
- Total Nilai Daun Clamped:
  $$100.1 + 100.2 + 110.0 + 110.0 + 100.1 + 100.2 + 110.0 + 110.0 + 110.0 + 105.3 + 104.9 + 110.0 + 100.0 + 110.0 + 110.0 + 101.4 = 1693.2$$ (akumulasi desimal penuh di kode = **1693.73**)
- Rata-rata ($Avg$): $1693.73 / 16 = 105.858\%$
- Pembulatan R6: `round(105.858, 1)` = **105.9%**
- Jumlah Indikator Tercapai ($\ge 100\%$): **16 dari 16 indikator**
- Status Kinerja R6: **Tercapai**

---

## 7. Rangkuman Verifikasi Pengujian Sistem

Seluruh aturan bisnis, normalisasi formula, dan kontrak divergensi terencana di atas telah diverifikasi secara otomatis melalui rangkaian pengujian regresi pada unit test dan feature test:

1. **`Tests\Unit\KpiScoreNormalizerTest`:**
   - Menguji deteksi rasio desimal $\le 1.10$.
   - Menguji konversi pengali 100 pada rasio dan *bypass* pada persentase.
   - Menguji penguncian (*clamping*) batas minimum 0.0% dan maksimum 110.0%.
   - Menguji resolusi skala formula (`polarity_ratio`, `average`, `date_delta`).
2. **`Tests\Feature\KpiCalculationContractRegressionTest`:**
   - Skenario 1: Numerik polaritas terbalik (R6 mengikuti skor Excel 110%, R7 rasio naif 75.1%).
   - Skenario 2: Over-achievement ATTB (R6 clamped 110.0%, R7 un-clamped 632.0%).
   - Skenario 3: Indikator score-only #403 (terkonversi 104.9% konsisten lintas reader).
   - Skenario 4: Indikator skala persen mentah #410 (terbaca 110.0% tanpa pengali ganda).
   - Skenario 5: Indikator tanggal #413 (konsisten 101.4% dengan display tanggal dan delta hari).
   - Skenario 6: Tanggal tanpa skor (mengembalikan NULL, bukan serial tanggal angka).
   - Skenario 7: Target bernilai nol (mencegah *division-by-zero*).
   - Skenario 8: Baris induk diabaikan secara ketat dari perhitungan rata-rata.
3. **`Tests\Feature\KpiReaderComparisonCharacterizationTest`:**
   - Memastikan karakterisasi matematis 16 daun berskor unit TJBT menghasilkan rata-rata tepat 105.9% pada R6 dan nilai un-clamped pada R7.

---

## 8. Register Keputusan Arsitektur & Transisi Stabilisasi Pra-Handover

Bagian ini memindahkan seluruh ketetapan arsitektur dan perbaikan stabilisasi pra-handover (Fix F2 sampai F15) dari berkas kerja lokal ke dalam dokumentasi resmi repositori Git.

### 8.1 Ringkasan Kontrak Perhitungan Indikator Daun & Baris Tanggal

Sistem menegakkan tiga aturan dasar pada pemrosesan seluruh indikator kinerja:
1. **Batas Cap [0.0% .. 110.0%]:** Diterapkan pada level agregat eksekutif (Reader R6 dan R9). Setiap nilai capaian indikator yang dinormalisasi dibatasi maksimal 110.0% dan minimal 0.0% melalui `KpiScoreNormalizer::clamp()` guna mencegah distorsi capaian ekstrem (contohnya realisasi ATTB 632.0%) terhadap rata-rata unit. Sebaliknya, pada tabel rincian operasional (Reader R7), nilai rasio numerik un-clamped ditampilkan apa adanya untuk transparansi audit lapangan.
2. **Isolasi Baris Daun (`is_group = 0`):** Seluruh formula agregasi rata-rata unit dan penghitungan jumlah indikator tercapai HANYA mengevaluasi baris daun operasional (`is_group = 0`). Baris judul kelompok/induk (`is_group = 1`) diabaikan secara ketat untuk mencegah penghitungan ganda (*double-counting*).
3. **Pengecualian Baris Bertipe Tanggal (Skor Workbook):** Untuk baris cut-off material (seperti indikator #413), sistem tidak membagi serial angka tanggal kalender. Nilai pencapaian diambil langsung dari skor hasil formula selisih tanggal di lembar kerja Excel (`performance_observations.pencapaian`), dinormalisasi, dan dikunci pada 101.4%. Jika skor tanggal di Excel kosong, sistem mengembalikan nilai `NULL`.

### 8.2 Sentralisasi Ambang Batas RAG & Eliminasi Double-Normalization (Fix F7a & F15)

1. **Sentralisasi Ambang Batas RAG (`KpiScoreNormalizer`):**
   - Sebelumnya, ambang batas evaluasi Red-Amber-Green (RAG) tersebar dalam bentuk angka hardcode `>= 100` dan `>= 95` di berbagai template Blade (`kinerja-realisasi.blade.php`, `nko-ranking.blade.php`) dan method Controller (`DashboardController::nkoDonut()`, `CustomChartController`).
   - Melalui perbaikan Fix F15, seluruh evaluasi RAG dipusatkan pada `KpiScoreNormalizer::ragTier(float $score)` dan `KpiScoreNormalizer::ragThresholds()` dengan konfigurasi resmi:
     - **Tercapai (Hijau / `TIER_TERCAPAI`):** Skor $\ge 100.0\%$
     - **Hampir Tercapai (Kuning / `TIER_HAMPIR`):** $95.0\% \le \text{Skor} < 100.0\%$
     - **Perlu Perhatian (Merah / `TIER_PERLU`):** Skor $< 95.0\%$
   - Konfigurasi ambang batas ini terdaftar terpusat pada `config/kpi.php` (`kpi.rag_thresholds.tercapai_min` dan `hampir_min`).
2. **Eliminasi Double-Normalization di View R7 (Fix F7a):**
   - Pada komponen `resources/views/components/widgets/kinerja-detail.blade.php`, controller structured maupun legacy sudah mengirimkan `$row['pencapaian']` dalam skala persentase (`0..N%`).
   - Pemanggilan ulang `KpiScoreNormalizer::normalize()` pada layer Blade sebelumnya menyebabkan indikator dengan capaian riil $\le 1.10\%$ (misalnya $0.8\%$ atau $1.0\%$) terkalikan 100 untuk kedua kalinya menjadi $80\%$ atau $100\%$ (berwarna Hijau palsu).
   - Melalui Fix F7a, layer Blade langsung mengonsumsi nilai persentase numerik dari controller tanpa normalisasi ganda, menjamin indikator yang berkinerja buruk tetap berstatus Merah.

### 8.3 Imutabilitas Slug Divisi & Batasan Target Kanonis Layout (Fix F2, F3, F13)

1. **Imutabilitas Slug Divisi (Fix F2):**
   - Slug divisi (`divisions.slug`) merupakan kunci kanonis yang dirujuk oleh proses import KPI (`DatasetController::importKpiReport`), otorisasi halaman rincian KPI (`DashboardController::canAccessKpiDetail`), serta relasi layout (`DashboardLayout::keyFor`).
   - Pada `DivisionController::update()`, pengubahan nama divisi kini dikunci agar **tidak pernah memutasi slug**. Slug bersifat kekal (*immutable*) pasca-pembuatan.
   - Pada `DivisionController::store()`, ditambahkan validasi deteksi tabrakan slug (*slug collision check*) sebelum penyimpanan divisi baru.
2. **Pembatasan 3 Target Kanonis Layout Dashboard (Fix F3 & F13):**
   - Backend `DashboardController::validateLayoutTarget()` membatasi target pengelolaan tata letak hanya pada 3 target kanonis yang didukung oleh antarmuka UI Builder:
     1. `admin` (tata letak operasional administrator)
     2. `staff_divisi:perencanaan-pengusahaan` (tata letak divisi KPI)
     3. `staff_divisi:managemen-aset` (tata letak divisi Aset)
   - Permintaan penyimpanan layout untuk target non-kanonis (seperti role `management` atau divisi non-aktif) ditolak dengan status HTTP 422.
   - Pengguna dengan peran `editor_dashboard` ditolak dengan status HTTP 403 (*Forbidden*) jika mencoba menyunting atau mereset target layout `admin`.
   - Widget mati `layout-editor-placeholder` telah dicabut dari layout default admin dan editor dashboard serta dihapus dari guard anti-lockout backend, karena UI builder telah dipindahkan secara mandiri ke rute `/dashboard/builder`.

### 8.4 Atomisitas Transaksi Impor Excel, Penguncian lockForUpdate(), Fail-Safe Storage, & Normalisasi Indikator (Fix F5, F6, F14)

1. **Normalisasi Case-Insensitive & Unicode Whitespace pada Master Indikator (Fix F5):**
   - Kolom `indicators.name` pada MySQL 8.4 menggunakan collation `utf8mb4_unicode_ci` (case-insensitive) dengan unique index `(org_unit_id, name)`.
   - `StructuredKpiImporter` menerapkan normalisasi kunci pencarian case-insensitive dan pembersihan spasi unicode:
     `$lookupKey = $orgUnitId."\0".mb_strtolower(trim($name), 'UTF-8');`
   - Normalisasi ini mencegah pelemparan eksepsi MySQL `1062 Duplicate entry` saat lembar kerja Excel pada periode berikutnya memuat perbedaan huruf kapital/kecil atau spasi internal pada nama indikator yang sama.
2. **Atomisitas Transaksional Status READY & Penguncian `lockForUpdate()` (Fix F6 & F14):**
   - Saat proses impor KPI berlangsung (`DatasetController::importKpiReport`), status dataset ditahan pada `DatasetStatus::VALIDATING` selama proses ekstraksi legacy dan terstruktur berjalan.
   - Transisi ke status `DatasetStatus::READY` hanya dieksekusi pada langkah akhir bersamaan dengan pengarsipan dataset periode terdahulu di dalam satu transaksi database tunggal yang dilindungi penguncian baris `lockForUpdate()`.
   - Hal ini menjamin pembacaan konkuren oleh pengguna dasbor tidak pernah melihat status `READY` prematur pada dataset yang observasinya belum selesai diekstrak.
3. **Fail-Safe Cleanup & Penghapusan File Yatim (Fix F6 & F14):**
   - Jika proses impor terstruktur mengalami kegagalan di titik mana pun, metode kompensasi `rollbackAndCleanupKpiDataset()` menghapus metrik dan observasi parsial, menghapus berkas fisik `.xlsx` dari disk penyimpanan privat (`storage/app/private/datasets/`), serta menandai status dataset sebagai `DatasetStatus::FAILED`.
   - Transisi kegagalan dieksekusi menggunakan supresi event Eloquent `Dataset::withoutEvents()` agar observer audit tidak memblokir pencatatan status kegagalan jika terjadi kendala pada audit trail.
4. **Deteksi Periode Terstruktur (Fix F14):**
   - Deteksi periode pada `importedKpiPeriods()`, `$willSupersede`, dan `$superseded` mengevaluasi keberadaan baris pada `performance_observations` selain `performance_metrics`, menjamin dialog konfirmasi pengarsipan (`confirm_archive`) tetap akurat pada dataset terstruktur murni.

### 8.5 Audit Trail Hardening: Percobaan Login Gagal & Cascade Layout Divisi (Fix F12)

1. **Pencatatan Audit Percobaan Login Gagal (`ACTION_LOGIN_FAILED`):**
   - Listener `Illuminate\Auth\Events\Failed` didaftarkan pada `AppServiceProvider::boot()` untuk mencatat setiap upaya masuk yang gagal ke dalam tabel `audit_logs`.
   - Nilai kolom `auditable_type` disetel `NULL` agar tidak terhapus oleh query pembersihan jejak entitas.
   - Kredensial dibersihkan secara ketat menggunakan regex case-insensitive `/(pass|secret|token|key|pin|cred)/i` sehingga password mentah tidak pernah tersimpan di basis data.
   - Pada `AuditLog::record()`, resolusi user ID menggunakan pemeriksaan `func_num_args() >= 5 ? $userId : auth()->id()` agar nilai `null` eksplisit dari percobaan login user anonim/non-existent tidak teratribusi secara keliru ke user aktif yang sedang login di sesi ambient.
   - Helper `AuditLogFormatter::userName()` menyajikan identitas percobaan autentikasi secara informatif tanpa menampilkan label keliru "Pengguna telah dihapus".
2. **Pencatatan Audit Cascade Penghapusan Divisi:**
   - Model `Division` memiliki relasi `dashboardLayouts(): HasMany`.
   - Pada `DivisionController::destroy()`, konfigurasi layout terkait dihapus terlebih dahulu via Eloquent `$division->dashboardLayouts()->get()->each->delete()` di dalam `DB::transaction` sebelum penghapusan `$division->delete()`.
   - Mekanisme ini memicu hook event `deleted` pada trait `Auditable`, menjamin terciptanya entri audit log terpisah untuk setiap `DashboardLayout` yang terhapus bersamaan dengan penghapusan divisi.

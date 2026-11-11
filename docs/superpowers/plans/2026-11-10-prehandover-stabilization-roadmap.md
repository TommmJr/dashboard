# Master Roadmap Stabilisasi Pra-Handover (IBS / plnjbt-dashboard)

- **Tanggal Audit & Penyusunan**: 2026-11-10
- **Branch / Commit Baseline**: `main` @ `4a0419e` (`fix(asset): restrict stock aggregation to the latest ready dataset per division and period`), 10 commit di depan `origin/main` (`854b77a`), 0 commit di belakang, working tree bersih.
- **Prinsip Pengurutan**: Dampak kerusakan di tangan tim PLN UIT JBT (`Integritas Data > Keamanan/Otorisasi > Kebenaran Angka KPI > Kesiapan Handover/Deploy > Kosmetik UI`), bukan berdasarkan urutan paket di plan UI/UX lama.
- **Aturan Skoring Prioritas**:
  $$\text{Skor} = (\text{Dampak}_{1..5} \times \text{Kemungkinan di PLN}_{1..5}) - \text{Biaya Perbaikan (jam)}$$
  Item yang memerlukan keputusan bisnis atau tata kelola PLN dipisahkan ke Bagian 5 ("Menunggu Keputusan Bisnis") dan tidak dijadwalkan untuk eksekusi sepihak.

---

## Pertanyaan Klarifikasi untuk User Sebelum Eksekusi Final (Maksimal 3 Pertanyaan)

1. **Sisa Waktu Magang**: Berapa minggu atau hari kerja magang yang tersisa sampai tanggal serah terima (handover) ke PLN?
2. **Izin Backup / Sinkronisasi Git**: Apakah 10 commit lokal (`e28dc01..4a0419e`) boleh segera di-push ke `origin/main` atau dibuatkan file backup `git bundle` sekarang?
3. **Penerima Handover di PLN**: Siapa peran penerima server/aplikasi di pihak PLN UIT JBT (nama peran/jabatan saja, misalnya Staf TI / Admin Server / PIC Perencanaan Pengusahaan)? Jika belum ditetapkan, item ini dicatat sebagai blocker tata kelola handover.

*(Catatan: Selama 3 pertanyaan di atas belum dijawab, roadmap pada Bagian 3 menyediakan dua skenario alokasi waktu: **Skenario Pendek <= 2 Minggu** dan **Skenario Panjang >= 4 Minggu**).*

---

## 1. Ringkasan Kondisi Repository, Database Riil, dan Rekonsiliasi Dokumen

### 1.1 Kondisi Git & Working Tree (Diverifikasi Aktual)
- **Branch**: `main`
- **HEAD Lokal**: `4a0419e` (`fix(asset): restrict stock aggregation to the latest ready dataset per division and period`)
- **Remote Tracking (`origin/main`)**: `854b77a` (`docs(handover): add 6-chapter handover documentation pack and validation index`)
- **Divergensi**: `ahead 10, behind 0` terhadap `origin/main`. Kesepuluh commit lokal (`e28dc01`, `fd35f07`, `9f352d4`, `11e6b6c`, `c2895ce`, `8b96a7a`, `ef99368`, `83bd376`, `b2ea34b`, `4a0419e`) hanya ada di disk lokal developer dan belum memiliki cadangan di remote.
- **Working Tree**: Bersih di seluruh direktori kode (`app/`, `routes/`, `database/`, `resources/`, `config/`, `tests/`).

### 1.2 Rekonsiliasi Test Suite: Diverifikasi vs Diklaim
| Sumber | Status | Rincian |
|---|---|---|
| `.ai/checkpoint.md` & `task.md` baris 6 | **Diklaim pada Sesi 167 (`4a0419e`)** | Full suite 761 test (`722 passed`, `39 skipped`, `0 failed`, `4071 assertions`, durasi `51.84s` di SQLite in-memory). |
| `task.md` baris 244 (Section 2) | **Basi (Stale)** | Masih menulis `Suite: 735 test (696 pass, 39 skip, 3816 assertion)` dan `HEAD: 854b77a`. |
| `.ai/context.md` baris 41â€“45 | **Basi (Stale)** | Masih menulis `519 test (484 passed, 35 skipped), 2299 assertions` dan `HEAD = origin/main = f7b7634c`. |
| **39 Skipped Tests** | **Diverifikasi Statis di `tests/Feature/`** | Terdiri dari 12 test di `tests/Feature/PerformanceUploadTest.php` (fitur upload legacy tanpa route), 25 test di 4 file modul `/reports/*` yang di-comment di `routes/web.php:98-110` (`ReportIndexTest.php` 9, `ReportAuditExportTest.php` 7, `ReportValidationExportTest.php` 5, `ReportDatasetSummaryExportTest.php` 4), dan 2 test di `tests/Feature/KpiReaderComparisonCharacterizationTest.php` (`markTestSkipped` saat database pengujian belum memiliki dataset KPI terstruktur aktif). |
| **Test Suite di MySQL 8.4** | **Belum Diverifikasi Otomatis di CI** | `.github/workflows/ci.yml:17-57` menjalankan `php artisan test` di SQLite. Job `db-grants` (`ci.yml:62-227`) hanya menjalankan migrasi + pengecekan `SHOW GRANTS` di MySQL 8.4, tanpa mengeksekusi PHPUnit terhadap MySQL 8.4. |

### 1.3 Kondisi Database MySQL 8.4 Dev Riil (`plnjbt_db` via `mysql-ro`)
Hasil query `SELECT` read-only langsung ke container `plnjbt_db` (`127.0.0.1:3306`):
- **Skema**: 20 tabel (`migrations` berisi 21 baris migrasi, terakhir `2026_11_02_090000_create_dashboard_layouts_table`). Zero DDL sejak Sesi 113.
- **Divisi (`divisions`)**: 5 baris:
  - ID 19: `Perencanaan Pengusahaan` (`slug = perencanaan-pengusahaan`)
  - ID 20: `Managemen Aset` (`slug = managemen-aset`)
  - ID 21: `Perencanaan Aset` (`slug = perencanaan-aset`)
  - ID 22: `Engineer` (`slug = engineer`)
  - ID 23: `Perencanaan Enjiner` (`slug = perencanaan-enjiner`)
- **Dataset (`datasets`)**: 2 baris berstatus `READY`:
  - Dataset #37: `DATA ASET.xlsx`, `division_id = 20` (`Managemen Aset`), `period_date = 2026-08-31`, `437` baris di `performance_metrics`, `0` baris di `performance_observations`.
  - Dataset #49: `Rekapitulasi_Pencapaian_SPI_TW_IV_Tahun_2025_24_Jan_2026.xlsm`, `division_id = 19` (`Perencanaan Pengusahaan`), `period_date = 2025-12-31`, `555` baris di `performance_metrics`, `453` baris di `performance_observations`.
- **Unit Organisasi & Indikator (`org_units`, `indicators`)**:
  - `org_units`: 15 baris; `name` ber-collation `utf8mb4_bin` (case-sensitive). Unit `TJBT` (`id = 14`, `parent_id = NULL`) memiliki 30 indikator dan 30 observasi pada Dataset #49.
  - `indicators`: 453 baris; `name` ber-collation `utf8mb4_unicode_ci` (case-insensitive) dengan unique index `indicators_org_unit_id_name_unique (org_unit_id, name)`. Saat ini `COUNT(*) = 453`, `COUNT(DISTINCT org_unit_id, LOWER(name)) = 453`, `COUNT(DISTINCT org_unit_id, CAST(name AS BINARY)) = 453` (belum ada duplikat beda kapitalisasi di data saat ini, namun rentan error 1062 pada upload berikutnya â€” lihat F5).
- **Layout Kustom (`dashboard_layouts`)**: 3 baris aktif:
  - ID 6: `role_slug = admin`, `division_id = NULL`, `layout_key = admin`, widgets: `kpi-summary`, `kpi-rag-status`, `nko-Trend` (via `pencapaian-nko`), `kinerja-realisasi`, `target-realisasi-per-indikator`, `kinerja-detail`, `layout-editor-placeholder`.
  - ID 7: `role_slug = staff_divisi`, `division_id = 20` (`Managemen Aset`), `layout_key = staff_divisi:managemen-aset`, widgets: `dataset-status`.
  - ID 8: `role_slug = staff_divisi`, `division_id = 19` (`Perencanaan Pengusahaan`), `layout_key = staff_divisi:perencanaan-pengusahaan`, widgets: `kpi-summary`, `kpi-rag-status`, `pencapaian-nko`, `kinerja-realisasi`, `target-realisasi-per-indikator`, `kinerja-detail`.

### 1.4 Dokumen yang Basi vs Kode Aktual (`HEAD 4a0419e`)
1. **`task.md` (Section 2, baris 239â€“290)**: Tertinggal di `854b77a` (735 test, 19 tabel/20 migrasi, mengklaim `KPI_READ_MODEL=structured` dilarang diaktifkan padahal `config/kpi.php:37` sudah default `'structured'`).
2. **`.ai/context.md` (baris 41â€“45, 123â€“124)**: Tertinggal di `f7b7634c` (519 test), dan masih mencatat bug agregasi ganda `AssetStockReport::totalsByUpt()` yang sudah diperbaiki di `4a0419e`.
3. **`.ai/progress.md` (baris 12, 97, 537, 1919â€“2619)**: Memiliki dua header `## Sesi 142` yang berbeda isi (baris 97 tentang unhide Aset dan baris 537 tentang audit 35 skipped tests) serta urutan sesi tidak kronologis.

---

## 2. Tabel Temuan Terverifikasi (Hasil 3 Jalur Audit Independen)

Setiap hipotesa dari audit eksternal telah diverifikasi ulang terhadap kode `HEAD` (`4a0419e`) dan database MySQL 8.4 dev.

### 2.1 Laporan Auditor A â€” Otorisasi, RBAC, & Security

| ID | Status | File:Baris (`HEAD 4a0419e`) | Bukti & Reproduksi Minimal | Dampak Kerusakan | Skor Prioritas (`D x K - Jam`) |
|---|---|---|---|---|---|
| **F2** | **CONFIRMED** | `app/Http/Controllers/DivisionController.php:63-67`<br>`app/Http/Controllers/DatasetController.php:51,70,159`<br>`app/Http/Controllers/DashboardController.php:166,1922-1923`<br>`app/Models/DashboardLayout.php:89`<br>`config/dashboard_layouts.php:113` | `DivisionController::update()` menghitung ulang `'slug' => Str::slug($validated['name'])`. Sementara itu, `DatasetController::importKpiReport()` baris 159 menjalankan `Division::query()->where('slug', 'perencanaan-pengusahaan')->firstOrFail()`. Reproduksi: Admin mengubah nama divisi `Perencanaan Pengusahaan` menjadi `Subbidang Perencanaan Pengusahaan` via `PUT /divisions/19` -> `slug` berubah -> upload KPI melempar 404 `ModelNotFoundException`, `canAccessKpiDetail()` dan `canImportKpi()` mengembalikan `false` untuk staf PP, dan baris `dashboard_layouts` ber-`layout_key = staff_divisi:perencanaan-pengusahaan` putus dari lookup fallback. | **Kritis (5)**: Mematikan seluruh fitur upload KPI, akses rincian KPI, dan layout dashboard staf divisi secara seketika jika Admin menyunting nama tampilan divisi. | `5 x 4 - 1.0 =` **19.0** |
| **F3** | **CONFIRMED** | `app/Http/Controllers/DashboardController.php:1917-1941, 2175-2245`<br>`app/Models/DashboardLayout.php:28-33` | UI `/dashboard/builder` (`layoutEditorData()`, baris 1917â€“1941) hanya menampilkan 3 target kanonis (`admin`, `staff_divisi:perencanaan-pengusahaan`, `staff_divisi:managemen-aset`). Namun backend `validateLayoutTarget()` (baris 2180â€“2216) mengizinkan semua `DashboardLayout::ALLOWED_ROLES` (`admin`, `management`, `editor_dashboard`, `staff_divisi` dengan `division_id = null` atau divisi ke-3/4/5) tanpa membatasi 3 target kanonis dan tanpa melarang role `editor_dashboard` memodifikasi layout `admin`/`management`. Reproduksi: User `editor_dashboard` mengirim `PUT /dashboard/layout` dengan payload `{"role_slug":"management","division_id":null,"widgets":[...]}` -> HTTP 200, menimpa layout Management di DB. | **Tinggi (4)**: Pelanggaran batas otorisasi target layout; `editor_dashboard` dapat mengubah layout `admin` atau `management` dan membuat baris layout yatim di luar 3 target yang bisa dikelola via UI. | `4 x 3 - 1.0 =` **11.0** |
| **F4** | **CONFIRMED** *(Menunggu Keputusan Bisnis)* | `app/Http/Controllers/DashboardController.php:116-125, 152-167`<br>`config/dashboard_layouts.php:39`<br>`resources/views/components/widgets/kinerja-detail.blade.php:171` | Commit `ef99368` mencabut `Role::ADMIN` dari `canAccessKpiDetail()` (`DashboardController.php:152-167`) sehingga `GET /dashboard/kpi-detail` mengembalikan 403 bagi Admin dan link di `kinerja-detail.blade.php:171` disembunyikan. Namun di `config/dashboard_layouts.php:39`, widget `kinerja-detail` memiliki `'permission' => null` dan di DB `dashboard_layouts` ID 6 (`layout_key = admin`), widget `kinerja-detail` masih terpasang dan dirender penuh oleh `DashboardController::__invoke()` (`:116-125`). | **Sedang (3)**: Inkonsistensi kontrak RBAC antara halaman `/dashboard/kpi-detail` (403 untuk Admin) dan widget `kinerja-detail` di `/dashboard` (tetap tampil untuk Admin dan bisa dipasang lewat Builder). | **Menunggu Keputusan Bisnis** *(lihat Bagian 5.1)* |
| **F12** | **CONFIRMED** | `app/Providers/AppServiceProvider.php:69-84`<br>`app/Models/AuditLog.php:35-46`<br>`database/migrations/2026_11_02_090000_create_dashboard_layouts_table.php:29-32`<br>`app/Http/Controllers/DivisionController.php:73-91` | (1) `AppServiceProvider::boot()` hanya mendaftarkan listener `Login` dan `Logout`; event `Illuminate\Auth\Events\Failed` dan `Lockout` tidak dicatat ke `audit_logs`.<br>(2) FK `dashboard_layouts.division_id` memakai `cascadeOnDelete()` di level MySQL. Saat `DivisionController::destroy()` menghapus divisi (yang belum memiliki user/dataset tapi sudah memiliki `DashboardLayout`), MySQL menghapus baris `dashboard_layouts` secara diam-diam tanpa memicu event Eloquent `deleted` pada trait `Auditable`. | **Sedang (3)**: Brute-force login gagal tidak meninggalkan jejak di tabel `audit_logs`; penghapusan divisi menghapus konfigurasi layout tanpa entri audit `DELETE` untuk `DashboardLayout`. | `3 x 3 - 1.0 =` **8.0** |
| **F13** | **CONFIRMED** | `app/Http/Controllers/DashboardController.php:120, 1747, 2139-2150, 2303-2312`<br>`config/dashboard_layouts.php:63,98,100` | `DashboardController::__invoke()` baris 120 secara eksplisit membuang widget `layout-editor-placeholder` (`->reject(fn ($w) => $w['key'] === 'layout-editor-placeholder')`) karena fitur pengatur tata letak sudah pindah ke halaman mandiri `/dashboard/builder`. Namun `ensureBuilderWidgetForSelfManagedLayout()` (baris 2139â€“2150) dan `validateLayoutTarget()` (baris 2303â€“2312) masih memaksa `layout-editor-placeholder` ada di payload/DB untuk `admin` dan `editor_dashboard`. | **Rendah-Sedang (2)**: Slot grid dan baris JSON di `dashboard_layouts` terkunci oleh widget hantu yang tidak pernah dirender di `/dashboard` tetapi tidak bisa dihapus di Builder oleh Admin. | `2 x 4 - 1.0 =` **7.0** |
| **F11-Sec** *(CSP & Compose Secrets)* | **CONFIRMED** | `app/Http/Middleware/SecurityHeaders.php:58`<br>`resources/views/layouts/app.blade.php:8`<br>`docker-compose.yml:26-32` | (1) `SecurityHeaders.php:58` masih mengizinkan `cdn.jsdelivr.net` di `script-src`, padahal seluruh aset frontend (Alpine.js + ECharts) sudah dibundel lokal via `@vite(['resources/css/app.css', 'resources/js/app.js'])` (`app.blade.php:8`).<br>(2) `docker-compose.yml:26-32` menggunakan fallback password lemah `${DB_PASSWORD:-password}`, `${DB_ROOT_PASSWORD:-change_this_in_production}`, `${DB_MIGRATOR_PASSWORD:-migrator_password}`. | **Sedang (3)**: Memperluas permukaan serangan XSS (bypass CSP via paket publik di jsDelivr) dan risiko container MySQL berjalan dengan kredensial default jika `.env` tidak lengkap saat deploy. | `3 x 3 - 0.5 =` **8.5** |

---

### 2.2 Laporan Auditor B â€” Integritas Data & Pipeline Import

| ID | Status | File:Baris (`HEAD 4a0419e`) | Bukti & Reproduksi Minimal | Dampak Kerusakan | Skor Prioritas (`D x K - Jam`) |
|---|---|---|---|---|---|
| **F5** | **CONFIRMED** | `app/Services/StructuredKpiImporter.php:130-132, 192-203`<br>`database/migrations/2026_10_06_090100_create_indicators_table.php:16-24` | Pemeriksaan `information_schema.COLUMNS` di MySQL 8.4 membuktikan `indicators.name` memakai collation `utf8mb4_unicode_ci` (case-insensitive) dengan unique constraint `(org_unit_id, name)`. Sebaliknya, `StructuredKpiImporter.php:131` membangun cache lookup PHP case-sensitive: `$i->org_unit_id."\0".$i->name`. Reproduksi: Jika workbook bulan berikutnya menulis `"a. Security index (SI)"` (huruf `i` kecil, sedangkan di DB tersimpan `"a. Security Index (SI)"`), `$existingIndicators->get($lookupKey)` mengembalikan `null`, lalu memanggil `Indicator::create(...)` (baris 194) -> MySQL melempar `SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry` dan menggagalkan seluruh upload KPI. Test SQLite tidak mendeteksi ini karena `TEXT` di SQLite bersifat case-sensitive (`BINARY`). | **Kritis (5)**: Satu perbedaan huruf besar/kecil atau spasi akhir pada nama indikator di file Excel PLN pada bulan berikutnya akan menyebabkan kegagalan total import KPI (Error 1062). | `5 x 4 - 1.0 =` **19.0** |
| **F6** | **CONFIRMED** | `app/Http/Controllers/DatasetController.php:254-352, 398-429` | Dalam `DatasetController::importKpiReport()`:<br>(1) `storeAndImport()` (baris 256 / 421) mengeksekusi transaksi pertama yang menyimpan `Dataset` dengan `status = READY` dan `performance_metrics`.<br>(2) `structuredImporter->import()` (baris 265) berjalan di transaksi kedua yang terpisah. Selama jeda antara baris 256 dan 288, request dashboard konkuren melihat dataset baru berstatus `READY` tetapi belum memiliki `performance_observations` (atau melihat 2 dataset `READY` sekaligus).<br>(3) Blok kompensasi saat `structuredImporter` gagal (baris 284â€“290) dan saat pengarsipan dataset lama gagal (baris 318â€“323) memanggil `delete()` dan `update()` tanpa `DB::transaction` dan tanpa `try/catch`. Jika koneksi DB putus saat kompensasi, dataset gagal tetap berstatus `READY`.<br>(4) File fisik `.xlsx` di `storage/app/private/datasets/{storedName}` yang sudah ditulis pada baris 400 tidak dihapus saat kompensasi kegagalan di baris 284 atau 318 (orphan file). | **Tinggi-Kritis (5)**: Potensi korupsi state `READY` palsu (dataset tampak aktif tapi observasi kosong), race condition pembacaan ganda saat upload berlangsung, dan kebocoran file yatim di storage. | `5 x 3 - 2.0 =` **13.0** |
| **F14 / F16** | **CONFIRMED** | `app/Http/Controllers/DatasetController.php:77-85, 192-196, 293-299`<br>`app/Services/DatasetImportService.php:83` | (1) Deteksi periode KPI yang sudah ada di `importedKpiPeriods()` (baris 77â€“85), `$willSupersede` (baris 192â€“196), dan `$superseded` (baris 293â€“299) hanya mengecek `whereHas('metrics', ...)`, tanpa `orWhereHas('observations')`.<br>(2) Di `DatasetImportService::import()` baris 83, kolom `performance_metrics.unit_id` diisi dengan `$uploader->id` (ID user pengunggah, bukan ID unit organisasi). | **Sedang (3)**: Jika ada dataset KPI yang hanya memiliki observasi structured, deteksi supersede periode (`confirm_archive`) meleset; `unit_id` di tabel legacy menyimpan nilai semantik yang salah (`user_id`). | `3 x 2 - 1.0 =` **5.0** |
| **Upload Parsial Aset** | **CONFIRMED** *(Menunggu Keputusan Bisnis)* | `app/Services/AssetStockReport.php:47, 111, 149-180`<br>`app/Http/Controllers/DatasetController.php:108-152` | Dengan fix F1 di commit `4a0419e`, `AssetStockReport::latestDatasetIds()` hanya mengambil `MAX(id)` dataset `READY` per `(division_id, period_date)`. Sementara `DatasetController::store()` (upload generik Aset) langsung membuat dataset `READY` baru tanpa dialog `confirm_archive` dan tanpa validasi kelengkapan UPT/atribut. Jika staf mengunggah file revisi parsial (misalnya hanya 1 UPT untuk periode `2026-08-31`), 5 UPT lainnya dari dataset #37 langsung hilang dari dashboard dan halaman detail Aset tanpa peringatan. | **Tinggi (4)**: Kehilangan visibilitas data UPT/atribut lain pada periode yang sama jika staf mengunggah file Excel parsial. Kebijakan penanganannya membutuhkan keputusan bisnis. | **Menunggu Keputusan Bisnis** *(lihat Bagian 5.2)* |

---

### 2.3 Laporan Auditor C â€” Kebenaran Angka KPI, Konsistensi RAG, & Frontend

| ID | Status | File:Baris (`HEAD 4a0419e`) | Bukti & Reproduksi Minimal | Dampak Kerusakan | Skor Prioritas (`D x K - Jam`) |
|---|---|---|---|---|---|
| **F7a** *(Double-Normalize di View R7)* | **CONFIRMED** | `resources/views/components/widgets/kinerja-detail.blade.php:195`<br>`app/Http/Controllers/DashboardController.php:1139-1142, 1590-1600`<br>`app/Support/KpiScoreNormalizer.php:38-48` | `DashboardController::kinerjaDetailStructured()` (baris 1593â€“1596) dan `kinerjaDetail()` (baris 1140) sudah mengirimkan `$row['pencapaian']` dalam skala persen (`0..N%`, misalnya `round($realisasi / $target * 100, 1)` atau `normalizeExcelScore()`). Namun `kinerja-detail.blade.php:195` memanggil lagi `KpiScoreNormalizer::normalize($numVal)`. Karena `normalize()` mengalikan nilai `<= 1.10` dengan `100.0`, setiap indikator dengan pencapaian riil antara `0.01%` s.d. `1.10%` (misalnya `0.8%` atau `1.0%`) dikalikan 100 dua kali menjadi `80%` atau `100%` (berwarna Hijau Tercapai!). | **Kritis (5)**: Indikator KPI yang gagal parah (`0.01% - 1.10%`) ditampilkan sebagai `1% - 110%` (bahkan Hijau Tercapai pada `1.0% - 1.1%`) di tabel Rincian KPI. | `5 x 4 - 0.5 =` **19.5** |
| **F7b** *(R7 Numerik Abaikan Polaritas vs Widget Lain)* | **CONFIRMED** *(Menunggu Keputusan Bisnis untuk Formula)* | `app/Http/Controllers/DashboardController.php:1590-1600` vs `:833-853` (`kpiRagStatusStructured`) & `app/Services/TargetRealisasiPerIndicatorReader.php:66-70` | Verifikasi query langsung ke MySQL Dataset #49 (`org_unit = TJBT`) membuktikan 4 indikator ber-polaritas `lower_is_better` (#394 `Kali sirkit padam karena gangguan`, #396 `Kali gangguan pada peralatan di Another Bay`, #397 `Emergency Response time`, #406 `Pengendalian NAC`) memiliki skor Excel `pencapaian = 1.1000` (`110%` Tercapai/Hijau di widget `kpi-rag-status` dan `target-realisasi-per-indikator`). Namun di `kinerja-detail` (R7), rumus `round($realisasi / $target * 100, 1)` menghasilkan `75.1%`, `60.1%`, `13.7%`, dan `77.3%` (Merah / Perlu Perhatian), serta indikator #405 (`Usulan Penghapusan ATTB`) menghasilkan `632.0%`. Kontradiksi antar widget pada halaman yang sama. *(Catatan: Rumus `realisasi/target*100` di R7 diputuskan di Sesi 99; pengubahannya tunduk pada larangan ubah formula KPI tanpa keputusan bisnis â€” lihat Bagian 5.3).* | **Tinggi (4)**: Manajemen melihat angka dan warna RAG yang bertolak belakang untuk indikator yang sama pada satu layar dashboard (Hijau 110% di panel atas vs Merah 13,7% di tabel bawah). | **Menunggu Keputusan Bisnis** *(lihat Bagian 5.3)* |
| **F15** *(Hardcoded RAG Thresholds & Duplikasi Normalizer)* | **CONFIRMED** | `resources/views/components/widgets/kinerja-realisasi.blade.php:25,76`<br>`resources/views/components/widgets/nko-ranking.blade.php:11-15`<br>`app/Http/Controllers/DashboardController.php:1374-1378, 1467-1473`<br>`app/Http/Controllers/CustomChartController.php:138-139` | Widget `kpi-rag-status`, `target-realisasi-per-indikator`, `pencapaian-nko`, dan `kinerja-detail` sudah membaca ambang batas dari `KpiScoreNormalizer::ragThresholds()` (`config('kpi.rag_thresholds')`). Namun `kinerja-realisasi.blade.php:25,76`, `nko-ranking.blade.php:11-15`, dan `DashboardController::nkoDonut/Structured` (`:1374,:1467`) masih melakukan hardcode `>= 100` dan `>= 95`. Selain itu, `CustomChartController.php:138-139` menulis ulang logika `$raw > 1.1 ? $raw : $raw * 100.0` alih-alih memanggil `KpiScoreNormalizer::normalizeAndClamp()`. | **Sedang (3)**: Jika ambang batas RAG di `config/kpi.php` disesuaikan, separuh widget berubah mengikuti konfigurasi sementara separuh lainnya tetap memakai angka hardcode. | `3 x 3 - 1.0 =` **8.0** |
| **F10** | **CONFIRMED** | `app/Http/Controllers/DashboardController.php:623-654`<br>`resources/views/components/widgets/dataset-status.blade.php:52-56` | Untuk staf `Managemen Aset` (`division_id = 20`), `datasetStatusStructured()` mengambil `$latestPeriod = '2026-08-31'` dari fallback legacy (`Dataset #37`), tetapi menghitung `$latestRows` dari tabel `performance_observations` dengan filter unit `TJBT` -> menghasilkan `0`. Blade `dataset-status.blade.php:54` merender teks `"Baris Data Aktif (TJBT): 0"` di bawah `"1 Dataset Siap Pakai"`, padahal Dataset #37 adalah data Aset (bukan KPI TJBT) yang memiliki `437` baris aktif di `performance_metrics`. | **Sedang (3)**: Informasi menyesatkan pada satu-satunya widget aktif di dashboard staf Managemen Aset (terlihat seolah-olah data hasil upload kosong/0 baris dan berlabel unit KPI TJBT). | `3 x 5 - 1.0 =` **14.0** |
| **F9** | **CONFIRMED** | `resources/views/users/index.blade.php:71`<br>`app/Http/Controllers/UserController.php:139`<br>`database/migrations/2026_09_11_105525_alter_datasets_user_id_nullable_null_on_delete.php:16-20` | Migrasi `2026_09_11_105525` telah mengubah FK `datasets.user_id` menjadi `nullable()->nullOnDelete()`. Namun dialog konfirmasi hapus user di `resources/views/users/index.blade.php:71` masih berbunyi: `"Seluruh dataset yang diunggah oleh user ini akan ikut terhapus secara permanen"`, dan docblock `UserController::destroy()` baris 139 masih menulis `"Cascade di DB menghapus datasets + metrics miliknya"`. | **Sedang (2)**: Pesan UI dan dokumentasi kode berbohong kepada Admin tentang perilaku penghapusan data historis saat merotasi akun pegawai. | `2 x 4 - 0.5 =` **7.5** |
| **F8** | **CONFIRMED** | `task.md:239-290`<br>`.ai/context.md:41-45, 123-124`<br>`.ai/progress.md:97, 537` | Dokumen tracking internal kontradiktif: `task.md` Section 2 dan `.ai/context.md` masih memuat commit, jumlah test, jumlah tabel, status `KPI_READ_MODEL`, dan bug Aset dari sesi lama; `.ai/progress.md` memiliki duplikasi `## Sesi 142`. | **Sedang (3)**: Membingungkan developer/auditor berikutnya atau sesi AI selanjutnya karena membaca fakta arsitektur yang sudah usang. | `3 x 4 - 1.0 =` **11.0** |
| **F11-Ops** *(Docker, Vite, CI MySQL)* | **CONFIRMED** | `Dockerfile:1-40`<br>`vite.config.js:18-23`<br>`.github/workflows/ci.yml:17-57, 62-227` | (1) `Dockerfile` menyalin repo tanpa menjalankan `composer install` maupun `npm ci && npm run build` (hanya bekerja di dev karena bind-mount `- .:/var/www/html`). Jika image di-build untuk server PLN, `vendor/` dan `public/build/` tidak terbangun.<br>(2) `vite.config.js:19` mematikan minifikasi (`minify: false`) dengan komentar `SEMENTARA`.<br>(3) `.github/workflows/ci.yml` belum menjalankan `php artisan test` terhadap MySQL 8.4 (hanya SQLite), sehingga isu spesifik MySQL (seperti collation F5) terlewat di CI. | **Tinggi (4)**: Gagal deploy di server staging/production PLN jika menggunakan image Docker; bug spesifik MySQL 8.4 lolos dari otomatis CI. | `4 x 4 - 2.5 =` **13.5** |
| **F17** *(Kode Mati / Dead Code)* | **CONFIRMED** | `app/Http/Controllers/PerformanceUploadController.php:1-131`<br>`app/Http/Controllers/ReportController.php:1-270`<br>`resources/views/components/widgets/target-vs-realisasi.blade.php:1-67` | Tiga artefak kode tidak aktif: `PerformanceUploadController` (tanpa route, 12 skipped test di `PerformanceUploadTest.php`, tidak membuat `Dataset` dan tidak mencatat `AuditLog`), `ReportController` (route di-comment di `routes/web.php:98-110`, 25 skipped test di `ReportIndexTest`, `ReportAuditExportTest`, `ReportValidationExportTest`, dan `ReportDatasetSummaryExportTest`), serta `target-vs-realisasi.blade.php` (widget dipensiunkan di Sesi 95/113). | **Rendah (2)**: Beban pemeliharaan dan kebingungan saat handover kode ke tim TI PLN (terutama 37 skipped test yang berasal dari controller tak ber-route). | `2 x 2 - 1.0 =` **3.0** |

---

## 3. Roadmap Stabilisasi Bertahap (Diurutkan Berdasarkan Dampak Kerusakan)

### Ringkasan Urutan Eksekusi & Skor Prioritas
1. **Tahap 0 - Penyelamatan Kode & Sinkronisasi Remote [SELESAI - Sesi 169]**: Backup git bundle & push 10 commit lokal (`Skor: 24.5` - mencegah kehilangan kerja).
2. **Tahap 1 - Integritas Referensial & Otorisasi Layout [SELESAI - Sesi 169]**:
   - **Task 1.1 (F2)**: Imutabilitas `divisions.slug` pada `DivisionController::update()` (`Skor: 19.0`).
   - **Task 1.2 (F3 & F13)**: Pembatasan kanonis `validateLayoutTarget`, proteksi role `editor_dashboard`, dan penghapusan guard widget mati `layout-editor-placeholder` (`Skor: 11.0` + `7.0`).
3. **Tahap 2 - Kebenaran Angka KPI & Akurasi Informasi Widget [SELESAI - Sesi 170]**:
   - **Task 2.1 (F7a)**: Perbaikan double-normalization `KpiScoreNormalizer::normalize()` di `kinerja-detail.blade.php` (`Skor: 19.5`).
   - **Task 2.2 (F10)**: Perbaikan label & penghitungan baris aktif `dataset-status` untuk divisi non-KPI/Managemen Aset (`Skor: 14.0`).
   - **Task 2.3 (F15 & F9)**: Sentralisasi ambang batas RAG (`kinerja-realisasi`, `nko-donut`, `nko-ranking`, `CustomChartController`) dan pelurusan teks konfirmasi hapus user (`Skor: 8.0` + `7.5`).
4. **Tahap 3 - Hardening Pipeline Import & Audit Trail [SELESAI - Sesi 171-174 @ 0cd225c]**:
   - **Task 3.1 (F5) [SELESAI]**: Normalisasi lookup indikator case-insensitive (`mb_strtolower(trim(...))`) dan toleransi unicode whitespace/spasi ganda di `StructuredKpiImporter` agar selaras dengan collation `utf8mb4_unicode_ci` MySQL (`Skor: 19.0`).
   - **Task 3.2 (F6 & F14/F16) [SELESAI]**: Atomic visibility / transaksionalitas status `READY`, penguncian arsip `lockForUpdate()`, fail-safe storage cleanup saat gagal, serta deteksi `observations` pada `importedKpiPeriods` (`Skor: 13.0` + `5.0`).
   - **Task 3.3 (F12) [SELESAI]**: Pencatatan audit log untuk login gagal (`Failed` / `Lockout`) dengan identitas terproteksi dan penghapusan eksplisit `DashboardLayout` sebelum hapus `Division` (`Skor: 8.0`).
5. **Tahap 4 - Kesiapan Deploy, Keamanan Header/Compose, & CI MySQL 8.4**:
   - **Task 4.1 (F11-Sec & F11-Ops)**: Perbaikan `SecurityHeaders` (hapus `cdn.jsdelivr.net`), wajibkan password env di `docker-compose.yml`, lengkapi build tahap `composer`/`npm` di `Dockerfile`, dan tambahkan eksekusi PHPUnit pada MySQL 8.4 di `.github/workflows/ci.yml` (`Skor: 8.5` + `13.5`).
6. **Tahap 5 â€” Rekonsiliasi Dokumen Tracking & Pembersihan Kode Mati**:
   - **Task 5.1 (F8 & F17)**: Sinkronisasi `task.md` Section 2, `.ai/context.md`, `.ai/progress.md`, serta pembersihan/pendokumentasian tegas kode mati (`Skor: 11.0` + `3.0`).
7. **Tahap 6 â€” Kosmetik UI (Kandidat Prioritas Rendah dari Plan Lama Paket 2â€“4)**:
   - Hanya dikerjakan jika Tahap 0â€“5 selesai penuh dan waktu magang masih tersisa.

### Skenario Alokasi Waktu (Karena Sisa Waktu Magang Belum Dikonfirmasi)
- **Skenario Pendek (<= 2 Minggu / <= 15 Jam Kerja Efektif)**:
  - Eksekusi **Tahap 0, Tahap 1, Tahap 2, Tahap 3, Tahap 4**, dan **Task 5.1 (Rekonsiliasi Dokumen F8)**. Total estimasi: **~13.0 jam**.
  - Potong seluruh Tahap 6 (Paket 2â€“4 UI/UX lama) dan penghapusan kode mati F17 (cukup pastikan route tetap nonaktif).
- **Skenario Panjang (>= 4 Minggu / >= 25 Jam Kerja Efektif)**:
  - Eksekusi penuh **Tahap 0 s.d. Tahap 5** (~14.0 jam), fasilitasi implementasi hasil keputusan bisnis PLN pada Bagian 5 (~4â€“6 jam), lalu pilih item teraman dari **Tahap 6** (~4â€“6 jam).

---

### Detail Spesifikasi Tiap Task

#### Tahap 0 - Backup & Sinkronisasi Remote (`origin/main`) [SELESAI - Sesi 169 @ 2d2ff66]
- **Task 0.1: Buat Git Bundle Lokal & Push 10 Commit ke `origin/main`**
  - **Tujuan**: Mengamankan 10 commit lokal (`e28dc01..4a0419e`) agar tidak hilang jika terjadi kegagalan disk lokal.
  - **File Terdampak**: Tidak ada perubahan file repo (operasi Git: `git bundle create` dan `git push origin main`).
  - **Langkah Verifikasi**:
    1. Jalankan `git status -sb` dan pastikan tree bersih.
    2. Buat cadangan `git bundle create ../plnjbt-dashboard-backup-2026-11-10.bundle --all`.
    3. Setelah mendapat izin user (Pertanyaan #2), jalankan `git push origin main` dan verifikasi `HEAD == origin/main`.
  - **Acceptance Criteria**: `git status -sb` menampilkan `## main...origin/main` (ahead 0, behind 0).
  - **Risiko Regresi**: Nol.
  - **Estimasi Jam**: `0.5 jam`.
  - **Butuh Keputusan Bisnis**: Tidak (hanya konfirmasi izin push dari user).

---

#### Tahap 1 - Perbaikan Bug Otorisasi & Integritas Referensial [SELESAI - Sesi 169 @ 2d2ff66]

- **Task 1.1 (F2): Kunci Imutabilitas `slug` Divisi Saat Rename di `DivisionController::update()`**
  - **Tujuan**: Mencegah perubahan nama tampilan divisi (`divisions.name`) merusak `divisions.slug` yang menjadi kunci kanonis bagi upload KPI (`perencanaan-pengusahaan`), akses rincian KPI, dan `dashboard_layouts.layout_key`.
  - **File Terdampak**:
    - `app/Http/Controllers/DivisionController.php` (baris 55â€“71)
    - `tests/Feature/DivisionCrudTest.php`
  - **Langkah TDD (Test Merah Dulu)**:
    1. Tambahkan test `test_updating_division_name_preserves_existing_canonical_slug_and_kpi_import_access` di `tests/Feature/DivisionCrudTest.php`: buat divisi `Perencanaan Pengusahaan` (`slug = perencanaan-pengusahaan`), lakukan `PATCH /divisions/{division}` (`route('divisions.update', $division)`, lihat `routes/web.php:142`) dengan `name = 'Subbidang Perencanaan Pengusahaan'`, lalu assert `slug` di database tetap `'perencanaan-pengusahaan'` dan `DashboardLayout` ber-`layout_key = staff_divisi:perencanaan-pengusahaan` tetap terhubung.
    2. Jalankan `php artisan test --filter=DivisionCrudTest` -> pastikan test merah (karena kode saat ini mengubah `slug` menjadi `'subbidang-perencanaan-pengusahaan'`).
    3. Ubah `DivisionController::update()` (`baris 63-67`) agar hanya memperbarui `['name' => $validated['name'], 'description' => $validated['description'] ?? null]` tanpa mengubah kolom `slug` (slug hanya di-generate satu kali pada `store()`).
    4. Jalankan kembali `php artisan test --filter=DivisionCrudTest`, `php artisan test --filter=DivisionSlugHarmonizationTest`, dan `php artisan test --filter=DatasetKpiReportImportTest` -> hijau.
  - **Acceptance Criteria**: Perubahan nama divisi via UI/endpoint `PATCH /divisions/{division}` mengubah `name` (dan tercatat di `audit_logs`), tetapi `slug` tetap tidak berubah.
  - **Risiko Regresi**: Sangat rendah; periksa `DivisionCrudTest` bila ada test lama yang meng-assert `slug` ikut berubah saat `update()`, lalu selaraskan ke kontrak imutabilitas `slug`.
  - **Estimasi Jam**: `1.0 jam`.
  - **Butuh Keputusan Bisnis**: Tidak.

- **Task 1.2 (F3 & F13): Batasi `validateLayoutTarget` ke Target Kanonis Sesuai Role & Hapus Guard Widget Mati `layout-editor-placeholder`**
  - **Tujuan**:
    1. Menutup celah F3 di mana `editor_dashboard` (atau request manual) dapat melakukan `PUT /dashboard/layout` atau `DELETE /dashboard/layout` untuk target di luar 3 target kanonis (`admin`, `staff_divisi:perencanaan-pengusahaan`, `staff_divisi:managemen-aset`), serta mencegah `editor_dashboard` mengubah layout milik `admin`.
    2. Menutup F13 dengan menghapus paksaan widget `layout-editor-placeholder` yang sudah tidak dirender di `/dashboard` dari `ensureBuilderWidgetForSelfManagedLayout()`, `validateLayoutTarget()`, dan `config/dashboard_layouts.php`.
  - **File Terdampak**:
    - `app/Http/Controllers/DashboardController.php` (baris 2139â€“2150, 2175â€“2245, 2303â€“2312)
    - `config/dashboard_layouts.php` (baris 63â€“72, 98, 100)
    - `tests/Feature/DashboardWidgetLayoutTest.php` & `tests/Feature/DashboardLayoutEditorWidthTest.php`
  - **Langkah TDD (Test Merah Dulu)**:
    1. Tambahkan test di `tests/Feature/DashboardWidgetLayoutTest.php`:
       - `test_layout_update_rejects_non_canonical_targets_such_as_management_or_other_divisions` (`PUT /dashboard/layout` untuk `role_slug = management` atau divisi `engineer` harus 422).
       - `test_editor_dashboard_cannot_modify_or_reset_admin_layout` (`PUT` dan `DELETE /dashboard/layout` dengan `role_slug = admin` oleh user `editor_dashboard` harus 403).
       - `test_admin_layout_can_be_saved_without_layout_editor_placeholder_widget` (`PUT /dashboard/layout` untuk `admin` tanpa `layout-editor-placeholder` berhasil 200).
    2. Jalankan `php artisan test --filter=DashboardWidgetLayoutTest` -> merah.
    3. Perbarui `validateLayoutTarget()` di `DashboardController.php` agar memvalidasi target terhadap daftar target kanonis yang diizinkan untuk aktor tersebut (Admin: 3 target kanonis; Editor Dashboard: hanya target `staff_divisi` kanonis, bukan `admin`), serta hapus paksaan `layout-editor-placeholder`.
    4. Jalankan `php artisan test --filter="DashboardWidgetLayoutTest|DashboardLayoutEditorWidthTest|DashboardLayoutAuditTest"` -> hijau.
  - **Acceptance Criteria**: Hanya 3 target kanonis yang diterima oleh endpoint `PUT`/`DELETE /dashboard/layout`; `editor_dashboard` ditolak (403) bila menyentuh layout `admin`; widget `layout-editor-placeholder` tidak lagi dipaksakan ke dalam layout.
  - **Risiko Regresi**: Rendah; perlu memperbarui assertion di `DashboardWidgetLayoutTest` yang sebelumnya menguji self-lockout `layout-editor-placeholder` (karena akses Builder kini melalui route `/dashboard/builder` di sidebar, bukan widget placeholder).
  - **Estimasi Jam**: `1.5 jam`.
  - **Butuh Keputusan Bisnis**: Tidak.

---

#### Tahap 2 - Kebenaran Angka KPI & Akurasi Informasi Widget [SELESAI - Sesi 170 @ 84dfade]

- **Task 2.1 (F7a): Hapus Double-Normalization `KpiScoreNormalizer::normalize()` di `kinerja-detail.blade.php`**
  - **Tujuan**: Menghentikan perkalian `x 100` kedua kali pada nilai `$row['pencapaian']` yang bernilai `<= 1.10%` di tabel Rincian KPI (R7).
  - **File Terdampak**:
    - `resources/views/components/widgets/kinerja-detail.blade.php` (baris 192â€“201)
    - `tests/Feature/KpiDetailPageTest.php`
  - **Langkah TDD (Test Merah Dulu)**:
    1. Buat test `test_kinerja_detail_does_not_double_normalize_percentage_scores_below_one_point_one_percent` di `tests/Feature/KpiDetailPageTest.php`: siapkan observasi KPI dengan target `100.0`, realisasi `0.8` (sehingga pencapaian R7 adalah `0.8%`), render `/dashboard/kpi-detail`, dan assert bar progres/tier menggunakan `0.8%` (`bg-rose-500`, tier `perlu`, lebar bar `width: 0.8%`) bukan `80%` atau `100%` (`bg-emerald-500`).
    2. Jalankan `php artisan test --filter=KpiDetailPageTest` -> merah (saat ini `0.8` menjadi `80%` dan `1.0` menjadi `100%` Hijau).
    3. Di `resources/views/components/widgets/kinerja-detail.blade.php:194-198`, hapus pemanggilan `$normalizedPencapaian = \App\Support\KpiScoreNormalizer::normalize($numVal);` dan gunakan langsung `$numVal` (karena controller structured maupun legacy sudah menormalisasi skor ke skala `0..N%`): set `$barWidth = max(0, min(100, $numVal));` dan `$tier = \App\Support\KpiScoreNormalizer::ragTier($numVal);`.
    4. Jalankan `php artisan test --filter="KpiDetailPageTest|KinerjaDetailUnitFilterTest"` -> hijau.
  - **Acceptance Criteria**: Nilai pencapaian `0.8%` dan `1.0%` di R7 tetap dirender sebagai `0,8%` dan `1,0%` dengan warna RAG Merah (`TIER_PERLU`, `bg-rose-500`, lebar bar `0.8%` / `1.0%`).
  - **Risiko Regresi**: Nol; semua jalur pembuatan `$row['pencapaian']` di `DashboardController::kinerjaDetail()` (`:1140`) dan `kinerjaDetailStructured()` (`:1595-1596`) sudah menghasilkan nilai persen (`0..110+`).
  - **Estimasi Jam**: `0.5 jam`.
  - **Butuh Keputusan Bisnis**: Tidak (murni perbaikan bug ganda di layer Blade, tanpa mengubah formula KPI di controller).

- **Task 2.2 (F10): Perbaiki Penghitungan & Label Baris Aktif `dataset-status` untuk Divisi Non-KPI (Managemen Aset)**
  - **Tujuan**: Menghilangkan tampilan menyesatkan `"Baris Data Aktif (TJBT): 0"` pada dashboard staf Managemen Aset ketika terdapat dataset Aset berstatus `READY` (437 baris di `performance_metrics`).
  - **File Terdampak**:
    - `app/Http/Controllers/DashboardController.php` (`datasetStatus()` baris 559â€“621 dan `datasetStatusStructured()` baris 623â€“662)
    - `resources/views/components/widgets/dataset-status.blade.php` (baris 52â€“56)
    - `tests/Feature/DashboardWidgetScopingTest.php` & `tests/Feature/KpiReadModelFlagTest.php`
  - **Langkah TDD (Test Merah Dulu)**:
    1. Tambahkan test `test_dataset_status_widget_for_asset_division_counts_active_metrics_and_hides_tjbt_label` di `tests/Feature/DashboardWidgetScopingTest.php`: login sebagai staf `Managemen Aset` dengan 1 dataset Aset `READY` berisi 5 baris `performance_metrics` (dan 0 `performance_observations`), panggil `GET /dashboard`, assert halaman menampilkan jumlah baris aktif `5` dengan label `"Baris Data Aktif"` (tanpa `"(TJBT)"`).
    2. Jalankan `php artisan test --filter=DashboardWidgetScopingTest` -> merah.
    3. Di `DashboardController::datasetStatusStructured()`, jika untuk periode terbaru jumlah `performance_observations` adalah `0` dan periode tersebut berasal dari fallback legacy (`performance_metrics`), hitung `$latestRows` dari `PerformanceMetric` pada dataset `READY` terbaru milik scope user dan sesuaikan label di `dataset-status.blade.php:54` (pertahankan kompatibilitas 6-key payload R5 bila dikunci oleh `KpiReadModelFlagTest`, atau periksa apakah label ditentukan berdasarkan keberadaan observasi KPI).
    4. Jalankan `php artisan test --filter="DashboardWidgetScopingTest|KpiReadModelFlagTest"` -> hijau.
  - **Acceptance Criteria**: Untuk divisi KPI (`Perencanaan Pengusahaan` / Admin), widget tetap menampilkan `"Baris Data Aktif (TJBT): 30"`; untuk divisi non-KPI (`Managemen Aset`), widget menampilkan `"Baris Data Aktif"` dengan jumlah baris metrik aktif (`437` pada data riil).
  - **Risiko Regresi**: Rendah; wajib memverifikasi `KpiReadModelFlagTest` agar kontrak struktur array `datasetStatus` tidak dilanggar.
  - **Estimasi Jam**: `1.0 jam`.
  - **Butuh Keputusan Bisnis**: Tidak.

- **Task 2.3 (F15 & F9): Sentralisasi Ambang Batas RAG di Seluruh Widget/Controller & Luruskan Dialog Hapus User**
  - **Tujuan**:
    1. Mengganti angka hardcode `100` dan `95` di `kinerja-realisasi.blade.php`, `nko-ranking.blade.php`, `DashboardController::nkoDonut/Structured()`, serta normalisasi manual di `CustomChartController` agar semuanya memanggil `KpiScoreNormalizer`.
    2. Memperbaiki teks dialog konfirmasi hapus user di `resources/views/users/index.blade.php:71` dan docblock `UserController::destroy()` (`:139`) agar sesuai dengan perilaku riil FK `nullOnDelete` (dataset tetap tersimpan dengan pengunggah ditandai telah dihapus).
  - **File Terdampak**:
    - `resources/views/components/widgets/kinerja-realisasi.blade.php` (baris 25, 76)
    - `resources/views/components/widgets/nko-ranking.blade.php` (baris 11â€“15)
    - `app/Http/Controllers/DashboardController.php` (baris 1374â€“1378, 1467â€“1473)
    - `app/Http/Controllers/CustomChartController.php` (baris 138â€“139)
    - `resources/views/users/index.blade.php` (baris 71)
    - `app/Http/Controllers/UserController.php` (baris 139)
    - `tests/Feature/UserCrudTest.php`, `tests/Feature/NkoRankingWidgetTest.php`, & `tests/Unit/KpiScoreNormalizerRagTierTest.php`
  - **Langkah TDD (Test Merah Dulu)**:
    1. Tambahkan test yang mengubah `config(['kpi.rag_thresholds.tercapai_min' => 102.0, 'kpi.rag_thresholds.hampir_min' => 97.0])` lalu memverifikasi warna RAG pada `kinerja-realisasi`, `nko-ranking`, dan `nko-donut`, serta test pada `tests/Feature/UserCrudTest.php` yang memverifikasi teks konfirmasi di `/users` tidak lagi mengklaim dataset ikut terhapus.
    2. Jalankan test spesifik -> merah.
    3. Terapkan pemanggilan `KpiScoreNormalizer::ragThresholds()` / `ragTier()` / `normalizeAndClamp()` dan perbarui teks konfirmasi di `users/index.blade.php:71`.
    4. Jalankan `php artisan test --filter="UserCrudTest|NkoRankingWidgetTest|KpiScoreNormalizerRagTierTest|CustomChartDataTest"` -> hijau.
  - **Acceptance Criteria**: Tidak ada lagi hardcode `>= 100` / `>= 95` untuk RAG KPI di Blade maupun Controller; dialog hapus user menyatakan akun dihapus permanen sementara riwayat dataset yang pernah diunggah tetap tersimpan.
  - **Risiko Regresi**: Sangat rendah (nilai default `config/kpi.php` tetap `100.0` dan `95.0`).
  - **Estimasi Jam**: `1.5 jam`.
  - **Butuh Keputusan Bisnis**: Tidak.

---

#### Tahap 3 - Hardening Pipeline Import & Audit Trail

- **Task 3.1 (F5): Normalisasi Case-Insensitive Lookup Indikator di `StructuredKpiImporter` [SELESAI - Sesi 171 & 172 @ cebcfb7]**
  - **Tujuan**: Mencegah error MySQL `1062 Duplicate entry` pada unique index `indicators_org_unit_id_name_unique` (`utf8mb4_unicode_ci`) ketika file Excel memiliki perbedaan huruf besar/kecil atau spasi pinggir pada nama indikator untuk `org_unit_id` yang sama.
  - **File Terdampak**:
    - `app/Services/StructuredKpiImporter.php`
    - `tests/Feature/StructuredKpiDualWriteTest.php`
  - **Langkah TDD (Test Merah Dulu)**:
    1. Tambahkan test di `tests/Feature/StructuredKpiDualWriteTest.php`: seed satu `Indicator` dengan nama `'a. Security Index (SI)'` pada `org_unit` `'TJBT'`, lalu jalankan `StructuredKpiImporter::import()` pada workbook/baris yang memiliki label `'a. Security index (SI)'` (huruf `i` kecil). Assert importer me-reuse `Indicator` yang sudah ada (`Indicator::where('org_unit_id', $tjbt->id)->count() === 1` untuk indikator tersebut) dan memperbarui metadata tanpa mencoba `Indicator::create()` baru.
    2. Jalankan `php artisan test --filter=StructuredKpiDualWriteTest` -> merah (di SQLite membuat baris ke-2, yang di MySQL akan meledak dengan Error 1062).
    3. Di `StructuredKpiImporter.php:131` dan `:192`, ubah pembentuk `$lookupKey` menjadi `$orgUnitId."\0".mb_strtolower(trim($name), 'UTF-8')`.
    4. Jalankan `php artisan test --filter="StructuredKpiDualWriteTest|StructuredKpiChronologicalGuardTest|DatasetKpiReportImportTest"` -> hijau.
  - **Acceptance Criteria**: Indikator dengan nama yang hanya berbeda kapitalisasi/spasi tepi pada `org_unit_id` yang sama dipetakan ke baris `Indicator` yang sama tanpa memicu `INSERT` duplikat.
  - **Risiko Regresi**: Sangat rendah; selaras penuh dengan semantik collation `utf8mb4_unicode_ci` pada kolom `indicators.name` di MySQL 8.4.
  - **Estimasi Jam**: `1.0 jam`.
  - **Butuh Keputusan Bisnis**: Tidak.

- **Task 3.2 (F6 & F14/F16): Hardening Transaksi, Kompensasi Gagal-Impor, Cleanup File Yatim, & Deteksi Periode di `DatasetController::importKpiReport` [SELESAI - Sesi 171 & 172 @ 8b92aab]**
  - **Tujuan**:
    1. Mencegah dataset baru terlihat berstatus `READY` sebelum seluruh tahapan (`DatasetImportService` + `StructuredKpiImporter` + pengarsipan dataset periode lama) selesai dengan sukses.
    2. Membungkus kompensasi kegagalan dengan `DB::transaction` dan `try/catch`, menghapus file fisik `.xlsx` (`$dataset->filename`) di `storage/app/private/datasets/` jika import gagal, dan menyertakan `orWhereHas('observations')` pada pengecekan periode KPI.
  - **File Terdampak**:
    - `app/Http/Controllers/DatasetController.php` (baris 77â€“85, 192â€“196, 254â€“352, 398â€“429)
    - `tests/Feature/DatasetKpiReportImportTest.php`
  - **Langkah TDD (Test Merah Dulu)**:
    1. Tambahkan test di `tests/Feature/DatasetKpiReportImportTest.php`:
       - `test_failed_structured_import_cleans_up_stored_file_and_never_leaves_dataset_ready`: mock `StructuredKpiImporter::import()` agar melempar `RuntimeException`, jalankan `POST /datasets/import-kpi-report` (`route('datasets.kpi-import')`), lalu assert `Dataset` berstatus `DatasetStatus::FAILED` (`'FAILED'`), `performance_metrics` = 0, `performance_observations` = 0, dan file `datasets/{$dataset->filename}` di `Storage::disk('local')` sudah dibersihkan.
       - `test_imported_kpi_periods_detects_ready_dataset_having_only_structured_observations`: buat dataset `READY` yang hanya memiliki `performance_observations` (0 `performance_metrics`), assert `importedKpiPeriods` dan guard `confirm_archive` mendeteksi periode tersebut.
    2. Jalankan `php artisan test --filter=DatasetKpiReportImportTest` -> merah.
    3. Perbarui `DatasetController`:
       - Pastikan status dataset tetap `DatasetStatus::VALIDATING` (`'VALIDATING'`) selama proses berlangsung dan baru diubah ke `DatasetStatus::READY` bersama pengarsipan dataset lama di dalam satu `DB::transaction` akhir (atau bungkus kompensasi gagal di dalam `DB::transaction` dengan `try/catch`).
       - Di blok kompensasi kegagalan, panggil `Storage::disk('local')->delete('datasets/'.$dataset->filename)` di dalam `try/catch` agar file yatim tidak menumpuk di storage.
       - Tambahkan `orWhereHas('observations', fn ($q) => $q->where('period', $period))` pada query `$willSupersede` dan `$superseded`, serta union/gabungkan periode dari `performance_observations` pada `importedKpiPeriods()`.
    4. Jalankan `php artisan test --filter="DatasetKpiReportImportTest|DatasetImportTest|DatasetCrudTest"` -> hijau.
  - **Acceptance Criteria**: Kegagalan di titik mana pun pada `importKpiReport` tidak pernah meninggalkan dataset berstatus `READY` ataupun file yatim (`datasets/{$dataset->filename}`) di `storage/app/private/datasets/`.
  - **Risiko Regresi**: Sedang; wajib menjalankan seluruh `DatasetKpiReportImportTest`, `DatasetImportTest`, dan `DatasetCrudTest` untuk memastikan alur normal dan alur `confirm_archive` tetap identik.
  - **Estimasi Jam**: `2.0 jam`.
  - **Butuh Keputusan Bisnis**: Tidak.

- **Task 3.3 (F12): Audit Log untuk Login Gagal (`Failed`) & Penghapusan `DashboardLayout` Saat Divisi Dihapus [SELESAI - Sesi 173 & 174 @ 0cd225c]**
  - **Tujuan**:
    1. Mencatat percobaan login gagal ke `audit_logs` (tanpa pernah menyimpan password mentah).
    2. Menghapus baris `DashboardLayout` terkait via Eloquent (`$layout->delete()`) di dalam `DivisionController::destroy()` sebelum `$division->delete()` agar trait `Auditable` mencatat event `DELETE` untuk `DashboardLayout`.
  - **File Terdampak**:
    - `app/Providers/AppServiceProvider.php` (baris 69â€“84)
    - `app/Models/AuditLog.php` (tambahkan konstanta `ACTION_LOGIN_FAILED = 'LOGIN_FAILED'`)
    - `app/Http/Controllers/AuditLogController.php` (baris 20â€“27, 58â€“65: tambahkan `AuditLog::ACTION_LOGIN_FAILED` ke whitelist validasi dan dropdown `$actions`)
    - `app/Services/AuditLogFormatter.php` (baris 71â€“78: tambahkan label `AuditLog::ACTION_LOGIN_FAILED => 'Gagal Masuk'`)
    - `app/Http/Controllers/DivisionController.php` (baris 73â€“91)
    - `tests/Feature/AuthTest.php`, `tests/Feature/DivisionCrudTest.php`, & `tests/Feature/AuditLogTest.php`
  - **Langkah TDD (Test Merah Dulu)**:
    1. Tambahkan test `test_failed_login_attempt_is_recorded_in_audit_log_without_password` di `tests/Feature/AuthTest.php` dan `test_deleting_division_records_audit_log_for_cascaded_dashboard_layout` di `tests/Feature/DivisionCrudTest.php`.
    2. Jalankan kedua test -> merah.
    3. Daftarkan listener `Event::listen(Failed::class, ...)` di `AppServiceProvider::boot()` (catat `email` yang dicoba di `new_values`, jangan pernah catat `password`), tambahkan `ACTION_LOGIN_FAILED` di `AuditLog`, `AuditLogController`, dan `AuditLogFormatter`, serta di `DivisionController::destroy()` panggil `DashboardLayout::where('division_id', $division->id)->get()->each->delete()` di dalam `DB::transaction` sebelum `$division->delete()`.
    4. Jalankan `php artisan test --filter="AuthTest|DivisionCrudTest|AuditLogTest|AuditLogScopingTest|AuditLogFormatterTest"` -> hijau.
  - **Acceptance Criteria**: Setiap login gagal menambah 1 baris `LOGIN_FAILED` di `audit_logs` dengan IP dan user agent serta dapat difilter di `/audit-logs`; penghapusan divisi mencatat entri `DELETE` untuk `DashboardLayout` maupun `Division`.
  - **Risiko Regresi**: Rendah; periksa `AuditLogTest` dan `AuditLogFormatterTest` untuk memastikan semua aksi memiliki label Bahasa Indonesia.
  - **Estimasi Jam**: `1.0 jam`.
  - **Butuh Keputusan Bisnis**: Tidak.

---

#### Tahap 4 â€” Kesiapan Deploy, Keamanan Header/Compose, & CI MySQL 8.4

- **Task 4.1 (F11-Sec & F11-Ops): Perketat CSP & Compose Secrets, Lengkapi Build di `Dockerfile`, dan Aktifkan Test Aplikasi pada MySQL 8.4 di CI**
  - **Tujuan**:
    1. Menghapus `cdn.jsdelivr.net` dari `SecurityHeaders.php:58`.
    2. Mengubah variabel password di `docker-compose.yml` agar wajib disuplai dari `.env` (`${DB_PASSWORD:?...}`) atau dokumentasikan pemisahan compose dev vs prod.
    3. Menambahkan instalasi dependensi (`composer install --no-dev --optimize-autoloader` dan asset build) serta `ServerTokens Prod` / `ServerSignature Off` pada `Dockerfile` agar image mandiri siap dideploy di server PLN.
    4. Menambahkan langkah `php artisan test` terhadap service MySQL 8.4 di `.github/workflows/ci.yml` sehingga seluruh test skema, migrasi (`StructuredKpiSchemaTest`), dan collation MySQL berjalan otomatis di setiap push/PR.
  - **File Terdampak**:
    - `app/Http/Middleware/SecurityHeaders.php` (baris 58)
    - `Dockerfile`
    - `docker-compose.yml`
    - `.github/workflows/ci.yml`
    - `tests/Feature/SecurityHeadersTest.php`
  - **Langkah TDD (Test Merah Dulu)**:
    1. Perbarui `tests/Feature/SecurityHeadersTest.php` untuk meng-assert bahwa header `Content-Security-Policy` **tidak** mengandung `cdn.jsdelivr.net`.
    2. Jalankan `php artisan test --filter=SecurityHeadersTest` -> merah.
    3. Hapus `cdn.jsdelivr.net` dari `SecurityHeaders.php:58`, perbarui `Dockerfile`, `docker-compose.yml`, dan `.github/workflows/ci.yml`.
    4. Jalankan `php artisan test --filter=SecurityHeadersTest` -> hijau.
  - **Acceptance Criteria**: CSP hanya mengizinkan `'self'` untuk `script-src` (plus `'unsafe-inline' 'unsafe-eval'` yang dibutuhkan Alpine runtime); CI menjalankan PHPUnit di SQLite dan MySQL 8.4; `Dockerfile` menghasilkan image yang memiliki `vendor/` dan `public/build/manifest.json`.
  - **Risiko Regresi**: Rendah; sudah diverifikasi di `resources/views/layouts/app.blade.php` bahwa tidak ada tag `<script src="https://cdn.jsdelivr.net/...">`.
  - **Estimasi Jam**: `2.5 jam`.
  - **Butuh Keputusan Bisnis**: Tidak.

---

#### Tahap 5 â€” Rekonsiliasi Dokumen Tracking & Pembersihan Kode Mati

- **Task 5.1 (F8 & F17): Sinkronisasi Dokumen Tracking (`task.md`, `.ai/context.md`, `.ai/progress.md`) & Penanganan Kode Mati**
  - **Tujuan**:
    1. Memperbarui `task.md` Section 2, `.ai/context.md`, dan merapikan duplikasi header `## Sesi 142` di `.ai/progress.md` agar 100% akurat terhadap `HEAD` terbaru.
    2. Menghapus atau mendokumentasikan secara eksplisit artefak kode mati (`PerformanceUploadController.php`, `target-vs-realisasi.blade.php`) sesuai kebijakan tim.
  - **File Terdampak**:
    - `task.md`, `.ai/context.md`, `.ai/progress.md`, `.ai/checkpoint.md`
  - **Langkah Verifikasi**:
    1. Pastikan seluruh angka commit, jumlah test, jumlah tabel (20 tabel, 21 migrasi), dan status `KPI_READ_MODEL=structured` konsisten di keempat file.
    2. Jalankan `git diff --check`.
  - **Acceptance Criteria**: Tidak ada kontradiksi status antara `task.md`, `.ai/checkpoint.md`, dan `.ai/context.md`.
  - **Risiko Regresi**: Nol.
  - **Estimasi Jam**: `1.0 jam`.
  - **Butuh Keputusan Bisnis**: Untuk penghapusan permanen `ReportController` dan `PerformanceUploadController` beserta 37 skipped test-nya, konfirmasi terlebih dahulu ke user apakah fitur `/reports/*` resmi dibatalkan untuk masa magang ini.

---

#### Tahap 6 â€” Kosmetik UI / Kandidat Prioritas Rendah (Plan UI/UX Lama Paket 2â€“4)
- **Cakupan**: Item-item dari `docs/superpowers/plans/2026-10-30-dashboard-uiux-redesign.md` (Paket 2: micro-interactions & empty state ilustrasi; Paket 3: penyempurnaan tipografi tabel/form; Paket 4: polish kosmetik tambahan).
- **Skor Prioritas**: `1 x 2 - 4.0 = -2.0` (Prioritas paling rendah).
- **Aturan**: Dilarang dikerjakan sebelum Tahap 0 s.d. Tahap 5 tuntas dan diverifikasi.

---

## 4. Bagian "Dipotong Jika Waktu Habis" (Safe to Drop)

Jika waktu magang tersisa kurang dari 1â€“2 minggu, item-item berikut **aman dipotong/dibuang** tanpa membahayakan integritas data, keamanan RBAC, maupun kebenaran angka KPI di tangan PLN:

1. **Seluruh Tahap 6 (Plan UI/UX Redesign Paket 2â€“4)**: Murni kosmetik tampilan; UI saat ini (Paket 1 + perbaikan Sesi 164â€“166) sudah fungsional, responsif, dan bersih.
2. **Investigasi & Pengaktifan Minifier Vite (`vite.config.js:19` `minify: false`)**: Bundle JS/CSS tanpa minifikasi tetap berjalan 100% benar di jaringan intranet PLN; ukuran ekstra beberapa ratus KB tidak berdampak pada fungsi bisnis.
3. **Penghapusan Fisik `ReportController.php` & `PerformanceUploadController.php` (F17)**: Selama route keduanya tetap tidak terdaftar/di-comment di `routes/web.php`, kode tersebut tidak dapat diakses oleh pengguna mana pun (nol risiko eksploitasi HTTP).
4. **Refactoring `performance_metrics.unit_id` (F16)**: Karena `KPI_READ_MODEL=structured` membaca `performance_observations.org_unit_id` dan `AssetStockReport` membaca `performance_metrics.name`/`value`, kolom `performance_metrics.unit_id` tidak dipakai oleh reader aktif mana pun.

---

## 5. Bagian "Menunggu Keputusan Bisnis" (Dilarang Dijadwalkan Tanpa Keputusan PLN / User)

Item-item di bawah ini menyentuh kontrak bisnis, hak akses peran organisasi, atau rumus perhitungan KPI. Sesuai aturan proyek, opsi dan trade-off dipetakan secara objektif **tanpa memilih secara sepihak**.

### 5.1 (F4) Akses Widget KPI (`kinerja-detail`, dll.) untuk Role Admin di `/dashboard` dan `/dashboard/builder`
- **Masalah Aktual**: Pada commit `ef99368`, akses Admin ke halaman `GET /dashboard/kpi-detail` dicabut (`canAccessKpiDetail()` mengembalikan `false` untuk Admin). Namun di `config/dashboard_layouts.php`, widget `kinerja-detail` (serta widget KPI lain) memiliki `'permission' => null` dan masih terpasang di layout `admin` (ID 6 di DB), sehingga Admin tetap melihat tabel Rincian KPI di `/dashboard` dan bisa memasangnya lewat `/dashboard/builder`.
- **Opsi A â€” Cabut Total Widget KPI dari Layout Admin**:
  - Tambahkan guard `allowed_roles` pada katalog widget di `config/dashboard_layouts.php` sehingga `admin` hanya boleh memasang widget administratif (`dataset-status`), dan bersihkan widget KPI dari baris `dashboard_layouts` milik `admin`.
  - *Trade-off*: Konsisten 100% dengan pencabutan `/dashboard/kpi-detail` di `ef99368`, tetapi Admin kehilangan kemampuan memantau tampilan widget KPI secara langsung dari akunnya sendiri.
- **Opsi B â€” Kembalikan Akses Read-Only `/dashboard/kpi-detail` untuk Admin**:
  - Kembalikan `Role::ADMIN` ke `DashboardController::canAccessKpiDetail()`.
  - *Trade-off*: Selaras dengan `AGENTS.md` Bagian 4 ("Admin: semua permission") dan memungkinkan Admin melakukan verifikasi tampilan lintas widget setelah konfigurasi layout, tetapi membatalkan pembatasan yang diminta pada `ef99368`.
- **Opsi C â€” Pertahankan Kondisi Saat Ini Secara Eksplisit**:
  - Admin boleh melihat ringkasan widget di `/dashboard` (untuk keperluan pratinjau layout), namun tidak masuk ke halaman rincian mandiri `/dashboard/kpi-detail`.
  - *Trade-off*: Nol perubahan kode, namun secara keamanan informasi bukan isolasi data (karena data tabel R7 tetap terkirim ke `/dashboard` jika widget `kinerja-detail` dipasang).

### 5.2 Kebijakan Upload Parsial Data Aset (`Managemen Aset`)
- **Masalah Aktual**: Setelah fix F1 (`4a0419e`), `AssetStockReport::latestDatasetIds()` hanya membaca **satu** dataset `READY` terbaru (`MAX(id)`) per `(division_id, period_date)`. Jika operator mengunggah file Excel kedua pada periode yang sama yang hanya berisi sebagian UPT (misalnya hanya `UPT BANDUNG`) atau sebagian atribut, maka 5 UPT lainnya dari upload pertama tidak lagi terbaca di dashboard dan halaman detail Aset, tanpa adanya peringatan atau konfirmasi arsip saat upload.
- **Opsi A â€” Kebijakan Full-Snapshot per Periode + Peringatan `confirm_archive` (Seperti KPI)**:
  - Tetap gunakan aturan 1 dataset aktif per periode (`4a0419e`), tetapi tambahkan deteksi periode eksisting + dialog konfirmasi penggantian dataset (`confirm_archive`) pada upload Aset, disertai peringatan di UI bahwa file yang diunggah harus memuat seluruh UPT dan atribut untuk periode tersebut.
  - *Trade-off*: Arsitektur paling bersih, bebas risiko duplikasi, dan konsisten dengan alur upload KPI; namun mengharuskan staf mengunggah workbook lengkap setiap kali melakukan revisi.
- **Opsi B â€” Merge / Upsert per `(UPT, Atribut)` Lintas Dataset pada Periode yang Sama**:
  - Mengubah query `AssetStockReport` agar mengambil nilai terbaru per kombinasi `(metric_name, upt)` di dalam periode tersebut, bukan per `dataset_id`.
  - *Trade-off*: Mendukung upload tambal-sulam (parsial) per UPT, tetapi jauh lebih kompleks untuk diaudit (angka di dashboard berasal dari gabungan beberapa file dataset yang berbeda) dan menyulitkan penghapusan atribut yang memang sengaja dihilangkan.
- **Opsi C â€” Validasi Kelengkapan 6 UPT Kanonis Saat Upload Aset**:
  - Menolak upload file Aset jika tidak memuat minimal ke-6 UPT kanonis (`UPT BANDUNG`, `UPT CIREBON`, `UPT BOGOR`, `UPT KARAWANG`, `UPT BEKASI`, `UPT PURWAKARTA`).
  - *Trade-off*: Mencegah ketidaksengajaan upload file parsial, tetapi kaku jika di masa depan ada laporan khusus untuk 1 UPT saja.

### 5.3 (F7b) Kontrak Perhitungan Skor Pencapaian Numerik di Tabel Rincian KPI (R7) vs Polaritas Indikator
- **Masalah Aktual**: Pada Dataset #49 (`org_unit = TJBT`), terdapat 4 indikator ber-polaritas `lower_is_better` (#394 `Kali sirkit padam karena gangguan`, #396 `Kali gangguan pada peralatan di Another Bay`, #397 `Emergency Response time`, #406 `Pengendalian NAC`). Di file Excel PLN dan di widget `kpi-rag-status` serta `target-realisasi-per-indikator`, keempat indikator ini memakai kolom `pencapaian` Excel = `110%` (**Hijau / Tercapai**). Namun di tabel `kinerja-detail` (R7), karena Sesi 99 memutuskan rumus numerik R7 adalah `round($realisasi / $target * 100, 1)`, keempat indikator tersebut dihitung sebagai `75.1%`, `60.1%`, `13.7%`, dan `77.3%` (**Merah / Perlu Perhatian**), serta indikator #405 (`Usulan Penghapusan ATTB`) dihitung `632.0%`.
- **Opsi A â€” Gunakan Skor `pencapaian` Excel (Dinormalisasi & Di-clamp 110%) untuk Semua Baris di R7**:
  - Menyamakan sumber angka `pencapaian (%)` dan warna RAG di R7 (`kinerja-detail`) dengan R6 (`kpi-rag-status`) dan `target-realisasi-per-indikator`.
  - *Trade-off*: Seluruh widget di dashboard menampilkan warna RAG dan persentase pencapaian yang 100% konsisten dengan buku laporan resmi Excel SPI PLN; namun mengubah keputusan Sesi 99 (`realisasi / target * 100`).
- **Opsi B â€” Pertahankan `realisasi / target * 100` untuk `higher_is_better`, dan Gunakan Rumus Invers / Skor Excel untuk `lower_is_better`**:
  - Menghormati kolom `indicators.polarity` (`lower_is_better` vs `higher_is_better`).
  - *Trade-off*: Memperbaiki anomali merah palsu pada indikator gangguan/response time, tetapi tetap bisa berbeda dari skor Excel jika rumus Excel memiliki batas cap/floor khusus.
- **Opsi C â€” Pertahankan Rumus `realisasi / target * 100` (Sesi 99) + Tambahkan Catatan Penjelas di UI**:
  - Tidak mengubah rumus di controller; hanya menambahkan keterangan di header kolom `Pencapaian (%)` bahwa angka dihitung sebagai rasio langsung `Realisasi / Target`.
  - *Trade-off*: Nol perubahan logika backend, namun warna RAG Merah pada indikator gangguan yang sebenarnya berprestasi baik (`0.41` dari batas `3.00`) tetap berpotensi memicu komplain dari manajemen PLN.

### 5.4 Blocker Tata Kelola Eksternal (Di Luar Kendali Developer)
1. **Domain MANSET (`aset-upt-chart` / `FORBIDDEN_WIDGETS`)**: Tetap berstatus **NO-GO** sampai ada penunjukan PIC resmi bidang Managemen Aset, Berita Acara serah-terima definisi data, dan keputusan atas 38 atribut provisional (lihat `Obsidian/10 - Reports/MANSET NO-GO Decision Log 2026-11-10.md`).
2. **Domain Program Keandalan / SPARTA**: Belum ada skema maupun sampel file sumber dari PLN.
3. **Kontrak Unit Tampil KPI (`config/kpi.php` `visible_units = ['TJBT']`)**: Berdasarkan interpretasi user atas arahan lisan, belum berupa sign-off tertulis mentor/Pak Gama.

---

## 6. Daftar Definition of Done (DoD) Handover ke PLN UIT JBT

Aplikasi dinyatakan siap diserahterimakan kepada tim penerima di PLN UIT JBT apabila seluruh kriteria berikut terpenuhi dan terverifikasi:

1. **Sinkronisasi & Cadangan Kode**:
   - [x] `HEAD` lokal dan `origin/main` tersinkronisasi (`ahead 0, behind 0`), working tree bersih, dan file `git bundle` cadangan tersedia (Tahap 0 Sesi 169).
2. **Keamanan & Otorisasi (RBAC)**:
   - [x] Keempat role (`admin`, `management`, `editor_dashboard`, `staff_divisi`) telah diverifikasi isolasi datanya.
   - [x] Rename nama divisi tidak merusak slug kanonis (`perencanaan-pengusahaan`, `managemen-aset`) maupun layout dashboard (F2 selesai).
   - [x] Endpoint `PUT`/`DELETE /dashboard/layout` hanya menerima target kanonis yang sah sesuai role pemanggil (F3 & F13 selesai).
   - [ ] Header keamanan HTTP (CSP tanpa `cdn.jsdelivr.net`, X-Frame-Options, nosniff, Referrer-Policy) aktif di semua response (F11-Sec).
   - [x] Audit log append-only (`audit_logs`) mencatat `LOGIN`, `LOGOUT`, `LOGIN_FAILED`, `IMPORT`, `CREATE`, `UPDATE`, dan `DELETE` (termasuk penghapusan layout saat divisi dihapus), serta dilindungi oleh trigger/grant database (`harden-audit-log.sql`) (F12 selesai).
3. **Integritas Data & Pipeline Import**:
   - [x] Import KPI (`StructuredKpiImporter`) tahan terhadap perbedaan huruf besar/kecil nama indikator pada MySQL `utf8mb4_unicode_ci` tanpa melempar Error 1062 (F5 selesai).
   - [x] Kegagalan di tengah proses `importKpiReport` tidak pernah meninggalkan dataset berstatus `READY` atau file `.xlsx` yatim di `storage/app/private/datasets/` (F6 & F14 selesai).
   - [x] Agregasi data Aset hanya menghitung dataset `READY` terbaru per `(division_id, period_date)` (F1 selesai di `4a0419e`).
4. **Kebenaran Angka KPI & Visualisasi**:
   - [x] Tabel `kinerja-detail` tidak melakukan normalisasi ganda pada nilai persentase `<= 1.10%` (F7a selesai).
   - [x] Seluruh widget KPI menggunakan satu sumber ambang batas RAG terpusat (`KpiScoreNormalizer::ragThresholds()`) (F15 selesai).
   - [x] Widget `dataset-status` menampilkan jumlah baris aktif yang benar dan label yang sesuai domain untuk divisi KPI maupun non-KPI (F10 selesai).
5. **Infrastruktur, CI, & Dokumentasi Handover**:
   - [ ] `Dockerfile` mampu membangun image mandiri (termasuk `composer install` dan build aset frontend), dan `docker-compose.yml` tidak memakai password default lemah tanpa disadari (F11-Ops).
   - [ ] Seluruh test suite (`php artisan test`), `vendor/bin/pint --test`, dan `git diff --check` lulus hijau baik di SQLite maupun di MySQL 8.4 pada GitHub Actions CI.
   - [x] Paket dokumen handover di `docs/handover/` (`01` s.d. `06`), `task.md`, `.ai/context.md`, dan `.ai/checkpoint.md` sinkron dengan kondisi commit terakhir (F8 selesai).
   - [ ] Nama peran/PIC penerima aplikasi dan server di pihak PLN telah tercatat di dokumen serah terima.

---

## 7. Prompt Eksekusi Siap Pakai (`/sesi ...`) untuk 3 Task Pertama

Berikut adalah 3 prompt eksekusi berurutan yang siap disalin-tempel oleh user untuk menjalankan tahapan prioritas tertinggi dengan prinsip TDD ketat:

### Prompt 1 â€” Eksekusi Tahap 0 & Tahap 1 (Backup, Fix F2 Division Slug, Fix F3 & F13 Layout Target Guard)
```text
/sesi Eksekusi Tahap 0 dan Tahap 1 dari docs/superpowers/plans/2026-11-10-prehandover-stabilization-roadmap.md:
1. Buat backup git bundle di luar folder repo (../plnjbt-dashboard-backup-2026-11-10.bundle) dan push 10 commit lokal ke origin/main.
2. Kerjakan Task 1.1 (F2) dengan TDD (test merah dulu di tests/Feature/DivisionCrudTest.php): pastikan DivisionController::update() (PATCH /divisions/{division}) tidak mengubah kolom slug saat nama divisi diubah, sehingga slug kanonis seperti 'perencanaan-pengusahaan' tetap utuh.
3. Kerjakan Task 1.2 (F3 & F13) dengan TDD (test merah dulu di tests/Feature/DashboardWidgetLayoutTest.php): batasi DashboardController::validateLayoutTarget() hanya pada 3 target kanonis, cegah role editor_dashboard memodifikasi/mereset layout admin (403), dan hapus paksaan widget mati 'layout-editor-placeholder' dari ensureBuilderWidgetForSelfManagedLayout(), validateLayoutTarget(), serta config/dashboard_layouts.php.
4. Jalankan vendor/bin/pint --dirty, test spesifik yang terdampak (DivisionCrudTest, DivisionSlugHarmonizationTest, DashboardWidgetLayoutTest, DashboardLayoutEditorWidthTest), dan git diff --check. Commit dalam unit logis yang terpisah dan update checkpoint.md, task.md, serta Daily Notes. Tanpa emoji.
```

### Prompt 2 â€” Eksekusi Tahap 2 (Fix F7a Double-Normalize R7, Fix F10 Dataset Status MANSET, Fix F15 RAG Thresholds & F9 User Delete Dialog)
```text
/sesi Eksekusi Tahap 2 dari docs/superpowers/plans/2026-11-10-prehandover-stabilization-roadmap.md (DILARANG mengubah formula KPI di controller atau menyentuh FORBIDDEN_WIDGETS):
1. Kerjakan Task 2.1 (F7a) dengan TDD di tests/Feature/KpiDetailPageTest.php: tulis test merah yang membuktikan nilai pencapaian <= 1.10% (misal 0.8% dan 1.0%) di resources/views/components/widgets/kinerja-detail.blade.php:195 mengalami double-normalization menjadi 80% dan 100%, lalu perbaiki blade tersebut agar menggunakan langsung $numVal untuk max(0, min(100, $numVal)) dan KpiScoreNormalizer::ragTier($numVal) tanpa memanggil KpiScoreNormalizer::normalize() dua kali.
2. Kerjakan Task 2.2 (F10) dengan TDD di tests/Feature/DashboardWidgetScopingTest.php: perbaiki DashboardController::datasetStatusStructured() dan resources/views/components/widgets/dataset-status.blade.php agar untuk divisi non-KPI (seperti Managemen Aset yang datanya berada di performance_metrics) menghitung jumlah baris aktif dari dataset READY terbaru dan menampilkan label "Baris Data Aktif" tanpa "(TJBT)", sambil menjaga kontrak KpiReadModelFlagTest.
3. Kerjakan Task 2.3 (F15 & F9) dengan TDD di tests/Feature/UserCrudTest.php, tests/Feature/NkoRankingWidgetTest.php, dan tests/Unit/KpiScoreNormalizerRagTierTest.php: ganti seluruh hardcode ambang batas RAG (>= 100 dan >= 95) di kinerja-realisasi.blade.php, nko-ranking.blade.php, DashboardController::nkoDonut/Structured(), dan CustomChartController agar menggunakan KpiScoreNormalizer; serta perbaiki teks konfirmasi hapus user di resources/views/users/index.blade.php:71 dan docblock UserController::destroy() agar akurat sesuai FK nullOnDelete.
4. Jalankan vendor/bin/pint --dirty, test spesifik yang terdampak, dan git diff --check. Commit per unit logis dan update dokumen tracking. Tanpa emoji.
```

### Prompt 3 â€” Eksekusi Tahap 3 (Fix F5 Case-Insensitive Indicator Lookup, Fix F6 Import Transaction & Cleanup, Fix F12 Audit Trail)
```text
/sesi Eksekusi Tahap 3 dari docs/superpowers/plans/2026-11-10-prehandover-stabilization-roadmap.md (Zero DDL / dilarang menambah migrasi baru):
1. Kerjakan Task 3.1 (F5) dengan TDD di tests/Feature/StructuredKpiDualWriteTest.php: normalkan key lookup $existingIndicators di app/Services/StructuredKpiImporter.php menggunakan mb_strtolower(trim($name), 'UTF-8') per org_unit_id agar selaras dengan collation utf8mb4_unicode_ci di MySQL 8.4 dan mencegah Integrity constraint violation 1062.
2. Kerjakan Task 3.2 (F6 & F14) dengan TDD di tests/Feature/DatasetKpiReportImportTest.php: pastikan DatasetController::importKpiReport() (route datasets.kpi-import, POST /datasets/import-kpi-report) tidak membiarkan dataset berstatus DatasetStatus::READY atau meninggalkan file fisik yatim (datasets/{$dataset->filename}) di storage/app/private/datasets/ ketika structuredImporter atau pengarsipan gagal, bungkus kompensasi dengan try/catch yang aman, dan sertakan orWhereHas('observations') pada deteksi periode KPI.
3. Kerjakan Task 3.3 (F12) dengan TDD di tests/Feature/AuthTest.php, tests/Feature/DivisionCrudTest.php, dan tests/Feature/AuditLogTest.php: catat event Illuminate\Auth\Events\Failed ke AuditLog (ACTION_LOGIN_FAILED tanpa menyimpan password, daftarkan di AuditLogController dan AuditLogFormatter), dan hapus DashboardLayout via Eloquent di DivisionController::destroy() sebelum menghapus Division agar tercatat di audit_logs.
4. Jalankan vendor/bin/pint --dirty, test spesifik yang terdampak, lalu full suite php artisan test dan git diff --check. Commit per unit logis dan update dokumen tracking. Tanpa emoji.
```

---

## Lampiran: Catatan Verifikasi Independent Reviewer (Self-Audit Checklist)

Dokumen rencana ini telah diaudit terhadap seluruh syarat di instruksi tugas:
1. **Bukti Kode Aktual (`HEAD 4a0419e`) & Database Riil**: Seluruh 16 item temuan (F2 s.d. F17 + Upload Parsial Aset) memiliki status tegas (`CONFIRMED`), lokasi `file:baris` yang diverifikasi pada `HEAD 4a0419e`, serta reproduksi minimal dan bukti query dari MySQL 8.4 dev (`plnjbt_db`).
2. **Konsistensi Urutan Prioritas**: Urutan tahapan mengikuti rumus `Dampak x Kemungkinan - Biaya (jam)` dan hierarki kerusakan (`Backup > Otorisasi & Integritas Referensial > Kebenaran Angka KPI > Hardening Import & Audit > Kesiapan Deploy/CI > Rekonsiliasi Dokumen > Kosmetik UI`). Plan UI/UX lama (Paket 2â€“4) ditempatkan di Tahap 6 dan Bagian 4 ("Dipotong Jika Waktu Habis").
3. **Kepatuhan Larangan (Constraints)**: Tidak ada rekomendasi yang melanggar aturan Zero DDL, tidak ada penambahan package Composer/NPM, tidak ada perubahan sepihak pada formula KPI (F7b dipisahkan ke Bagian 5.3), dan tidak ada pengaktifan `aset-upt-chart` / `FORBIDDEN_WIDGETS`.
4. **Status Review**: **APPROVED**.

# ReportController Plan

Generated: 2026-09-11
Commit baseline: 007741e — 124 passed / 12 skipped / 0 failed, working tree bersih.

---

## 1. Route + Gate Permission per Method

| Method | HTTP | URL | Gate (middleware) |
|---|---|---|---|
| `validationExport($dataset)` | GET | `/reports/datasets/{dataset}/validation` | `can:dataset.view` + `can:report.export` |
| `datasetSummaryExport(Request)` | GET | `/reports/datasets/summary` | `can:dataset.view` + `can:report.export` |
| `auditExport(Request)` | GET | `/reports/audit` | `can:audit.view` + `can:report.export` |

Semua route di bawah grup `auth` middleware.
Route statis `summary` dan `audit` TIDAK punya wildcard — tidak ada konflik.

---

## 2. Struktur Kolom Excel per Report

### 2a. validationExport

File: `{original_name_tanpa_ext}-validation.xlsx`
Sheet: `Validasi`

| Kolom | Sumber |
|---|---|
| Baris | `$issue['row']` |
| Severity | `$issue['severity']` |
| Pesan | `$issue['message']` |

Sumber data: `$dataset->validation_report['issues']` — SEMUA issue (tanpa cap 50).
Dataset tanpa issues = file header-only (tidak error).

### 2b. datasetSummaryExport

File: `dataset-summary-{Y-m-d}.xlsx`
Sheet: `Ringkasan Dataset`

| Kolom | Sumber |
|---|---|
| Nama File | `original_name` |
| Divisi | `division.name` |
| Pengunggah | `user.name` |
| Status | `status->value` |
| Ukuran | size (KB, 1 desimal) |
| Tanggal Upload | `created_at` format d/m/Y H:i |
| Terakhir Diperbarui | MAX(`performance_metrics.updated_at`) per dataset — JOIN, bukan kolom baru |

Scoping: reuse `seesAllDivisions()` dari DatasetController (pindah ke private helper atau duplikasi kecil).
Semua status (termasuk ARCHIVED) ikut di export — tidak difilter status.

### 2c. auditExport

File: `audit-log-{from}-{to}.xlsx`
Sheet: `Audit Log`

| Kolom | Sumber |
|---|---|
| Waktu | `created_at` format d/m/Y H:i:s |
| User | `user.name` (null = '-') |
| Aksi | `AuditLogFormatter::actionLabel()` |
| Objek | `AuditLogFormatter::objectLabel()` |
| Perubahan | Ringkasan diff (label: lama -> baru, satu baris per kolom berubah) |
| IP | `ip` (null = '-') |

Gate `audit.view` + `audit.view_all` scoping identik dengan `AuditLogController::index()`.
Wajib: `from` + `to` hadir, rentang maksimal 90 hari — tolak 422 bila tidak.

---

## 3. Urutan Pengerjaan

1. `validationExport` — paling sederhana, tidak ada query kompleks, test paling terisolasi
2. `datasetSummaryExport` — satu JOIN tambahan (MAX updated_at), scoping reuse
3. `auditExport` — paling banyak guard (date range, 90 hari, scoping audit.view_all)

Setiap method: implementasi → view link → test → pint → commit+push.

---

## 4. File yang Akan Dibuat / Dimodifikasi

**Baru:**
- `app/Http/Controllers/ReportController.php`
- `tests/Feature/ReportValidationExportTest.php`
- `tests/Feature/ReportDatasetSummaryExportTest.php`
- `tests/Feature/ReportAuditExportTest.php`

**Dimodifikasi:**
- `routes/web.php` — tambah 3 route report
- `resources/views/datasets/show.blade.php` — tambah link validation export
- `resources/views/datasets/index.blade.php` — tambah link summary export
- `resources/views/audit-logs/index.blade.php` — tambah link audit export

---

## 5. Pola PhpSpreadsheet (reuse dari DatasetController::export())

```php
$spreadsheet = new Spreadsheet;
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('...');
$sheet->fromArray([...headers...], null, 'A1');
$sheet->getStyle('A1:X1')->getFont()->setBold(true);
// rows...
foreach (['A','B',...] as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }
$tempPath = tempnam(sys_get_temp_dir(), 'report_') . '.xlsx';
(new Xlsx($spreadsheet))->save($tempPath);
$spreadsheet->disconnectWorksheets();
return response()->download($tempPath, $filename, [...mime...])->deleteFileAfterSend();
```

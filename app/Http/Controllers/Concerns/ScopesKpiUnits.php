<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Query\Builder;

/**
 * Pembatasan unit yang TAMPIL di dashboard KPI.
 *
 * Provenance: keputusan USER (2026-10-12), BUKAN sign-off formal mentor/Pak
 * Gama. Dasar = kuotasi percakapan user: "yang ditampilkan hanya unit kita
 * saja, unit induk. kalau unit lainnya itu beda lagi." Kuotasi itu ambigu
 * antara "hanya Unit Induk Transmisi Jawa Bagian Tengah (TJBT)" dan "seluruh
 * kelas Unit Induk lain". Scope yang dipakai proyek ini = TJBT SAJA, dan itu
 * INTERPRETASI scope oleh user, bukan kuotasi eksplisit.
 *
 * Batas = TAMPILAN saja (read-path): unit lain tetap diimpor, disimpan, dan
 * tidak dihapus — hanya TIDAK ditampilkan di dashboard; impor unit lain tidak
 * ditolak.
 *
 * Daftar unit ada di config `kpi.visible_units` supaya satu sumber kebenaran
 * untuk kedua controller (dashboard + chart API) dan tidak menyentuh schema.
 * Nilai `null` atau array kosong = TANPA pembatasan (perilaku lama), disediakan
 * sebagai jaring rollback.
 *
 * Identitas unit CASE-SENSITIVE: 'tjbt' BUKAN 'TJBT' (sejalan dengan collation
 * `org_units.name` = utf8mb4_bin). Perbandingan memakai `in_array(..., true)`.
 *
 * Filter diterapkan pada batas PRESENTASI unit — SETELAH pemilihan periode dan
 * SETELAH pemilihan dataset terbaru — sehingga mekanisme "latest dataset wins"
 * tidak berubah; yang berubah hanya unit mana yang ditampilkan.
 */
trait ScopesKpiUnits
{
    /**
     * Daftar unit yang boleh tampil, atau null bila tanpa pembatasan.
     *
     * @return list<string>|null
     */
    private function visibleUnits(): ?array
    {
        $units = config('kpi.visible_units');

        return is_array($units) && $units !== [] ? array_values($units) : null;
    }

    private function unitVisible(string $unit): bool
    {
        $visible = $this->visibleUnits();

        return $visible === null || in_array($unit, $visible, true);
    }

    /**
     * Saring daftar nama unit ke scope yang boleh tampil, urutan dipertahankan.
     *
     * @param  list<string>  $units
     * @return list<string>
     */
    private function scopeUnits(array $units): array
    {
        $visible = $this->visibleUnits();

        if ($visible === null) {
            return array_values($units);
        }

        return array_values(array_filter(
            $units,
            fn (string $unit): bool => in_array($unit, $visible, true),
        ));
    }

    /**
     * Hitung baris performance_metrics milik unit yang boleh tampil.
     *
     * Unit = prefix `metric_name` sebelum " | " (pola legacy yang sama dengan
     * reader lain); baris tanpa prefix unit tidak dihitung saat pembatasan
     * aktif. Tanpa pembatasan (`null`) = COUNT apa adanya (perilaku lama).
     *
     * ponytail: LIKE prefix hanya prefilter kasar (case-insensitive di MySQL),
     * kecocokan pasti case-sensitive dicek di PHP lewat unitVisible(). Pindah
     * ke kolom unit terindeks bila jumlah baris membuat pluck terlalu berat.
     */
    private function countVisibleUnitRows(Builder $query): int
    {
        $visible = $this->visibleUnits();

        if ($visible === null) {
            return $query->count();
        }

        return $query
            ->where(function (Builder $q) use ($visible): void {
                foreach ($visible as $unit) {
                    $q->orWhere('performance_metrics.metric_name', 'like', $unit.' | %');
                }
            })
            ->pluck('performance_metrics.metric_name')
            ->filter(fn (string $name): bool => $this->unitVisible(explode(' | ', $name, 2)[0]))
            ->count();
    }

    /**
     * Saring peta ber-key nama unit (mis. pairs[unit][indikator], nko[unit]).
     * Nilai per unit TIDAK diubah — hanya entri unit di luar scope yang dibuang,
     * sehingga agregasi di dalam unit yang tersisa tetap utuh.
     *
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function scopeUnitMap(array $map): array
    {
        $visible = $this->visibleUnits();

        if ($visible === null) {
            return $map;
        }

        return array_intersect_key($map, array_flip($visible));
    }
}

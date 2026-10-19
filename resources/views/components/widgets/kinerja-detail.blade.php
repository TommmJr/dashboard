@props(['widget' => [], 'data' => null])

{{-- Widget detail metrik KPI per unit/indikator (server-side).
     Data dihitung dari laporan KPI untuk periode terpilih. --}}
@php
    $period = $data['period'] ?? null;
    $rows = collect($data['rows'] ?? []);
    $truncated = (bool) ($data['truncated'] ?? false);
    $totalRows = (int) ($data['total_rows'] ?? 0);

    // Daftar SEMUA unit di data (termasuk yang barisnya terpotong cap 50),
    // dipakai untuk dropdown pemilih unit. Payload reader tidak ditambah key.
    $unitOptions = array_values(array_filter(
        (array) ($widget['units'] ?? []),
        fn ($unit): bool => is_string($unit) && $unit !== '',
    ));

    // Filter yang sedang aktif dibaca langsung dari request: komponen ini
    // hanya menerima `widget` + `data`, bukan variabel $filters halaman.
    $startDate = request('start_date');
    $endDate = request('end_date');
    $requestedUnit = request('unit');

    // Unit terpilih hanya bila ada di daftar; kalau tidak, tidak ada highlight
    // (perilaku default tetap berlaku).
    $selectedUnit = is_string($requestedUnit) && in_array($requestedUnit, $unitOptions, true)
        ? $requestedUnit
        : null;

    // ponytail: format Indonesia (desimal koma, ribuan titik). Nilai bulat tampil
    // bulat (100,00 → 100, 100,0% → 100%); pecahan asli tetap maks 2 desimal
    // (105,50 → 105,5).
    $fmt = function ($value, int $decimals, string $suffix = ''): string {
        if ($value === null) {
            return '—';
        }

        $formatted = number_format((float) $value, $decimals, ',', '.');

        if (str_contains($formatted, ',')) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return $formatted.$suffix;
    };
@endphp

<div class="rounded-lg bg-white border border-slate-200 shadow-sm p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Detail Metrik KPI' }}</h3>
            <p class="mt-1 text-sm text-gray-500">Periode {{ $period ?? '—' }}</p>
        </div>

        @if (! empty($unitOptions))
            {{-- Form GET: halaman dimuat ulang supaya widget server-side lain
                 (kinerja-realisasi, target vs realisasi) ikut unit yang sama.
                 Filter tanggal dibawa agar tidak hilang saat pindah unit. --}}
            <form method="GET" action="{{ route('dashboard') }}" class="flex flex-wrap items-end gap-2">
                @if (is_string($startDate) && $startDate !== '')
                    <input type="hidden" name="start_date" value="{{ $startDate }}">
                @endif
                @if (is_string($endDate) && $endDate !== '')
                    <input type="hidden" name="end_date" value="{{ $endDate }}">
                @endif
                <label for="kinerja-detail-unit" class="sr-only">Pilih unit</label>
                <select id="kinerja-detail-unit" name="unit" onchange="this.form.submit()"
                        class="h-9 rounded border border-slate-300 bg-white px-2 text-sm text-gray-700">
                    <option value="">Semua unit</option>
                    @foreach ($unitOptions as $unitOption)
                        <option value="{{ $unitOption }}" @selected($selectedUnit === $unitOption)>{{ $unitOption }}</option>
                    @endforeach
                </select>
                <noscript>
                    <button type="submit" class="h-9 rounded border border-slate-300 bg-white px-3 text-sm text-gray-700">Tampilkan</button>
                </noscript>
                {{-- Jalan pintas supaya pembaca non-developer langsung menjangkau
                     TJBT (di urutan pertama daftar). --}}
                <a href="{{ route('dashboard', array_filter([
                        'unit' => 'TJBT',
                        'start_date' => is_string($startDate) ? $startDate : null,
                        'end_date' => is_string($endDate) ? $endDate : null,
                    ])) }}"
                   class="inline-flex h-9 items-center rounded border border-primary px-3 text-sm font-medium text-primary hover:bg-primary hover:text-white">
                    TJBT
                </a>
            </form>
        @endif
    </div>

    @if ($rows->isEmpty())
        <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">
            Belum ada data metrik pada periode ini.
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="mt-4 w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-gray-600">
                        <th class="py-2">Unit</th>
                        <th class="py-2">Indikator</th>
                        <th class="py-2 text-right">Target</th>
                        <th class="py-2 text-right">Realisasi</th>
                        <th class="py-2 text-right">Pencapaian %</th>
                        <th class="py-2 text-right">NKO</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-b">
                            <td class="py-2 font-medium">{{ $row['unit'] }}</td>
                            <td class="py-2">{{ $row['indikator'] }}</td>
                            <td class="py-2 text-right">{{ $row['target_display'] ?? $fmt($row['target'] ?? null, 2) }}</td>
                            <td class="py-2 text-right">{{ $row['realisasi_display'] ?? $fmt($row['realisasi'] ?? null, 2) }}</td>
                            <td class="py-2 text-right">{{ $fmt($row['pencapaian'] ?? null, 1, '%') }}</td>
                            <td class="py-2 text-right">{{ $fmt($row['nko'] ?? null, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($truncated)
            <p class="mt-3 text-xs text-gray-500">Menampilkan 50 dari {{ $totalRows }} baris.</p>
        @endif
    @endif
</div>

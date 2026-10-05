@props(['widget' => [], 'data' => null])

{{-- Widget detail metrik KPI per unit/indikator (server-side).
     Data dihitung dari laporan KPI untuk periode terpilih. --}}
@php
    $period = $data['period'] ?? null;
    $rows = collect($data['rows'] ?? []);
    $truncated = (bool) ($data['truncated'] ?? false);
    $totalRows = (int) ($data['total_rows'] ?? 0);

    $fmt = function ($value, int $decimals, string $suffix = ''): string {
        return $value === null ? '—' : number_format((float) $value, $decimals).$suffix;
    };
@endphp

<div class="rounded-lg bg-white border border-slate-200 shadow-sm p-6">
    <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Detail Metrik KPI' }}</h3>
    <p class="mt-1 text-sm text-gray-500">Periode {{ $period ?? '—' }}</p>

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
                            <td class="py-2 text-right">{{ $fmt($row['target'] ?? null, 2) }}</td>
                            <td class="py-2 text-right">{{ $fmt($row['realisasi'] ?? null, 2) }}</td>
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

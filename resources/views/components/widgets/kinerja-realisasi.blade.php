@props(['widget' => [], 'data' => null])

{{-- Widget realisasi vs target KPI per unit (server-side).
     Data dihitung dari periode terbaru; status mengikuti legenda laporan:
     Tercapai >= 100, Hampir Tercapai >= 95, Perlu Peningkatan < 95. --}}
@php
    $period = $data['period'] ?? null;
    $rows = $data['rows'] ?? [];
    $hasData = ($data['has_data'] ?? false) && ! empty($rows);

    $pill = function (string $status): string {
        return match ($status) {
            'Tercapai' => 'bg-green-100 text-green-800',
            'Hampir Tercapai' => 'bg-yellow-100 text-yellow-800',
            default => 'bg-red-100 text-red-800',
        };
    };
@endphp

<div class="rounded-lg bg-white border border-slate-200 shadow-sm p-6">
    <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Realisasi vs Target' }}</h3>
    <p class="mt-1 text-xs text-gray-500">Periode {{ $period ?? '—' }}</p>

    @if (! $hasData)
        <p class="mt-4 rounded-lg bg-slate-50 p-5 text-sm text-gray-600">
            Belum ada data realisasi vs target pada periode ini.
        </p>
    @else
        <table class="mt-4 w-full text-sm">
            <thead>
                <tr class="border-b text-left text-gray-600">
                    <th class="py-2">Unit</th>
                    <th class="py-2">Tercapai</th>
                    <th class="py-2 text-right">Rata-rata Pencapaian</th>
                    <th class="py-2 text-right">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $row['unit'] ?? '—' }}</td>
                        <td class="py-2">{{ number_format((float) ($row['achieved'] ?? 0), 0, ',', '.') }} dari {{ number_format((float) ($row['total'] ?? 0), 0, ',', '.') }}</td>
                        <td class="py-2 text-right">{{ number_format((float) ($row['avg'] ?? 0), 1, ',', '.') }}%</td>
                        <td class="py-2 text-right">
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $pill((string) ($row['status'] ?? '')) }}">{{ $row['status'] ?? 'Perlu Peningkatan' }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

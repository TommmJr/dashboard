@props(['widget' => [], 'data' => null])

{{-- Widget peringkat NKO per unit (server-side).
     Data dihitung DashboardController::nkoRanking() untuk periode terbaru.
     Kategori mengikuti legenda laporan KPI: Tercapai >= 100,
     Hampir Tercapai 95-100, Perlu Peningkatan < 95. --}}
@php
    $rows = collect($data ?? []);

    $category = function (float $nko): array {
        return match (true) {
            $nko >= 100 => ['Tercapai', 'bg-green-100 text-green-800'],
            $nko >= 95 => ['Hampir Tercapai', 'bg-yellow-100 text-yellow-800'],
            default => ['Perlu Peningkatan', 'bg-red-100 text-red-800'],
        };
    };
@endphp

<div class="rounded-lg bg-white p-6 shadow">
    <h3 class="text-lg font-bold text-primary">{{ $widget['label'] ?? 'Peringkat NKO Unit' }}</h3>

    @if ($rows->isEmpty())
        <p class="mt-4 rounded-lg bg-surface p-5 text-sm text-gray-600">
            Belum ada data NKO. Konversi laporan KPI lalu unggah berkasnya lewat menu Upload Dataset.
        </p>
    @else
        <table class="mt-4 w-full text-sm">
            <thead>
                <tr class="border-b text-left text-gray-600">
                    <th class="py-2">#</th>
                    <th class="py-2">Unit</th>
                    <th class="py-2 text-right">NKO</th>
                    <th class="py-2 text-right">Kategori</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $index => $row)
                    @php
                        [$label, $classes] = $category((float) $row->nko);
                    @endphp
                    <tr class="border-b">
                        <td class="py-2">{{ $index + 1 }}</td>
                        <td class="py-2 font-medium">{{ $row->unit }}</td>
                        <td class="py-2 text-right">{{ number_format((float) $row->nko, 2) }}</td>
                        <td class="py-2 text-right">
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $classes }}">{{ $label }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

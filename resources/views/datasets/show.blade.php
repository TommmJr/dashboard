@extends('layouts.app')

@section('title', 'Dataset: '.$dataset->original_name)

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <a href="{{ route('datasets.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Kembali ke daftar</a>

    @if (session('success'))
        <div class="mt-4 rounded-md bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <div class="mt-4 rounded-lg bg-white p-6 shadow">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-xl font-bold text-gray-900">{{ $dataset->original_name }}</h1>
                <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 text-sm text-gray-600 sm:grid-cols-3">
                    <div><dt class="font-semibold text-gray-800">Divisi</dt><dd>{{ $dataset->division?->name ?? '-' }}</dd></div>
                    <div><dt class="font-semibold text-gray-800">Pengunggah</dt><dd>{{ $dataset->user?->name ?? '-' }}</dd></div>
                    <div><dt class="font-semibold text-gray-800">Ukuran</dt><dd>{{ number_format($dataset->size / 1024, 1) }} KB</dd></div>
                    <div><dt class="font-semibold text-gray-800">MIME</dt><dd>{{ $dataset->mime_type }}</dd></div>
                    <div><dt class="font-semibold text-gray-800">Diunggah</dt><dd>{{ $dataset->created_at->format('d M Y H:i') }}</dd></div>
                    <div>
                        <dt class="font-semibold text-gray-800">Status</dt>
                        <dd><x-dataset-status-badge :status="$dataset->status->value" /></dd>
                    </div>
                </dl>
                @if ($dataset->status === \App\Enums\DatasetStatus::FAILED && $dataset->error_message)
                    <p class="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ $dataset->error_message }}</p>
                @endif

                @php
                    $validationReport = $dataset->validation_report ?? [];
                    $conflictObservationsQuery = $dataset->observations()->where('period_conflict', true);
                    $periodConflictCount = is_int($validationReport['period_conflict'] ?? null)
                        ? $validationReport['period_conflict']
                        : (int) ($validationReport['period_conflict_count'] ?? 0);

                    if ($periodConflictCount === 0) {
                        $periodConflictCount = $conflictObservationsQuery->count();
                    }

                    if ($periodConflictCount === 0 && ! empty($validationReport['period_conflict'])) {
                        $periodConflictCount = 1;
                    }

                    $conflictingSheetPeriods = $validationReport['conflicting_sheet_periods']
                        ?? ($periodConflictCount > 0
                            ? (clone $conflictObservationsQuery)
                                ->whereNotNull('sheet_period')
                                ->distinct()
                                ->orderBy('sheet_period')
                                ->pluck('sheet_period')
                                ->all()
                            : []);
                @endphp
                @if ($dataset->validation_report || $periodConflictCount > 0)
                    <div class="mt-3 rounded-md border border-gray-200 p-3 text-sm">
                        <p class="font-semibold text-gray-800">Ringkasan validasi</p>
                        <div class="mt-1 flex flex-wrap gap-4 text-xs">
                            <span class="rounded-full bg-green-100 px-2 py-0.5 font-semibold text-green-800">OK: {{ $validationReport['ok'] ?? 0 }}</span>
                            <span class="rounded-full bg-yellow-100 px-2 py-0.5 font-semibold text-yellow-800">WARNING: {{ $validationReport['warning'] ?? 0 }}</span>
                            <span class="rounded-full bg-red-100 px-2 py-0.5 font-semibold text-red-800">FATAL: {{ $validationReport['fatal'] ?? 0 }}</span>
                            @if ($periodConflictCount > 0)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-800">KONFLIK PERIODE: {{ $periodConflictCount }}</span>
                            @endif
                        </div>
                        @if ($periodConflictCount > 0)
                            <div class="mt-2 rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800">
                                <p class="font-semibold">Konflik periode banner sheet ({{ $periodConflictCount }} observasi)</p>
                                <p class="mt-0.5">
                                    Periode banner sheet berbeda dari periode upload efektif. Data tetap disimpan menggunakan periode upload{{ $conflictingSheetPeriods !== [] ? ' (banner sheet: '.implode(', ', $conflictingSheetPeriods).')' : '' }}.
                                </p>
                            </div>
                        @endif
                        @if (! empty($validationReport['issues']))
                            <ul class="mt-2 max-h-40 space-y-1 overflow-y-auto text-xs text-gray-600">
                                @foreach ($validationReport['issues'] as $issue)
                                    <li>
                                        <span class="font-semibold {{ $issue['severity'] === 'FATAL' ? 'text-red-700' : 'text-yellow-700' }}">[{{ $issue['severity'] }}]</span>
                                        Baris {{ $issue['row'] }}: {{ $issue['message'] }}
                                    </li>
                                @endforeach
                            </ul>
                            @if (! empty($validationReport['issues_truncated']))
                                <p class="mt-1 text-xs text-gray-400">Hanya menampilkan 50 masalah pertama. Total: {{ ($validationReport['fatal'] ?? 0) + ($validationReport['warning'] ?? 0) }}.</p>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
            <div class="flex gap-2">
                @can('report.export')
                    <a href="{{ route('datasets.export', $dataset) }}"
                       class="rounded bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700">
                        Export Excel
                    </a>
                    {{-- Fitur Reports dinonaktifkan sementara (2026-09-15) —
                         route reports.validation-export di-comment, tombol dihapus. --}}
                    {{-- @if ($dataset->validation_report)
                        <a href="{{ route('reports.validation-export', $dataset) }}"
                           class="rounded bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                            Export Validasi
                        </a>
                    @endif --}}
                @endcan
                @can('dataset.update')
                    @if ($dataset->status !== \App\Enums\DatasetStatus::ARCHIVED)
                        <form method="POST" action="{{ route('datasets.archive', $dataset) }}"
                              onsubmit="return confirm('Arsipkan dataset ini? Data keluar dari chart tetapi tetap tersimpan.');">
                            @csrf @method('PATCH')
                            <button class="rounded bg-yellow-500 px-3 py-2 text-sm font-semibold text-white hover:bg-yellow-600">Arsipkan</button>
                        </form>
                    @endif
                @endcan
                @can('dataset.delete')
                    <form method="POST" action="{{ route('datasets.destroy', $dataset) }}"
                          onsubmit="return confirm('HAPUS PERMANEN dataset ini beserta seluruh baris datanya?');">
                        @csrf @method('DELETE')
                        <button class="rounded bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700">Hapus Permanen</button>
                    </form>
                @endcan
            </div>
        </div>
    </div>

    @php $canEditMetrics = auth()->user()->can('dataset.update') && ! ($hasObservations ?? false); @endphp
    <div class="mt-6 rounded-lg bg-white shadow">
        <h2 class="border-b px-6 py-4 text-lg font-semibold text-gray-900">Preview Data ({{ $metrics->total() }} baris)</h2>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3">Metric</th>
                    <th class="px-4 py-3">Value</th>
                    <th class="px-4 py-3">Period</th>
                    @if ($canEditMetrics)
                        <th class="px-4 py-3">Aksi</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($metrics as $metric)
                    <tr>
                        <td class="px-4 py-2">{{ $metric->metric_name }}</td>
                        <td class="px-4 py-2">{{ number_format((float) $metric->value, 2) }}</td>
                        <td class="px-4 py-2">{{ $metric->period }}</td>
                        @if ($canEditMetrics)
                            <td class="px-4 py-2">
                                <a href="{{ route('performance_metrics.edit', $metric) }}"
                                   class="text-sm text-indigo-600 hover:underline">Edit</a>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">Belum ada baris data terimpor.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-6 py-4">{{ $metrics->links() }}</div>
    </div>
</div>
@endsection

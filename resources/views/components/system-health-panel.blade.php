@props(['health' => null])

@php
    $isAdminUser = auth()->user()?->hasRole(\App\Models\Role::ADMIN) ?? false;
@endphp

@if ($isAdminUser && is_array($health))
    @php
        $badgeStyle = static function (string $tone): array {
            return match ($tone) {
                'healthy' => ['bg' => 'bg-emerald-50 border-emerald-200 text-emerald-700', 'dot' => 'bg-emerald-500'],
                'info' => ['bg' => 'bg-sky-50 border-sky-200 text-sky-700', 'dot' => 'bg-sky-500'],
                'danger' => ['bg' => 'bg-rose-50 border-rose-200 text-rose-700', 'dot' => 'bg-rose-500'],
                default => ['bg' => 'bg-slate-100 border-slate-200 text-slate-700', 'dot' => 'bg-slate-400'],
            };
        };

        $db = $health['database'];
        $queue = $health['queue'];
        $assetSync = $health['asset_sync'];
        $storage = $health['storage'];

        $dbBadge = $badgeStyle((string) ($db['tone'] ?? 'neutral'));
        $queueBadge = $badgeStyle((string) ($queue['tone'] ?? 'neutral'));
        $syncBadge = $badgeStyle((string) ($assetSync['tone'] ?? 'neutral'));
        $storageBadge = $badgeStyle((string) ($storage['tone'] ?? 'neutral'));
    @endphp

    <section id="system-health-panel" aria-labelledby="system-health-heading" class="ibs-card overflow-hidden animate-entrance">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/60 px-4 py-3 sm:px-5">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-[#e6f4f6] text-[#0b6175]" aria-hidden="true">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                    </svg>
                </span>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 id="system-health-heading" class="text-sm font-semibold tracking-tight text-slate-900">Kesehatan Sistem</h2>
                    <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]">
                        Panel Admin
                    </span>
                </div>
            </div>
            <p class="text-xs font-medium tabular-nums text-slate-500">
                Diperiksa {{ $health['checked_at_label'] }}
            </p>
        </div>

        <div class="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-4">
            {{-- 1. Koneksi Basis Data --}}
            <div class="p-4 sm:p-5">
                <div class="flex items-center justify-between gap-2">
                    <p class="ibs-metric-label uppercase tracking-wider">Koneksi Basis Data</p>
                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $dbBadge['bg'] }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $dbBadge['dot'] }}" aria-hidden="true"></span>
                        {{ $db['status_label'] }}
                    </span>
                </div>
                <p class="mt-2.5 text-lg font-bold tracking-tight text-slate-900 tabular-nums">
                    {{ $db['driver_label'] }}
                </p>
                <p class="mt-1 text-xs font-medium tabular-nums text-slate-500">
                    {{ $db['detail_label'] }}
                </p>
            </div>

            {{-- 2. Antrean Latar Belakang --}}
            <div class="p-4 sm:p-5">
                <div class="flex items-center justify-between gap-2">
                    <p class="ibs-metric-label uppercase tracking-wider">Antrean Latar Belakang</p>
                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $queueBadge['bg'] }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $queueBadge['dot'] }}" aria-hidden="true"></span>
                        {{ $queue['status_label'] }}
                    </span>
                </div>
                <p class="mt-2.5 text-lg font-bold tracking-tight text-slate-900 tabular-nums">
                    {{ number_format((int) $queue['pending_count'], 0, ',', '.') }} antrean tertunda
                </p>
                <p class="mt-1 text-xs font-medium tabular-nums {{ (int) $queue['failed_count'] > 0 ? 'text-rose-700' : 'text-slate-500' }}">
                    {{ number_format((int) $queue['failed_count'], 0, ',', '.') }} tugas gagal
                </p>
            </div>

            {{-- 3. Sinkronisasi Aset Terakhir --}}
            <div class="p-4 sm:p-5">
                <div class="flex items-center justify-between gap-2">
                    <p class="ibs-metric-label uppercase tracking-wider">Sinkronisasi Aset</p>
                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $syncBadge['bg'] }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $syncBadge['dot'] }}" aria-hidden="true"></span>
                        {{ $assetSync['status_label'] }}
                    </span>
                </div>
                <p class="mt-2.5 truncate text-lg font-bold tracking-tight text-slate-900 tabular-nums" title="{{ $assetSync['last_run_label'] }}">
                    {{ $assetSync['last_run_label'] }}
                </p>
                <p class="mt-1 truncate text-xs font-medium text-slate-500" title="{{ $assetSync['detail_label'] }}">
                    {{ $assetSync['detail_label'] }}
                </p>
            </div>

            {{-- 4. Penyimpanan Dataset Privat --}}
            <div class="p-4 sm:p-5">
                <div class="flex items-center justify-between gap-2">
                    <p class="ibs-metric-label uppercase tracking-wider">Penyimpanan Dataset</p>
                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-semibold {{ $storageBadge['bg'] }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $storageBadge['dot'] }}" aria-hidden="true"></span>
                        {{ $storage['status_label'] }}
                    </span>
                </div>
                <p class="mt-2.5 text-lg font-bold tracking-tight text-slate-900 tabular-nums">
                    {{ $storage['file_count_label'] }}
                </p>
                <p class="mt-1 text-xs font-medium tabular-nums text-slate-500">
                    Total ukuran {{ $storage['total_size_label'] }}
                </p>
            </div>
        </div>
    </section>
@endif

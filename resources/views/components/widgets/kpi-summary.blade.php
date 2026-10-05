@props(['widget' => [], 'data' => null])

{{-- KPI cards (kartu putih di atas background abu, ikut mockup Pengusahaan).
     Angka memakai ukuran/berat gaya mockup: 31px, weight 800, spasi rapat.
     Delta pill ditampilkan sebagai "—" karena pembanding periode sebelumnya
     belum tersedia (baru ada 1 periode); pill dihitung ulang saat data >1
     periode tersedia. --}}
<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rata-rata NKO</p>
        <p class="mt-3 text-3xl font-extrabold leading-none tracking-tight text-primary" x-text="kpi ? formatNumber(kpi.average_nko) : '—'"></p>
        <p class="mt-2 inline-flex items-center rounded-full bg-slate-50 px-2 py-0.5 text-xs font-bold text-slate-500" x-text="'—'"></p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Dataset READY</p>
        <p class="mt-3 text-3xl font-extrabold leading-none tracking-tight text-primary" x-text="kpi ? kpi.ready_datasets : '—'"></p>
        <p class="mt-2 inline-flex items-center rounded-full bg-slate-50 px-2 py-0.5 text-xs font-bold text-slate-500" x-text="'—'"></p>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Baris Data Aktif</p>
        <p class="mt-3 text-3xl font-extrabold leading-none tracking-tight text-primary" x-text="kpi ? kpi.metric_count : '—'"></p>
        <p class="mt-2 inline-flex items-center rounded-full bg-slate-50 px-2 py-0.5 text-xs font-bold text-slate-500" x-text="'—'"></p>
    </div>
</div>

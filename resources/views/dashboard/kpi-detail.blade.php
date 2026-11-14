@extends('layouts.app')

@section('title', 'Rincian Kinerja KPI')

@section('content')
@php
    $fullscreenPayload = $fullscreenChartData ?? [
        'period' => $kinerjaDetailData['period'] ?? ($targetRealisasiData['period'] ?? null),
        'unitLabel' => ! empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit',
        'ragThresholds' => \App\Support\KpiScoreNormalizer::ragThresholds(),
        'indicators' => [],
    ];

    $csvRows = [];
    if (! empty($fullscreenPayload['indicators'])) {
        foreach ($fullscreenPayload['indicators'] as $item) {
            $pctStr = $item['pencapaian'] !== null ? number_format((float) $item['pencapaian'], 1, ',', '') : 'Belum tersedia';
            $csvRows[] = [
                'indikator' => $item['indikator'] ?? '',
                'polaritas' => $item['polaritas'] ?? 'Positif',
                'satuan' => $item['satuan'] ?? 'Tidak tercatat',
                'bobot' => $item['bobot'] ?? 'Tidak tercatat',
                'target' => $item['target_display'] ?? 'Tidak tercatat',
                'realisasi' => $item['realisasi_display'] ?? 'Tidak tercatat',
                'pencapaian' => $pctStr,
                'status' => $item['status'] ?? 'Belum tersedia',
            ];
        }
    } else {
        $panelMap = [];
        foreach (($targetRealisasiData['sections'] ?? []) as $section) {
            foreach (($section['panels'] ?? []) as $panel) {
                $panelMap[$panel['name']] = $panel;
            }
        }

        $sourceRows = $kinerjaDetailData['rows'] ?? [];
        foreach ($sourceRows as $row) {
            $name = $row['indikator'] ?? '';
            $panel = $panelMap[$name] ?? null;
            $pct = $row['pencapaian'] ?? null;
            $status = 'Belum tersedia';
            if ($pct !== null) {
                $tier = \App\Support\KpiScoreNormalizer::ragTier((float) $pct);
                $status = match ($tier) {
                    \App\Support\KpiScoreNormalizer::TIER_TERCAPAI => 'Tercapai',
                    \App\Support\KpiScoreNormalizer::TIER_HAMPIR => 'Hampir Tercapai',
                    default => 'Perlu Peningkatan',
                };
            }
            $targetVal = $row['target_display'] ?? ($row['target'] !== null ? $row['target'] : ($panel['numeric']['target'] ?? ($panel['date']['target'] ?? 'Tidak tercatat')));
            $realisasiVal = $row['realisasi_display'] ?? ($row['realisasi'] !== null ? $row['realisasi'] : ($panel['numeric']['realisasi'] ?? ($panel['date']['realisasi'] ?? 'Tidak tercatat')));
            $satuanVal = $panel['satuan'] ?? ($row['satuan'] ?? 'Tidak tercatat');
            $pctStr = $pct !== null ? number_format((float) $pct, 1, ',', '') : ($panel['score']['percent'] !== null ? number_format((float) $panel['score']['percent'], 1, ',', '') : 'Belum tersedia');

            $csvRows[] = [
                'indikator' => $name,
                'polaritas' => 'Positif',
                'satuan' => $satuanVal ?: 'Tidak tercatat',
                'bobot' => 'Tidak tercatat',
                'target' => $targetVal,
                'realisasi' => $realisasiVal,
                'pencapaian' => $pctStr,
                'status' => $status,
            ];
        }
    }
@endphp

<style>
@media print {
    @page {
        size: landscape;
        margin: 8mm;
    }
    aside,
    nav,
    header,
    #header-breadcrumb,
    #kpi-detail-actions,
    #kpi-view-switcher,
    #target-realisasi-toolbar,
    button,
    .no-print {
        display: none !important;
    }
    body, main, #kpi-detail-container {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        width: 100% !important;
        max-width: 100% !important;
        overflow: visible !important;
    }
    .shadow-xs, .shadow-2xs, .shadow-md, .shadow-sm {
        box-shadow: none !important;
    }
    .border {
        border-color: #cbd5e1 !important;
    }
}
</style>

<script>
if (typeof window.kpiDetailManager !== 'function') {
    window.kpiDetailManager = function() {
        let fullscreenChartInstance = null;
        let fullscreenResizeObserver = null;
        let renderTimer = null;
        let echartsWaitAttempts = 0;

        const parseJson = (id, fallback) => {
            try {
                return JSON.parse(document.getElementById(id)?.textContent || 'null') || fallback;
            } catch (e) {
                return fallback;
            }
        };

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        const formatPercentId = (val) => {
            if (val === null || val === undefined || Number.isNaN(Number(val))) {
                return 'Belum tersedia';
            }
            return Number(val).toLocaleString('id-ID', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1,
            }) + '%';
        };

        return {
            viewMode: 'split',
            isFullscreen: false,
            chartMode: 'achievement',
            ragFilter: 'all',
            searchQuery: '',
            isChartLoading: false,
            chartError: '',
            payload: parseJson('kpiFullscreenChartData', {
                period: null,
                unitLabel: 'Semua Unit',
                ragThresholds: { tercapai_min: 100, hampir_min: 95 },
                indicators: [],
            }),

            init() {
                document.addEventListener('fullscreenchange', () => {
                    const active = !!document.fullscreenElement;
                    if (!active && this.isFullscreen) {
                        this.isFullscreen = false;
                        this.disposeChart();
                    }
                });
            },

            get allIndicators() {
                return Array.isArray(this.payload?.indicators) ? this.payload.indicators : [];
            },

            get searchedIndicators() {
                const q = this.searchQuery.trim().toLowerCase();
                if (!q) {
                    return this.allIndicators;
                }
                return this.allIndicators.filter((item) => {
                    const haystack = [item.indikator, item.unit, item.satuan, item.group]
                        .filter(Boolean)
                        .join(' ')
                        .toLowerCase();
                    return haystack.includes(q);
                });
            },

            get filteredIndicators() {
                if (this.ragFilter === 'all') {
                    return this.searchedIndicators;
                }
                return this.searchedIndicators.filter((item) => item.tier === this.ragFilter);
            },

            get hasNonNumericInFiltered() {
                return this.filteredIndicators.some((item) => item.target_num === null || item.realisasi_num === null);
            },

            get summaryStats() {
                const base = this.searchedIndicators;
                const filtered = this.filteredIndicators;
                let tercapai = 0;
                let hampir = 0;
                let perlu = 0;

                base.forEach((item) => {
                    if (item.tier === 'tercapai') tercapai++;
                    else if (item.tier === 'hampir') hampir++;
                    else if (item.tier === 'perlu') perlu++;
                });

                const scored = filtered.filter((item) => item.pencapaian !== null && item.pencapaian !== undefined);
                const avg = scored.length > 0
                    ? scored.reduce((sum, item) => sum + Number(item.pencapaian), 0) / scored.length
                    : null;

                return {
                    total: filtered.length,
                    allTotal: this.allIndicators.length,
                    tercapai,
                    hampir,
                    perlu,
                    avgDisplay: formatPercentId(avg),
                };
            },

            toggleFullscreen() {
                if (!this.isFullscreen) {
                    this.openFullscreen();
                } else {
                    this.closeFullscreen();
                }
            },

            openFullscreen() {
                const el = document.getElementById('kpi-detail-container') || document.documentElement;
                this.isFullscreen = true;
                this.chartError = '';

                if (el.requestFullscreen) {
                    el.requestFullscreen().catch(() => {});
                } else if (el.webkitRequestFullscreen) {
                    el.webkitRequestFullscreen();
                }

                this.scheduleChartRender();
            },

            closeFullscreen() {
                if (renderTimer) {
                    clearTimeout(renderTimer);
                    renderTimer = null;
                }
                if (document.fullscreenElement) {
                    if (document.exitFullscreen) {
                        document.exitFullscreen().catch(() => {});
                    } else if (document.webkitExitFullscreen) {
                        document.webkitExitFullscreen();
                    }
                }
                this.isFullscreen = false;
                this.disposeChart();
            },

            setChartMode(mode) {
                this.chartMode = mode;
                this.scheduleChartRender();
            },

            setRagFilter(tier) {
                this.ragFilter = tier;
                this.scheduleChartRender();
            },

            resetFullscreenFilters() {
                this.ragFilter = 'all';
                this.searchQuery = '';
                this.scheduleChartRender();
            },

            scheduleChartRender() {
                if (!this.isFullscreen) {
                    return;
                }
                this.isChartLoading = true;
                this.chartError = '';
                echartsWaitAttempts = 0;

                this.$nextTick(() => {
                    if (renderTimer) {
                        clearTimeout(renderTimer);
                    }
                    renderTimer = setTimeout(() => {
                        this.renderFullscreenChart();
                    }, 80);
                });
            },

            disposeChart() {
                if (fullscreenResizeObserver) {
                    fullscreenResizeObserver.disconnect();
                    fullscreenResizeObserver = null;
                }
                if (fullscreenChartInstance) {
                    if (!fullscreenChartInstance.isDisposed()) {
                        fullscreenChartInstance.dispose();
                    }
                    fullscreenChartInstance = null;
                }
            },

            renderFullscreenChart() {
                if (!this.isFullscreen) {
                    this.isChartLoading = false;
                    return;
                }

                const rows = this.filteredIndicators;
                if (rows.length === 0) {
                    this.disposeChart();
                    this.isChartLoading = false;
                    return;
                }

                if (!window.echarts) {
                    if (echartsWaitAttempts < 15) {
                        echartsWaitAttempts++;
                        renderTimer = setTimeout(() => this.renderFullscreenChart(), 80);
                        return;
                    }
                    this.chartError = 'Pustaka grafik belum termuat. Silakan muat ulang halaman.';
                    this.isChartLoading = false;
                    return;
                }

                const canvasEl = document.getElementById('kpi-fullscreen-echart-canvas');
                if (!canvasEl) {
                    this.isChartLoading = false;
                    return;
                }

                try {
                    if (!fullscreenChartInstance || fullscreenChartInstance.isDisposed()) {
                        fullscreenChartInstance = window.echarts.init(canvasEl);
                    }

                    if (!fullscreenResizeObserver) {
                        fullscreenResizeObserver = new ResizeObserver(() => {
                            if (fullscreenChartInstance && !fullscreenChartInstance.isDisposed()) {
                                fullscreenChartInstance.resize();
                            }
                        });
                        fullscreenResizeObserver.observe(canvasEl);
                    }

                    const rag = this.payload?.ragThresholds || { tercapai_min: 100, hampir_min: 95 };
                    const tercapaiMin = Number(rag.tercapai_min ?? 100);
                    const hampirMin = Number(rag.hampir_min ?? 95);
                    const tokens = window.PLN_CHART_PALETTE || {
                        primary: '#0b6175',
                        accent: '#16a3b8',
                        softTeal: '#bce3e8',
                        targetSlate: '#cbd5e1',
                        gridLine: '#f1f5f9',
                        axisText: '#64748b',
                        rag: { tercapai: '#10b981', waspada: '#f59e0b', kritis: '#f43f5e' },
                    };

                    const labels = rows.map((r) => {
                        const name = r.indikator || '';
                        return name.length > 34 ? name.substring(0, 32) + '..' : name;
                    });
                    const scores = rows.map((r) => r.pencapaian !== null && r.pencapaian !== undefined ? Number(r.pencapaian) : 0);
                    const maxPct = Math.max(120, ...scores.map((v) => Math.ceil((v + 10) / 10) * 10));

                    const tooltipFormatter = (params) => {
                        const first = Array.isArray(params) ? params[0] : params;
                        const idx = first?.dataIndex ?? 0;
                        const row = rows[idx];
                        if (!row) return '';
                        return [
                            '<div style="max-width:340px;white-space:normal;font-size:12px;line-height:1.45;">',
                            '<div style="font-weight:700;color:#0f172a;margin-bottom:4px;">' + escapeHtml(row.indikator) + '</div>',
                            '<div><span style="color:#64748b;">Unit:</span> <strong>' + escapeHtml(row.unit) + '</strong> &middot; <span style="color:#64748b;">Satuan:</span> ' + escapeHtml(row.satuan) + '</div>',
                            '<div><span style="color:#64748b;">Target:</span> <strong style="font-variant-numeric:tabular-nums;">' + escapeHtml(row.target_display) + '</strong></div>',
                            '<div><span style="color:#64748b;">Realisasi:</span> <strong style="font-variant-numeric:tabular-nums;">' + escapeHtml(row.realisasi_display) + '</strong></div>',
                            '<div><span style="color:#64748b;">Pencapaian:</span> <strong style="font-variant-numeric:tabular-nums;">' + escapeHtml(row.pencapaian_display) + '</strong></div>',
                            '<div><span style="color:#64748b;">Polaritas:</span> ' + escapeHtml(row.polaritas) + ' &middot; <span style="color:#64748b;">Status RAG:</span> <strong>' + escapeHtml(row.status) + '</strong></div>',
                            '</div>',
                        ].join('');
                    };

                    let option;
                    if (this.chartMode === 'comparison') {
                        const useSlider = rows.length > 8;
                        option = {
                            animation: true,
                            animationDuration: 450,
                            tooltip: {
                                trigger: 'axis',
                                axisPointer: { type: 'cross' },
                                formatter: tooltipFormatter,
                            },
                            legend: {
                                top: 6,
                                left: 8,
                                textStyle: { fontSize: 11, color: tokens.axisText },
                            },
                            toolbox: {
                                right: 16,
                                top: 4,
                                feature: {
                                    restore: { title: 'Reset Zoom' },
                                },
                            },
                            dataZoom: useSlider
                                ? [
                                    { type: 'inside', xAxisIndex: 0 },
                                    { type: 'slider', xAxisIndex: 0, bottom: 8, height: 18, startValue: 0, endValue: Math.min(rows.length - 1, 13) },
                                ]
                                : [{ type: 'inside', xAxisIndex: 0 }],
                            grid: {
                                left: 68,
                                right: 64,
                                top: 52,
                                bottom: useSlider ? 88 : (rows.length > 5 ? 68 : 44),
                            },
                            xAxis: {
                                type: 'category',
                                data: labels,
                                axisLine: { lineStyle: { color: tokens.gridLine } },
                                axisLabel: {
                                    fontSize: 10,
                                    color: tokens.axisText,
                                    interval: 0,
                                    rotate: rows.length > 5 ? 22 : 0,
                                    width: 120,
                                    overflow: 'truncate',
                                },
                            },
                            yAxis: [
                                {
                                    type: 'value',
                                    name: 'Nilai Target & Realisasi',
                                    nameTextStyle: { fontSize: 11, color: tokens.axisText },
                                    axisLabel: { fontSize: 11, color: tokens.axisText },
                                    splitLine: { lineStyle: { color: tokens.gridLine } },
                                },
                                {
                                    type: 'value',
                                    name: 'Pencapaian (%)',
                                    min: 0,
                                    max: maxPct,
                                    nameTextStyle: { fontSize: 11, color: tokens.primary },
                                    axisLabel: { formatter: '{value}%', fontSize: 11, color: tokens.primary },
                                    splitLine: { show: false },
                                },
                            ],
                            series: [
                                {
                                    name: 'Target',
                                    type: 'bar',
                                    yAxisIndex: 0,
                                    barMaxWidth: 26,
                                    itemStyle: { color: tokens.targetSlate, borderRadius: [6, 6, 0, 0] },
                                    data: rows.map((r) => r.target_num),
                                },
                                {
                                    name: 'Realisasi',
                                    type: 'bar',
                                    yAxisIndex: 0,
                                    barMaxWidth: 26,
                                    itemStyle: { color: tokens.primary, borderRadius: [6, 6, 0, 0] },
                                    data: rows.map((r) => r.realisasi_num),
                                },
                                {
                                    name: 'Pencapaian (%)',
                                    type: 'line',
                                    yAxisIndex: 1,
                                    smooth: true,
                                    symbol: 'circle',
                                    symbolSize: 7,
                                    itemStyle: { color: tokens.accent },
                                    lineStyle: { width: 2.5, color: tokens.accent },
                                    data: rows.map((r) => r.pencapaian),
                                },
                            ],
                        };
                    } else {
                        const useSlider = rows.length > 12;
                        option = {
                            animation: true,
                            animationDuration: 450,
                            tooltip: {
                                trigger: 'axis',
                                axisPointer: { type: 'shadow' },
                                formatter: tooltipFormatter,
                            },
                            toolbox: {
                                right: 16,
                                top: 4,
                                feature: {
                                    restore: { title: 'Reset Zoom' },
                                },
                            },
                            dataZoom: useSlider
                                ? [
                                    { type: 'inside', yAxisIndex: 0 },
                                    { type: 'slider', yAxisIndex: 0, right: 8, width: 14, startValue: 0, endValue: 11 },
                                ]
                                : [{ type: 'inside', yAxisIndex: 0 }],
                            grid: {
                                left: 230,
                                right: useSlider ? 66 : 56,
                                top: 42,
                                bottom: 32,
                            },
                            xAxis: {
                                type: 'value',
                                min: 0,
                                max: maxPct,
                                axisLabel: { formatter: '{value}%', fontSize: 11, color: tokens.axisText },
                                splitLine: { lineStyle: { color: tokens.gridLine } },
                            },
                            yAxis: {
                                type: 'category',
                                inverse: true,
                                data: labels,
                                axisLine: { lineStyle: { color: tokens.gridLine } },
                                axisLabel: {
                                    fontSize: 11,
                                    color: tokens.axisText,
                                    width: 210,
                                    overflow: 'truncate',
                                },
                            },
                            series: [
                                {
                                    name: 'Capaian (%)',
                                    type: 'bar',
                                    barMaxWidth: 22,
                                    data: rows.map((r) => {
                                        let color = tokens.targetSlate;
                                        if (r.tier === 'tercapai') color = tokens.rag.tercapai;
                                        else if (r.tier === 'hampir') color = tokens.rag.waspada;
                                        else if (r.tier === 'perlu') color = tokens.rag.kritis;
                                        return {
                                            value: r.pencapaian !== null && r.pencapaian !== undefined ? Number(r.pencapaian) : 0,
                                            itemStyle: { color, borderRadius: [0, 6, 6, 0] },
                                        };
                                    }),
                                    label: {
                                        show: true,
                                        position: 'right',
                                        fontSize: 11,
                                        color: tokens.axisText,
                                        formatter: (p) => rows[p.dataIndex]?.pencapaian_display || 'Belum tersedia',
                                    },
                                    markLine: {
                                        silent: true,
                                        symbol: 'none',
                                        data: [
                                            {
                                                xAxis: tercapaiMin,
                                                lineStyle: { color: tokens.primary, type: 'dashed', width: 1.5 },
                                                label: { formatter: 'Target 100%', position: 'end', fontSize: 10, color: tokens.primary },
                                            },
                                            {
                                                xAxis: hampirMin,
                                                lineStyle: { color: tokens.rag.waspada, type: 'dotted', width: 1.5 },
                                                label: { formatter: 'Ambang 95%', position: 'start', fontSize: 10, color: tokens.rag.waspada },
                                            },
                                        ],
                                    },
                                },
                            ],
                        };
                    }

                    fullscreenChartInstance.setOption(option, true);
                    fullscreenChartInstance.resize();
                } catch (e) {
                    this.chartError = 'Gagal merender grafik capaian KPI.';
                } finally {
                    this.isChartLoading = false;
                }
            },

            downloadChartPng() {
                if (!fullscreenChartInstance || fullscreenChartInstance.isDisposed() || this.filteredIndicators.length === 0) {
                    return;
                }
                const dataUrl = fullscreenChartInstance.getDataURL({
                    type: 'png',
                    pixelRatio: 2,
                    backgroundColor: '#ffffff',
                });
                const rawUnit = String(this.payload?.unitLabel || 'semua-unit').toLowerCase();
                const unitSlug = rawUnit.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'semua-unit';
                const link = document.createElement('a');
                link.setAttribute('href', dataUrl);
                link.setAttribute('download', 'grafik-kinerja-kpi-' + unitSlug + '.png');
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            },

            downloadCsv() {
                const rawData = parseJson('kpiExportData', []);
                let csv = '\uFEFFsep=;\r\n';
                csv += 'Indikator;Polaritas;Satuan;Bobot;Target;Realisasi;Pencapaian (%);Status\r\n';
                rawData.forEach(row => {
                    const clean = (val) => '"' + String(val ?? '').replace(/"/g, '""') + '"';
                    csv += [
                        clean(row.indikator),
                        clean(row.polaritas),
                        clean(row.satuan),
                        clean(row.bobot),
                        clean(row.target),
                        clean(row.realisasi),
                        clean(row.pencapaian),
                        clean(row.status)
                    ].join(';') + '\r\n';
                });
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.setAttribute('href', url);
                link.setAttribute('download', 'Rincian_Kinerja_KPI_' + new Date().toISOString().slice(0, 10) + '.csv');
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            }
        };
    };
}
</script>

<script type="application/json" id="kpiExportData">
{!! json_encode($csvRows) !!}
</script>

<script type="application/json" id="kpiFullscreenChartData">
{!! json_encode($fullscreenPayload) !!}
</script>

<div
    id="kpi-detail-container"
    x-data="kpiDetailManager()"
    @keydown.escape.window="if (isFullscreen) closeFullscreen()"
    class="mx-auto max-w-[1600px] space-y-5"
>
    {{-- Fullscreen Interactive ECharts Visualization Workspace --}}
    <div
        id="kpi-fullscreen-workspace"
        x-show="isFullscreen"
        x-cloak
        role="dialog"
        aria-modal="true"
        aria-labelledby="kpi-fullscreen-title"
        class="fixed inset-0 z-50 bg-slate-50/95 backdrop-blur-sm p-5 overflow-y-auto no-print"
    >
        <div class="mx-auto max-w-[1600px] space-y-4">
            {{-- Header & Action Strip --}}
            <div class="ibs-card flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#e6f4f6] text-[#0b6175]">
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div>
                        <h2 id="kpi-fullscreen-title" class="ibs-section-title text-base font-bold text-slate-900">
                            Visualisasi Grafik Capaian Indikator Kinerja
                        </h2>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500 tabular-nums">
                            <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175]">
                                Unit: <strong class="ml-1 font-bold" x-text="payload.unitLabel || 'Semua Unit'"></strong>
                            </span>
                            <span class="inline-flex items-center rounded-full border border-slate-200/80 bg-white px-2.5 py-0.5 text-[11px] font-medium text-slate-600">
                                Periode: <strong class="ml-1 font-semibold text-slate-900" x-text="payload.period || 'Belum tersedia'"></strong>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        id="kpi-fullscreen-download-chart-btn"
                        @click="downloadChartPng()"
                        :disabled="filteredIndicators.length === 0 || !!chartError"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-[#0b6175]/30 bg-[#e6f4f6]/70 px-3 py-1.5 text-xs font-semibold text-[#0b6175] transition hover:bg-[#e6f4f6] disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4m4-5 5 5 5-5m-5 5V3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span>Unduh Grafik (PNG)</span>
                    </button>

                    <button
                        type="button"
                        id="kpi-fullscreen-download-csv-btn"
                        @click="downloadCsv()"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    >
                        <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M14 2v6h6M8 13h8M8 17h8M8 9h2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span>Unduh Data (CSV)</span>
                    </button>

                    <button
                        type="button"
                        id="kpi-fullscreen-close-btn"
                        @click="closeFullscreen()"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    >
                        <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span>Tutup Layar Penuh (ESC)</span>
                    </button>
                </div>
            </div>

            {{-- Unified Summary Strip --}}
            <div class="ibs-card overflow-hidden">
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">
                    <div class="px-4 py-3">
                        <div class="ibs-metric-label">Total Indikator</div>
                        <div class="ibs-metric-value mt-1 text-xl sm:text-2xl" x-text="summaryStats.total">0</div>
                    </div>
                    <div class="px-4 py-3">
                        <div class="flex items-center gap-1.5 ibs-metric-label">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                            <span>Tercapai (&ge; 100%)</span>
                        </div>
                        <div class="ibs-metric-value mt-1 text-xl sm:text-2xl text-emerald-700" x-text="summaryStats.tercapai">0</div>
                    </div>
                    <div class="px-4 py-3">
                        <div class="flex items-center gap-1.5 ibs-metric-label">
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                            <span>Hampir Tercapai (95% s.d. 99,9%)</span>
                        </div>
                        <div class="ibs-metric-value mt-1 text-xl sm:text-2xl text-amber-700" x-text="summaryStats.hampir">0</div>
                    </div>
                    <div class="px-4 py-3">
                        <div class="flex items-center gap-1.5 ibs-metric-label">
                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500 shrink-0"></span>
                            <span>Perlu Peningkatan (&lt; 95%)</span>
                        </div>
                        <div class="ibs-metric-value mt-1 text-xl sm:text-2xl text-rose-700" x-text="summaryStats.perlu">0</div>
                    </div>
                    <div class="col-span-2 sm:col-span-1 px-4 py-3">
                        <div class="ibs-metric-label">Rata-rata Capaian %</div>
                        <div class="ibs-metric-value mt-1 text-xl sm:text-2xl text-[#0b6175]" x-text="summaryStats.avgDisplay">Belum tersedia</div>
                    </div>
                </div>
            </div>

            {{-- Interactive Filter & Mode Toolbar --}}
            <div class="ibs-card flex flex-col gap-2.5 p-3.5 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex items-center rounded-xl bg-slate-100/90 p-1 text-xs font-medium">
                        <button
                            type="button"
                            id="kpi-fullscreen-mode-achievement"
                            @click="setChartMode('achievement')"
                            :class="chartMode === 'achievement' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                            class="pill-tab-transition rounded-lg px-3 py-1 cursor-pointer whitespace-nowrap"
                        >
                            Capaian Indikator (%) &amp; Status RAG
                        </button>
                        <button
                            type="button"
                            id="kpi-fullscreen-mode-comparison"
                            @click="setChartMode('comparison')"
                            :class="chartMode === 'comparison' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                            class="pill-tab-transition rounded-lg px-3 py-1 cursor-pointer whitespace-nowrap"
                        >
                            Perbandingan Target vs Realisasi
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
                        <button
                            type="button"
                            data-fullscreen-rag="all"
                            @click="setRagFilter('all')"
                            :class="ragFilter === 'all' ? 'border-slate-800 text-slate-900 font-bold ring-1 ring-slate-800/15 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50'"
                            class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1 transition cursor-pointer"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-slate-400 shrink-0"></span>
                            Semua Status
                        </button>
                        <button
                            type="button"
                            data-fullscreen-rag="tercapai"
                            @click="setRagFilter('tercapai')"
                            :class="ragFilter === 'tercapai' ? 'border-emerald-600 text-emerald-950 font-bold ring-1 ring-emerald-600/20 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50'"
                            class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1 transition cursor-pointer"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                            Tercapai (&ge; 100%)
                        </button>
                        <button
                            type="button"
                            data-fullscreen-rag="hampir"
                            @click="setRagFilter('hampir')"
                            :class="ragFilter === 'hampir' ? 'border-amber-600 text-amber-950 font-bold ring-1 ring-amber-600/20 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50'"
                            class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1 transition cursor-pointer"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                            Hampir Tercapai (95% s.d. 99%)
                        </button>
                        <button
                            type="button"
                            data-fullscreen-rag="perlu"
                            @click="setRagFilter('perlu')"
                            :class="ragFilter === 'perlu' ? 'border-rose-600 text-rose-950 font-bold ring-1 ring-rose-600/20 shadow-2xs' : 'border-slate-200 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50'"
                            class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1 transition cursor-pointer"
                        >
                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500 shrink-0"></span>
                            Perlu Peningkatan (&lt; 95%)
                        </button>
                    </div>
                </div>

                <div class="w-full lg:w-64 shrink-0">
                    <input
                        id="kpi-fullscreen-search"
                        type="search"
                        x-model="searchQuery"
                        @input="scheduleChartRender()"
                        placeholder="Cari indikator..."
                        aria-label="Cari indikator di grafik layar penuh"
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-800 placeholder:text-slate-400 focus:border-[#16a3b8] focus:outline-none focus:ring-1 focus:ring-[#16a3b8]"
                    />
                </div>
            </div>

            {{-- ECharts Canvas Card --}}
            <div class="ibs-card p-5">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-2">
                        <h3
                            class="ibs-section-title"
                            x-text="chartMode === 'achievement' ? 'Grafik Capaian Indikator (%) terhadap Target 100%' : 'Perbandingan Target vs Realisasi dan Pencapaian (%)'"
                        ></h3>
                        <span class="rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175] tabular-nums" x-text="filteredIndicators.length + ' indikator'"></span>
                    </div>
                    <div x-show="chartMode === 'comparison' && hasNonNumericInFiltered" class="text-[11px] text-slate-500">
                        Indikator non-numerik menampilkan kurva Capaian (%).
                    </div>
                </div>

                <div x-show="isChartLoading" class="flex items-center justify-center rounded-xl border border-slate-100 bg-slate-50/70" style="width: 100%; height: 500px; min-height: 440px;">
                    <span class="text-xs font-medium text-slate-500">Memuat visualisasi grafik...</span>
                </div>

                <div x-show="!isChartLoading && chartError" class="flex flex-col items-center justify-center gap-2 rounded-xl border border-rose-200 bg-rose-50/40 p-6 text-center" style="width: 100%; height: 500px; min-height: 440px;">
                    <p class="text-xs font-medium text-rose-800" x-text="chartError"></p>
                    <button
                        type="button"
                        @click="scheduleChartRender()"
                        class="rounded-xl border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-50 cursor-pointer"
                    >
                        Muat Ulang Grafik
                    </button>
                </div>

                <div x-show="!isChartLoading && !chartError && filteredIndicators.length === 0" class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-slate-50/60 p-6 text-center" style="width: 100%; height: 500px; min-height: 440px;">
                    <p class="text-xs font-medium text-slate-600">Tidak ada indikator yang cocok dengan filter atau kata kunci pencarian.</p>
                    <button
                        type="button"
                        @click="resetFullscreenFilters()"
                        class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer"
                    >
                        Reset Filter
                    </button>
                </div>

                <div
                    x-show="!isChartLoading && !chartError && filteredIndicators.length > 0"
                    id="kpi-fullscreen-echart-canvas"
                    style="width: 100%; height: 500px; min-height: 440px;"
                ></div>
            </div>

            {{-- Companion Summary Table --}}
            <div class="ibs-card p-5">
                <div class="mb-3 flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="ibs-section-title">Tabel Ringkas Indikator Terfilter</h3>
                    <span class="text-xs text-slate-500 tabular-nums" x-text="filteredIndicators.length + ' dari ' + allIndicators.length + ' indikator'"></span>
                </div>
                <div class="overflow-x-auto rounded-xl border border-slate-200/80">
                    <table class="w-full text-xs">
                        <thead class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-600">
                            <tr>
                                <th class="px-3 py-2.5 text-left">No</th>
                                <th class="px-3 py-2.5 text-left">Unit</th>
                                <th class="px-3 py-2.5 text-left">Indikator</th>
                                <th class="px-3 py-2.5 text-left">Satuan</th>
                                <th class="px-3 py-2.5 text-left">Polaritas</th>
                                <th class="px-3 py-2.5 text-right">Target</th>
                                <th class="px-3 py-2.5 text-right">Realisasi</th>
                                <th class="px-3 py-2.5 text-right">Pencapaian (%)</th>
                                <th class="px-3 py-2.5 text-left">Status RAG</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="(row, idx) in filteredIndicators" :key="row.key">
                                <tr class="hover:bg-slate-50/70">
                                    <td class="px-3 py-2 text-slate-500 tabular-nums" x-text="idx + 1"></td>
                                    <td class="px-3 py-2 font-semibold text-[#0b6175]" x-text="row.unit"></td>
                                    <td class="px-3 py-2 font-medium text-slate-800" x-text="row.indikator"></td>
                                    <td class="px-3 py-2 text-slate-600" x-text="row.satuan"></td>
                                    <td class="px-3 py-2 text-slate-600" x-text="row.polaritas"></td>
                                    <td class="px-3 py-2 text-right font-medium text-slate-700 tabular-nums" x-text="row.target_display"></td>
                                    <td class="px-3 py-2 text-right font-medium text-slate-700 tabular-nums" x-text="row.realisasi_display"></td>
                                    <td class="px-3 py-2 text-right font-bold text-slate-900 tabular-nums" x-text="row.pencapaian_display"></td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-medium text-slate-700 whitespace-nowrap">
                                            <span
                                                class="h-1.5 w-1.5 rounded-full shrink-0"
                                                :class="{
                                                    'bg-emerald-500': row.tier === 'tercapai',
                                                    'bg-amber-500': row.tier === 'hampir',
                                                    'bg-rose-500': row.tier === 'perlu',
                                                    'bg-slate-400': row.tier === 'unverified',
                                                }"
                                            ></span>
                                            <span x-text="row.status"></span>
                                        </span>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="filteredIndicators.length === 0">
                                <td colspan="9" class="px-3 py-6 text-center text-xs text-slate-500">
                                    Tidak ada baris indikator pada filter aktif.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="kpi-detail-header" class="ibs-card p-4 sm:p-5">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-2.5">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#e6f4f6] text-[#0b6175]">
                    <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M9 17v-6m3 6V7m3 10v-4M5 21h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2Z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h1 class="text-xl font-bold tracking-tight text-slate-900 whitespace-nowrap">Rincian Kinerja KPI</h1>
                <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-xs font-semibold text-[#0b6175] whitespace-nowrap">
                    {{ !empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit' }}
                </span>
                @if (! empty($fullscreenPayload['period']))
                    <span class="inline-flex items-center rounded-full border border-slate-200/80 bg-white px-2.5 py-0.5 text-xs font-medium text-slate-600 tabular-nums whitespace-nowrap">
                        Periode {{ $fullscreenPayload['period'] }}
                    </span>
                @endif
            </div>

            <div id="kpi-detail-actions" class="flex flex-wrap shrink-0 items-center gap-2">
                <button
                    type="button"
                    id="kpi-detail-fullscreen-btn"
                    @click="toggleFullscreen()"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 shadow-2xs cursor-pointer whitespace-nowrap"
                    :title="isFullscreen ? 'Keluar Layar Penuh' : 'Mode Layar Penuh'"
                >
                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span x-text="isFullscreen ? 'Keluar Penuh' : 'Layar Penuh'">Layar Penuh</span>
                </button>

                <button
                    type="button"
                    id="kpi-detail-download-btn"
                    @click="downloadCsv()"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 shadow-2xs cursor-pointer whitespace-nowrap"
                >
                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4m4-5 5 5 5-5m-5 5V3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Download CSV/Excel</span>
                </button>

                <button
                    type="button"
                    id="kpi-detail-print-btn"
                    onclick="window.print()"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 shadow-2xs cursor-pointer whitespace-nowrap"
                >
                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M6 14h12v8H6z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Cetak / PDF</span>
                </button>

                @if (\App\Http\Controllers\KpiAnalyticsController::canAccessKpiAnalytics(auth()->user()))
                    <a href="{{ route('dashboard.kpi-analytics') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-[#0b6175]/25 bg-[#e6f4f6]/70 px-3 py-1.5 text-xs font-semibold text-[#0b6175] transition hover:bg-[#e6f4f6] whitespace-nowrap">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="m19 9-5 5-4-4-3 3" stroke-linecap="round" stroke-linejoin="round" />
                            <circle cx="19" cy="9" r="1.5" fill="currentColor" stroke="none" />
                        </svg>
                        Analitik & Bottleneck KPI
                    </a>
                @endif

                <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 shadow-2xs whitespace-nowrap">
                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>Kembali ke Ringkasan</span>
                </a>
            </div>
        </div>

        <div class="mt-3.5 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
            <div id="kpi-view-switcher" class="inline-flex flex-wrap items-center rounded-xl bg-slate-100/90 p-1 text-xs font-medium shrink-0">
                <button
                    type="button"
                    @click="viewMode = 'split'; $dispatch('kpi-view-changed', 'split')"
                    :class="viewMode === 'split' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                    class="pill-tab-transition inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 cursor-pointer whitespace-nowrap"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="3" width="8" height="18" rx="1"/>
                        <rect x="13" y="3" width="8" height="18" rx="1"/>
                    </svg>
                    <span>Semua Berdampingan Compact</span>
                </button>
                <button
                    type="button"
                    @click="viewMode = 'cards'; $dispatch('kpi-view-changed', 'cards')"
                    :class="viewMode === 'cards' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                    class="pill-tab-transition inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 cursor-pointer whitespace-nowrap"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="3" width="7" height="7" rx="1"/>
                        <rect x="14" y="3" width="7" height="7" rx="1"/>
                        <rect x="3" y="14" width="7" height="7" rx="1"/>
                        <rect x="14" y="14" width="7" height="7" rx="1"/>
                    </svg>
                    <span>Fokus Kartu Visual (Grid 4 Kolom)</span>
                </button>
                <button
                    type="button"
                    @click="viewMode = 'table'; $dispatch('kpi-view-changed', 'table')"
                    :class="viewMode === 'table' ? 'bg-white text-[#0b6175] font-semibold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                    class="pill-tab-transition inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 cursor-pointer whitespace-nowrap"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M3 6h18M3 12h18M3 18h18" stroke-linecap="round"/>
                    </svg>
                    <span>Fokus Tabel Matriks Padat</span>
                </button>
            </div>
            <div class="hidden sm:block text-[11px] text-slate-500">
                Mode: <span class="font-semibold text-slate-700" x-text="viewMode === 'split' ? 'Berdampingan' : (viewMode === 'cards' ? 'Kartu Visual' : 'Tabel Matriks')"></span>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12 items-start w-full">
        <div
            id="kpi-target-realisasi-container"
            x-show="viewMode === 'split' || viewMode === 'cards'"
            :class="{ 'lg:!col-span-12': viewMode === 'cards', 'hidden': viewMode === 'table' }"
            class="h-full transition-all duration-200 lg:col-span-6 w-full"
        >
            <x-widgets.target-realisasi-per-indikator
                :widget="$targetRealisasiWidget"
                :data="$targetRealisasiData"
            />
        </div>

        <div
            id="kinerja-detail"
            x-show="viewMode === 'split' || viewMode === 'table'"
            :class="{ 'lg:!col-span-12': viewMode === 'table', 'hidden': viewMode === 'cards' }"
            class="h-full transition-all duration-200 lg:col-span-6 w-full"
        >
            <x-widgets.kinerja-detail
                :widget="$kinerjaDetailWidget"
                :data="$kinerjaDetailData"
            />
        </div>
    </div>
</div>
@endsection

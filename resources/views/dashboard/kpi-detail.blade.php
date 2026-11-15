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

    $ragCounts = [
        'all' => count($fullscreenPayload['indicators'] ?? []),
        'tercapai' => 0,
        'hampir' => 0,
        'perlu' => 0,
    ];
    foreach (($fullscreenPayload['indicators'] ?? []) as $indicatorItem) {
        $tier = $indicatorItem['tier'] ?? null;
        if (isset($ragCounts[$tier])) {
            $ragCounts[$tier]++;
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
    #kpi-command-bar,
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

        const formatChartIndicatorName = (rawName, maxLen) => {
            const raw = String(rawName ?? '').trim();
            const stripped = raw
                .replace(/^[a-z]\.\s+/i, '')
                .replace(/^\d+\.\s+/, '')
                .trim();
            const label = stripped || raw;
            if (label.length <= maxLen) {
                return label;
            }
            const slice = label.slice(0, Math.max(1, maxLen - 1)).trimEnd();
            return slice + '\u2026';
        };

        const formatValueWithUnit = (displayVal, row) => {
            if (!displayVal || displayVal === 'Tidak tercatat' || displayVal === 'Belum tersedia' || displayVal === 'Tidak ada target') {
                return displayVal || 'Belum tersedia';
            }
            const unitTxt = (row?.satuan && row.satuan !== 'Tidak tercatat' && row.mode === 'numeric')
                ? (' ' + row.satuan)
                : '';
            return displayVal + unitTxt;
        };

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
            pageSearch: '',
            pageRag: 'all',
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

            applyPageFilters() {
                window.dispatchEvent(new CustomEvent('kpi-page-filter-changed', {
                    detail: {
                        search: this.pageSearch,
                        rag: this.pageRag,
                    },
                }));
            },

            setPageRag(tier) {
                this.pageRag = tier;
                this.applyPageFilters();
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
                    allBase: base.length,
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

                    const chartFont = 'Inter, system-ui, -apple-system, sans-serif';
                    const containerWidth = canvasEl.clientWidth || window.innerWidth || 1280;
                    const isNarrow = containerWidth < 680;
                    const useSlider = rows.length > 20;
                    const maxLabelLen = isNarrow ? 26 : 48;

                    const labels = rows.map((r) => formatChartIndicatorName(r.indikator, maxLabelLen));
                    const scores = rows.map((r) => {
                        const raw = r.pencapaian !== null && r.pencapaian !== undefined ? Number(r.pencapaian) : 0;
                        return Number.isNaN(raw) ? 0 : Math.max(0, raw);
                    });
                    const maxPct = Math.max(120, ...scores.map((v) => Math.ceil((v + 12) / 10) * 10));

                    const ragColor = (tier) => {
                        if (tier === 'tercapai') return tokens.rag.tercapai;
                        if (tier === 'hampir') return tokens.rag.waspada;
                        if (tier === 'perlu') return tokens.rag.kritis;
                        return tokens.targetSlate;
                    };

                    const comparisonColor = (tier) => {
                        if (tier === 'tercapai') return tokens.primary;
                        if (tier === 'hampir') return tokens.rag.waspada;
                        if (tier === 'perlu') return tokens.rag.kritis;
                        return tokens.targetSlate;
                    };

                    const tooltipFormatter = (params) => {
                        const first = Array.isArray(params) ? params[0] : params;
                        const idx = first?.dataIndex ?? 0;
                        const row = rows[idx];
                        if (!row) return '';

                        const dotColor = ragColor(row.tier);
                        const categoryLabel = row.group && row.group !== 'Umum' ? row.group : row.unit;
                        const targetWithUnit = formatValueWithUnit(row.target_display, row);
                        const realisasiWithUnit = formatValueWithUnit(row.realisasi_display, row);

                        return [
                            '<div style="max-width:360px;white-space:normal;font-family:' + chartFont + ';font-size:12px;line-height:1.5;color:#334155;">',
                            '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">',
                            '<span style="display:inline-block;background:#e6f4f6;color:#0b6175;font-size:10px;font-weight:700;padding:2px 7px;border-radius:9999px;">' + escapeHtml(categoryLabel || 'KPI') + '</span>',
                            row.unit ? '<span style="color:#64748b;font-size:10.5px;font-weight:600;">' + escapeHtml(row.unit) + '</span>' : '',
                            '</div>',
                            '<div style="font-weight:700;color:#0f172a;margin-bottom:8px;line-height:1.35;">' + escapeHtml(row.indikator) + '</div>',
                            '<div style="display:grid;grid-template-columns:auto 1fr;gap:3px 14px;border-top:1px solid #f1f5f9;padding-top:6px;">',
                            '<span style="color:#64748b;">Target</span><strong style="text-align:right;color:#0f172a;font-variant-numeric:tabular-nums;">' + escapeHtml(targetWithUnit) + '</strong>',
                            '<span style="color:#64748b;">Realisasi</span><strong style="text-align:right;color:#0f172a;font-variant-numeric:tabular-nums;">' + escapeHtml(realisasiWithUnit) + '</strong>',
                            '<span style="color:#64748b;">Capaian</span><strong style="text-align:right;color:#0b6175;font-variant-numeric:tabular-nums;">' + escapeHtml(row.pencapaian_display || 'Belum tersedia') + '</strong>',
                            '<span style="color:#64748b;">Status</span><span style="text-align:right;font-weight:600;color:#0f172a;"><span style="display:inline-block;width:7px;height:7px;border-radius:9999px;background:' + dotColor + ';margin-right:5px;vertical-align:middle;"></span>' + escapeHtml(row.status) + '</span>',
                            '</div>',
                            '</div>',
                        ].join('');
                    };

                    const baseYAxis = {
                        type: 'category',
                        inverse: true,
                        data: labels,
                        axisLine: { lineStyle: { color: tokens.gridLine } },
                        axisTick: { show: false },
                        axisLabel: {
                            fontFamily: chartFont,
                            fontSize: isNarrow ? 10 : 11.5,
                            lineHeight: 15,
                            color: '#1e293b',
                            width: isNarrow ? 138 : 270,
                            overflow: 'truncate',
                        },
                    };

                    const baseTooltip = {
                        trigger: 'axis',
                        axisPointer: { type: 'shadow' },
                        backgroundColor: '#ffffff',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        padding: [10, 12],
                        extraCssText: 'box-shadow:0 10px 25px -5px rgba(15,23,42,0.10);border-radius:10px;',
                        formatter: tooltipFormatter,
                    };

                    const baseDataZoom = useSlider
                        ? [
                            { type: 'inside', yAxisIndex: 0 },
                            { type: 'slider', yAxisIndex: 0, right: 6, width: 12, startValue: 0, endValue: 19 },
                        ]
                        : [];

                    let option;
                    if (this.chartMode === 'comparison') {
                        option = {
                            animation: true,
                            animationDuration: 420,
                            textStyle: { fontFamily: chartFont },
                            tooltip: baseTooltip,
                            legend: {
                                top: 2,
                                left: isNarrow ? 150 : 285,
                                itemWidth: 12,
                                itemHeight: 8,
                                textStyle: { fontFamily: chartFont, fontSize: 11, color: '#475569' },
                                data: [
                                    { name: 'Target', itemStyle: { color: tokens.targetSlate } },
                                    { name: 'Realisasi', itemStyle: { color: tokens.primary } },
                                ],
                            },
                            dataZoom: baseDataZoom,
                            grid: {
                                left: isNarrow ? 150 : 285,
                                right: (isNarrow ? 75 : 175) + (useSlider ? 20 : 0),
                                top: 38,
                                bottom: 26,
                            },
                            xAxis: {
                                type: 'value',
                                min: 0,
                                max: maxPct,
                                axisLabel: {
                                    fontFamily: chartFont,
                                    formatter: (val) => val + '%',
                                    fontSize: 11,
                                    color: tokens.axisText,
                                },
                                splitLine: { lineStyle: { color: tokens.gridLine } },
                            },
                            yAxis: baseYAxis,
                            series: [
                                {
                                    name: 'Target',
                                    type: 'bar',
                                    barMaxWidth: 9,
                                    barGap: '28%',
                                    itemStyle: { color: tokens.targetSlate, borderRadius: [0, 4, 4, 0] },
                                    data: rows.map((r) => {
                                        const hasTarget = (r.target_num !== null && r.target_num !== undefined)
                                            || (r.pencapaian !== null && r.pencapaian !== undefined);
                                        return {
                                            value: hasTarget ? 100 : 0,
                                            raw_display: formatValueWithUnit(r.target_display, r),
                                        };
                                    }),
                                    label: {
                                        show: !isNarrow,
                                        position: 'right',
                                        fontFamily: chartFont,
                                        fontSize: 10,
                                        color: '#64748b',
                                        formatter: (p) => p?.data?.raw_display || '',
                                    },
                                },
                                {
                                    name: 'Realisasi',
                                    type: 'bar',
                                    barMaxWidth: 9,
                                    data: rows.map((r) => {
                                        const rawPct = r.pencapaian !== null && r.pencapaian !== undefined ? Number(r.pencapaian) : 0;
                                        return {
                                            value: Number.isNaN(rawPct) ? 0 : Math.max(0, rawPct),
                                            raw_display: formatValueWithUnit(r.realisasi_display, r),
                                            itemStyle: { color: comparisonColor(r.tier), borderRadius: [0, 4, 4, 0] },
                                        };
                                    }),
                                    label: {
                                        show: true,
                                        position: 'right',
                                        fontFamily: chartFont,
                                        fontSize: 10.5,
                                        fontWeight: 600,
                                        color: '#0f172a',
                                        formatter: (p) => {
                                            const r = rows[p.dataIndex];
                                            if (!r) return '';
                                            const pctTxt = r.pencapaian_display || 'Belum tersedia';
                                            if (isNarrow) return pctTxt;
                                            const realTxt = formatValueWithUnit(r.realisasi_display, r);
                                            return realTxt + ' (' + pctTxt + ')';
                                        },
                                    },
                                    markLine: {
                                        silent: true,
                                        symbol: 'none',
                                        data: [
                                            {
                                                xAxis: tercapaiMin,
                                                lineStyle: { color: tokens.primary, type: 'dashed', width: 1.5 },
                                                label: {
                                                    formatter: 'Target 100%',
                                                    position: 'start',
                                                    align: 'center',
                                                    distance: 6,
                                                    fontFamily: chartFont,
                                                    fontSize: 10,
                                                    color: tokens.primary,
                                                },
                                            },
                                        ],
                                    },
                                },
                            ],
                        };
                    } else {
                        option = {
                            animation: true,
                            animationDuration: 420,
                            textStyle: { fontFamily: chartFont },
                            tooltip: baseTooltip,
                            dataZoom: baseDataZoom,
                            grid: {
                                left: isNarrow ? 150 : 285,
                                right: (isNarrow ? 65 : 155) + (useSlider ? 20 : 0),
                                top: 34,
                                bottom: 26,
                            },
                            xAxis: {
                                type: 'value',
                                min: 0,
                                max: maxPct,
                                axisLabel: {
                                    fontFamily: chartFont,
                                    formatter: '{value}%',
                                    fontSize: 11,
                                    color: tokens.axisText,
                                },
                                splitLine: { lineStyle: { color: tokens.gridLine } },
                            },
                            yAxis: baseYAxis,
                            series: [
                                {
                                    name: 'Capaian (%)',
                                    type: 'bar',
                                    barMaxWidth: 16,
                                    showBackground: true,
                                    backgroundStyle: {
                                        color: 'rgba(241, 245, 249, 0.75)',
                                        borderRadius: [0, 6, 6, 0],
                                    },
                                    data: rows.map((r) => {
                                        const rawPct = r.pencapaian !== null && r.pencapaian !== undefined ? Number(r.pencapaian) : 0;
                                        return {
                                            value: Number.isNaN(rawPct) ? 0 : Math.max(0, rawPct),
                                            itemStyle: { color: ragColor(r.tier), borderRadius: [0, 6, 6, 0] },
                                        };
                                    }),
                                    label: {
                                        show: true,
                                        position: 'right',
                                        formatter: (p) => {
                                            const r = rows[p.dataIndex];
                                            if (!r) return 'Belum tersedia';
                                            const pctTxt = r.pencapaian_display || 'Belum tersedia';
                                            const hasPair = !isNarrow
                                                && r.realisasi_display && r.realisasi_display !== 'Belum tersedia' && r.realisasi_display !== 'Tidak tercatat'
                                                && r.target_display && r.target_display !== 'Tidak tercatat' && r.target_display !== 'Tidak ada target';
                                            if (!hasPair) {
                                                return '{pct|' + pctTxt + '}';
                                            }
                                            return '{pct|' + pctTxt + '}  {sep||}  {ratio|' + r.realisasi_display + ' / ' + r.target_display + '}';
                                        },
                                        rich: {
                                            pct: {
                                                fontFamily: chartFont,
                                                fontWeight: 700,
                                                fontSize: 11,
                                                color: '#0f172a',
                                            },
                                            sep: {
                                                fontFamily: chartFont,
                                                fontSize: 10,
                                                color: '#cbd5e1',
                                            },
                                            ratio: {
                                                fontFamily: chartFont,
                                                fontSize: 10.5,
                                                color: '#64748b',
                                            },
                                        },
                                    },
                                    markLine: {
                                        silent: true,
                                        symbol: 'none',
                                        data: [
                                            {
                                                xAxis: tercapaiMin,
                                                lineStyle: { color: tokens.primary, type: 'dashed', width: 1.5 },
                                                label: {
                                                    formatter: 'Target 100%',
                                                    position: 'start',
                                                    align: 'left',
                                                    distance: 6,
                                                    fontFamily: chartFont,
                                                    fontSize: 10,
                                                    color: tokens.primary,
                                                },
                                            },
                                            {
                                                xAxis: hampirMin,
                                                lineStyle: { color: tokens.rag.waspada, type: 'dotted', width: 1.5 },
                                                label: {
                                                    formatter: 'Ambang 95%',
                                                    position: 'start',
                                                    align: 'right',
                                                    distance: 6,
                                                    fontFamily: chartFont,
                                                    fontSize: 10,
                                                    color: tokens.rag.waspada,
                                                },
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
                csv += 'Indikator;Polaritas;Satuan;Bobot;Target;Realisasi;Skor Pencapaian KPI (Maks 110%);Status\r\n';
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
    class="mx-auto max-w-[1600px] min-w-0 max-w-full space-y-4"
>
    {{-- Fullscreen Executive Boardroom Presentation Studio --}}
    <div
        id="kpi-fullscreen-workspace"
        x-show="isFullscreen"
        x-cloak
        role="dialog"
        aria-modal="true"
        aria-labelledby="kpi-fullscreen-title"
        class="fixed inset-0 z-50 bg-slate-900/55 backdrop-blur-sm p-3 sm:p-4 overflow-y-auto no-print"
    >
        <div class="mx-auto max-w-[1600px] rounded-2xl border border-slate-200/80 bg-slate-50/95 p-3 sm:p-4 shadow-2xl space-y-3">
            {{-- Compact Single Unified Studio Command Header --}}
            <div class="ibs-card rounded-2xl border border-slate-200/80 bg-white px-4 py-3 shadow-sm">
                {{-- Top Row: Identity and Actions --}}
                <div class="flex flex-col gap-2.5 xl:flex-row xl:items-center xl:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5 text-[11px] font-medium text-slate-500">
                            <span class="font-semibold uppercase tracking-[0.06em] text-[#0b6175]">Executive Presentation Studio</span>
                            <span class="h-1 w-1 rounded-full bg-slate-300" aria-hidden="true"></span>
                            <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175]" x-text="payload.unitLabel || 'Semua Unit'"></span>
                            <span class="inline-flex items-center rounded-full border border-slate-200/80 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600 tabular-nums">
                                Periode <strong class="ml-1 font-semibold text-slate-900" x-text="payload.period || 'Belum tersedia'"></strong>
                            </span>
                        </div>
                        <h2 id="kpi-fullscreen-title" class="mt-0.5 text-base sm:text-lg font-semibold tracking-tight text-slate-900">
                            Visualisasi Grafik Capaian Indikator Kinerja
                        </h2>
                    </div>

                    {{-- Compact Action Buttons --}}
                    <div class="flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            id="kpi-fullscreen-download-chart-btn"
                            @click="downloadChartPng()"
                            :disabled="filteredIndicators.length === 0 || !!chartError"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-[#0b6175]/25 bg-[#e6f4f6]/70 px-2.5 py-1.5 text-xs font-semibold text-[#0b6175] transition hover:bg-[#e6f4f6] disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4m4-5 5 5 5-5m-5 5V3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span>Unduh PNG</span>
                        </button>

                        <button
                            type="button"
                            id="kpi-fullscreen-download-csv-btn"
                            @click="downloadCsv()"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                        >
                            <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M14 2v6h6M8 13h8M8 17h8M8 9h2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span>Unduh CSV</span>
                        </button>

                        <button
                            type="button"
                            id="kpi-fullscreen-close-btn"
                            @click="closeFullscreen()"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                        >
                            <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span>Tutup</span>
                            <kbd class="rounded border border-slate-200 bg-slate-100 px-1 py-0.5 text-[10px] font-semibold text-slate-500">ESC</kbd>
                        </button>
                    </div>
                </div>

                {{-- Integrated Control Ribbon --}}
                <div class="mt-2.5 pt-2.5 border-t border-slate-100 flex flex-col gap-2 xl:flex-row xl:items-center xl:justify-between">
                    <div class="flex flex-wrap items-center gap-2">
                        {{-- Segmented Chart Mode Switcher --}}
                        <div class="inline-flex items-center rounded-xl border border-slate-200/60 bg-slate-100/90 p-0.5 text-xs">
                            <button
                                type="button"
                                id="kpi-fullscreen-mode-achievement"
                                @click="setChartMode('achievement')"
                                :class="chartMode === 'achievement' ? 'bg-white text-[#0b6175] font-semibold shadow-xs ring-1 ring-slate-900/5' : 'text-slate-600 font-medium hover:text-slate-900'"
                                class="pill-tab-transition rounded-lg px-2.5 py-1 cursor-pointer whitespace-nowrap"
                            >
                                Capaian (%)
                            </button>
                            <button
                                type="button"
                                id="kpi-fullscreen-mode-comparison"
                                @click="setChartMode('comparison')"
                                :class="chartMode === 'comparison' ? 'bg-white text-[#0b6175] font-semibold shadow-xs ring-1 ring-slate-900/5' : 'text-slate-600 font-medium hover:text-slate-900'"
                                class="pill-tab-transition rounded-lg px-2.5 py-1 cursor-pointer whitespace-nowrap"
                            >
                                Perbandingan Target vs Realisasi
                            </button>
                        </div>

                        {{-- RAG Status Pills --}}
                        <div class="flex flex-wrap items-center gap-1.5 text-[11px] tabular-nums" role="group" aria-label="Filter status capaian grafik layar penuh">
                            <button
                                type="button"
                                data-fullscreen-rag="all"
                                @click="setRagFilter('all')"
                                :class="ragFilter === 'all' ? 'border-slate-800 text-slate-900 font-semibold ring-1 ring-slate-800/15 shadow-2xs' : 'border-slate-200/80 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50'"
                                class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1 transition cursor-pointer whitespace-nowrap"
                            >
                                <span class="h-1.5 w-1.5 rounded-full bg-slate-400 shrink-0"></span>
                                <span>Semua</span>
                                <span class="text-slate-400" x-text="summaryStats.allBase">0</span>
                            </button>
                            <button
                                type="button"
                                data-fullscreen-rag="tercapai"
                                @click="setRagFilter('tercapai')"
                                :class="ragFilter === 'tercapai' ? 'border-emerald-600 text-emerald-950 font-semibold ring-1 ring-emerald-600/20 shadow-2xs' : 'border-slate-200/80 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50'"
                                class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1 transition cursor-pointer whitespace-nowrap"
                            >
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                                <span>Tercapai</span>
                                <span class="text-emerald-700" x-text="summaryStats.tercapai">0</span>
                            </button>
                            <button
                                type="button"
                                data-fullscreen-rag="hampir"
                                @click="setRagFilter('hampir')"
                                :class="ragFilter === 'hampir' ? 'border-amber-600 text-amber-950 font-semibold ring-1 ring-amber-600/20 shadow-2xs' : 'border-slate-200/80 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50'"
                                class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1 transition cursor-pointer whitespace-nowrap"
                            >
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                                <span>Hampir Tercapai</span>
                                <span class="text-amber-700" x-text="summaryStats.hampir">0</span>
                            </button>
                            <button
                                type="button"
                                data-fullscreen-rag="perlu"
                                @click="setRagFilter('perlu')"
                                :class="ragFilter === 'perlu' ? 'border-rose-600 text-rose-950 font-semibold ring-1 ring-rose-600/20 shadow-2xs' : 'border-slate-200/80 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50'"
                                class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1 transition cursor-pointer whitespace-nowrap"
                            >
                                <span class="h-1.5 w-1.5 rounded-full bg-rose-500 shrink-0"></span>
                                <span>Perlu Peningkatan</span>
                                <span class="text-rose-700" x-text="summaryStats.perlu">0</span>
                            </button>
                        </div>
                    </div>

                    {{-- Live Search + Filtered Count Badge --}}
                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                        <span
                            class="inline-flex items-center rounded-full border border-slate-200/80 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-600 tabular-nums whitespace-nowrap"
                            x-text="'Menampilkan ' + filteredIndicators.length + ' dari ' + allIndicators.length + ' indikator'"
                        ></span>
                        <div class="relative w-full sm:w-60 shrink-0">
                            <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="7"/>
                                <path d="m20 20-3.5-3.5" stroke-linecap="round"/>
                            </svg>
                            <input
                                id="kpi-fullscreen-search"
                                type="search"
                                x-model="searchQuery"
                                @input="scheduleChartRender()"
                                placeholder="Cari indikator..."
                                aria-label="Cari indikator di grafik layar penuh"
                                class="w-full rounded-lg border border-slate-200/80 bg-white pl-8 pr-3 py-1.5 text-xs text-slate-800 placeholder:text-slate-400 transition focus:border-[#0b6175] focus:outline-none focus:ring-2 focus:ring-[#0b6175]/15"
                            />
                        </div>
                    </div>
                </div>
            </div>

            {{-- Boardroom Stage Grid: Primary Chart (8 cols) + Companion Scorecard (4 cols) --}}
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-4 items-stretch">
                {{-- Left/Primary Visual Panel --}}
                <div class="ibs-card xl:col-span-8 min-w-0 max-w-full overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-sm flex flex-col">
                    <div class="mb-2.5 flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3
                                class="ibs-section-title text-sm font-semibold text-slate-900"
                                x-text="chartMode === 'achievement' ? 'Capaian Indikator (%) terhadap Target 100%' : 'Perbandingan Target vs Realisasi (Skala Ternormalisasi %)'"
                            ></h3>
                            <span class="rounded-full bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175] tabular-nums" x-text="filteredIndicators.length + ' indikator'"></span>
                        </div>

                        {{-- Minimalist Contextual Color Key --}}
                        <div x-show="chartMode === 'achievement'" class="flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
                            <span class="inline-flex items-center gap-1.5">
                                <span class="h-2 w-2 rounded-full bg-emerald-500 shrink-0"></span>
                                <span>&ge; 100% Tercapai</span>
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <span class="h-2 w-2 rounded-full bg-amber-500 shrink-0"></span>
                                <span>95% s.d. 99,9% Mendekati Target</span>
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <span class="h-2 w-2 rounded-full bg-rose-500 shrink-0"></span>
                                <span>&lt; 95% Perlu Perhatian</span>
                            </span>
                        </div>

                        <div x-show="chartMode === 'comparison'" class="flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
                            <span class="inline-flex items-center gap-1.5">
                                <span class="h-2 w-2 rounded-full bg-slate-300 shrink-0"></span>
                                <span>Target (Acuan 100%)</span>
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <span class="h-2 w-2 rounded-full bg-[#0b6175] shrink-0"></span>
                                <span>Realisasi (nilai aktual tertera di setiap baris)</span>
                            </span>
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
                            class="rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-50 cursor-pointer"
                        >
                            Muat Ulang Grafik
                        </button>
                    </div>

                    <div x-show="!isChartLoading && !chartError && filteredIndicators.length === 0" class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-slate-50/60 p-6 text-center" style="width: 100%; height: 500px; min-height: 440px;">
                        <p class="text-xs font-medium text-slate-600">Tidak ada indikator yang cocok dengan filter atau kata kunci pencarian.</p>
                        <button
                            type="button"
                            @click="resetFullscreenFilters()"
                            class="rounded-lg border border-slate-200/80 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer"
                        >
                            Reset Filter
                        </button>
                    </div>

                    <div
                        x-show="!isChartLoading && !chartError && filteredIndicators.length > 0"
                        id="kpi-fullscreen-echart-canvas"
                        class="w-full"
                        style="width: 100%; height: 500px; min-height: 440px;"
                    ></div>
                </div>

                {{-- Right/Companion Executive Scorecard & Matrix --}}
                <div class="ibs-card xl:col-span-4 min-w-0 max-w-full rounded-2xl border border-slate-200/80 bg-white flex flex-col shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                        <h3 class="ibs-section-title text-sm font-semibold text-slate-900">Ringkasan Kinerja per Indikator</h3>
                        <span class="rounded-full border border-slate-200/80 bg-slate-50 px-2 py-0.5 text-[11px] font-medium text-slate-600 tabular-nums whitespace-nowrap">
                            Skor Maks 110%
                        </span>
                    </div>

                    <div class="flex-1 max-h-[540px] overflow-y-auto overflow-x-hidden">
                        <table class="w-full table-fixed border-collapse text-xs">
                            <thead class="sticky top-0 z-10 border-b border-slate-200/80 bg-slate-50/95 backdrop-blur-xs text-[11px] font-semibold uppercase tracking-[0.05em] text-slate-500">
                                <tr>
                                    <th class="w-[44%] pl-3.5 pr-1.5 py-2.5 text-left">Indikator</th>
                                    <th class="w-[18%] px-1.5 py-2.5 text-right whitespace-nowrap">Target</th>
                                    <th class="w-[19%] px-1.5 py-2.5 text-right whitespace-nowrap">Realisasi</th>
                                    <th class="w-[19%] pl-1.5 pr-3.5 py-2.5 text-right whitespace-nowrap">Capaian</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template x-for="row in filteredIndicators" :key="row.key">
                                    <tr class="transition-colors hover:bg-slate-50/80">
                                        <td class="pl-3.5 pr-1.5 py-2.5 align-top break-words">
                                            <div class="font-medium leading-snug text-slate-800" x-text="row.indikator"></div>
                                            <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[10.5px] text-slate-500">
                                                <span class="inline-flex items-center rounded bg-[#e6f4f6] px-1.5 py-0.5 font-semibold text-[#0b6175]" x-text="row.unit"></span>
                                                <span x-show="row.group && row.group !== 'Umum'" class="truncate max-w-[140px]" x-text="row.group"></span>
                                                <span x-show="row.satuan && row.satuan !== 'Tidak tercatat'" class="text-slate-400" x-text="row.satuan"></span>
                                            </div>
                                        </td>
                                        <td class="px-1.5 py-2.5 text-right align-top font-medium text-slate-600 tabular-nums" x-text="row.target_display || 'Tidak tercatat'"></td>
                                        <td
                                            class="px-1.5 py-2.5 text-right align-top font-medium tabular-nums"
                                            :class="(row.realisasi_display && row.realisasi_display !== 'Tidak tercatat' && row.realisasi_display !== 'Belum tersedia') ? 'text-slate-800' : 'text-slate-400'"
                                            x-text="(row.realisasi_display && row.realisasi_display !== 'Tidak tercatat') ? row.realisasi_display : 'Belum tersedia'"
                                        ></td>
                                        <td class="pl-1.5 pr-3.5 py-2.5 text-right align-top tabular-nums">
                                            <div class="inline-flex items-center justify-end gap-1.5">
                                                <span
                                                    class="h-1.5 w-1.5 rounded-full shrink-0"
                                                    :class="{
                                                        'bg-emerald-500': row.tier === 'tercapai',
                                                        'bg-amber-500': row.tier === 'hampir',
                                                        'bg-rose-500': row.tier === 'perlu',
                                                        'bg-slate-400': row.tier === 'unverified',
                                                    }"
                                                ></span>
                                                <span class="font-bold text-slate-900" x-text="row.pencapaian_display || 'Belum tersedia'"></span>
                                            </div>
                                            <div class="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-slate-100">
                                                <div
                                                    class="h-1 rounded-full transition-all"
                                                    :class="{
                                                        'bg-emerald-500': row.tier === 'tercapai',
                                                        'bg-amber-500': row.tier === 'hampir',
                                                        'bg-rose-500': row.tier === 'perlu',
                                                        'bg-slate-300': row.tier === 'unverified',
                                                    }"
                                                    :style="'width: ' + Math.min(100, Math.max(0, Number(row.pencapaian || 0))) + '%'"
                                                ></div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="filteredIndicators.length === 0">
                                    <td colspan="4" class="px-4 py-8 text-center text-xs text-slate-500">
                                        Tidak ada baris indikator pada filter aktif.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="kpi-detail-header" class="ibs-card min-w-0 max-w-full rounded-2xl border border-slate-200/80 bg-white px-4 py-3 sm:px-5 sm:py-3.5 shadow-[0_1px_3px_rgba(15,23,42,0.03)]">
        {{-- Tier 1: Executive Identity & Action Cluster --}}
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between min-w-0">
            <div class="flex flex-wrap items-center gap-2.5 sm:gap-3 min-w-0">
                <a
                    href="{{ route('dashboard') }}"
                    class="inline-flex items-center gap-1.5 rounded-lg py-1 text-xs font-medium text-slate-500 transition hover:text-slate-900 whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>Ringkasan</span>
                </a>
                <span class="hidden sm:block h-4 w-px bg-slate-200" aria-hidden="true"></span>
                <h1 class="text-lg sm:text-xl font-bold tracking-tight text-slate-900 sm:whitespace-nowrap">Rincian Kinerja KPI</h1>
                <span class="inline-flex items-center rounded-full bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175] whitespace-nowrap">
                    {{ !empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit' }}
                </span>
                @if (! empty($fullscreenPayload['period']))
                    <span class="inline-flex items-center rounded-full border border-slate-200/80 bg-slate-50/70 px-2.5 py-0.5 text-[11px] font-medium text-slate-600 tabular-nums whitespace-nowrap">
                        Periode {{ $fullscreenPayload['period'] }}
                    </span>
                @endif
            </div>

            <div id="kpi-detail-actions" class="flex min-w-0 max-w-full flex-wrap items-center gap-2">
                @if (\App\Http\Controllers\KpiAnalyticsController::canAccessKpiAnalytics(auth()->user()))
                    <a
                        href="{{ route('dashboard.kpi-analytics') }}"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    >
                        <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M3 3v18h18" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="m19 9-5 5-4-4-3 3" stroke-linecap="round" stroke-linejoin="round" />
                            <circle cx="19" cy="9" r="1.5" fill="currentColor" stroke="none" />
                        </svg>
                        <span>Analitik KPI</span>
                    </a>
                @endif

                <button
                    type="button"
                    id="kpi-detail-download-btn"
                    @click="downloadCsv()"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                >
                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4m4-5 5 5 5-5m-5 5V3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Unduh CSV</span>
                </button>

                <button
                    type="button"
                    id="kpi-detail-print-btn"
                    onclick="window.print()"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200/80 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                >
                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M6 14h12v8H6z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Cetak Laporan</span>
                </button>

                <button
                    type="button"
                    id="kpi-detail-fullscreen-btn"
                    @click="toggleFullscreen()"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-[#0b6175] px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-[#094e5e] cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    :title="isFullscreen ? 'Keluar Layar Penuh' : 'Mode Layar Penuh'"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span x-text="isFullscreen ? 'Keluar Penuh' : 'Layar Penuh'">Layar Penuh</span>
                </button>
            </div>
        </div>

        {{-- Tier 2: Single Unified Command Bar --}}
        <div id="kpi-command-bar" class="mt-3 flex flex-col gap-2.5 border-t border-slate-200/70 pt-2.5 xl:flex-row xl:items-center xl:justify-between min-w-0 no-print">
            <div id="kpi-view-switcher" class="inline-flex min-w-0 max-w-full flex-wrap items-center gap-0.5 rounded-xl border border-slate-200/60 bg-slate-100/90 p-1 text-xs">
                <button
                    type="button"
                    @click="viewMode = 'split'; $dispatch('kpi-view-changed', 'split')"
                    :class="viewMode === 'split' ? 'bg-white text-[#0b6175] shadow-xs ring-1 ring-slate-900/5 font-semibold' : 'text-slate-600 hover:text-slate-900 font-medium'"
                    class="pill-tab-transition inline-flex max-w-full items-center gap-1.5 rounded-lg px-3 py-1.5 cursor-pointer sm:whitespace-nowrap text-left"
                >
                    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="3" width="8" height="18" rx="1"/>
                        <rect x="13" y="3" width="8" height="18" rx="1"/>
                    </svg>
                    <span>Berdampingan</span>
                    <span class="sr-only">Semua Berdampingan Compact</span>
                </button>
                <button
                    type="button"
                    @click="viewMode = 'cards'; $dispatch('kpi-view-changed', 'cards')"
                    :class="viewMode === 'cards' ? 'bg-white text-[#0b6175] shadow-xs ring-1 ring-slate-900/5 font-semibold' : 'text-slate-600 hover:text-slate-900 font-medium'"
                    class="pill-tab-transition inline-flex max-w-full items-center gap-1.5 rounded-lg px-3 py-1.5 cursor-pointer sm:whitespace-nowrap text-left"
                >
                    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="3" width="7" height="7" rx="1"/>
                        <rect x="14" y="3" width="7" height="7" rx="1"/>
                        <rect x="3" y="14" width="7" height="7" rx="1"/>
                        <rect x="14" y="14" width="7" height="7" rx="1"/>
                    </svg>
                    <span>Kartu Visual</span>
                    <span class="sr-only">Fokus Kartu Visual (Grid 4 Kolom)</span>
                </button>
                <button
                    type="button"
                    @click="viewMode = 'table'; $dispatch('kpi-view-changed', 'table')"
                    :class="viewMode === 'table' ? 'bg-white text-[#0b6175] shadow-xs ring-1 ring-slate-900/5 font-semibold' : 'text-slate-600 hover:text-slate-900 font-medium'"
                    class="pill-tab-transition inline-flex max-w-full items-center gap-1.5 rounded-lg px-3 py-1.5 cursor-pointer sm:whitespace-nowrap text-left"
                >
                    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M3 6h18M3 12h18M3 18h18" stroke-linecap="round"/>
                    </svg>
                    <span>Tabel Matriks</span>
                    <span class="sr-only">Fokus Tabel Matriks Padat</span>
                </button>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center min-w-0">
                <div class="flex flex-wrap items-center gap-1.5 text-xs" role="group" aria-label="Filter status capaian KPI">
                    <button
                        type="button"
                        @click="setPageRag('all')"
                        :class="pageRag === 'all' ? 'border-slate-800 text-slate-900 font-semibold ring-1 ring-slate-900/10 shadow-2xs' : 'border-slate-200/80 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50'"
                        class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1.5 transition cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400 shrink-0"></span>
                        <span>Semua</span>
                        <span class="tabular-nums text-[11px]" :class="pageRag === 'all' ? 'text-slate-700' : 'text-slate-400'">{{ $ragCounts['all'] }}</span>
                    </button>
                    <button
                        type="button"
                        @click="setPageRag('tercapai')"
                        :class="pageRag === 'tercapai' ? 'border-emerald-600 text-emerald-950 font-semibold ring-1 ring-emerald-600/20 shadow-2xs' : 'border-slate-200/80 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50'"
                        class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1.5 transition cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                        <span>Tercapai</span>
                        <span class="tabular-nums text-[11px]" :class="pageRag === 'tercapai' ? 'text-emerald-800' : 'text-slate-400'">{{ $ragCounts['tercapai'] }}</span>
                    </button>
                    <button
                        type="button"
                        @click="setPageRag('hampir')"
                        :class="pageRag === 'hampir' ? 'border-amber-600 text-amber-950 font-semibold ring-1 ring-amber-600/20 shadow-2xs' : 'border-slate-200/80 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50'"
                        class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1.5 transition cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                        <span>Mendekati</span>
                        <span class="tabular-nums text-[11px]" :class="pageRag === 'hampir' ? 'text-amber-800' : 'text-slate-400'">{{ $ragCounts['hampir'] }}</span>
                    </button>
                    <button
                        type="button"
                        @click="setPageRag('perlu')"
                        :class="pageRag === 'perlu' ? 'border-rose-600 text-rose-950 font-semibold ring-1 ring-rose-600/20 shadow-2xs' : 'border-slate-200/80 text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50'"
                        class="inline-flex items-center gap-1.5 rounded-lg border bg-white px-2.5 py-1.5 transition cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-rose-500 shrink-0"></span>
                        <span>Perlu Perhatian</span>
                        <span class="tabular-nums text-[11px]" :class="pageRag === 'perlu' ? 'text-rose-800' : 'text-slate-400'">{{ $ragCounts['perlu'] }}</span>
                    </button>
                </div>

                <div class="relative w-full sm:w-60 shrink-0">
                    <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"/>
                        <path d="m20 20-3.5-3.5" stroke-linecap="round"/>
                    </svg>
                    <input
                        id="kpi-unified-search"
                        type="search"
                        x-model="pageSearch"
                        @input="applyPageFilters()"
                        placeholder="Cari indikator KPI..."
                        aria-label="Cari indikator KPI"
                        class="w-full rounded-lg border border-slate-200/80 bg-white pl-8 pr-3 py-1.5 text-xs text-slate-800 placeholder:text-slate-400 transition focus:border-[#0b6175] focus:outline-none focus:ring-2 focus:ring-[#0b6175]/15"
                    />
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12 items-start w-full min-w-0">
        <div
            id="kpi-target-realisasi-container"
            x-show="viewMode === 'split' || viewMode === 'cards'"
            :class="{ 'lg:!col-span-12': viewMode === 'cards', 'hidden': viewMode === 'table' }"
            class="h-full min-w-0 max-w-full transition-all duration-200 lg:col-span-5 w-full"
        >
            <x-widgets.target-realisasi-per-indikator
                :widget="$targetRealisasiWidget"
                :data="$targetRealisasiData"
                :embedded-in-kpi-detail="true"
            />
        </div>

        <div
            id="kinerja-detail"
            x-show="viewMode === 'split' || viewMode === 'table'"
            :class="{ 'lg:!col-span-12': viewMode === 'table', 'hidden': viewMode === 'cards' }"
            class="h-full min-w-0 max-w-full transition-all duration-200 lg:col-span-7 w-full"
        >
            <x-widgets.kinerja-detail
                :widget="$kinerjaDetailWidget"
                :data="$kinerjaDetailData"
                :embedded-in-kpi-detail="true"
            />
        </div>
    </div>
</div>
@endsection

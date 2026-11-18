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

<script @cspNonce>
if (typeof window.kpiDetailManager !== 'function') {
    window.kpiDetailManager = function() {
        let fullscreenChartInstance = null;
        let fullscreenResizeObserver = null;
        let renderTimer = null;
        let spotlightTimer = null;
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

        return {
            viewMode: 'cards',
            pageSearch: '',
            pageRag: 'all',
            pageGroup: 'all',
            pageSort: 'default',
            inspectorOpen: false,
            inspectedItem: null,
            inspectorPreviousFocus: null,
            isFullscreen: false,
            studioTheme: 'light',
            focusMode: false,
            autoSpotlight: false,
            spotlightIndex: 0,
            selectedIndicatorKey: null,
            fullscreenSort: 'default',
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
                const params = new URLSearchParams(window.location.search);
                const ragParam = params.get('rag');
                if (['all', 'tercapai', 'hampir', 'perlu'].includes(ragParam)) {
                    this.pageRag = ragParam;
                    this.ragFilter = ragParam;
                }
                const viewParam = params.get('view');
                if (['split', 'cards', 'table'].includes(viewParam)) {
                    this.viewMode = viewParam;
                }
                const qParam = params.get('search');
                if (qParam) {
                    this.pageSearch = qParam;
                }

                if (ragParam || viewParam || qParam) {
                    this.$nextTick(() => {
                        this.$dispatch('kpi-view-changed', this.viewMode);
                        this.applyPageFilters();
                    });
                }

                document.addEventListener('fullscreenchange', () => {
                    const active = !!document.fullscreenElement;
                    if (!active && this.isFullscreen) {
                        this.stopSpotlight();
                        this.isFullscreen = false;
                        this.disposeChart();
                    }
                });
            },

            get availableGroups() {
                const seen = new Set();
                const groups = [];
                this.allIndicators.forEach((item) => {
                    const grp = String(item.group || '').trim();
                    if (grp && grp !== 'Umum' && !seen.has(grp)) {
                        seen.add(grp);
                        groups.push(grp);
                    }
                });
                return groups;
            },

            get hasActivePageFilters() {
                return this.pageSearch.trim() !== ''
                    || this.pageRag !== 'all'
                    || this.pageGroup !== 'all'
                    || this.pageSort !== 'default';
            },

            resetPageFilters() {
                this.pageSearch = '';
                this.pageRag = 'all';
                this.pageGroup = 'all';
                this.pageSort = 'default';
                this.applyPageFilters();
            },

            applyPageFilters() {
                window.dispatchEvent(new CustomEvent('kpi-page-filter-changed', {
                    detail: {
                        search: this.pageSearch,
                        rag: this.pageRag,
                        group: this.pageGroup,
                        sort: this.pageSort,
                    },
                }));
            },

            setPageRag(tier) {
                this.pageRag = tier;
                this.applyPageFilters();
            },

            setPageGroup(group) {
                this.pageGroup = group;
                this.applyPageFilters();
            },

            openInspectorById(indicatorId) {
                const found = this.allIndicators.find((item) => Number(item.indicator_id) === Number(indicatorId));
                if (!found) return;
                this.openInspectorWithItem(found);
            },

            openInspectorByName(indikator, unit) {
                const found = this.allIndicators.find(
                    (item) => String(item.indikator || '').trim() === String(indikator || '').trim()
                        && (!unit || String(item.unit || '').trim() === String(unit || '').trim())
                ) || this.allIndicators.find((item) => String(item.indikator || '').trim() === String(indikator || '').trim());

                if (!found) return;
                this.openInspectorWithItem(found);
            },

            openInspectorWithItem(item) {
                this.inspectorPreviousFocus = document.activeElement;
                this.inspectedItem = item;
                this.inspectorOpen = true;
                this.$nextTick(() => this.$refs.inspectorCloseBtn?.focus());
            },

            closeInspector() {
                if (!this.inspectorOpen) return;
                this.inspectorOpen = false;
                this.$nextTick(() => this.inspectorPreviousFocus?.focus?.());
            },

            openFullscreenFromInspector() {
                const targetItem = this.inspectedItem;
                this.closeInspector();
                this.ragFilter = 'all';
                this.searchQuery = '';
                this.openFullscreen();
                if (targetItem) {
                    this.$nextTick(() => {
                        setTimeout(() => this.selectIndicator(targetItem.key, true), 180);
                    });
                }
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
                const base = this.ragFilter === 'all'
                    ? [...this.searchedIndicators]
                    : this.searchedIndicators.filter((item) => item.tier === this.ragFilter);

                if (this.fullscreenSort === 'lowest') {
                    base.sort((a, b) => (a.pencapaian ?? 9999) - (b.pencapaian ?? 9999));
                } else if (this.fullscreenSort === 'highest') {
                    base.sort((a, b) => (b.pencapaian ?? -9999) - (a.pencapaian ?? -9999));
                }
                return base;
            },

            get selectedIndicator() {
                if (!this.selectedIndicatorKey) return null;
                return this.filteredIndicators.find((item) => item.key === this.selectedIndicatorKey) || null;
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

                return {
                    total: filtered.length,
                    allBase: base.length,
                    allTotal: this.allIndicators.length,
                    tercapai,
                    hampir,
                    perlu,
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
                const el = document.documentElement;
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
                this.stopSpotlight();
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

            toggleStudioTheme() {
                this.studioTheme = this.studioTheme === 'light' ? 'dark' : 'light';
                this.scheduleChartRender();
            },

            toggleFocusMode() {
                this.focusMode = !this.focusMode;
                this.$nextTick(() => {
                    setTimeout(() => {
                        if (fullscreenChartInstance && !fullscreenChartInstance.isDisposed()) {
                            fullscreenChartInstance.resize();
                        }
                    }, 120);
                });
            },

            toggleSpotlight() {
                if (this.autoSpotlight) {
                    this.stopSpotlight();
                    return;
                }
                if (this.filteredIndicators.length === 0) {
                    return;
                }
                this.autoSpotlight = true;
                this.spotlightIndex = 0;
                this.selectIndicator(this.filteredIndicators[0].key, true);
                spotlightTimer = setInterval(() => {
                    const list = this.filteredIndicators;
                    if (!this.isFullscreen || !this.autoSpotlight || list.length === 0) {
                        this.stopSpotlight();
                        return;
                    }
                    this.spotlightIndex = (this.spotlightIndex + 1) % list.length;
                    this.selectIndicator(list[this.spotlightIndex].key, true);
                }, 6000);
            },

            stopSpotlight() {
                this.autoSpotlight = false;
                if (spotlightTimer) {
                    clearInterval(spotlightTimer);
                    spotlightTimer = null;
                }
            },

            selectIndicator(key, scrollCompanion = true) {
                this.selectedIndicatorKey = key;
                const idx = this.filteredIndicators.findIndex((item) => item.key === key);
                if (idx >= 0) {
                    this.spotlightIndex = idx;
                    if (fullscreenChartInstance && !fullscreenChartInstance.isDisposed()) {
                        fullscreenChartInstance.dispatchAction({
                            type: 'showTip',
                            seriesIndex: this.chartMode === 'comparison' ? 1 : 0,
                            dataIndex: idx,
                        });
                    }
                    if (scrollCompanion) {
                        this.$nextTick(() => {
                            const rowEl = document.querySelector('[data-studio-row-key="' + CSS.escape(String(key)) + '"]');
                            rowEl?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        });
                    }
                }
            },

            handleFullscreenKeydown(event) {
                if (!this.isFullscreen) return;
                const tag = document.activeElement?.tagName;
                const isTyping = ['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || document.activeElement?.isContentEditable;

                if (event.key === 'Escape') {
                    this.closeFullscreen();
                    return;
                }
                if (event.key === '/' && !isTyping) {
                    event.preventDefault();
                    this.$refs.fullscreenSearch?.focus();
                    return;
                }
                if (isTyping) return;

                if (event.key === '1') {
                    this.setChartMode('achievement');
                } else if (event.key === '2') {
                    this.setChartMode('comparison');
                } else if (event.key.toLowerCase() === 'p') {
                    this.toggleFocusMode();
                } else if (event.key.toLowerCase() === 'd') {
                    this.toggleStudioTheme();
                } else if (event.code === 'Space') {
                    event.preventDefault();
                    this.toggleSpotlight();
                }
            },

            setChartMode(mode) {
                this.chartMode = mode;
                this.scheduleChartRender();
            },

            setRagFilter(tier) {
                this.ragFilter = tier;
                this.scheduleChartRender();
            },

            setFullscreenSort(sortKey) {
                this.fullscreenSort = sortKey;
                this.scheduleChartRender();
            },

            resetFullscreenFilters() {
                this.ragFilter = 'all';
                this.searchQuery = '';
                this.fullscreenSort = 'default';
                this.selectedIndicatorKey = null;
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
                        fullscreenChartInstance.on('click', (params) => {
                            const clickedRow = this.filteredIndicators[params?.dataIndex];
                            if (clickedRow) {
                                this.selectIndicator(clickedRow.key, true);
                            }
                        });
                    }

                    if (!fullscreenResizeObserver) {
                        fullscreenResizeObserver = new ResizeObserver(() => {
                            if (fullscreenChartInstance && !fullscreenChartInstance.isDisposed()) {
                                fullscreenChartInstance.resize();
                            }
                        });
                        fullscreenResizeObserver.observe(canvasEl);
                    }

                    const isDark = this.studioTheme === 'dark';
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

                    const chartFont = window.PLN_PENGUSAHAAN_FONT || "'Geist', 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif";
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
                        if (tier === 'tercapai') return isDark ? '#22d3ee' : tokens.primary;
                        if (tier === 'hampir') return tokens.rag.waspada;
                        if (tier === 'perlu') return tokens.rag.kritis;
                        return tokens.targetSlate;
                    };

                    const labelTextColor = isDark ? '#e2e8f0' : '#1e293b';
                    const axisMutedColor = isDark ? '#94a3b8' : tokens.axisText;
                    const gridStrokeColor = isDark ? 'rgba(148, 163, 184, 0.14)' : tokens.gridLine;
                    const targetLineColor = isDark ? '#38bdf8' : tokens.primary;

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
                            (row.delta_display ? '<span style="color:#64748b;">Perbandingan</span><strong style="text-align:right;color:#0f172a;font-variant-numeric:tabular-nums;">' + escapeHtml(row.delta_display) + '</strong>' : ''),
                            '<span style="color:#64748b;">Status</span><span style="text-align:right;font-weight:600;color:#0f172a;"><span style="display:inline-block;width:7px;height:7px;border-radius:9999px;background:' + dotColor + ';margin-right:5px;vertical-align:middle;"></span>' + escapeHtml(row.status) + '</span>',
                            '</div>',
                            '</div>',
                        ].join('');
                    };

                    const baseYAxis = {
                        type: 'category',
                        inverse: true,
                        data: labels,
                        axisLine: { lineStyle: { color: gridStrokeColor } },
                        axisTick: { show: false },
                        axisLabel: {
                            fontFamily: chartFont,
                            fontSize: isNarrow ? 10 : 11.5,
                            lineHeight: 15,
                            color: labelTextColor,
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
                        extraCssText: 'box-shadow:0 10px 25px -5px rgba(15,23,42,0.14);border-radius:10px;',
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
                                textStyle: { fontFamily: chartFont, fontSize: 11, color: axisMutedColor },
                                data: [
                                    { name: 'Target', itemStyle: { color: isDark ? '#475569' : tokens.targetSlate } },
                                    { name: 'Realisasi', itemStyle: { color: isDark ? '#22d3ee' : tokens.primary } },
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
                                    color: axisMutedColor,
                                },
                                splitLine: { lineStyle: { color: gridStrokeColor } },
                            },
                            yAxis: baseYAxis,
                            series: [
                                {
                                    name: 'Target',
                                    type: 'bar',
                                    barMaxWidth: 9,
                                    barGap: '28%',
                                    itemStyle: { color: isDark ? '#475569' : tokens.targetSlate, borderRadius: [0, 4, 4, 0] },
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
                                        color: axisMutedColor,
                                        formatter: (p) => p?.data?.raw_display || '',
                                    },
                                },
                                {
                                    name: 'Realisasi',
                                    type: 'bar',
                                    z: 4,
                                    barMaxWidth: 9,
                                    data: rows.map((r) => {
                                        const rawPct = r.pencapaian !== null && r.pencapaian !== undefined ? Number(r.pencapaian) : 0;
                                        const isSelected = this.selectedIndicatorKey && r.key === this.selectedIndicatorKey;
                                        return {
                                            value: Number.isNaN(rawPct) ? 0 : Math.max(0, rawPct),
                                            raw_display: formatValueWithUnit(r.realisasi_display, r),
                                            itemStyle: {
                                                color: comparisonColor(r.tier),
                                                borderRadius: [0, 4, 4, 0],
                                                borderColor: isSelected ? (isDark ? '#ffffff' : '#0f172a') : 'transparent',
                                                borderWidth: isSelected ? 2 : 0,
                                            },
                                        };
                                    }),
                                    label: {
                                        show: true,
                                        position: 'right',
                                        backgroundColor: isDark ? '#0f172a' : '#ffffff',
                                        borderRadius: 4,
                                        padding: [1, 5],
                                        fontFamily: chartFont,
                                        fontSize: 10.5,
                                        fontWeight: 600,
                                        color: labelTextColor,
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
                                        z: 1,
                                        data: [
                                            {
                                                xAxis: tercapaiMin,
                                                lineStyle: { color: targetLineColor, type: 'dashed', width: 1.5 },
                                                label: {
                                                    formatter: 'Target 100%',
                                                    position: 'start',
                                                    align: 'center',
                                                    distance: 6,
                                                    fontFamily: chartFont,
                                                    fontSize: 10,
                                                    color: targetLineColor,
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
                                    color: axisMutedColor,
                                },
                                splitLine: { lineStyle: { color: gridStrokeColor } },
                            },
                            yAxis: baseYAxis,
                            series: [
                                {
                                    name: 'Capaian (%)',
                                    type: 'bar',
                                    z: 4,
                                    barMaxWidth: 16,
                                    showBackground: true,
                                    backgroundStyle: {
                                        color: isDark ? 'rgba(30, 41, 59, 0.7)' : 'rgba(241, 245, 249, 0.8)',
                                        borderRadius: [0, 6, 6, 0],
                                    },
                                    data: rows.map((r) => {
                                        const rawPct = r.pencapaian !== null && r.pencapaian !== undefined ? Number(r.pencapaian) : 0;
                                        const isSelected = this.selectedIndicatorKey && r.key === this.selectedIndicatorKey;
                                        return {
                                            value: Number.isNaN(rawPct) ? 0 : Math.max(0, rawPct),
                                            itemStyle: {
                                                color: ragColor(r.tier),
                                                borderRadius: [0, 6, 6, 0],
                                                borderColor: isSelected ? (isDark ? '#ffffff' : '#0f172a') : 'transparent',
                                                borderWidth: isSelected ? 2 : 0,
                                            },
                                        };
                                    }),
                                    label: {
                                        show: true,
                                        position: 'right',
                                        backgroundColor: isDark ? '#0f172a' : '#ffffff',
                                        borderRadius: 4,
                                        padding: [2, 5],
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
                                                color: labelTextColor,
                                            },
                                            sep: {
                                                fontFamily: chartFont,
                                                fontSize: 10,
                                                color: isDark ? '#475569' : '#cbd5e1',
                                            },
                                            ratio: {
                                                fontFamily: chartFont,
                                                fontSize: 10.5,
                                                color: axisMutedColor,
                                            },
                                        },
                                    },
                                    markLine: {
                                        silent: true,
                                        symbol: 'none',
                                        z: 1,
                                        data: [
                                            {
                                                xAxis: tercapaiMin,
                                                lineStyle: { color: targetLineColor, type: 'dashed', width: 1.5 },
                                                label: {
                                                    formatter: 'Target 100%',
                                                    position: 'start',
                                                    align: 'left',
                                                    distance: 6,
                                                    padding: [0, 0, 0, 5],
                                                    fontFamily: chartFont,
                                                    fontSize: 10,
                                                    color: targetLineColor,
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
                                                    padding: [0, 5, 0, 0],
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

            downloadChartJpg() {
                if (!fullscreenChartInstance || fullscreenChartInstance.isDisposed() || this.filteredIndicators.length === 0) {
                    return;
                }
                const dataUrl = fullscreenChartInstance.getDataURL({
                    type: 'jpeg',
                    pixelRatio: 2,
                    backgroundColor: this.studioTheme === 'dark' ? '#0f172a' : '#ffffff',
                });
                const rawUnit = String(this.payload?.unitLabel || 'semua-unit').toLowerCase();
                const unitSlug = rawUnit.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'semua-unit';
                const link = document.createElement('a');
                link.setAttribute('href', dataUrl);
                link.setAttribute('download', 'grafik-kinerja-kpi-' + unitSlug + '.jpg');
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        };
    };
}
</script>

<script type="application/json" id="kpiFullscreenChartData">
{!! json_encode($fullscreenPayload) !!}
</script>

<div
    id="kpi-detail-container"
    x-data="kpiDetailManager()"
    @open-kpi-inspector.window="openInspectorById($event.detail?.id)"
    @open-kpi-inspector-by-name.window="openInspectorByName($event.detail?.indikator, $event.detail?.unit)"
    @kpi-reset-filters.window="resetPageFilters()"
    @fullscreenchange.window="if (!document.fullscreenElement && isFullscreen) { closeFullscreen(); }"
    @keydown.window="if (isFullscreen) { handleFullscreenKeydown($event); } else if ($event.key === 'Escape' && inspectorOpen) { closeInspector(); }"
    class="mx-auto max-w-[1600px] min-w-0 max-w-full space-y-4 font-pengusahaan"
>
    {{-- Fullscreen Executive Boardroom & TV Presentation Studio --}}
    <div
        id="kpi-fullscreen-workspace"
        x-show="isFullscreen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-[0.985]"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-[0.985]"
        x-cloak
        role="dialog"
        aria-modal="true"
        aria-labelledby="kpi-fullscreen-title"
        :class="studioTheme === 'dark' ? 'bg-slate-950/95' : 'bg-slate-900/55 backdrop-blur-sm'"
        class="fixed inset-0 z-50 h-dvh w-screen p-2.5 sm:p-3.5 overflow-y-auto"
    >
        <div
            :class="studioTheme === 'dark' ? 'border-slate-800 bg-slate-950 text-slate-100' : 'border-slate-200/80 bg-slate-50/95 text-slate-900'"
            class="mx-auto max-w-[1600px] rounded-2xl border p-3 sm:p-4 shadow-2xl space-y-3 transition-colors duration-200"
        >
            {{-- Studio Command Header --}}
            <div
                :class="studioTheme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200/80 bg-white'"
                class="ibs-card rounded-2xl border px-4 py-3 shadow-sm transition-colors duration-200"
            >
                <div class="flex flex-col gap-2.5 xl:flex-row xl:items-center xl:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5 text-[11px] font-medium" :class="studioTheme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                            <span class="font-semibold uppercase tracking-[0.06em]" :class="studioTheme === 'dark' ? 'text-cyan-400' : 'text-[#0b6175]'">Executive Presentation Studio</span>
                            <span class="h-1 w-1 rounded-full bg-slate-400/60" aria-hidden="true"></span>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="studioTheme === 'dark' ? 'bg-cyan-950/80 text-cyan-300 border border-cyan-800/60' : 'bg-[#e6f4f6] text-[#0b6175]'" x-text="payload.unitLabel || 'Semua Unit'"></span>
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium tabular-nums" :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-300' : 'border-slate-200/80 bg-slate-50 text-slate-600'">
                                Periode <strong class="ml-1 font-semibold" :class="studioTheme === 'dark' ? 'text-white' : 'text-slate-900'" x-text="payload.period || 'Belum tersedia'"></strong>
                            </span>
                        </div>
                        <h2 id="kpi-fullscreen-title" class="mt-0.5 text-base sm:text-lg font-semibold tracking-tight" :class="studioTheme === 'dark' ? 'text-white' : 'text-slate-900'">
                            Visualisasi Grafik Capaian Indikator Kinerja
                        </h2>
                    </div>

                    {{-- Studio Utility Actions --}}
                    <div class="flex flex-wrap items-center gap-1.5">
                        {{-- Auto-Spotlight TV Mode --}}
                        <button
                            type="button"
                            @click="toggleSpotlight()"
                            :aria-pressed="autoSpotlight"
                            :class="autoSpotlight
                                ? 'border-emerald-500/60 bg-emerald-500/15 text-emerald-700 font-semibold'
                                : (studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-200 hover:bg-slate-700' : 'border-slate-200/80 bg-white text-slate-700 hover:bg-slate-50')"
                            class="pg-control-btn inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium cursor-pointer whitespace-nowrap"
                            title="Putar sorotan otomatis untuk tampilan TV (Space)"
                        >
                            <span class="h-2 w-2 rounded-full" :class="autoSpotlight ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400'" aria-hidden="true"></span>
                            <span x-text="autoSpotlight ? 'Hentikan Spotlight' : 'Spotlight TV'"></span>
                        </button>

                        {{-- Focus Presentation Mode --}}
                        <button
                            type="button"
                            @click="toggleFocusMode()"
                            :aria-pressed="focusMode"
                            :class="focusMode
                                ? 'border-[#16a3b8] bg-[#e6f4f6] text-[#0b6175] font-semibold'
                                : (studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-200 hover:bg-slate-700' : 'border-slate-200/80 bg-white text-slate-700 hover:bg-slate-50')"
                            class="pg-control-btn hidden xl:inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium cursor-pointer whitespace-nowrap"
                            title="Sembunyikan/tampilkan tabel pendamping (P)"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="16" rx="2"/>
                                <path d="M15 4v16" stroke-linecap="round"/>
                            </svg>
                            <span x-text="focusMode ? 'Tampilkan Daftar' : 'Fokus Grafik'"></span>
                        </button>

                        {{-- Light / Dark Studio Theme Toggle --}}
                        <button
                            type="button"
                            @click="toggleStudioTheme()"
                            :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-amber-300 hover:bg-slate-700' : 'border-slate-200/80 bg-white text-slate-700 hover:bg-slate-50'"
                            class="pg-control-btn inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium cursor-pointer whitespace-nowrap"
                            title="Ganti tema Light / Dark Studio (D)"
                        >
                            <span x-text="studioTheme === 'dark' ? 'Tema Terang' : 'Tema Gelap'"></span>
                        </button>

                        <button
                            type="button"
                            id="kpi-fullscreen-download-chart-btn"
                            @click="downloadChartJpg()"
                            :disabled="filteredIndicators.length === 0 || !!chartError"
                            class="pg-control-btn inline-flex items-center gap-1.5 rounded-lg border border-[#0b6175]/25 bg-[#e6f4f6]/80 px-2.5 py-1.5 text-xs font-semibold text-[#0b6175] hover:bg-[#e6f4f6] disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4m4-5 5 5 5-5m-5 5V3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span>Unduh JPG</span>
                        </button>

                        <button
                            type="button"
                            id="kpi-fullscreen-close-btn"
                            @click="closeFullscreen()"
                            :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-200 hover:bg-slate-700' : 'border-slate-200/80 bg-white text-slate-700 hover:bg-slate-50 hover:text-slate-900'"
                            class="pg-control-btn inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span>Tutup</span>
                            <kbd class="rounded border border-slate-200/80 bg-slate-100 px-1 py-0.5 text-[10px] font-semibold text-slate-500">ESC</kbd>
                        </button>
                    </div>
                </div>

                {{-- Integrated Control Ribbon --}}
                <div
                    :class="studioTheme === 'dark' ? 'border-slate-800' : 'border-slate-100'"
                    class="mt-2.5 pt-2.5 border-t flex flex-col gap-2 xl:flex-row xl:items-center xl:justify-between"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        {{-- Segmented Chart Mode Switcher --}}
                        <div
                            :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800' : 'border-slate-200/60 bg-slate-100/90'"
                            class="inline-flex items-center rounded-xl border p-0.5 text-xs"
                        >
                            <button
                                type="button"
                                id="kpi-fullscreen-mode-achievement"
                                @click="setChartMode('achievement')"
                                :class="chartMode === 'achievement'
                                    ? (studioTheme === 'dark' ? 'bg-slate-900 text-cyan-300 font-semibold shadow-xs' : 'bg-white text-[#0b6175] font-semibold shadow-xs ring-1 ring-slate-900/5')
                                    : (studioTheme === 'dark' ? 'text-slate-300 font-medium hover:text-white' : 'text-slate-600 font-medium hover:text-slate-900')"
                                class="pill-tab-transition rounded-lg px-2.5 py-1 cursor-pointer whitespace-nowrap"
                            >
                                Capaian (%)
                            </button>
                            <button
                                type="button"
                                id="kpi-fullscreen-mode-comparison"
                                @click="setChartMode('comparison')"
                                :class="chartMode === 'comparison'
                                    ? (studioTheme === 'dark' ? 'bg-slate-900 text-cyan-300 font-semibold shadow-xs' : 'bg-white text-[#0b6175] font-semibold shadow-xs ring-1 ring-slate-900/5')
                                    : (studioTheme === 'dark' ? 'text-slate-300 font-medium hover:text-white' : 'text-slate-600 font-medium hover:text-slate-900')"
                                class="pill-tab-transition rounded-lg px-2.5 py-1 cursor-pointer whitespace-nowrap"
                            >
                                Perbandingan Target vs Realisasi
                            </button>
                        </div>

                        {{-- RAG Status Pills (Dark Studio Adaptive) --}}
                        <div class="flex flex-wrap items-center gap-1.5 text-[11px] tabular-nums" role="group" aria-label="Filter status capaian grafik layar penuh">
                            <button
                                type="button"
                                data-fullscreen-rag="all"
                                @click="setRagFilter('all')"
                                :class="ragFilter === 'all'
                                    ? (studioTheme === 'dark' ? 'border-cyan-400 bg-cyan-950/70 text-cyan-200 font-semibold' : 'border-slate-800 bg-white text-slate-900 font-semibold ring-1 ring-slate-800/15 shadow-2xs')
                                    : (studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-300 font-medium hover:bg-slate-700' : 'border-slate-200/80 bg-white text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50')"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 transition cursor-pointer whitespace-nowrap"
                            >
                                <span class="h-1.5 w-1.5 rounded-full bg-slate-400 shrink-0"></span>
                                <span>Semua</span>
                                <span :class="studioTheme === 'dark' ? 'text-slate-400' : 'text-slate-400'" x-text="summaryStats.allBase">0</span>
                            </button>
                            <button
                                type="button"
                                data-fullscreen-rag="tercapai"
                                @click="setRagFilter('tercapai')"
                                :class="ragFilter === 'tercapai'
                                    ? (studioTheme === 'dark' ? 'border-emerald-500 bg-emerald-950/70 text-emerald-200 font-semibold' : 'border-emerald-600 bg-white text-emerald-950 font-semibold ring-1 ring-emerald-600/20 shadow-2xs')
                                    : (studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-300 font-medium hover:bg-slate-700' : 'border-slate-200/80 bg-white text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50')"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 transition cursor-pointer whitespace-nowrap"
                            >
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                                <span>Tercapai</span>
                                <span :class="studioTheme === 'dark' ? 'text-emerald-300' : 'text-emerald-700'" x-text="summaryStats.tercapai">0</span>
                            </button>
                            <button
                                type="button"
                                data-fullscreen-rag="hampir"
                                @click="setRagFilter('hampir')"
                                :class="ragFilter === 'hampir'
                                    ? (studioTheme === 'dark' ? 'border-amber-500 bg-amber-950/70 text-amber-200 font-semibold' : 'border-amber-600 bg-white text-amber-950 font-semibold ring-1 ring-amber-600/20 shadow-2xs')
                                    : (studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-300 font-medium hover:bg-slate-700' : 'border-slate-200/80 bg-white text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50')"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 transition cursor-pointer whitespace-nowrap"
                            >
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                                <span>Hampir Tercapai</span>
                                <span :class="studioTheme === 'dark' ? 'text-amber-300' : 'text-amber-700'" x-text="summaryStats.hampir">0</span>
                            </button>
                            <button
                                type="button"
                                data-fullscreen-rag="perlu"
                                @click="setRagFilter('perlu')"
                                :class="ragFilter === 'perlu'
                                    ? (studioTheme === 'dark' ? 'border-rose-500 bg-rose-950/70 text-rose-200 font-semibold' : 'border-rose-600 bg-white text-rose-950 font-semibold ring-1 ring-rose-600/20 shadow-2xs')
                                    : (studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-300 font-medium hover:bg-slate-700' : 'border-slate-200/80 bg-white text-slate-600 font-medium hover:border-slate-300 hover:bg-slate-50')"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 transition cursor-pointer whitespace-nowrap"
                            >
                                <span class="h-1.5 w-1.5 rounded-full bg-rose-500 shrink-0"></span>
                                <span>Perlu Peningkatan</span>
                                <span :class="studioTheme === 'dark' ? 'text-rose-300' : 'text-rose-700'" x-text="summaryStats.perlu">0</span>
                            </button>
                        </div>
                    </div>

                    {{-- Sort + Live Search + Filtered Count --}}
                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                        <select
                            id="kpi-fullscreen-sort"
                            x-model="fullscreenSort"
                            @change="scheduleChartRender()"
                            aria-label="Urutkan indikator grafik"
                            :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-200' : 'border-slate-200/80 bg-white text-slate-700'"
                            class="h-8 rounded-lg border px-2.5 text-xs font-medium outline-none focus:border-[#0b6175]"
                        >
                            <option value="default">Urutan Standar</option>
                            <option value="lowest">Capaian Terendah Dulu</option>
                            <option value="highest">Capaian Tertinggi Dulu</option>
                        </select>

                        <span
                            :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-300' : 'border-slate-200/80 bg-slate-50 text-slate-600'"
                            class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-medium tabular-nums whitespace-nowrap"
                            x-text="'Menampilkan ' + filteredIndicators.length + ' dari ' + allIndicators.length + ' indikator'"
                        ></span>

                        <div class="relative w-full sm:w-56 shrink-0">
                            <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="7"/>
                                <path d="m20 20-3.5-3.5" stroke-linecap="round"/>
                            </svg>
                            <input
                                id="kpi-fullscreen-search"
                                x-ref="fullscreenSearch"
                                type="search"
                                x-model="searchQuery"
                                @input="scheduleChartRender()"
                                placeholder="Cari indikator..."
                                aria-label="Cari indikator di grafik layar penuh"
                                :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-100 placeholder:text-slate-400' : 'border-slate-200/80 bg-white text-slate-800 placeholder:text-slate-400'"
                                class="w-full rounded-lg border pl-8 pr-3 py-1.5 text-xs transition focus:border-[#0b6175] focus:outline-none focus:ring-2 focus:ring-[#0b6175]/15"
                            />
                        </div>
                    </div>
                </div>
            </div>

            {{-- Live Spotlight Callout Banner (saat indikator dipilih atau auto-spotlight aktif) --}}
            <div
                x-show="selectedIndicator"
                x-cloak
                :class="studioTheme === 'dark' ? 'border-cyan-800/70 bg-slate-900 text-slate-100' : 'border-[#0b6175]/20 bg-white text-slate-900'"
                class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-4 py-2.5 text-xs shadow-xs"
            >
                <div class="flex flex-wrap items-center gap-2 min-w-0">
                    <span class="rounded-full bg-[#e6f4f6] px-2 py-0.5 text-[10.5px] font-semibold text-[#0b6175]" x-text="selectedIndicator?.unit || 'KPI'"></span>
                    <strong class="font-semibold truncate" x-text="selectedIndicator?.indikator"></strong>
                    <span class="text-slate-400" x-show="selectedIndicator?.delta_display" x-text="selectedIndicator?.delta_display"></span>
                </div>
                <div class="flex flex-wrap items-center gap-4 tabular-nums">
                    <span>Target: <strong x-text="selectedIndicator?.target_display || 'Tidak tercatat'"></strong></span>
                    <span>Realisasi: <strong x-text="selectedIndicator?.realisasi_display || 'Belum tersedia'"></strong></span>
                    <span class="rounded-full bg-[#e6f4f6] px-2.5 py-0.5 font-bold text-[#0b6175]" x-text="selectedIndicator?.pencapaian_display || 'Belum tersedia'"></span>
                    <button type="button" @click="selectedIndicatorKey = null; stopSpotlight()" class="text-[11px] font-medium text-slate-400 hover:text-slate-700 cursor-pointer">Tutup sorotan</button>
                </div>
            </div>

            {{-- Boardroom Stage Grid: Primary Chart (8 cols, expands to 12 in Focus Mode) + Companion Scorecard (4 cols) --}}
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-4 items-stretch">
                {{-- Left/Primary Visual Panel --}}
                <div
                    :class="[
                        focusMode ? 'xl:!col-span-12' : '',
                        studioTheme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200/80 bg-white'
                    ]"
                    class="ibs-card xl:col-span-8 min-w-0 max-w-full overflow-hidden rounded-2xl border p-4 sm:p-5 shadow-sm flex flex-col transition-colors duration-200"
                >
                    <div
                        :class="studioTheme === 'dark' ? 'border-slate-800' : 'border-slate-100'"
                        class="mb-2.5 flex flex-wrap items-center justify-between gap-2 border-b pb-2.5"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <h3
                                class="ibs-section-title text-sm font-semibold"
                                :class="studioTheme === 'dark' ? 'text-white' : 'text-slate-900'"
                                x-text="chartMode === 'achievement' ? 'Capaian Indikator (%) terhadap Target 100%' : 'Perbandingan Target vs Realisasi (Skala Ternormalisasi %)'"
                            ></h3>
                            <span class="rounded-full bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175] tabular-nums" x-text="filteredIndicators.length + ' indikator'"></span>
                        </div>

                        {{-- Contextual Color Key --}}
                        <div x-show="chartMode === 'achievement'" class="flex flex-wrap items-center gap-3 text-[11px]" :class="studioTheme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
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

                        <div x-show="chartMode === 'comparison'" class="flex flex-wrap items-center gap-3 text-[11px]" :class="studioTheme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
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

                    <div x-show="isChartLoading" class="flex items-center justify-center rounded-xl border border-slate-100 bg-slate-50/70" style="width: 100%; height: clamp(300px, calc(100dvh - 345px), 560px);">
                        <span class="text-xs font-medium text-slate-500">Memuat visualisasi grafik...</span>
                    </div>

                    <div x-show="!isChartLoading && chartError" class="flex flex-col items-center justify-center gap-2 rounded-xl border border-rose-200 bg-rose-50/40 p-6 text-center" style="width: 100%; height: clamp(300px, calc(100dvh - 345px), 560px);">
                        <p class="text-xs font-medium text-rose-800" x-text="chartError"></p>
                        <button
                            type="button"
                            @click="scheduleChartRender()"
                            class="rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-50 cursor-pointer"
                        >
                            Muat Ulang Grafik
                        </button>
                    </div>

                    <div x-show="!isChartLoading && !chartError && filteredIndicators.length === 0" class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-slate-50/60 p-6 text-center" style="width: 100%; height: clamp(300px, calc(100dvh - 345px), 560px);">
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
                        style="width: 100%; height: clamp(300px, calc(100dvh - 345px), 560px);"
                    ></div>

                    {{-- Keyboard Shortcuts Micro-Footer --}}
                    <div
                        :class="studioTheme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-100 text-slate-400'"
                        class="mt-2 pt-2 border-t flex flex-wrap items-center justify-between gap-2 text-[10.5px]"
                    >
                        <span>Klik batang grafik atau baris di kanan untuk menyinkronkan sorotan.</span>
                        <div class="hidden sm:flex flex-wrap items-center gap-3">
                            <span><kbd class="font-semibold">1</kbd>/<kbd class="font-semibold">2</kbd> Mode</span>
                            <span><kbd class="font-semibold">P</kbd> Fokus</span>
                            <span><kbd class="font-semibold">D</kbd> Tema</span>
                            <span><kbd class="font-semibold">Space</kbd> Spotlight</span>
                            <span><kbd class="font-semibold">/</kbd> Cari</span>
                        </div>
                    </div>
                </div>

                {{-- Right/Companion Executive Scorecard & Matrix --}}
                <div
                    x-show="!focusMode"
                    :class="studioTheme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200/80 bg-white'"
                    class="ibs-card xl:col-span-4 min-w-0 max-w-full rounded-2xl border flex flex-col shadow-sm overflow-hidden transition-colors duration-200"
                >
                    <div
                        :class="studioTheme === 'dark' ? 'border-slate-800' : 'border-slate-100'"
                        class="flex items-center justify-between gap-2 border-b px-4 py-3"
                    >
                        <h3 class="ibs-section-title text-sm font-semibold" :class="studioTheme === 'dark' ? 'text-white' : 'text-slate-900'">Ringkasan Kinerja per Indikator</h3>
                        <span
                            :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-300' : 'border-slate-200/80 bg-slate-50 text-slate-600'"
                            class="rounded-full border px-2 py-0.5 text-[11px] font-medium tabular-nums whitespace-nowrap"
                        >
                            Skor Maks 110%
                        </span>
                    </div>

                    <div class="flex-1 max-h-[clamp(310px,calc(100dvh-305px),590px)] overflow-y-auto overflow-x-hidden">
                        <table class="w-full table-fixed border-collapse text-xs">
                            <thead
                                :class="studioTheme === 'dark' ? 'border-slate-800 bg-slate-900/95 text-slate-400' : 'border-slate-200/80 bg-slate-50/95 text-slate-500'"
                                class="sticky top-0 z-10 border-b backdrop-blur-xs text-[11px] font-semibold uppercase tracking-[0.05em]"
                            >
                                <tr>
                                    <th class="w-[44%] pl-3.5 pr-1.5 py-2.5 text-left">Indikator</th>
                                    <th class="w-[18%] px-1.5 py-2.5 text-right whitespace-nowrap">Target</th>
                                    <th class="w-[19%] px-1.5 py-2.5 text-right whitespace-nowrap">Realisasi</th>
                                    <th class="w-[19%] pl-1.5 pr-3.5 py-2.5 text-right whitespace-nowrap">Capaian</th>
                                </tr>
                            </thead>
                            <tbody :class="studioTheme === 'dark' ? 'divide-slate-800' : 'divide-slate-100'" class="divide-y">
                                <template x-for="row in filteredIndicators" :key="row.key">
                                    <tr
                                        :data-studio-row-key="row.key"
                                        @click="selectIndicator(row.key, false)"
                                        :class="[
                                            selectedIndicatorKey === row.key
                                                ? (studioTheme === 'dark' ? 'bg-cyan-950/60' : 'bg-[#e6f4f6]/60')
                                                : (studioTheme === 'dark' ? 'hover:bg-slate-800/70' : 'hover:bg-slate-50/80')
                                        ]"
                                        class="transition-colors cursor-pointer"
                                    >
                                        <td class="pl-3.5 pr-1.5 py-2.5 align-top break-words">
                                            <div class="font-medium leading-snug" :class="studioTheme === 'dark' ? 'text-slate-100' : 'text-slate-800'" x-text="row.indikator"></div>
                                            <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[10.5px] text-slate-400">
                                                <span class="inline-flex items-center rounded bg-[#e6f4f6] px-1.5 py-0.5 font-semibold text-[#0b6175]" x-text="row.unit"></span>
                                                <span x-show="row.group && row.group !== 'Umum'" class="truncate max-w-[140px]" x-text="row.group"></span>
                                                <span x-show="row.indicator_hint || (row.satuan && row.satuan !== 'Tidak tercatat' && row.satuan !== '-')" x-text="row.indicator_hint || row.satuan"></span>
                                            </div>
                                            <div x-show="row.delta_display" class="mt-0.5 text-[10.5px] font-medium" :class="studioTheme === 'dark' ? 'text-cyan-400' : 'text-[#0b6175]'" x-text="row.delta_display"></div>
                                        </td>
                                        <td class="px-1.5 py-2.5 text-right align-top font-medium tabular-nums" :class="studioTheme === 'dark' ? 'text-slate-300' : 'text-slate-600'">
                                            <div x-text="row.target_display || 'Tidak tercatat'"></div>
                                            <div x-show="row.target_hint" class="mt-0.5 text-[10px] font-normal text-slate-400" x-text="row.target_hint"></div>
                                        </td>
                                        <td class="px-1.5 py-2.5 text-right align-top font-medium tabular-nums">
                                            <div
                                                :class="(row.realisasi_display && row.realisasi_display !== 'Tidak tercatat' && row.realisasi_display !== 'Belum tersedia')
                                                    ? (studioTheme === 'dark' ? 'text-slate-100' : 'text-slate-800')
                                                    : 'text-slate-400'"
                                                x-text="(row.realisasi_display && row.realisasi_display !== 'Tidak tercatat') ? row.realisasi_display : 'Belum tersedia'"
                                            ></div>
                                            <div x-show="row.realisasi_hint" class="mt-0.5 text-[10px] font-normal text-slate-400" x-text="row.realisasi_hint"></div>
                                        </td>
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
                                                <span class="font-bold" :class="studioTheme === 'dark' ? 'text-white' : 'text-slate-900'" x-text="row.pencapaian_display || 'Belum tersedia'"></span>
                                            </div>
                                            <div class="mt-1.5 h-1 w-full overflow-hidden rounded-full" :class="studioTheme === 'dark' ? 'bg-slate-800' : 'bg-slate-100'">
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

    {{-- Slide-Over Indicator Inspector Drawer (Progressive Disclosure) --}}
    <div
        id="kpi-indicator-inspector"
        x-show="inspectorOpen"
        x-cloak
        class="fixed inset-0 z-50 overflow-hidden"
        role="dialog"
        aria-modal="true"
        aria-labelledby="kpi-inspector-title"
    >
        <div
            x-show="inspectorOpen"
            x-transition:enter="transition-opacity ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition-opacity ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="closeInspector()"
            class="fixed inset-0 bg-slate-900/35 overscroll-contain"
            aria-hidden="true"
        ></div>

        <div class="fixed inset-y-0 right-0 flex w-full max-w-md pl-4 sm:max-w-lg sm:pl-10">
            <div
                x-show="inspectorOpen"
                x-transition:enter="transform transition ease-out duration-250"
                x-transition:enter-start="translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transform transition ease-in duration-200"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
                class="flex w-full flex-col border-l border-slate-200/90 bg-white shadow-2xl overscroll-contain"
            >
                <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="inline-flex items-center rounded-md bg-[#e6f4f6] px-2 py-0.5 text-[11px] font-semibold text-[#0b6175]" x-text="inspectedItem?.unit || 'KPI'"></span>
                            <span x-show="inspectedItem?.group && inspectedItem?.group !== 'Umum'" class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600" x-text="inspectedItem?.group"></span>
                        </div>
                        <h2 id="kpi-inspector-title" class="mt-1.5 text-base font-bold leading-snug text-slate-900" x-text="inspectedItem?.indikator || 'Detail Indikator'"></h2>
                    </div>

                    <button
                        type="button"
                        x-ref="inspectorCloseBtn"
                        @click="closeInspector()"
                        aria-label="Tutup panel detail indikator"
                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200/80 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-900 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto overscroll-contain px-5 py-5 space-y-5">
                    {{-- Score Headline & Bullet Bar --}}
                    <div class="rounded-2xl border border-slate-200/80 bg-slate-50/70 p-4 space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Capaian Periode Aktif</span>
                                <div class="mt-1 text-3xl font-bold tracking-tight text-slate-900 tabular-nums" x-text="inspectedItem?.pencapaian_display || 'Belum tersedia'"></div>
                            </div>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold"
                                :class="{
                                    'border-emerald-200 bg-emerald-50 text-emerald-800': inspectedItem?.tier === 'tercapai',
                                    'border-amber-200 bg-amber-50 text-amber-800': inspectedItem?.tier === 'hampir',
                                    'border-rose-200 bg-rose-50 text-rose-800': inspectedItem?.tier === 'perlu',
                                    'border-slate-200 bg-white text-slate-600': inspectedItem?.tier === 'unverified',
                                }"
                            >
                                <span
                                    class="h-2 w-2 rounded-full"
                                    :class="{
                                        'bg-emerald-500': inspectedItem?.tier === 'tercapai',
                                        'bg-amber-500': inspectedItem?.tier === 'hampir',
                                        'bg-rose-500': inspectedItem?.tier === 'perlu',
                                        'bg-slate-400': inspectedItem?.tier === 'unverified',
                                    }"
                                ></span>
                                <span x-text="inspectedItem?.status || 'Belum Terverifikasi'"></span>
                            </span>
                        </div>

                        <div class="relative pt-1">
                            <div class="relative h-2.5 w-full overflow-hidden rounded-full bg-slate-200/80">
                                <div
                                    class="h-full rounded-full transition-all duration-300"
                                    :class="{
                                        'bg-emerald-500': inspectedItem?.tier === 'tercapai',
                                        'bg-amber-500': inspectedItem?.tier === 'hampir',
                                        'bg-rose-500': inspectedItem?.tier === 'perlu',
                                        'bg-slate-400': inspectedItem?.tier === 'unverified',
                                    }"
                                    :style="'width: ' + Math.min(100, Math.max(0, ((Number(inspectedItem?.pencapaian || 0)) / 110) * 100)) + '%'"
                                ></div>
                            </div>
                            <span class="pointer-events-none absolute top-0.5 h-3.5 w-px bg-amber-500" style="left: 86.36%" aria-hidden="true"></span>
                            <span class="pointer-events-none absolute top-0 h-4 w-0.5 rounded-full bg-slate-800" style="left: 90.9%" aria-hidden="true"></span>
                        </div>
                        <div class="flex items-center justify-between text-[11px] text-slate-500 tabular-nums">
                            <span>0%</span>
                            <span>Target 100%</span>
                            <span>Maks 110%</span>
                        </div>
                    </div>

                    {{-- Target vs Realisasi Side-by-Side --}}
                    <div class="grid grid-cols-2 gap-3 tabular-nums">
                        <div class="rounded-xl border border-slate-200/80 bg-white p-3.5">
                            <span class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">Target</span>
                            <strong class="mt-1 block text-lg font-bold text-slate-800" x-text="inspectedItem?.target_display || 'Tidak tercatat'"></strong>
                            <span x-show="inspectedItem?.target_hint" class="mt-1 block text-[11px] text-slate-500" x-text="inspectedItem?.target_hint"></span>
                        </div>
                        <div class="rounded-xl border border-slate-200/80 bg-white p-3.5">
                            <span class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">Realisasi</span>
                            <strong class="mt-1 block text-lg font-bold text-slate-900" x-text="inspectedItem?.realisasi_display || 'Belum tersedia'"></strong>
                            <span x-show="inspectedItem?.realisasi_hint" class="mt-1 block text-[11px] text-slate-500" x-text="inspectedItem?.realisasi_hint"></span>
                        </div>
                    </div>

                    {{-- Metadata Details --}}
                    <dl class="divide-y divide-slate-100 rounded-xl border border-slate-200/80 bg-white text-xs">
                        <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                            <dt class="text-slate-500">Perbandingan / Deviasi</dt>
                            <dd class="font-semibold text-slate-900 text-right tabular-nums" x-text="inspectedItem?.delta_display || 'Tidak tercatat'"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                            <dt class="text-slate-500">Satuan Pengukuran</dt>
                            <dd class="font-semibold text-slate-900 text-right" x-text="inspectedItem?.satuan || 'Tidak tercatat'"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                            <dt class="text-slate-500">Arah Polaritas</dt>
                            <dd class="font-semibold text-slate-900 text-right" x-text="inspectedItem?.polaritas || 'Positif'"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 px-4 py-2.5">
                            <dt class="text-slate-500">Bobot Indikator</dt>
                            <dd class="font-semibold text-slate-900 text-right tabular-nums" x-text="inspectedItem?.bobot || 'Tidak tercatat'"></dd>
                        </div>
                    </dl>
                </div>

                <div class="border-t border-slate-100 bg-slate-50/70 px-5 py-3.5 flex items-center justify-between gap-2">
                    <button
                        type="button"
                        @click="closeInspector()"
                        class="pg-control-btn rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 cursor-pointer"
                    >
                        Tutup
                    </button>
                    <button
                        type="button"
                        @click="openFullscreenFromInspector()"
                        class="pg-control-btn inline-flex items-center gap-1.5 rounded-xl bg-[#0b6175] px-4 py-2 text-xs font-semibold text-white hover:bg-[#094e5e] cursor-pointer"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span>Sorot di Layar Penuh</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Sticky Executive Header & Unified Command Bar --}}
    <div id="kpi-detail-header" class="ibs-card lg:sticky lg:top-14 z-20 min-w-0 max-w-full bg-white/95 backdrop-blur-md px-4 py-3.5 sm:px-5">
        {{-- Tier 1: Executive Identity & Action Cluster --}}
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between min-w-0 pb-3 border-b border-slate-100">
            <div class="flex flex-wrap items-center gap-2.5 sm:gap-3 min-w-0">
                <a
                    href="{{ route('dashboard') }}"
                    class="inline-flex items-center gap-1.5 rounded-lg py-1 text-xs font-semibold text-slate-500 transition hover:text-[#0b6175] whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M19 12H5M12 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>Ringkasan</span>
                </a>
                <span class="hidden sm:block h-4 w-px bg-slate-200" aria-hidden="true"></span>
                <h1 class="text-lg sm:text-xl font-bold tracking-tight text-slate-900 sm:whitespace-nowrap">Rincian Kinerja KPI</h1>
                <span class="inline-flex items-center rounded-full border border-[#0b6175]/20 bg-[#e6f4f6] px-2.5 py-0.5 text-[11px] font-semibold text-[#0b6175] whitespace-nowrap">
                    Unit: {{ !empty(config('kpi.visible_units')) ? implode(', ', config('kpi.visible_units')) : 'Semua Unit' }}
                </span>
                @if (! empty($fullscreenPayload['period']))
                    <span class="inline-flex items-center rounded-full border border-slate-200/80 bg-slate-50 px-2.5 py-0.5 text-[11px] font-medium text-slate-600 tabular-nums whitespace-nowrap">
                        Periode: {{ $fullscreenPayload['period'] }}
                    </span>
                @endif
            </div>

            <div id="kpi-detail-actions" class="flex min-w-0 max-w-full flex-wrap items-center gap-2">
                <button
                    type="button"
                    id="kpi-dictionary-trigger"
                    @click="$dispatch('open-kpi-dictionary')"
                    class="pg-control-btn inline-flex items-center gap-1.5 rounded-xl border border-slate-200/90 bg-white px-3.5 py-1.5 text-xs font-semibold text-slate-700 hover:border-[#0b6175]/40 hover:bg-[#e6f4f6]/50 hover:text-[#0b6175] cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                >
                    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Kamus KPI</span>
                </button>
                <button
                    type="button"
                    id="kpi-detail-fullscreen-btn"
                    @click="toggleFullscreen()"
                    class="pg-control-btn inline-flex items-center gap-1.5 rounded-xl bg-[#0b6175] px-4 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-[#094e5e] cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
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
        <div
            id="kpi-command-bar"
            @keydown.window="if ($event.key === '/' && !isFullscreen && !inspectorOpen && !$event.ctrlKey && !$event.metaKey && !$event.altKey && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName) && !document.activeElement?.isContentEditable) { $event.preventDefault(); $refs.unifiedSearch?.focus(); }"
            class="mt-3 flex flex-col gap-2.5 xl:flex-row xl:items-center xl:justify-between min-w-0"
        >
            <div class="flex flex-wrap items-center gap-2 min-w-0">
                <div class="relative w-full sm:w-64 shrink-0">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"/>
                        <path d="m20 20-3.5-3.5" stroke-linecap="round"/>
                    </svg>
                    <input
                        id="kpi-unified-search"
                        x-ref="unifiedSearch"
                        type="search"
                        x-model="pageSearch"
                        @input="applyPageFilters()"
                        placeholder="Cari indikator KPI..."
                        aria-label="Cari indikator KPI"
                        class="w-full rounded-xl border border-slate-200/80 bg-slate-50/80 pl-8.5 pr-9 py-1.5 text-xs font-medium text-slate-800 placeholder:text-slate-400 transition hover:bg-slate-100/70 focus:bg-white focus:border-[#0b6175] focus:outline-none focus:ring-2 focus:ring-[#0b6175]/15"
                    />
                    <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 hidden sm:inline-flex items-center rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-400 shadow-2xs" aria-hidden="true">/</span>
                </div>

                {{-- Sort Control --}}
                <select
                    id="kpi-page-sort"
                    x-show="viewMode === 'cards' || viewMode === 'split'"
                    x-model="pageSort"
                    @change="applyPageFilters()"
                    aria-label="Urutkan kartu indikator"
                    class="h-8 rounded-xl border border-slate-200/80 bg-white px-2.5 text-xs font-medium text-slate-700 outline-none focus:border-[#0b6175]"
                >
                    <option value="default">Urutan Standar</option>
                    <option value="lowest">Prioritas (Terendah)</option>
                    <option value="highest">Capaian Tertinggi</option>
                    <option value="name">Nama (A-Z)</option>
                </select>

                {{-- Group Filter Select (hanya muncul bila ada > 1 grup) --}}
                <select
                    id="kpi-page-group"
                    x-show="availableGroups.length > 1 && viewMode !== 'table'"
                    x-cloak
                    x-model="pageGroup"
                    @change="applyPageFilters()"
                    aria-label="Filter kategori grup KPI"
                    class="h-8 max-w-[190px] rounded-xl border border-slate-200/80 bg-white px-2.5 text-xs font-medium text-slate-700 outline-none focus:border-[#0b6175]"
                >
                    <option value="all">Semua Kategori</option>
                    <template x-for="grp in availableGroups" :key="grp">
                        <option :value="grp" x-text="grp"></option>
                    </template>
                </select>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                {{-- RAG Filter Pills --}}
                <div class="flex flex-wrap items-center gap-1.5 text-xs" role="group" aria-label="Filter status capaian KPI">
                    <button
                        type="button"
                        @click="setPageRag('all')"
                        :class="pageRag === 'all' ? 'border-[#0b6175] bg-[#0b6175] text-white font-semibold shadow-2xs' : 'border-slate-200/80 bg-slate-50/80 text-slate-600 font-medium hover:border-slate-300 hover:bg-white'"
                        class="pg-control-btn inline-flex items-center gap-1.5 rounded-full border px-3 py-1 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    >
                        <span>Semua</span>
                        <span class="rounded-full px-1.5 py-0.5 text-[10.5px] tabular-nums" :class="pageRag === 'all' ? 'bg-white/20 text-white' : 'bg-slate-200/70 text-slate-600'">{{ $ragCounts['all'] }}</span>
                    </button>
                    <button
                        type="button"
                        @click="setPageRag('tercapai')"
                        :class="pageRag === 'tercapai' ? 'border-emerald-600 bg-emerald-50 text-emerald-900 font-semibold ring-1 ring-emerald-600/20 shadow-2xs' : 'border-slate-200/80 bg-slate-50/80 text-slate-600 font-medium hover:border-emerald-200 hover:bg-emerald-50/50 hover:text-emerald-800'"
                        class="pg-control-btn inline-flex items-center gap-1.5 rounded-full border px-3 py-1 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                        <span>Tercapai</span>
                        <span class="rounded-full px-1.5 py-0.5 text-[10.5px] font-semibold tabular-nums" :class="pageRag === 'tercapai' ? 'bg-emerald-200/70 text-emerald-900' : 'bg-emerald-100/70 text-emerald-700'">{{ $ragCounts['tercapai'] }}</span>
                    </button>
                    <button
                        type="button"
                        @click="setPageRag('hampir')"
                        :class="pageRag === 'hampir' ? 'border-amber-600 bg-amber-50 text-amber-900 font-semibold ring-1 ring-amber-600/20 shadow-2xs' : 'border-slate-200/80 bg-slate-50/80 text-slate-600 font-medium hover:border-amber-200 hover:bg-amber-50/50 hover:text-amber-800'"
                        class="pg-control-btn inline-flex items-center gap-1.5 rounded-full border px-3 py-1 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                        <span>Mendekati</span>
                        <span class="rounded-full px-1.5 py-0.5 text-[10.5px] font-semibold tabular-nums" :class="pageRag === 'hampir' ? 'bg-amber-200/70 text-amber-900' : 'bg-amber-100/70 text-amber-700'">{{ $ragCounts['hampir'] }}</span>
                    </button>
                    <button
                        type="button"
                        @click="setPageRag('perlu')"
                        :class="pageRag === 'perlu' ? 'border-rose-600 bg-rose-50 text-rose-900 font-semibold ring-1 ring-rose-600/20 shadow-2xs' : 'border-slate-200/80 bg-slate-50/80 text-slate-600 font-medium hover:border-rose-200 hover:bg-rose-50/50 hover:text-rose-800'"
                        class="pg-control-btn inline-flex items-center gap-1.5 rounded-full border px-3 py-1 cursor-pointer whitespace-nowrap focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8]/40"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-rose-500 shrink-0"></span>
                        <span>Perlu Perhatian</span>
                        <span class="rounded-full px-1.5 py-0.5 text-[10.5px] font-semibold tabular-nums" :class="pageRag === 'perlu' ? 'bg-rose-200/70 text-rose-900' : 'bg-rose-100/70 text-rose-700'">{{ $ragCounts['perlu'] }}</span>
                    </button>
                </div>

                {{-- View Mode Switcher (Default: Kartu Visual untuk bebas overload) --}}
                <div id="kpi-view-switcher" class="inline-flex min-w-0 max-w-full flex-wrap items-center gap-0.5 rounded-xl border border-slate-200/70 bg-slate-100/85 p-1 text-xs shrink-0">
                    <button
                        type="button"
                        @click="viewMode = 'cards'; $dispatch('kpi-view-changed', 'cards')"
                        :class="viewMode === 'cards' ? 'bg-white text-[#0b6175] shadow-xs ring-1 ring-slate-900/5 font-semibold' : 'text-slate-600 hover:text-slate-900 font-medium'"
                        class="pill-tab-transition inline-flex max-w-full items-center gap-1.5 rounded-lg px-2.5 py-1 cursor-pointer sm:whitespace-nowrap text-left"
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
                        class="pill-tab-transition inline-flex max-w-full items-center gap-1.5 rounded-lg px-2.5 py-1 cursor-pointer sm:whitespace-nowrap text-left"
                    >
                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path d="M3 6h18M3 12h18M3 18h18" stroke-linecap="round"/>
                        </svg>
                        <span>Tabel Matriks</span>
                        <span class="sr-only">Fokus Tabel Matriks Padat</span>
                    </button>
                    <button
                        type="button"
                        @click="viewMode = 'split'; $dispatch('kpi-view-changed', 'split')"
                        :class="viewMode === 'split' ? 'bg-white text-[#0b6175] shadow-xs ring-1 ring-slate-900/5 font-semibold' : 'text-slate-600 hover:text-slate-900 font-medium'"
                        class="pill-tab-transition inline-flex max-w-full items-center gap-1.5 rounded-lg px-2.5 py-1 cursor-pointer sm:whitespace-nowrap text-left"
                    >
                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <rect x="3" y="3" width="8" height="18" rx="1"/>
                            <rect x="13" y="3" width="8" height="18" rx="1"/>
                        </svg>
                        <span>Berdampingan</span>
                        <span class="sr-only">Semua Berdampingan Compact</span>
                    </button>
                </div>

                <button
                    type="button"
                    x-show="hasActivePageFilters"
                    x-cloak
                    @click="resetPageFilters()"
                    class="pg-control-btn inline-flex items-center rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 cursor-pointer"
                >
                    Reset
                </button>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12 items-stretch w-full min-w-0">
        <div
            id="kpi-target-realisasi-container"
            x-show="viewMode === 'split' || viewMode === 'cards'"
            :class="{ 'lg:!col-span-12 xl:!col-span-12': viewMode === 'cards', 'hidden': viewMode === 'table' }"
            class="h-full min-w-0 max-w-full transition-all duration-200 lg:col-span-5 xl:col-span-6 w-full animate-entrance stagger-1"
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
            :class="{ 'lg:!col-span-12 xl:!col-span-12': viewMode === 'table', 'hidden': viewMode === 'cards' }"
            class="h-full min-w-0 max-w-full transition-all duration-200 lg:col-span-7 xl:col-span-6 w-full animate-entrance stagger-2"
        >
            <x-widgets.kinerja-detail
                :widget="$kinerjaDetailWidget"
                :data="$kinerjaDetailData"
                :embedded-in-kpi-detail="true"
            />
        </div>
    </div>

    <x-kpi-dictionary-drawer />
</div>
@endsection

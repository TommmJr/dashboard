@props(['payload' => [], 'initialSection' => 'trafo'])

@php
    $allowedSections = ['trafo', 'mva', 'transmisi', 'tower'];
    $safeInitialSection = in_array($initialSection, $allowedSections, true) ? $initialSection : 'trafo';
@endphp

<script type="application/json" id="assetFullscreenStudioData">
{!! json_encode($payload) !!}
</script>

<script @cspNonce>
if (typeof window.assetFullscreenStudio !== 'function') {
    window.assetFullscreenStudio = function (initialTab) {
        let studioChartInstance = null;
        let studioResizeObserver = null;
        let renderTimer = null;
        let echartsWaitAttempts = 0;

        const validTabs = ['trafo', 'mva', 'transmisi', 'tower'];

        const parsePayload = () => {
            try {
                const raw = document.getElementById('assetFullscreenStudioData')?.textContent || 'null';
                const parsed = JSON.parse(raw);
                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (e) {
                return {};
            }
        };

        const isRecorded = (val) => val !== null && val !== undefined && val !== '' && !Number.isNaN(Number(val));

        const safeNum = (val) => (isRecorded(val) ? Number(val) : 0);

        const formatIdNumber = (val, decimals) => {
            if (!isRecorded(val)) {
                return 'Tidak tercatat';
            }
            const num = Number(val);
            if (typeof decimals === 'number') {
                return num.toLocaleString('id-ID', {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals,
                });
            }
            return num % 1 !== 0
                ? num.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
                : num.toLocaleString('id-ID');
        };

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        const CANONICAL_UPT_ORDER = ['BANDUNG', 'BEKASI', 'BOGOR', 'CIREBON', 'KARAWANG', 'PURWOKERTO', 'SALATIGA', 'SEMARANG'];

        const getPalette = (isDark) => {
            const mode = isDark ? 'dark' : 'light';
            const shared = window.PLN_ASET_PALETTE && window.PLN_ASET_PALETTE[mode];
            if (shared) {
                return {
                    primary: shared.primary,
                    secondary: shared.secondary,
                    warmAccent: shared.warmAccent,
                    targetSlate: shared.targetSlate,
                    gridLine: shared.gridLine,
                    axisText: shared.axisText,
                    labelText: shared.labelText,
                    uptSeries: shared.uptSeries,
                    voltageSeries: shared.voltageSeries,
                    pairPhysical: shared.pairPhysical,
                    pairSuSk: shared.pairSuSk,
                };
            }
            return isDark
                ? {
                    primary: '#22d3ee',
                    secondary: '#fbbf24',
                    warmAccent: '#fb923c',
                    targetSlate: '#475569',
                    gridLine: 'rgba(148, 163, 184, 0.16)',
                    axisText: '#94a3b8',
                    labelText: '#e2e8f0',
                    uptSeries: ['#22d3ee', '#fbbf24', '#34d399', '#fb7185', '#a78bfa', '#38bdf8', '#fb923c', '#94a3b8'],
                    voltageSeries: ['#22d3ee', '#fbbf24', '#a78bfa', '#34d399', '#fb7185', '#38bdf8', '#fb923c', '#94a3b8'],
                    pairPhysical: ['#22d3ee', '#fbbf24'],
                    pairSuSk: ['#22d3ee', '#fb923c'],
                }
                : {
                    primary: '#0b6175',
                    secondary: '#f59e0b',
                    warmAccent: '#ea580c',
                    targetSlate: '#cbd5e1',
                    gridLine: '#f1f5f9',
                    axisText: '#64748b',
                    labelText: '#1e293b',
                    uptSeries: ['#0b6175', '#f59e0b', '#10b981', '#e11d48', '#7c3aed', '#0284c7', '#ea580c', '#475569'],
                    voltageSeries: ['#0b6175', '#f59e0b', '#7c3aed', '#10b981', '#e11d48', '#0284c7', '#ea580c', '#475569'],
                    pairPhysical: ['#0b6175', '#f59e0b'],
                    pairSuSk: ['#0b6175', '#ea580c'],
                };
        };

        const getUptColor = (pal, uptName, fallbackIdx) => {
            const key = String(uptName ?? '').trim().toUpperCase();
            const idx = CANONICAL_UPT_ORDER.indexOf(key);
            const colorIdx = idx >= 0 ? idx : (fallbackIdx >= 0 ? fallbackIdx : 0);
            return pal.uptSeries[colorIdx % pal.uptSeries.length];
        };

        return {
            isFullscreen: false,
            activeTab: validTabs.includes(initialTab) ? initialTab : 'trafo',
            studioUpt: 'all',
            studioTheme: 'light',
            focusMode: false,
            trafoMetric: 'mva',
            trafoStackMode: 'stacked',
            mvaSort: 'desc',
            transmisiView: 'upt',
            towerView: 'voltage',
            isChartLoading: false,
            chartError: '',
            exportError: '',
            isExporting: false,
            payload: parsePayload(),

            init() {
                const params = new URLSearchParams(window.location.search);
                const tabParam = params.get('tab');
                if (tabParam && validTabs.includes(tabParam)) {
                    this.activeTab = tabParam;
                }
                const fsParam = params.get('fullscreen');
                if (fsParam === '1' || fsParam === 'true') {
                    this.openStudio({ tab: this.activeTab, requestNative: false });
                }

                document.addEventListener('fullscreenchange', () => {
                    if (!document.fullscreenElement && this.isFullscreen && this.nativeFullscreenActive) {
                        this.nativeFullscreenActive = false;
                        this.closeStudio(false);
                    }
                });
            },

            nativeFullscreenActive: false,

            openStudio(options) {
                const opts = options && typeof options === 'object' ? options : {};
                if (opts.tab && validTabs.includes(opts.tab)) {
                    this.activeTab = opts.tab;
                }
                this.isFullscreen = true;
                this.chartError = '';
                this.exportError = '';

                const shouldRequestNative = opts.requestNative !== false;
                if (shouldRequestNative) {
                    const el = document.documentElement;
                    if (el.requestFullscreen) {
                        el.requestFullscreen()
                            .then(() => {
                                this.nativeFullscreenActive = true;
                            })
                            .catch(() => {
                                this.nativeFullscreenActive = false;
                            });
                    } else if (el.webkitRequestFullscreen) {
                        try {
                            el.webkitRequestFullscreen();
                            this.nativeFullscreenActive = true;
                        } catch (e) {
                            this.nativeFullscreenActive = false;
                        }
                    }
                }

                this.scheduleRender();
            },

            closeStudio(exitNative) {
                if (renderTimer) {
                    clearTimeout(renderTimer);
                    renderTimer = null;
                }
                this.nativeFullscreenActive = false;
                if (exitNative !== false && document.fullscreenElement) {
                    if (document.exitFullscreen) {
                        document.exitFullscreen().catch(() => {});
                    } else if (document.webkitExitFullscreen) {
                        try {
                            document.webkitExitFullscreen();
                        } catch (e) {
                            // Abaikan jika browser sudah keluar fullscreen
                        }
                    }
                }
                this.isFullscreen = false;
                this.disposeChart();
            },

            setActiveTab(tabKey) {
                if (!validTabs.includes(tabKey)) {
                    return;
                }
                this.activeTab = tabKey;
                this.exportError = '';
                this.$nextTick(() => {
                    const tabBtn = document.getElementById('asset-studio-tab-' + tabKey);
                    tabBtn?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                    if (this.$refs.companionScroll) {
                        this.$refs.companionScroll.scrollTop = 0;
                    }
                });
                this.scheduleRender();
            },

            handleTabKeydown(event, currentIdx) {
                let nextIdx = currentIdx;
                if (event.key === 'ArrowRight') {
                    nextIdx = (currentIdx + 1) % validTabs.length;
                } else if (event.key === 'ArrowLeft') {
                    nextIdx = (currentIdx - 1 + validTabs.length) % validTabs.length;
                } else if (event.key === 'Home') {
                    nextIdx = 0;
                } else if (event.key === 'End') {
                    nextIdx = validTabs.length - 1;
                } else {
                    return;
                }
                event.preventDefault();
                const targetTab = validTabs[nextIdx];
                this.setActiveTab(targetTab);
                this.$nextTick(() => {
                    document.getElementById('asset-studio-tab-' + targetTab)?.focus();
                });
            },

            setStudioUpt(upt) {
                this.studioUpt = upt ? String(upt) : 'all';
                this.exportError = '';
                this.$nextTick(() => {
                    if (this.$refs.companionScroll) {
                        this.$refs.companionScroll.scrollTop = 0;
                    }
                });
                this.scheduleRender();
            },

            toggleStudioTheme() {
                this.studioTheme = this.studioTheme === 'light' ? 'dark' : 'light';
                this.scheduleRender();
            },

            toggleFocusMode() {
                this.focusMode = !this.focusMode;
                this.$nextTick(() => {
                    setTimeout(() => {
                        if (studioChartInstance && !studioChartInstance.isDisposed()) {
                            studioChartInstance.resize();
                        }
                    }, 120);
                });
            },

            resetStudioFilters() {
                this.studioUpt = 'all';
                this.trafoMetric = 'mva';
                this.trafoStackMode = 'stacked';
                this.mvaSort = 'desc';
                this.transmisiView = 'upt';
                this.towerView = 'voltage';
                this.exportError = '';
                this.scheduleRender();
            },

            handleStudioKeydown(event) {
                if (!this.isFullscreen) {
                    return;
                }
                const tag = document.activeElement?.tagName;
                const isTyping = ['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || document.activeElement?.isContentEditable;

                if (event.key === 'Escape') {
                    event.preventDefault();
                    this.closeStudio(true);
                    return;
                }
                if (isTyping || event.ctrlKey || event.metaKey || event.altKey) {
                    return;
                }

                if (event.key === '1') {
                    this.setActiveTab('trafo');
                } else if (event.key === '2') {
                    this.setActiveTab('mva');
                } else if (event.key === '3') {
                    this.setActiveTab('transmisi');
                } else if (event.key === '4') {
                    this.setActiveTab('tower');
                } else if (event.key.toLowerCase() === 'p') {
                    this.toggleFocusMode();
                } else if (event.key.toLowerCase() === 'd') {
                    this.toggleStudioTheme();
                }
            },

            get canonicalUpts() {
                return Array.isArray(this.payload?.canonical_upts) ? this.payload.canonical_upts : [];
            },

            get ownership() {
                return this.payload?.ownership || {
                    parent_code: 'UIT JBT',
                    parent_name: 'Unit Induk Transmisi Jawa Bagian Tengah (UIT JBT)',
                    parent_source: 'Rekap Induk UIT (Tab 1a)',
                    distribution_scope: 'Distribusi Wilayah 8 UPT Kanonis (Tab 1b)',
                    has_uit_totals: false,
                };
            },

            get summaryTotals() {
                return this.payload?.summary_totals || {};
            },

            get activeTabMeta() {
                const map = {
                    trafo: {
                        title: 'Bedah Trafo & Tegangan',
                        unit: this.trafoMetric === 'physical' ? 'Bank / Unit' : 'MVA',
                        indukLabel: this.trafoMetric === 'physical'
                            ? formatIdNumber(this.summaryTotals.total_trafo_bank, 0) + ' Bank / ' + formatIdNumber(this.summaryTotals.total_trafo_unit, 0) + ' Unit'
                            : formatIdNumber(this.summaryTotals.total_trafo_mva, 1) + ' MVA',
                    },
                    mva: {
                        title: 'Distribusi Daya (MVA)',
                        unit: 'MVA',
                        indukLabel: formatIdNumber(this.summaryTotals.total_trafo_mva, 1) + ' MVA',
                    },
                    transmisi: {
                        title: 'Jaringan Transmisi (kms)',
                        unit: 'kms',
                        indukLabel: formatIdNumber(this.summaryTotals.total_transmisi, 1) + ' kms',
                    },
                    tower: {
                        title: 'Sebaran Tower (Unit)',
                        unit: 'Unit',
                        indukLabel: formatIdNumber(this.summaryTotals.total_tower, 0) + ' Unit',
                    },
                };
                return map[this.activeTab] || map.trafo;
            },

            get chartHeading() {
                if (this.studioUpt !== 'all') {
                    if (this.activeTab === 'trafo') {
                        return this.trafoMetric === 'physical'
                            ? 'Fisik Trafo per Kelas Tegangan: UPT ' + this.studioUpt
                            : 'Kapasitas Daya Trafo per Kelas Tegangan: UPT ' + this.studioUpt;
                    }
                    if (this.activeTab === 'mva') {
                        return 'Sebaran Kapasitas Daya (MVA) per ULTG: UPT ' + this.studioUpt;
                    }
                    if (this.activeTab === 'transmisi') {
                        return 'Jaringan Transmisi SU vs SK per Tegangan: UPT ' + this.studioUpt;
                    }
                    return 'Sebaran Tower SU vs SK per Tegangan: UPT ' + this.studioUpt;
                }

                if (this.activeTab === 'trafo') {
                    return this.trafoMetric === 'physical'
                        ? 'Perbandingan Fisik Trafo (Bank & Unit) Lintas 8 UPT'
                        : 'Kapasitas Trafo (MVA) per Kelas Tegangan Lintas 8 UPT';
                }
                if (this.activeTab === 'mva') {
                    return 'Peringkat Kapasitas Daya (MVA) 8 UPT terhadap Induk UIT JBT';
                }
                if (this.activeTab === 'transmisi') {
                    return this.transmisiView === 'voltage'
                        ? 'Komposisi Saluran Udara (SU) vs Kabel (SK) per Kelas Tegangan'
                        : 'Panjang Jaringan Transmisi SU vs SK (kms) Lintas 8 UPT';
                }
                return this.towerView === 'susk'
                    ? 'Sebaran Tower Saluran Udara (SU) vs Kabel (SK) Lintas 8 UPT'
                    : 'Sebaran Tower per Kelas Tegangan (Unit) Lintas 8 UPT';
            },

            get selectedUptShareBadge() {
                if (this.studioUpt === 'all') {
                    return '';
                }
                let uptVal = null;
                let parentVal = null;
                let unitText = '';
                let decimals = 1;

                if (this.activeTab === 'trafo' || this.activeTab === 'mva') {
                    uptVal = this.payload?.mva_by_upt?.[this.studioUpt] ?? null;
                    parentVal = this.summaryTotals?.total_trafo_mva ?? null;
                    unitText = 'MVA';
                    decimals = 1;
                } else if (this.activeTab === 'transmisi') {
                    uptVal = this.payload?.transmisi_by_upt?.[this.studioUpt]?.total_kms ?? null;
                    parentVal = this.summaryTotals?.total_transmisi ?? null;
                    unitText = 'kms';
                    decimals = 1;
                } else {
                    uptVal = this.payload?.tower_by_upt?.[this.studioUpt] ?? null;
                    parentVal = this.summaryTotals?.total_tower ?? null;
                    unitText = 'Unit';
                    decimals = 0;
                }

                if (!isRecorded(uptVal)) {
                    return 'UPT ' + this.studioUpt + ': Tidak tercatat';
                }
                const pctText = isRecorded(parentVal) && Number(parentVal) > 0
                    ? ' (' + ((Number(uptVal) / Number(parentVal)) * 100).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '% dari Induk UIT JBT)'
                    : '';
                return 'UPT ' + this.studioUpt + ': ' + formatIdNumber(uptVal, decimals) + ' ' + unitText + pctText;
            },

            get hasActiveFilters() {
                return this.studioUpt !== 'all';
            },

            get allUptRankingRows() {
                const upts = this.canonicalUpts;
                const parentMva = this.summaryTotals?.total_trafo_mva ?? null;
                const parentTransmisi = this.summaryTotals?.total_transmisi ?? null;
                const parentTower = this.summaryTotals?.total_tower ?? null;

                const rows = upts.map((upt) => {
                    if (this.activeTab === 'trafo') {
                        const mva = this.payload?.mva_by_upt?.[upt] ?? null;
                        const bank = this.payload?.bank_by_upt?.[upt] ?? null;
                        const unit = this.payload?.unit_by_upt?.[upt] ?? null;
                        const pct = isRecorded(mva) && isRecorded(parentMva) && Number(parentMva) > 0
                            ? (Number(mva) / Number(parentMva)) * 100
                            : null;
                        return {
                            upt,
                            primaryValue: mva,
                            primaryFormatted: formatIdNumber(mva, 1) + (isRecorded(mva) ? ' MVA' : ''),
                            secondaryText: (isRecorded(bank) ? formatIdNumber(bank, 0) + ' Bank' : 'Bank tidak tercatat')
                                + ' | '
                                + (isRecorded(unit) ? formatIdNumber(unit, 0) + ' Unit' : 'Unit tidak tercatat'),
                            pct,
                            sortValue: isRecorded(mva) ? Number(mva) : -1,
                        };
                    }

                    if (this.activeTab === 'mva') {
                        const mva = this.payload?.mva_by_upt?.[upt] ?? null;
                        const ratio = this.payload?.capacity_ratios?.trafo?.by_upt?.[upt]?.avg_mva_per_unit ?? null;
                        const pct = isRecorded(mva) && isRecorded(parentMva) && Number(parentMva) > 0
                            ? (Number(mva) / Number(parentMva)) * 100
                            : null;
                        return {
                            upt,
                            primaryValue: mva,
                            primaryFormatted: formatIdNumber(mva, 1) + (isRecorded(mva) ? ' MVA' : ''),
                            secondaryText: isRecorded(ratio) ? 'Rata-rata ' + formatIdNumber(ratio, 1) + ' MVA/Unit' : 'Rasio tidak tercatat',
                            pct,
                            sortValue: isRecorded(mva) ? Number(mva) : -1,
                        };
                    }

                    if (this.activeTab === 'transmisi') {
                        const item = this.payload?.transmisi_by_upt?.[upt] || {};
                        const tot = item.total_kms ?? null;
                        const su = item.su_kms ?? null;
                        const sk = item.sk_kms ?? null;
                        const pct = isRecorded(tot) && isRecorded(parentTransmisi) && Number(parentTransmisi) > 0
                            ? (Number(tot) / Number(parentTransmisi)) * 100
                            : null;
                        return {
                            upt,
                            primaryValue: tot,
                            primaryFormatted: formatIdNumber(tot, 1) + (isRecorded(tot) ? ' kms' : ''),
                            secondaryText: 'SU ' + formatIdNumber(su, 1) + ' | SK ' + formatIdNumber(sk, 1),
                            pct,
                            sortValue: isRecorded(tot) ? Number(tot) : -1,
                        };
                    }

                    const tower = this.payload?.tower_by_upt?.[upt] ?? null;
                    const pct = isRecorded(tower) && isRecorded(parentTower) && Number(parentTower) > 0
                        ? (Number(tower) / Number(parentTower)) * 100
                        : null;
                    return {
                        upt,
                        primaryValue: tower,
                        primaryFormatted: formatIdNumber(tower, 0) + (isRecorded(tower) ? ' Unit' : ''),
                        secondaryText: 'Tower 500/150/70 kV',
                        pct,
                        sortValue: isRecorded(tower) ? Number(tower) : -1,
                    };
                });

                if (this.activeTab === 'mva' && this.mvaSort === 'alpha') {
                    return rows.sort((a, b) => a.upt.localeCompare(b.upt));
                }
                return rows.sort((a, b) => b.sortValue - a.sortValue);
            },

            get selectedUptUltgRows() {
                if (this.studioUpt === 'all') {
                    return [];
                }
                const list = Array.isArray(this.payload?.ultg_by_upt?.[this.studioUpt])
                    ? this.payload.ultg_by_upt[this.studioUpt]
                    : [];

                return list.map((row) => {
                    if (this.activeTab === 'trafo' || this.activeTab === 'mva') {
                        const val = row.trafo_mva ?? null;
                        return {
                            ultg: row.ultg,
                            primaryText: isRecorded(val) ? formatIdNumber(val, 1) + ' MVA' : 'Tidak tercatat',
                            secondaryText: (isRecorded(row.trafo_bank) ? formatIdNumber(row.trafo_bank, 0) + ' Bank' : 'Bank tidak tercatat')
                                + ' | '
                                + (isRecorded(row.trafo_unit) ? formatIdNumber(row.trafo_unit, 0) + ' Unit' : 'Unit tidak tercatat'),
                            recorded: isRecorded(val),
                            sortValue: isRecorded(val) ? Number(val) : -1,
                        };
                    }
                    if (this.activeTab === 'transmisi') {
                        const val = row.transmisi ?? null;
                        return {
                            ultg: row.ultg,
                            primaryText: isRecorded(val) ? formatIdNumber(val, 1) + ' kms' : 'Tidak tercatat',
                            secondaryText: 'Tower ' + formatIdNumber(row.tower, 0) + (isRecorded(row.tower) ? ' Unit' : ''),
                            recorded: isRecorded(val),
                            sortValue: isRecorded(val) ? Number(val) : -1,
                        };
                    }
                    const val = row.tower ?? null;
                    return {
                        ultg: row.ultg,
                        primaryText: isRecorded(val) ? formatIdNumber(val, 0) + ' Unit' : 'Tidak tercatat',
                        secondaryText: 'Transmisi ' + formatIdNumber(row.transmisi, 1) + (isRecorded(row.transmisi) ? ' kms' : ''),
                        recorded: isRecorded(val),
                        sortValue: isRecorded(val) ? Number(val) : -1,
                    };
                }).sort((a, b) => b.sortValue - a.sortValue);
            },

            get hasChartData() {
                return this.canonicalUpts.length > 0 && (
                    (Array.isArray(this.payload?.trafo_groups) && this.payload.trafo_groups.length > 0)
                    || (Array.isArray(this.payload?.transmisi_groups) && this.payload.transmisi_groups.length > 0)
                    || (Array.isArray(this.payload?.tower_groups) && this.payload.tower_groups.length > 0)
                );
            },

            formatNum(val, decimals) {
                return formatIdNumber(val, decimals);
            },

            formatPct(val) {
                if (!isRecorded(val)) {
                    return 'Tidak tercatat';
                }
                return Number(val).toLocaleString('id-ID', {
                    minimumFractionDigits: 1,
                    maximumFractionDigits: 1,
                }) + '%';
            },

            scheduleRender() {
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
                        this.renderStudioChart();
                    }, 80);
                });
            },

            disposeChart() {
                if (studioResizeObserver) {
                    studioResizeObserver.disconnect();
                    studioResizeObserver = null;
                }
                if (studioChartInstance) {
                    if (!studioChartInstance.isDisposed()) {
                        studioChartInstance.dispose();
                    }
                    studioChartInstance = null;
                }
            },

            buildChartOption(containerWidth) {
                const isDark = this.studioTheme === 'dark';
                const pal = getPalette(isDark);
                const isNarrow = containerWidth < 640;
                const isHorizontalMobile = containerWidth < 460;
                const chartFont = window.PLN_ASET_FONT || "'Geist', 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif";

                const visibleUpts = this.studioUpt === 'all' ? this.canonicalUpts : [this.studioUpt];

                const tooltipBase = {
                    trigger: 'axis',
                    axisPointer: { type: 'shadow' },
                    backgroundColor: isDark ? '#1e293b' : '#ffffff',
                    borderColor: isDark ? '#334155' : '#e2e8f0',
                    borderWidth: 1,
                    padding: [10, 12],
                    textStyle: { fontFamily: chartFont, color: isDark ? '#e2e8f0' : '#334155', fontSize: 12 },
                    extraCssText: 'box-shadow:0 10px 25px -5px rgba(15,23,42,0.18);border-radius:10px;',
                };

                const xAxisCategory = (categories) => ({
                    type: 'category',
                    data: categories,
                    axisLine: { lineStyle: { color: pal.targetSlate } },
                    axisTick: { show: false },
                    axisLabel: {
                        interval: 0,
                        hideOverlap: false,
                        fontFamily: chartFont,
                        color: pal.labelText,
                        fontSize: isNarrow ? 10 : 11,
                        fontWeight: 600,
                        margin: 10,
                        rotate: isHorizontalMobile ? 0 : (containerWidth < 760 && categories.length > 5 ? 26 : 0),
                    },
                });

                const yAxisValue = (unitLabel) => ({
                    type: 'value',
                    name: isHorizontalMobile ? '' : unitLabel,
                    nameTextStyle: { fontFamily: chartFont, color: pal.axisText, fontSize: 11, padding: [0, 0, 6, 0] },
                    axisLabel: {
                        show: !isHorizontalMobile,
                        fontFamily: chartFont,
                        color: pal.axisText,
                        fontSize: 11,
                        formatter: (val) => Number(val).toLocaleString('id-ID'),
                    },
                    splitLine: { lineStyle: { color: pal.gridLine, type: 'dashed' } },
                });

                const buildAxisTooltip = (titlePrefix, unitSuffix, decimals, showTotal) => (params) => {
                    if (!Array.isArray(params) || params.length === 0) {
                        return '';
                    }
                    const head = escapeHtml((titlePrefix ? titlePrefix + ' ' : '') + params[0].name);
                    let html = '<div style="font-weight:700;margin-bottom:6px;padding-bottom:4px;border-bottom:1px solid '
                        + (isDark ? '#334155' : '#f1f5f9') + ';color:' + (isDark ? '#f8fafc' : '#0f172a') + ';">'
                        + head + '</div>';
                    let sumTotal = 0;
                    let anyRecorded = false;
                    params.forEach((item) => {
                        const recorded = item.data && item.data.isRecorded !== false && isRecorded(item.value);
                        if (recorded) {
                            anyRecorded = true;
                            sumTotal += Number(item.value);
                        }
                        const valText = recorded
                            ? formatIdNumber(item.value, decimals) + (unitSuffix ? ' ' + unitSuffix : '')
                            : 'Tidak tercatat';
                        html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding:2px 0;font-variant-numeric:tabular-nums;">'
                            + '<span style="display:inline-flex;align-items:center;gap:6px;color:' + (isDark ? '#cbd5e1' : '#475569') + ';">'
                            + item.marker + escapeHtml(item.seriesName) + '</span>'
                            + '<strong style="color:' + (recorded ? (isDark ? '#f8fafc' : '#0f172a') : '#94a3b8') + ';">'
                            + escapeHtml(valText) + '</strong></div>';
                    });
                    if (showTotal && anyRecorded && params.length > 1) {
                        html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:14px;margin-top:5px;padding-top:5px;border-top:1px solid '
                            + (isDark ? '#334155' : '#f1f5f9') + ';font-variant-numeric:tabular-nums;">'
                            + '<span style="font-weight:600;color:' + (isDark ? '#cbd5e1' : '#334155') + ';">Total</span>'
                            + '<strong style="color:' + pal.primary + ';">' + escapeHtml(formatIdNumber(sumTotal, decimals) + (unitSuffix ? ' ' + unitSuffix : '')) + '</strong></div>';
                    }
                    return html;
                };

                // TAB 1: BEDAH TRAFO & TEGANGAN
                if (this.activeTab === 'trafo') {
                    const groups = Array.isArray(this.payload?.trafo_groups) ? this.payload.trafo_groups : [];

                    if (this.studioUpt !== 'all') {
                        const upt = this.studioUpt;
                        const activeGroups = groups.filter((g) => {
                            const cell = g.by_upt?.[upt] || {};
                            return isRecorded(cell.mva) || isRecorded(cell.bank) || isRecorded(cell.unit);
                        });
                        const targetGroups = activeGroups.length > 0 ? activeGroups : groups;
                        const labels = targetGroups.map((g) => g.voltage || g.label);

                        if (this.trafoMetric === 'physical') {
                            return {
                                animation: true,
                                animationDuration: 420,
                                textStyle: { fontFamily: chartFont },
                                tooltip: Object.assign({}, tooltipBase, { formatter: buildAxisTooltip('Tegangan', '', 0, false) }),
                                legend: { top: 4, textStyle: { fontFamily: chartFont, fontSize: 11, color: pal.axisText } },
                                grid: { top: 44, bottom: isNarrow ? 44 : 32, left: 16, right: 16, containLabel: true },
                                xAxis: xAxisCategory(labels),
                                yAxis: yAxisValue('Jumlah Fisik'),
                                series: [
                                    {
                                        name: 'Bank Fisik',
                                        type: 'bar',
                                        barMaxWidth: 28,
                                        itemStyle: { color: pal.pairPhysical[0], borderRadius: [6, 6, 0, 0] },
                                        data: targetGroups.map((g) => {
                                            const raw = g.by_upt?.[upt]?.bank;
                                            return { value: safeNum(raw), isRecorded: isRecorded(raw) };
                                        }),
                                    },
                                    {
                                        name: 'Unit / Set Fisik',
                                        type: 'bar',
                                        barMaxWidth: 28,
                                        itemStyle: { color: pal.pairPhysical[1], borderRadius: [6, 6, 0, 0] },
                                        data: targetGroups.map((g) => {
                                            const raw = g.by_upt?.[upt]?.unit;
                                            return { value: safeNum(raw), isRecorded: isRecorded(raw) };
                                        }),
                                    },
                                ],
                            };
                        }

                        return {
                            animation: true,
                            animationDuration: 420,
                            textStyle: { fontFamily: chartFont },
                            tooltip: Object.assign({}, tooltipBase, { formatter: buildAxisTooltip('Tegangan', 'MVA', 1, false) }),
                            grid: { top: 36, bottom: isNarrow ? 44 : 32, left: 16, right: 16, containLabel: true },
                            xAxis: xAxisCategory(labels),
                            yAxis: yAxisValue('MVA'),
                            series: [
                                {
                                    name: 'Kapasitas Trafo (MVA)',
                                    type: 'bar',
                                    barMaxWidth: 38,
                                    label: {
                                        show: true,
                                        position: 'top',
                                        fontFamily: chartFont,
                                        fontSize: 10.5,
                                        fontWeight: 600,
                                        color: pal.labelText,
                                        formatter: (p) => (p.data?.isRecorded ? formatIdNumber(p.value, 1) : ''),
                                    },
                                    data: targetGroups.map((g, idx) => {
                                        const raw = g.by_upt?.[upt]?.mva;
                                        return {
                                            value: safeNum(raw),
                                            isRecorded: isRecorded(raw),
                                            itemStyle: {
                                                color: pal.voltageSeries[idx % pal.voltageSeries.length],
                                                borderRadius: [6, 6, 0, 0],
                                            },
                                        };
                                    }),
                                },
                            ],
                        };
                    }

                    const orderedUpts = isHorizontalMobile ? [...visibleUpts].reverse() : visibleUpts;

                    if (this.trafoMetric === 'physical') {
                        const catAxis = xAxisCategory(orderedUpts);
                        const valAxis = yAxisValue('Jumlah Fisik');
                        const radius = isHorizontalMobile ? [0, 5, 5, 0] : [5, 5, 0, 0];
                        return {
                            animation: true,
                            animationDuration: 420,
                            textStyle: { fontFamily: chartFont },
                            tooltip: Object.assign({}, tooltipBase, { formatter: buildAxisTooltip('UPT', '', 0, false) }),
                            legend: { top: 4, textStyle: { fontFamily: chartFont, fontSize: 11, color: pal.axisText } },
                            grid: isHorizontalMobile
                                ? { top: 38, bottom: 16, left: 8, right: 20, containLabel: true }
                                : { top: 44, bottom: 26, left: 16, right: 16, containLabel: true },
                            xAxis: isHorizontalMobile ? valAxis : catAxis,
                            yAxis: isHorizontalMobile ? catAxis : valAxis,
                            series: [
                                {
                                    name: 'Bank Fisik',
                                    type: 'bar',
                                    barMaxWidth: isHorizontalMobile ? 12 : 24,
                                    itemStyle: { color: pal.pairPhysical[0], borderRadius: radius },
                                    data: orderedUpts.map((u) => {
                                        const raw = this.payload?.bank_by_upt?.[u];
                                        return { value: safeNum(raw), isRecorded: isRecorded(raw) };
                                    }),
                                },
                                {
                                    name: 'Unit / Set Fisik',
                                    type: 'bar',
                                    barMaxWidth: isHorizontalMobile ? 12 : 24,
                                    itemStyle: { color: pal.pairPhysical[1], borderRadius: radius },
                                    data: orderedUpts.map((u) => {
                                        const raw = this.payload?.unit_by_upt?.[u];
                                        return { value: safeNum(raw), isRecorded: isRecorded(raw) };
                                    }),
                                },
                            ],
                        };
                    }

                    const isStacked = this.trafoStackMode === 'stacked' || isHorizontalMobile;
                    const catAxis = xAxisCategory(orderedUpts);
                    const valAxis = yAxisValue('MVA');
                    return {
                        animation: true,
                        animationDuration: 420,
                        textStyle: { fontFamily: chartFont },
                        tooltip: Object.assign({}, tooltipBase, { formatter: buildAxisTooltip('UPT', 'MVA', 1, isStacked) }),
                        legend: {
                            type: 'scroll',
                            top: 2,
                            textStyle: { fontFamily: chartFont, fontSize: 11, color: pal.axisText },
                        },
                        grid: isHorizontalMobile
                            ? { top: 42, bottom: 16, left: 8, right: 20, containLabel: true }
                            : { top: 48, bottom: 26, left: 16, right: 16, containLabel: true },
                        xAxis: isHorizontalMobile ? valAxis : catAxis,
                        yAxis: isHorizontalMobile ? catAxis : valAxis,
                        series: groups.map((g, idx) => ({
                            name: g.voltage || g.label,
                            type: 'bar',
                            stack: isStacked ? 'trafo_mva' : undefined,
                            barMaxWidth: isHorizontalMobile ? 18 : (isStacked ? 36 : 16),
                            itemStyle: {
                                color: pal.voltageSeries[idx % pal.voltageSeries.length],
                                borderRadius: isStacked && idx < groups.length - 1
                                    ? [0, 0, 0, 0]
                                    : (isHorizontalMobile ? [0, 5, 5, 0] : [5, 5, 0, 0]),
                            },
                            data: orderedUpts.map((u) => {
                                const raw = g.by_upt?.[u]?.mva;
                                return { value: safeNum(raw), isRecorded: isRecorded(raw) };
                            }),
                        })),
                    };
                }

                // TAB 2: DISTRIBUSI DAYA (MVA)
                if (this.activeTab === 'mva') {
                    if (this.studioUpt !== 'all') {
                        const ultgList = Array.isArray(this.payload?.ultg_by_upt?.[this.studioUpt])
                            ? [...this.payload.ultg_by_upt[this.studioUpt]]
                            : [];
                        if (this.mvaSort === 'alpha') {
                            ultgList.sort((a, b) => String(a.ultg || '').localeCompare(String(b.ultg || '')));
                        } else {
                            ultgList.sort((a, b) => safeNum(b.trafo_mva) - safeNum(a.trafo_mva));
                        }
                        const uptTotalMva = this.payload?.mva_by_upt?.[this.studioUpt] ?? null;

                        return {
                            animation: true,
                            animationDuration: 420,
                            textStyle: { fontFamily: chartFont },
                            tooltip: Object.assign({}, tooltipBase, { formatter: buildAxisTooltip('ULTG', 'MVA', 1, false) }),
                            grid: { top: 18, bottom: 22, left: 14, right: isNarrow ? 75 : 135, containLabel: true },
                            xAxis: yAxisValue('MVA'),
                            yAxis: {
                                type: 'category',
                                inverse: true,
                                data: ultgList.map((u) => u.ultg),
                                axisLine: { lineStyle: { color: pal.targetSlate } },
                                axisTick: { show: false },
                                axisLabel: { interval: 0, fontFamily: chartFont, color: pal.labelText, fontSize: 11, fontWeight: 600 },
                            },
                            series: [
                                {
                                    name: 'Kapasitas ULTG (MVA)',
                                    type: 'bar',
                                    barMaxWidth: 24,
                                    data: ultgList.map((u, idx) => {
                                        const val = safeNum(u.trafo_mva);
                                        const recorded = isRecorded(u.trafo_mva);
                                        return {
                                            value: val,
                                            isRecorded: recorded,
                                            itemStyle: {
                                                color: recorded ? pal.uptSeries[idx % pal.uptSeries.length] : '#e2e8f0',
                                                borderRadius: [0, 6, 6, 0],
                                            },
                                        };
                                    }),
                                    label: {
                                        show: true,
                                        position: 'right',
                                        fontFamily: chartFont,
                                        fontSize: 10.5,
                                        fontWeight: 600,
                                        color: pal.labelText,
                                        formatter: (p) => {
                                            if (!p.data?.isRecorded) {
                                                return 'Tidak tercatat';
                                            }
                                            const pct = isRecorded(uptTotalMva) && Number(uptTotalMva) > 0
                                                ? ' (' + ((Number(p.value) / Number(uptTotalMva)) * 100).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%)'
                                                : '';
                                            return formatIdNumber(p.value, 1) + ' MVA' + (isNarrow ? '' : pct);
                                        },
                                    },
                                },
                            ],
                        };
                    }

                    const parentTotalMva = this.summaryTotals?.total_trafo_mva ?? null;
                    const ranked = visibleUpts.map((u) => ({
                        upt: u,
                        raw: this.payload?.mva_by_upt?.[u] ?? null,
                    }));
                    if (this.mvaSort === 'alpha') {
                        ranked.sort((a, b) => a.upt.localeCompare(b.upt));
                    } else {
                        ranked.sort((a, b) => (isRecorded(b.raw) ? Number(b.raw) : -1) - (isRecorded(a.raw) ? Number(a.raw) : -1));
                    }

                    return {
                        animation: true,
                        animationDuration: 420,
                        textStyle: { fontFamily: chartFont },
                        tooltip: Object.assign({}, tooltipBase, { formatter: buildAxisTooltip('UPT', 'MVA', 1, false) }),
                        grid: { top: 18, bottom: 22, left: 14, right: isNarrow ? 78 : 145, containLabel: true },
                        xAxis: yAxisValue('MVA'),
                        yAxis: {
                            type: 'category',
                            inverse: true,
                            data: ranked.map((r) => r.upt),
                            axisLine: { lineStyle: { color: pal.targetSlate } },
                            axisTick: { show: false },
                            axisLabel: { interval: 0, fontFamily: chartFont, color: pal.labelText, fontSize: 11, fontWeight: 600 },
                        },
                        series: [
                            {
                                name: 'Kapasitas Daya (MVA)',
                                type: 'bar',
                                barMaxWidth: 26,
                                data: ranked.map((r, idx) => {
                                    const recorded = isRecorded(r.raw);
                                    const val = safeNum(r.raw);
                                    return {
                                        value: val,
                                        isRecorded: recorded,
                                        itemStyle: {
                                            color: recorded ? getUptColor(pal, r.upt, idx) : '#e2e8f0',
                                            borderRadius: [0, 6, 6, 0],
                                        },
                                    };
                                }),
                                label: {
                                    show: true,
                                    position: 'right',
                                    fontFamily: chartFont,
                                    fontSize: 10.5,
                                    fontWeight: 600,
                                    color: pal.labelText,
                                    formatter: (p) => {
                                        if (!p.data?.isRecorded) {
                                            return 'Tidak tercatat';
                                        }
                                        const pct = isRecorded(parentTotalMva) && Number(parentTotalMva) > 0
                                            ? ' (' + ((Number(p.value) / Number(parentTotalMva)) * 100).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%)'
                                            : '';
                                        return formatIdNumber(p.value, 1) + ' MVA' + (isNarrow ? '' : pct);
                                    },
                                },
                            },
                        ],
                    };
                }

                // TAB 3: JARINGAN TRANSMISI (KMS)
                if (this.activeTab === 'transmisi') {
                    const groups = Array.isArray(this.payload?.transmisi_groups) ? this.payload.transmisi_groups : [];

                    if (this.studioUpt !== 'all' || this.transmisiView === 'voltage') {
                        const upt = this.studioUpt;
                        const labels = groups.map((g) => g.voltage || g.label);
                        const suData = groups.map((g) => {
                            const raw = upt !== 'all' ? g.by_upt?.[upt]?.su_kms : (g.uit_total?.su_kms ?? g.total_upt?.su_kms);
                            return { value: safeNum(raw), isRecorded: isRecorded(raw) };
                        });
                        const skData = groups.map((g) => {
                            const raw = upt !== 'all' ? g.by_upt?.[upt]?.sk_kms : (g.uit_total?.sk_kms ?? g.total_upt?.sk_kms);
                            return { value: safeNum(raw), isRecorded: isRecorded(raw) };
                        });

                        return {
                            animation: true,
                            animationDuration: 420,
                            textStyle: { fontFamily: chartFont },
                            tooltip: Object.assign({}, tooltipBase, { formatter: buildAxisTooltip('Tegangan', 'kms', 1, true) }),
                            legend: { top: 4, textStyle: { fontFamily: chartFont, fontSize: 11, color: pal.axisText } },
                            grid: { top: 44, bottom: 28, left: 16, right: 16, containLabel: true },
                            xAxis: xAxisCategory(labels),
                            yAxis: yAxisValue('kms'),
                            series: [
                                {
                                    name: 'Saluran Udara (SU)',
                                    type: 'bar',
                                    stack: 'transmisi_v',
                                    barMaxWidth: 36,
                                    itemStyle: { color: pal.pairSuSk[0] },
                                    data: suData,
                                },
                                {
                                    name: 'Saluran Kabel (SK)',
                                    type: 'bar',
                                    stack: 'transmisi_v',
                                    barMaxWidth: 36,
                                    itemStyle: { color: pal.pairSuSk[1], borderRadius: [6, 6, 0, 0] },
                                    data: skData,
                                },
                            ],
                        };
                    }

                    const orderedUpts = isHorizontalMobile ? [...visibleUpts].reverse() : visibleUpts;
                    const catAxis = xAxisCategory(orderedUpts);
                    const valAxis = yAxisValue('kms');
                    return {
                        animation: true,
                        animationDuration: 420,
                        textStyle: { fontFamily: chartFont },
                        tooltip: Object.assign({}, tooltipBase, { formatter: buildAxisTooltip('UPT', 'kms', 1, true) }),
                        legend: { type: 'scroll', top: 2, textStyle: { fontFamily: chartFont, fontSize: 11, color: pal.axisText } },
                        grid: isHorizontalMobile
                            ? { top: 40, bottom: 16, left: 8, right: 20, containLabel: true }
                            : { top: 48, bottom: 26, left: 16, right: 16, containLabel: true },
                        xAxis: isHorizontalMobile ? valAxis : catAxis,
                        yAxis: isHorizontalMobile ? catAxis : valAxis,
                        series: [
                            {
                                name: 'Saluran Udara (SU kms)',
                                type: 'bar',
                                stack: 'transmisi_upt',
                                barMaxWidth: isHorizontalMobile ? 18 : 34,
                                itemStyle: { color: pal.pairSuSk[0] },
                                data: orderedUpts.map((u) => {
                                    const raw = this.payload?.transmisi_by_upt?.[u]?.su_kms;
                                    return { value: safeNum(raw), isRecorded: isRecorded(raw) };
                                }),
                            },
                            {
                                name: 'Saluran Kabel (SK kms)',
                                type: 'bar',
                                stack: 'transmisi_upt',
                                barMaxWidth: isHorizontalMobile ? 18 : 34,
                                itemStyle: { color: pal.pairSuSk[1], borderRadius: isHorizontalMobile ? [0, 5, 5, 0] : [5, 5, 0, 0] },
                                data: orderedUpts.map((u) => {
                                    const raw = this.payload?.transmisi_by_upt?.[u]?.sk_kms;
                                    return { value: safeNum(raw), isRecorded: isRecorded(raw) };
                                }),
                            },
                        ],
                    };
                }

                // TAB 4: SEBARAN TOWER (UNIT)
                const towerGroups = Array.isArray(this.payload?.tower_groups) ? this.payload.tower_groups : [];

                if (this.studioUpt !== 'all') {
                    const upt = this.studioUpt;
                    const labels = towerGroups.map((tw) => tw.voltage || tw.label);
                    return {
                        animation: true,
                        animationDuration: 420,
                        textStyle: { fontFamily: chartFont },
                        tooltip: Object.assign({}, tooltipBase, { formatter: buildAxisTooltip('Tegangan', 'Unit', 0, true) }),
                        legend: { top: 4, textStyle: { fontFamily: chartFont, fontSize: 11, color: pal.axisText } },
                        grid: { top: 44, bottom: 28, left: 16, right: 16, containLabel: true },
                        xAxis: xAxisCategory(labels),
                        yAxis: yAxisValue('Unit'),
                        series: [
                            {
                                name: 'Tower SU (Unit)',
                                type: 'bar',
                                stack: 'tower_single',
                                barMaxWidth: 36,
                                itemStyle: { color: pal.pairSuSk[0] },
                                data: towerGroups.map((tw) => {
                                    const raw = tw.by_upt?.[upt]?.su_unit;
                                    return { value: safeNum(raw), isRecorded: isRecorded(raw) };
                                }),
                            },
                            {
                                name: 'Tower SK (Unit)',
                                type: 'bar',
                                stack: 'tower_single',
                                barMaxWidth: 36,
                                itemStyle: { color: pal.pairSuSk[1], borderRadius: [6, 6, 0, 0] },
                                data: towerGroups.map((tw) => {
                                    const raw = tw.by_upt?.[upt]?.sk_unit;
                                    return { value: safeNum(raw), isRecorded: isRecorded(raw) };
                                }),
                            },
                        ],
                    };
                }

                const orderedUpts = isHorizontalMobile ? [...visibleUpts].reverse() : visibleUpts;
                const catAxis = xAxisCategory(orderedUpts);
                const valAxis = yAxisValue('Unit');

                if (this.towerView === 'susk') {
                    const sumByType = (upt, field) => {
                        let hasVal = false;
                        let total = 0;
                        towerGroups.forEach((tw) => {
                            const v = tw.by_upt?.[upt]?.[field];
                            if (isRecorded(v)) {
                                hasVal = true;
                                total += Number(v);
                            }
                        });
                        return { value: hasVal ? total : 0, isRecorded: hasVal };
                    };

                    return {
                        animation: true,
                        animationDuration: 420,
                        textStyle: { fontFamily: chartFont },
                        tooltip: Object.assign({}, tooltipBase, { formatter: buildAxisTooltip('UPT', 'Unit', 0, true) }),
                        legend: { top: 4, textStyle: { fontFamily: chartFont, fontSize: 11, color: pal.axisText } },
                        grid: isHorizontalMobile
                            ? { top: 40, bottom: 16, left: 8, right: 20, containLabel: true }
                            : { top: 44, bottom: 26, left: 16, right: 16, containLabel: true },
                        xAxis: isHorizontalMobile ? valAxis : catAxis,
                        yAxis: isHorizontalMobile ? catAxis : valAxis,
                        series: [
                            {
                                name: 'Tower SU (Unit)',
                                type: 'bar',
                                stack: 'tower_susk',
                                barMaxWidth: isHorizontalMobile ? 18 : 34,
                                itemStyle: { color: pal.pairSuSk[0] },
                                data: orderedUpts.map((u) => sumByType(u, 'su_unit')),
                            },
                            {
                                name: 'Tower SK (Unit)',
                                type: 'bar',
                                stack: 'tower_susk',
                                barMaxWidth: isHorizontalMobile ? 18 : 34,
                                itemStyle: { color: pal.pairSuSk[1], borderRadius: isHorizontalMobile ? [0, 5, 5, 0] : [5, 5, 0, 0] },
                                data: orderedUpts.map((u) => sumByType(u, 'sk_unit')),
                            },
                        ],
                    };
                }

                return {
                    animation: true,
                    animationDuration: 420,
                    textStyle: { fontFamily: chartFont },
                    tooltip: Object.assign({}, tooltipBase, { formatter: buildAxisTooltip('UPT', 'Unit', 0, true) }),
                    legend: { type: 'scroll', top: 2, textStyle: { fontFamily: chartFont, fontSize: 11, color: pal.axisText } },
                    grid: isHorizontalMobile
                        ? { top: 42, bottom: 16, left: 8, right: 20, containLabel: true }
                        : { top: 48, bottom: 26, left: 16, right: 16, containLabel: true },
                    xAxis: isHorizontalMobile ? valAxis : catAxis,
                    yAxis: isHorizontalMobile ? catAxis : valAxis,
                    series: towerGroups.map((tw, idx) => ({
                        name: tw.voltage || tw.label,
                        type: 'bar',
                        stack: isHorizontalMobile ? 'tower_v' : undefined,
                        barMaxWidth: isHorizontalMobile ? 18 : 20,
                        itemStyle: {
                            color: pal.voltageSeries[idx % pal.voltageSeries.length],
                            borderRadius: isHorizontalMobile
                                ? (idx === towerGroups.length - 1 ? [0, 5, 5, 0] : [0, 0, 0, 0])
                                : [5, 5, 0, 0],
                        },
                        data: orderedUpts.map((u) => {
                            const raw = tw.by_upt?.[u]?.total_unit;
                            return { value: safeNum(raw), isRecorded: isRecorded(raw) };
                        }),
                    })),
                };
            },

            renderStudioChart() {
                if (!this.isFullscreen) {
                    this.isChartLoading = false;
                    return;
                }

                if (!this.hasChartData) {
                    this.disposeChart();
                    this.isChartLoading = false;
                    return;
                }

                if (!window.echarts) {
                    if (echartsWaitAttempts < 15) {
                        echartsWaitAttempts++;
                        renderTimer = setTimeout(() => this.renderStudioChart(), 80);
                        return;
                    }
                    this.chartError = 'Pustaka grafik belum termuat. Silakan muat ulang halaman.';
                    this.isChartLoading = false;
                    return;
                }

                const canvasEl = document.getElementById('asset-fullscreen-echart-canvas');
                if (!canvasEl) {
                    this.isChartLoading = false;
                    return;
                }

                try {
                    if (!studioChartInstance || studioChartInstance.isDisposed()) {
                        studioChartInstance = window.echarts.init(canvasEl);
                        studioChartInstance.on('click', (params) => {
                            const clickedName = String(params?.name || '').trim().toUpperCase();
                            if (this.studioUpt === 'all' && this.canonicalUpts.includes(clickedName)) {
                                this.setStudioUpt(clickedName);
                            }
                        });
                    }

                    if (!studioResizeObserver) {
                        let lastWidthBucket = (canvasEl.clientWidth || window.innerWidth) < 460 ? 'mob' : 'desk';
                        studioResizeObserver = new ResizeObserver(() => {
                            if (studioChartInstance && !studioChartInstance.isDisposed()) {
                                const curW = canvasEl.clientWidth || window.innerWidth;
                                const nextBucket = curW < 460 ? 'mob' : 'desk';
                                if (nextBucket !== lastWidthBucket) {
                                    lastWidthBucket = nextBucket;
                                    studioChartInstance.setOption(this.buildChartOption(curW), true);
                                }
                                studioChartInstance.resize();
                            }
                        });
                        studioResizeObserver.observe(canvasEl);
                    }

                    const containerWidth = canvasEl.clientWidth || window.innerWidth || 1280;
                    const option = this.buildChartOption(containerWidth);
                    studioChartInstance.setOption(option, true);
                    studioChartInstance.resize();
                } catch (e) {
                    this.chartError = 'Gagal merender grafik aset layar penuh.';
                } finally {
                    this.isChartLoading = false;
                }
            },

            downloadSlidePng() {
                if (!studioChartInstance || studioChartInstance.isDisposed() || !this.hasChartData || this.isExporting) {
                    return;
                }

                this.isExporting = true;
                this.exportError = '';

                const isDark = this.studioTheme === 'dark';
                const bgHex = isDark ? '#0f172a' : '#ffffff';
                const headerBg = isDark ? '#1e293b' : '#f8fafc';
                const borderHex = isDark ? '#334155' : '#e2e8f0';
                const accentHex = isDark ? '#16a3b8' : '#0b6175';
                const titleHex = isDark ? '#f8fafc' : '#0f172a';
                const mutedHex = isDark ? '#94a3b8' : '#475569';
                const badgeBg = isDark ? '#083344' : '#e6f4f6';
                const badgeText = isDark ? '#67e8f9' : '#0b6175';

                const rawChartUrl = studioChartInstance.getDataURL({
                    type: 'png',
                    pixelRatio: 2,
                    backgroundColor: bgHex,
                });

                const periodSlug = String(this.payload?.period || 'terbaru').replace(/[^a-z0-9-]+/gi, '-');
                const scopeSlug = this.studioUpt === 'all' ? 'semua-8-upt' : ('upt-' + this.studioUpt.toLowerCase());
                const filename = 'eksplorasi-aset-uit-jbt-' + this.activeTab + '-' + scopeSlug + '-' + periodSlug + '.png';

                const triggerDownload = (url) => {
                    const link = document.createElement('a');
                    link.setAttribute('href', url);
                    link.setAttribute('download', filename);
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                };

                const img = new Image();
                img.onload = () => {
                    try {
                        const padX = 56;
                        const headerH = 152;
                        const footerH = 64;
                        const canvasW = Math.max(img.width + padX * 2, 1600);
                        const canvasH = headerH + img.height + footerH + 36;

                        const offscreen = document.createElement('canvas');
                        offscreen.width = canvasW;
                        offscreen.height = canvasH;
                        const ctx = offscreen.getContext('2d');

                        if (!ctx) {
                            triggerDownload(rawChartUrl);
                            this.isExporting = false;
                            return;
                        }

                        ctx.fillStyle = bgHex;
                        ctx.fillRect(0, 0, canvasW, canvasH);

                        ctx.fillStyle = headerBg;
                        ctx.fillRect(0, 0, canvasW, headerH);
                        ctx.fillStyle = accentHex;
                        ctx.fillRect(0, 0, canvasW, 8);
                        ctx.fillStyle = borderHex;
                        ctx.fillRect(0, headerH - 2, canvasW, 2);

                        ctx.fillStyle = badgeText;
                        ctx.font = '700 20px "Geist", "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif';
                        ctx.fillText('EKSPLORASI ASET UIT JBT  |  ' + this.activeTabMeta.title.toUpperCase(), padX, 52);

                        ctx.fillStyle = titleHex;
                        ctx.font = '700 32px "Geist", "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif';
                        ctx.fillText(this.chartHeading, padX, 96);

                        const scopeLabel = this.studioUpt === 'all' ? 'Sebaran 8 UPT' : ('Fokus UPT ' + this.studioUpt);
                        const periodLabel = this.payload?.formatted_period || this.payload?.period || 'Periode aktif';
                        ctx.fillStyle = mutedHex;
                        ctx.font = '500 20px "Geist", "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif';
                        ctx.fillText('Cakupan: ' + scopeLabel + '  |  Periode: ' + periodLabel, padX, 130);

                        const indukText = 'Induk UIT JBT: ' + this.activeTabMeta.indukLabel;
                        ctx.font = '700 20px "Geist", "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif';
                        const badgeW = ctx.measureText(indukText).width + 36;
                        const badgeX = canvasW - padX - badgeW;
                        ctx.fillStyle = badgeBg;
                        ctx.fillRect(badgeX, 42, badgeW, 44);
                        ctx.fillStyle = badgeText;
                        ctx.fillText(indukText, badgeX + 18, 71);

                        const imgX = Math.max(padX, Math.round((canvasW - img.width) / 2));
                        ctx.drawImage(img, imgX, headerH + 20);

                        const footerY = canvasH - footerH;
                        ctx.fillStyle = borderHex;
                        ctx.fillRect(padX, footerY, canvasW - padX * 2, 1);
                        ctx.fillStyle = mutedHex;
                        ctx.font = '500 18px "Geist", "Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif';
                        ctx.fillText('Sumber: Rekap Induk UIT JBT & Distribusi 8 UPT', padX, footerY + 38);

                        const exportedAt = new Date().toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
                        const rightFooter = 'Diekspor ' + exportedAt;
                        const rightFooterW = ctx.measureText(rightFooter).width;
                        ctx.fillText(rightFooter, canvasW - padX - rightFooterW, footerY + 38);

                        const composedUrl = offscreen.toDataURL('image/png');
                        triggerDownload(composedUrl);
                    } catch (err) {
                        triggerDownload(rawChartUrl);
                    } finally {
                        this.isExporting = false;
                    }
                };
                img.onerror = () => {
                    this.exportError = 'Gagal menyiapkan gambar PNG grafik.';
                    this.isExporting = false;
                };
                img.src = rawChartUrl;
            },
        };
    };
}
</script>

<div
    id="asset-fullscreen-studio-root"
    class="font-aset"
    x-data='assetFullscreenStudio(@json($safeInitialSection))'
    @open-asset-fullscreen.window="openStudio($event.detail || {})"
    @keydown.window="handleStudioKeydown($event)"
>
    <div
        id="asset-fullscreen-workspace"
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
        aria-labelledby="asset-fullscreen-title"
        :class="studioTheme === 'dark' ? 'bg-slate-950/95' : 'bg-slate-900/55 backdrop-blur-xs'"
        class="fixed inset-0 z-50 h-dvh w-screen overflow-y-auto overscroll-contain p-2 sm:p-3.5"
    >
        <div
            :class="studioTheme === 'dark' ? 'border-slate-800 bg-slate-950 text-slate-100' : 'border-slate-200/80 bg-slate-50/95 text-slate-900'"
            class="mx-auto max-w-[1640px] min-w-0 rounded-2xl border p-2.5 sm:p-3.5 shadow-2xl space-y-2.5 transition-colors duration-200"
        >
            {{-- Header Studio Ringkas (Maksimal 2 Baris Kontrol Bersih) --}}
            <div
                :class="studioTheme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200/80 bg-white'"
                class="rounded-2xl border px-3.5 py-2.5 sm:px-4 shadow-xs transition-colors duration-200"
            >
                {{-- Baris 1: Judul + Ringkasan Induk Aktif + Aksi Utama --}}
                <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                        <span class="text-[10.5px] font-bold uppercase tracking-[0.06em]" :class="studioTheme === 'dark' ? 'text-cyan-400' : 'text-[#0b6175]'">
                            EKSPLORASI ASET UIT JBT
                        </span>
                        <span class="h-1 w-1 rounded-full bg-slate-400/70" aria-hidden="true"></span>
                        <h2 id="asset-fullscreen-title" class="text-sm font-bold tracking-tight" :class="studioTheme === 'dark' ? 'text-white' : 'text-slate-900'">
                            Kepemilikan Induk &amp; Sebaran 8 UPT
                        </h2>
                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium font-sans tabular-nums" :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-300' : 'border-slate-200/80 bg-slate-50 text-slate-600'" x-text="payload.formatted_period || payload.period || 'Tidak tercatat'"></span>
                        <div
                            id="asset-fullscreen-induk-ribbon"
                            :class="studioTheme === 'dark' ? 'border-cyan-900/70 bg-cyan-950/60 text-cyan-200' : 'border-[#0b6175]/15 bg-[#e6f4f6]/75 text-[#0b6175]'"
                            class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-sans tabular-nums"
                        >
                            <span class="font-semibold">Kepemilikan Induk UIT JBT:</span>
                            <strong class="font-bold" :class="studioTheme === 'dark' ? 'text-white' : 'text-slate-900'" x-text="activeTabMeta.indukLabel"></strong>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                        <button
                            type="button"
                            id="asset-fullscreen-focus-btn"
                            @click="toggleFocusMode()"
                            :aria-pressed="focusMode"
                            :class="focusMode
                                ? (studioTheme === 'dark' ? 'border-cyan-500 bg-cyan-950/70 text-cyan-200 font-semibold' : 'border-[#16a3b8] bg-[#e6f4f6] text-[#0b6175] font-semibold')
                                : (studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-200 hover:bg-slate-700' : 'border-slate-200/80 bg-white text-slate-700 hover:bg-slate-50')"
                            class="hidden xl:inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium cursor-pointer whitespace-nowrap transition focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                            title="Sembunyikan atau tampilkan panel pendamping (P)"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="16" rx="2"/>
                                <path d="M15 4v16" stroke-linecap="round"/>
                            </svg>
                            <span x-text="focusMode ? 'Tampilkan Panel' : 'Fokus Grafik'">Fokus Grafik</span>
                        </button>

                        <button
                            type="button"
                            id="asset-fullscreen-theme-btn"
                            @click="toggleStudioTheme()"
                            :aria-pressed="studioTheme === 'dark'"
                            :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-amber-300 hover:bg-slate-700' : 'border-slate-200/80 bg-white text-slate-700 hover:bg-slate-50'"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium cursor-pointer whitespace-nowrap transition focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                            title="Ganti tema Terang atau Gelap (D)"
                        >
                            <span x-text="studioTheme === 'dark' ? 'Tema Terang' : 'Tema Gelap'">Tema Gelap</span>
                        </button>

                        <button
                            type="button"
                            id="asset-fullscreen-download-png-btn"
                            @click="downloadSlidePng()"
                            :disabled="!hasChartData || !!chartError || isExporting"
                            :class="studioTheme === 'dark' ? 'border-cyan-700/70 bg-cyan-950/70 text-cyan-200 hover:bg-cyan-900/70' : 'border-[#0b6175]/25 bg-[#e6f4f6]/80 text-[#0b6175] hover:bg-[#e6f4f6]'"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer whitespace-nowrap transition focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4m4-5 5 5 5-5m-5 5V3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span x-text="isExporting ? 'Menyiapkan PNG...' : 'Unduh PNG'">Unduh PNG</span>
                        </button>

                        <button
                            type="button"
                            id="asset-fullscreen-close-btn"
                            @click="closeStudio(true)"
                            :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-200 hover:bg-slate-700' : 'border-slate-200/80 bg-white text-slate-700 hover:bg-slate-50'"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium cursor-pointer whitespace-nowrap transition focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                        >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span>Tutup</span>
                            <kbd :class="studioTheme === 'dark' ? 'border-slate-600 bg-slate-900 text-slate-300' : 'border-slate-200 bg-slate-100 text-slate-500'" class="rounded border px-1 py-0.5 text-[10px] font-semibold">ESC</kbd>
                        </button>
                    </div>
                </div>

                {{-- Baris 2: 4 Tab Seksi + Pemilih UPT Ringkas --}}
                <div class="mt-2 flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between border-t pt-2" :class="studioTheme === 'dark' ? 'border-slate-800' : 'border-slate-100'">
                    <div
                        role="tablist"
                        aria-label="Mode eksplorasi aset layar penuh"
                        class="flex flex-nowrap items-center gap-1 overflow-x-auto pb-0.5 [&::-webkit-scrollbar]:hidden [-ms-overflow-style:none] [scrollbar-width:none]"
                    >
                        <button
                            type="button"
                            role="tab"
                            id="asset-studio-tab-trafo"
                            :aria-selected="activeTab === 'trafo' ? 'true' : 'false'"
                            :tabindex="activeTab === 'trafo' ? 0 : -1"
                            @click="setActiveTab('trafo')"
                            @keydown="handleTabKeydown($event, 0)"
                            :class="activeTab === 'trafo'
                                ? (studioTheme === 'dark' ? 'border-b-2 border-cyan-400 bg-cyan-950/60 text-cyan-200 font-semibold' : 'border-b-2 border-[#0b6175] bg-[#e6f4f6]/80 text-[#0b6175] font-semibold')
                                : (studioTheme === 'dark' ? 'border-b-2 border-transparent text-slate-300 hover:bg-slate-800 hover:text-white font-medium' : 'border-b-2 border-transparent text-slate-600 hover:bg-slate-100 hover:text-slate-900 font-medium')"
                            class="shrink-0 inline-flex items-center gap-1.5 rounded-t-lg px-3 py-1.5 text-xs cursor-pointer whitespace-nowrap transition focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                        >
                            <span>1. Bedah Trafo &amp; Tegangan</span>
                        </button>
                        <button
                            type="button"
                            role="tab"
                            id="asset-studio-tab-mva"
                            :aria-selected="activeTab === 'mva' ? 'true' : 'false'"
                            :tabindex="activeTab === 'mva' ? 0 : -1"
                            @click="setActiveTab('mva')"
                            @keydown="handleTabKeydown($event, 1)"
                            :class="activeTab === 'mva'
                                ? (studioTheme === 'dark' ? 'border-b-2 border-cyan-400 bg-cyan-950/60 text-cyan-200 font-semibold' : 'border-b-2 border-[#0b6175] bg-[#e6f4f6]/80 text-[#0b6175] font-semibold')
                                : (studioTheme === 'dark' ? 'border-b-2 border-transparent text-slate-300 hover:bg-slate-800 hover:text-white font-medium' : 'border-b-2 border-transparent text-slate-600 hover:bg-slate-100 hover:text-slate-900 font-medium')"
                            class="shrink-0 inline-flex items-center gap-1.5 rounded-t-lg px-3 py-1.5 text-xs cursor-pointer whitespace-nowrap transition focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                        >
                            <span>2. Distribusi Daya (MVA)</span>
                        </button>
                        <button
                            type="button"
                            role="tab"
                            id="asset-studio-tab-transmisi"
                            :aria-selected="activeTab === 'transmisi' ? 'true' : 'false'"
                            :tabindex="activeTab === 'transmisi' ? 0 : -1"
                            @click="setActiveTab('transmisi')"
                            @keydown="handleTabKeydown($event, 2)"
                            :class="activeTab === 'transmisi'
                                ? (studioTheme === 'dark' ? 'border-b-2 border-cyan-400 bg-cyan-950/60 text-cyan-200 font-semibold' : 'border-b-2 border-[#0b6175] bg-[#e6f4f6]/80 text-[#0b6175] font-semibold')
                                : (studioTheme === 'dark' ? 'border-b-2 border-transparent text-slate-300 hover:bg-slate-800 hover:text-white font-medium' : 'border-b-2 border-transparent text-slate-600 hover:bg-slate-100 hover:text-slate-900 font-medium')"
                            class="shrink-0 inline-flex items-center gap-1.5 rounded-t-lg px-3 py-1.5 text-xs cursor-pointer whitespace-nowrap transition focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                        >
                            <span>3. Jaringan Transmisi (kms)</span>
                        </button>
                        <button
                            type="button"
                            role="tab"
                            id="asset-studio-tab-tower"
                            :aria-selected="activeTab === 'tower' ? 'true' : 'false'"
                            :tabindex="activeTab === 'tower' ? 0 : -1"
                            @click="setActiveTab('tower')"
                            @keydown="handleTabKeydown($event, 3)"
                            :class="activeTab === 'tower'
                                ? (studioTheme === 'dark' ? 'border-b-2 border-cyan-400 bg-cyan-950/60 text-cyan-200 font-semibold' : 'border-b-2 border-[#0b6175] bg-[#e6f4f6]/80 text-[#0b6175] font-semibold')
                                : (studioTheme === 'dark' ? 'border-b-2 border-transparent text-slate-300 hover:bg-slate-800 hover:text-white font-medium' : 'border-b-2 border-transparent text-slate-600 hover:bg-slate-100 hover:text-slate-900 font-medium')"
                            class="shrink-0 inline-flex items-center gap-1.5 rounded-t-lg px-3 py-1.5 text-xs cursor-pointer whitespace-nowrap transition focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-[#16a3b8]"
                        >
                            <span>4. Sebaran Tower (Unit)</span>
                        </button>
                    </div>

                    {{-- Pemilih Wilayah UPT Kompak --}}
                    <div id="asset-fullscreen-upt-bar" class="flex items-center gap-2 shrink-0">
                        <label for="asset-fullscreen-upt-select" class="text-[11px] font-semibold shrink-0" :class="studioTheme === 'dark' ? 'text-slate-400' : 'text-slate-500'">Wilayah:</label>
                        <select
                            id="asset-fullscreen-upt-select"
                            x-model="studioUpt"
                            @change="scheduleRender()"
                            aria-label="Pilih wilayah UPT pada layar penuh"
                            :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-100' : 'border-slate-200 bg-white text-slate-700'"
                            class="min-w-[165px] rounded-lg border px-2.5 py-1.5 text-xs font-semibold focus:border-[#0b6175] focus:outline-hidden"
                        >
                            <option value="all">Semua UPT (8 UPT)</option>
                            <template x-for="uptName in canonicalUpts" :key="'opt-' + uptName">
                                <option :value="uptName" x-text="'UPT ' + uptName"></option>
                            </template>
                        </select>
                        <button
                            type="button"
                            x-show="hasActiveFilters"
                            x-cloak
                            @click="resetStudioFilters()"
                            :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-slate-200 hover:bg-slate-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'"
                            class="rounded-lg border px-2.5 py-1.5 text-xs font-semibold cursor-pointer whitespace-nowrap transition"
                        >
                            Semua UPT
                        </button>
                    </div>
                </div>
            </div>

            {{-- Error Banner Ekspor PNG --}}
            <div
                x-show="exportError"
                x-cloak
                role="alert"
                class="flex items-center justify-between gap-2 rounded-xl border border-rose-300/80 bg-rose-50 px-3.5 py-2 text-xs font-medium text-rose-800"
            >
                <span x-text="exportError"></span>
                <button type="button" @click="exportError = ''" class="font-semibold underline cursor-pointer">Tutup</button>
            </div>

            {{-- Panggung Presentasi 12 Kolom: 9 Kolom Grafik Utama (75%) + 3 Kolom Panel Ringkas Kontekstual (25%) --}}
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-3 items-stretch">
                {{-- Panel Kiri: Kanvas Grafik ECharts Utama (75% lebar / 100% saat Fokus Grafik) --}}
                <div
                    role="tabpanel"
                    :aria-labelledby="'asset-studio-tab-' + activeTab"
                    :class="[
                        focusMode ? 'xl:!col-span-12' : '',
                        studioTheme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200/80 bg-white'
                    ]"
                    class="xl:col-span-9 min-w-0 max-w-full overflow-hidden rounded-2xl border p-3.5 sm:p-4 shadow-xs flex flex-col transition-colors duration-200"
                >
                    <div
                        :class="studioTheme === 'dark' ? 'border-slate-800' : 'border-slate-100'"
                        class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b pb-2"
                    >
                        <div class="min-w-0 flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-bold tracking-tight" :class="studioTheme === 'dark' ? 'text-white' : 'text-slate-900'" x-text="chartHeading"></h3>
                            <span
                                x-show="studioUpt !== 'all'"
                                x-cloak
                                :class="studioTheme === 'dark' ? 'bg-cyan-950/80 text-cyan-300' : 'bg-[#e6f4f6] text-[#0b6175]'"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold font-sans tabular-nums"
                                x-text="selectedUptShareBadge"
                            ></span>
                        </div>

                        {{-- Sub-Toggle Kontekstual per Tab --}}
                        <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                            <template x-if="activeTab === 'trafo'">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <div :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800' : 'border-slate-200/80 bg-slate-100'" class="inline-flex rounded-lg border p-0.5 text-[11px]">
                                        <button
                                            type="button"
                                            @click="trafoMetric = 'mva'; scheduleRender()"
                                            :class="trafoMetric === 'mva' ? (studioTheme === 'dark' ? 'bg-slate-900 text-cyan-300 font-semibold' : 'bg-white text-[#0b6175] font-semibold shadow-2xs') : (studioTheme === 'dark' ? 'text-slate-300' : 'text-slate-600')"
                                            class="rounded-md px-2.5 py-1 cursor-pointer whitespace-nowrap"
                                        >
                                            Kapasitas (MVA)
                                        </button>
                                        <button
                                            type="button"
                                            @click="trafoMetric = 'physical'; scheduleRender()"
                                            :class="trafoMetric === 'physical' ? (studioTheme === 'dark' ? 'bg-slate-900 text-cyan-300 font-semibold' : 'bg-white text-[#0b6175] font-semibold shadow-2xs') : (studioTheme === 'dark' ? 'text-slate-300' : 'text-slate-600')"
                                            class="rounded-md px-2.5 py-1 cursor-pointer whitespace-nowrap"
                                        >
                                            Fisik (Bank &amp; Unit)
                                        </button>
                                    </div>
                                    <div x-show="trafoMetric === 'mva' && studioUpt === 'all'" :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800' : 'border-slate-200/80 bg-slate-100'" class="inline-flex rounded-lg border p-0.5 text-[11px]">
                                        <button
                                            type="button"
                                            @click="trafoStackMode = 'stacked'; scheduleRender()"
                                            :class="trafoStackMode === 'stacked' ? (studioTheme === 'dark' ? 'bg-slate-900 text-cyan-300 font-semibold' : 'bg-white text-[#0b6175] font-semibold shadow-2xs') : (studioTheme === 'dark' ? 'text-slate-300' : 'text-slate-600')"
                                            class="rounded-md px-2 py-1 cursor-pointer whitespace-nowrap"
                                        >
                                            Tumpuk
                                        </button>
                                        <button
                                            type="button"
                                            @click="trafoStackMode = 'grouped'; scheduleRender()"
                                            :class="trafoStackMode === 'grouped' ? (studioTheme === 'dark' ? 'bg-slate-900 text-cyan-300 font-semibold' : 'bg-white text-[#0b6175] font-semibold shadow-2xs') : (studioTheme === 'dark' ? 'text-slate-300' : 'text-slate-600')"
                                            class="rounded-md px-2 py-1 cursor-pointer whitespace-nowrap"
                                        >
                                            Grup
                                        </button>
                                    </div>
                                </div>
                            </template>

                            <template x-if="activeTab === 'mva'">
                                <div :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800' : 'border-slate-200/80 bg-slate-100'" class="inline-flex rounded-lg border p-0.5 text-[11px]">
                                    <button
                                        type="button"
                                        @click="mvaSort = 'desc'; scheduleRender()"
                                        :class="mvaSort === 'desc' ? (studioTheme === 'dark' ? 'bg-slate-900 text-cyan-300 font-semibold' : 'bg-white text-[#0b6175] font-semibold shadow-2xs') : (studioTheme === 'dark' ? 'text-slate-300' : 'text-slate-600')"
                                        class="rounded-md px-2.5 py-1 cursor-pointer whitespace-nowrap"
                                    >
                                        Tertinggi
                                    </button>
                                    <button
                                        type="button"
                                        @click="mvaSort = 'alpha'; scheduleRender()"
                                        :class="mvaSort === 'alpha' ? (studioTheme === 'dark' ? 'bg-slate-900 text-cyan-300 font-semibold' : 'bg-white text-[#0b6175] font-semibold shadow-2xs') : (studioTheme === 'dark' ? 'text-slate-300' : 'text-slate-600')"
                                        class="rounded-md px-2.5 py-1 cursor-pointer whitespace-nowrap"
                                    >
                                        Urut Abjad
                                    </button>
                                </div>
                            </template>

                            <template x-if="activeTab === 'transmisi' && studioUpt === 'all'">
                                <div :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800' : 'border-slate-200/80 bg-slate-100'" class="inline-flex rounded-lg border p-0.5 text-[11px]">
                                    <button
                                        type="button"
                                        @click="transmisiView = 'upt'; scheduleRender()"
                                        :class="transmisiView === 'upt' ? (studioTheme === 'dark' ? 'bg-slate-900 text-cyan-300 font-semibold' : 'bg-white text-[#0b6175] font-semibold shadow-2xs') : (studioTheme === 'dark' ? 'text-slate-300' : 'text-slate-600')"
                                        class="rounded-md px-2.5 py-1 cursor-pointer whitespace-nowrap"
                                    >
                                        Per UPT (SU vs SK)
                                    </button>
                                    <button
                                        type="button"
                                        @click="transmisiView = 'voltage'; scheduleRender()"
                                        :class="transmisiView === 'voltage' ? (studioTheme === 'dark' ? 'bg-slate-900 text-cyan-300 font-semibold' : 'bg-white text-[#0b6175] font-semibold shadow-2xs') : (studioTheme === 'dark' ? 'text-slate-300' : 'text-slate-600')"
                                        class="rounded-md px-2.5 py-1 cursor-pointer whitespace-nowrap"
                                    >
                                        Per Tegangan
                                    </button>
                                </div>
                            </template>

                            <template x-if="activeTab === 'tower' && studioUpt === 'all'">
                                <div :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800' : 'border-slate-200/80 bg-slate-100'" class="inline-flex rounded-lg border p-0.5 text-[11px]">
                                    <button
                                        type="button"
                                        @click="towerView = 'voltage'; scheduleRender()"
                                        :class="towerView === 'voltage' ? (studioTheme === 'dark' ? 'bg-slate-900 text-cyan-300 font-semibold' : 'bg-white text-[#0b6175] font-semibold shadow-2xs') : (studioTheme === 'dark' ? 'text-slate-300' : 'text-slate-600')"
                                        class="rounded-md px-2.5 py-1 cursor-pointer whitespace-nowrap"
                                    >
                                        Per Tegangan
                                    </button>
                                    <button
                                        type="button"
                                        @click="towerView = 'susk'; scheduleRender()"
                                        :class="towerView === 'susk' ? (studioTheme === 'dark' ? 'bg-slate-900 text-cyan-300 font-semibold' : 'bg-white text-[#0b6175] font-semibold shadow-2xs') : (studioTheme === 'dark' ? 'text-slate-300' : 'text-slate-600')"
                                        class="rounded-md px-2.5 py-1 cursor-pointer whitespace-nowrap"
                                    >
                                        SU vs SK
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Loading State --}}
                    <div
                        x-show="isChartLoading"
                        class="flex items-center justify-center rounded-xl border border-slate-200/60 bg-slate-50/40"
                        style="width: 100%; height: clamp(360px, calc(100dvh - 235px), 660px); min-height: 360px;"
                    >
                        <span class="text-xs font-medium text-slate-500">Memuat visualisasi aset...</span>
                    </div>

                    {{-- Error State --}}
                    <div
                        x-show="!isChartLoading && chartError"
                        x-cloak
                        class="flex flex-col items-center justify-center gap-2.5 rounded-xl border border-rose-200 bg-rose-50/40 p-6 text-center"
                        style="width: 100%; height: clamp(360px, calc(100dvh - 235px), 660px); min-height: 360px;"
                    >
                        <p class="text-xs font-medium text-rose-800" x-text="chartError"></p>
                        <button
                            type="button"
                            @click="scheduleRender()"
                            class="rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-50 cursor-pointer"
                        >
                            Muat Ulang Grafik
                        </button>
                    </div>

                    {{-- Empty State --}}
                    <div
                        x-show="!isChartLoading && !chartError && !hasChartData"
                        x-cloak
                        class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 p-6 text-center"
                        style="width: 100%; height: clamp(360px, calc(100dvh - 235px), 660px); min-height: 360px;"
                    >
                        <p class="text-xs font-medium text-slate-500">Belum ada data aset aktif untuk ditampilkan pada periode ini.</p>
                    </div>

                    {{-- Primary ECharts Canvas (Lega >= 75% Stage) --}}
                    <div
                        x-show="!isChartLoading && !chartError && hasChartData"
                        id="asset-fullscreen-echart-canvas"
                        class="w-full"
                        style="width: 100%; height: clamp(360px, calc(100dvh - 235px), 660px); min-height: 360px;"
                    ></div>

                    {{-- Micro-Footer Ringkas --}}
                    <div
                        :class="studioTheme === 'dark' ? 'border-slate-800 text-slate-400' : 'border-slate-100 text-slate-400'"
                        class="mt-2 pt-2 border-t flex flex-wrap items-center justify-between gap-2 text-[10.5px]"
                    >
                        <span>Sumber: Rekap Induk UIT JBT &amp; Distribusi 8 UPT</span>
                        <div class="hidden sm:flex flex-wrap items-center gap-3">
                            <span><kbd class="font-semibold">1</kbd>/<kbd class="font-semibold">2</kbd>/<kbd class="font-semibold">3</kbd>/<kbd class="font-semibold">4</kbd> Tab</span>
                            <span><kbd class="font-semibold">P</kbd> Fokus</span>
                            <span><kbd class="font-semibold">D</kbd> Tema</span>
                            <span><kbd class="font-semibold">Esc</kbd> Tutup</span>
                        </div>
                    </div>
                </div>

                {{-- Panel Kanan: 1 Daftar Ringkas Kontekstual (25% lebar) --}}
                <div
                    id="asset-fullscreen-side-panel"
                    x-show="!focusMode"
                    :class="studioTheme === 'dark' ? 'border-slate-800 bg-slate-900' : 'border-slate-200/80 bg-white'"
                    class="xl:col-span-3 min-w-0 max-w-full rounded-2xl border flex flex-col shadow-xs overflow-hidden transition-colors duration-200"
                >
                    <div
                        :class="studioTheme === 'dark' ? 'border-slate-800' : 'border-slate-100'"
                        class="flex items-center justify-between gap-2 border-b px-3.5 py-2.5"
                    >
                        <div class="min-w-0">
                            <h3
                                class="text-xs font-bold uppercase tracking-wider truncate"
                                :class="studioTheme === 'dark' ? 'text-cyan-300' : 'text-[#0b6175]'"
                                x-text="studioUpt === 'all' ? 'Peringkat 8 UPT' : ('Daftar ULTG: ' + studioUpt)"
                            ></h3>
                            <p class="text-[11px] truncate" :class="studioTheme === 'dark' ? 'text-slate-400' : 'text-slate-500'" x-text="studioUpt === 'all' ? 'Klik UPT untuk melihat ULTG' : (selectedUptUltgRows.length + ' unit layanan tercatat')"></p>
                        </div>
                        <button
                            type="button"
                            x-show="studioUpt !== 'all'"
                            x-cloak
                            @click="setStudioUpt('all')"
                            :class="studioTheme === 'dark' ? 'border-slate-700 bg-slate-800 text-cyan-300 hover:bg-slate-700' : 'border-slate-200 bg-slate-50 text-[#0b6175] hover:bg-[#e6f4f6]'"
                            class="shrink-0 rounded-lg border px-2 py-1 text-[11px] font-semibold cursor-pointer"
                        >
                            Semua
                        </button>
                    </div>

                    <div x-ref="companionScroll" class="flex-1 max-h-[clamp(360px,calc(100dvh-235px),660px)] overflow-y-auto overscroll-contain">
                        {{-- Daftar 1: Peringkat 8 UPT saat Semua UPT aktif --}}
                        <template x-if="studioUpt === 'all'">
                            <div class="divide-y" :class="studioTheme === 'dark' ? 'divide-slate-800' : 'divide-slate-100'">
                                <template x-for="(row, idx) in allUptRankingRows" :key="'rank-' + row.upt">
                                    <button
                                        type="button"
                                        @click="setStudioUpt(row.upt)"
                                        :class="studioTheme === 'dark' ? 'hover:bg-slate-800/70' : 'hover:bg-[#e6f4f6]/40'"
                                        class="w-full text-left px-3.5 py-2.5 cursor-pointer transition-colors block focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#16a3b8]"
                                    >
                                        <div class="flex items-center justify-between gap-2 text-xs">
                                            <span class="flex items-center gap-2 min-w-0">
                                                <span
                                                    :class="idx === 0 ? (studioTheme === 'dark' ? 'bg-cyan-900 text-cyan-200' : 'bg-[#0b6175] text-white') : (studioTheme === 'dark' ? 'bg-slate-800 text-slate-400' : 'bg-slate-100 text-slate-600')"
                                                    class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded text-[10px] font-bold font-sans tabular-nums"
                                                    x-text="idx + 1"
                                                ></span>
                                                <span class="font-semibold truncate" :class="studioTheme === 'dark' ? 'text-slate-100' : 'text-slate-800'" x-text="row.upt"></span>
                                            </span>
                                            <span class="shrink-0 font-bold font-sans tabular-nums" :class="studioTheme === 'dark' ? 'text-white' : 'text-slate-900'" x-text="row.primaryFormatted"></span>
                                        </div>
                                        <div class="mt-1.5 flex items-center justify-between gap-2">
                                            <div class="h-1 flex-1 rounded-full overflow-hidden" :class="studioTheme === 'dark' ? 'bg-slate-800' : 'bg-slate-100'">
                                                <div class="h-full rounded-full" :class="studioTheme === 'dark' ? 'bg-cyan-400' : 'bg-[#0b6175]'" :style="'width: ' + Math.min(100, Math.max(0, row.pct !== null ? row.pct : 0)) + '%'"></div>
                                            </div>
                                            <span class="text-[10.5px] font-semibold font-sans tabular-nums shrink-0" :class="studioTheme === 'dark' ? 'text-cyan-300' : 'text-[#0b6175]'" x-text="formatPct(row.pct)"></span>
                                        </div>
                                    </button>
                                </template>
                            </div>
                        </template>

                        {{-- Daftar 2: Daftar ULTG saat 1 UPT dipilih --}}
                        <template x-if="studioUpt !== 'all'">
                            <div class="divide-y" :class="studioTheme === 'dark' ? 'divide-slate-800' : 'divide-slate-100'">
                                <template x-for="ultg in selectedUptUltgRows" :key="'ultg-' + ultg.ultg">
                                    <div class="px-3.5 py-2.5 text-xs">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-semibold truncate" :class="studioTheme === 'dark' ? 'text-slate-100' : 'text-slate-800'" x-text="ultg.ultg"></span>
                                            <span class="shrink-0 font-bold font-sans tabular-nums" :class="ultg.recorded ? (studioTheme === 'dark' ? 'text-cyan-300' : 'text-[#0b6175]') : 'text-slate-400 font-normal'" x-text="ultg.primaryText"></span>
                                        </div>
                                        <div class="mt-0.5 text-[10.5px] font-sans tabular-nums truncate" :class="studioTheme === 'dark' ? 'text-slate-400' : 'text-slate-500'" x-text="ultg.secondaryText"></div>
                                    </div>
                                </template>
                                <div x-show="selectedUptUltgRows.length === 0" class="p-5 text-center text-xs" :class="studioTheme === 'dark' ? 'text-slate-400' : 'text-slate-500'">
                                    Belum ada rincian ULTG tercatat untuk UPT ini.
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

// Entry point JavaScript aplikasi.
// Alpine.js di-start
import Alpine from 'alpinejs';

// Apache ECharts: import MODULAR (bukan 'echarts' penuh) agar bundle kecil.
// Hanya chart + komponen yang dipakai dashboard yang di-register.
import * as echarts from 'echarts/core';
import { BarChart, GaugeChart, LineChart, PieChart } from 'echarts/charts';
import {
    DataZoomComponent,
    GridComponent,
    LegendComponent,
    MarkLineComponent,
    ToolboxComponent,
    TooltipComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([
    BarChart,
    GaugeChart,
    LineChart,
    PieChart,
    DataZoomComponent,
    GridComponent,
    LegendComponent,
    MarkLineComponent,
    ToolboxComponent,
    TooltipComponent,
    CanvasRenderer,
]);

// Design token warna — selaraskan dengan @theme di resources/css/app.css.
// Single Source of Truth untuk seluruh warna ECharts di aplikasi.
window.PLN_CHART_PALETTE = {
    primary: '#0b6175',
    accent: '#16a3b8',
    softTeal: '#bce3e8',
    targetSlate: '#cbd5e1',
    gridLine: '#f1f5f9',
    axisText: '#64748b',
    rag: {
        tercapai: '#10b981',
        waspada: '#f59e0b',
        kritis: '#f43f5e',
    },
};

export const CHART_COLORS = {
    primary: window.PLN_CHART_PALETTE.primary,
    accent: window.PLN_CHART_PALETTE.accent,
    highlight: '#f1dc3a',
    muted: '#d8e3e7',
};

// Store global untuk state sidebar (hover-expand).
// Dipakai sidebar.blade.php (nulis) dan app.blade.php (baca, buat
// nge-push margin konten). Satu sumber kebenaran, bukan dua x-data
// yang gak sinkron.
Alpine.store('ui', {
    sidebarExpanded: window.innerWidth >= 1024,
    sidebarTimer: null,
});

// Lightweight Alpine data component for smooth number ticker animations on mount
Alpine.data('numberTicker', (targetVal, duration = 500) => ({
    display: targetVal,
    init() {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        const strVal = String(targetVal ?? '').trim();
        if (!strVal) return;

        // Parse numerical value safely (supporting Indonesian 1.234,56 or standard 1234.56)
        const cleanStr = strVal.replace(/\./g, '').replace(',', '.');
        const num = parseFloat(cleanStr);
        if (isNaN(num) || num === 0) {
            return;
        }

        const hasDecimals = strVal.includes(',');
        const decimalDigits = hasDecimals ? (strVal.split(',')[1] || '').length : 0;
        const startTime = performance.now();
        const startVal = 0;

        const animate = (now) => {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            // Ease-out cubic curve: 1 - (1 - progress)^3
            const ease = 1 - Math.pow(1 - progress, 3);
            const current = startVal + (num - startVal) * ease;

            if (hasDecimals) {
                this.display = current.toLocaleString('id-ID', {
                    minimumFractionDigits: decimalDigits,
                    maximumFractionDigits: decimalDigits,
                });
            } else {
                this.display = Math.round(current).toLocaleString('id-ID');
            }

            if (progress < 1) {
                requestAnimationFrame(animate);
            } else {
                this.display = strVal;
            }
        };

        requestAnimationFrame(animate);
    },
}));

// Global safe ECharts initializer preventing 0x0 container init and Proxy trapping
window.initSafeEChart = function (domElement, options = null, theme = null) {
    if (!domElement || !window.echarts) return null;
    let instance = window.echarts.getInstanceByDom(domElement);
    if (!instance) {
        instance = window.echarts.init(domElement, theme);
    }
    if (options) {
        instance.setOption(options);
    }
    if (!domElement.__resizeObserverAttached) {
        const ro = new ResizeObserver(() => {
            if (instance && !instance.isDisposed()) {
                instance.resize();
            }
        });
        ro.observe(domElement);
        domElement.__resizeObserverAttached = true;
    }
    return instance;
};

window.Alpine = Alpine;
window.echarts = echarts;

Alpine.start();

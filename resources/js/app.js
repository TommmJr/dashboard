// Entry point JavaScript aplikasi.
// Alpine.js di-start
import Alpine from 'alpinejs';

// Apache ECharts: import MODULAR (bukan 'echarts' penuh) agar bundle kecil.
// Hanya chart + komponen yang dipakai dashboard yang di-register.
import * as echarts from 'echarts/core';
import { BarChart, LineChart } from 'echarts/charts';
import {
    GridComponent,
    LegendComponent,
    TooltipComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([BarChart, LineChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer]);

// Design token warna — selaraskan dengan @theme di resources/css/app.css.
// Satu sumber untuk semua series chart di aplikasi.
export const CHART_COLORS = {
    primary: '#125d72',
    accent: '#14a2ba',
    highlight: '#efe62f',
    muted: '#d9d9d9',
};

window.Alpine = Alpine;
window.echarts = echarts;

Alpine.start();

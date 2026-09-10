<?php

/*
 * Konfigurasi Dashboard Builder Fase 1: katalog widget + layout per role.
 *
 * CATATAN DEVIASI FR-001: Fase 1 menyimpan layout secara HARDCODE di config
 * ini dan bersifat role-scoped — belum memenuhi FR-001 §3 (builder drag-drop
 * per user di tabel DB). Pemindahan ke tabel dashboard_layouts + UI builder
 * dijadwalkan di Fase 2.
 *
 * Struktur:
 * - widgets : katalog widget_key => label, view (Blade component anonim di
 *   resources/views/components/widgets/), permission (nullable).
 * - layouts : slug role => daftar widget_key terurut (urutan render).
 * - default : fallback untuk role null / slug tidak dikenal.
 *
 * PENTING: file ini BUKAN mekanisme keamanan. DashboardController tetap
 * memfilter setiap widget via Gate::allows($permission) di backend; layout
 * hanya menentukan urutan & komposisi tampilan.
 */
return [

    /*
     * Katalog widget.
     *
     * - label      : judul widget (di-render server-side, aman di-assertSee).
     * - view       : nama component relatif resources/views/components/.
     * - permission : permission yang wajib dimiliki user; null = tanpa gating
     *                tambahan (tetap tunduk pada layout role-nya).
     */
    'widgets' => [
        'kpi-summary' => ['label' => 'Ringkasan KPI',      'view' => 'widgets.kpi-summary',          'permission' => null],
        'trend-chart' => ['label' => 'Grafik Tren Kinerja', 'view' => 'widgets.trend-chart',         'permission' => null],
        'recent-audit' => ['label' => 'Audit Terbaru',      'view' => 'widgets.recent-audit',         'permission' => 'audit.view'],
        'failed-datasets' => ['label' => 'Dataset Gagal',      'view' => 'widgets.failed-datasets',      'permission' => 'dataset.view'],
        'layout-editor-placeholder' => ['label' => 'Editor Layout',      'view' => 'widgets.layout-editor-placeholder', 'permission' => 'dashboard.update'],
    ],

    /*
     * Layout per role. Key = slug role (string literal, identik dengan
     * konstanta di App\Models\Role), value = daftar widget_key terurut.
     */
    'layouts' => [
        'admin' => ['kpi-summary', 'trend-chart', 'recent-audit', 'failed-datasets'],
        'management' => ['kpi-summary', 'trend-chart'],
        'editor_dashboard' => ['kpi-summary', 'trend-chart', 'layout-editor-placeholder'],
        'staff_divisi' => ['kpi-summary', 'trend-chart'],
    ],

    // Fallback: role null / slug tidak dikenal -> KPI + chart saja.
    'default' => ['kpi-summary', 'trend-chart'],
];

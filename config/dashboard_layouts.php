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
        'nko-ranking' => ['label' => 'Peringkat NKO Unit',   'view' => 'widgets.nko-ranking',          'permission' => 'dashboard.view'],
        // Katalog saja, BELUM dipasang ke layout role mana pun (M2: visual
        // per UPT confirmed, mounting production masih blocked agar user
        // nyata tidak melihat grafik berisi data nol). Daftar series
        // provisional mengikuti data; subset final menunggu data valid.
        'aset-upt-chart' => ['label' => 'Stok Aset per UPT', 'view' => 'widgets.aset-upt-chart', 'permission' => 'dashboard.view'],
        'recent-audit' => ['label' => 'Audit Terbaru',      'view' => 'widgets.recent-audit',         'permission' => 'audit.view'],
        'failed-datasets' => ['label' => 'Dataset Gagal',      'view' => 'widgets.failed-datasets',      'permission' => 'dataset.view'],
        'admin-summary' => ['label' => 'Ringkasan Operasional', 'view' => 'widgets.admin-summary',      'permission' => 'dashboard.view'],
        'admin-quick-actions' => ['label' => 'Aksi Cepat',      'view' => 'widgets.admin-quick-actions',  'permission' => 'dashboard.view'],
        'layout-editor-placeholder' => ['label' => 'Editor Layout',      'view' => 'widgets.layout-editor-placeholder', 'permission' => 'dashboard.update'],
    ],

    /*
     * Layout per role. Key = slug role (string literal, identik dengan
     * konstanta di App\Models\Role), value = daftar widget_key terurut.
     *
     * Admin = operasional saja (audit, dataset); widget KPI/NKO milik
     * dashboard tiap divisi. nko-ranking HANYA di layout lintas divisi yang
     * memakai KPI (management, editor_dashboard). Peringkat lintas-unit
     * tidak bermakna untuk staf divisi yang scoping datanya satu divisi saja.
     */
    'layouts' => [
        'admin' => ['admin-summary', 'recent-audit', 'failed-datasets', 'admin-quick-actions'],
        'management' => ['kpi-summary', 'trend-chart', 'nko-ranking'],
        'editor_dashboard' => ['kpi-summary', 'trend-chart', 'nko-ranking', 'layout-editor-placeholder'],
        'staff_divisi' => ['kpi-summary', 'trend-chart'],
    ],

    // Fallback: role null / slug tidak dikenal -> KPI + chart saja.
    'default' => ['kpi-summary', 'trend-chart'],
];

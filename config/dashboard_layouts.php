<?php

/*
 * Konfigurasi Dashboard Layout: katalog widget + fallback layout per role.
 *
 * Resolusi layout kini DB-first via tabel `dashboard_layouts`, dikelola melalui
 * UI builder untuk role yang memiliki permission `dashboard.update`. Konfigurasi
 * di file ini berfungsi sebagai:
 * 1. Katalog definitif widget aktif (`widgets`).
 * 2. Fallback layout bawaan per role (`layouts`) dan fallback umum (`default`)
 *    apabila belum ada layout tersimpan di database atau saat override di-reset.
 *
 * Struktur:
 * - widgets : katalog widget_key => label, view (Blade component anonim di
 *   resources/views/components/widgets/), permission (nullable).
 * - layouts : slug role (atau staff_divisi:<slug>) => daftar widget_key terurut.
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
        'kpi-summary' => ['default_cols' => 3, 'label' => 'Ringkasan KPI',      'view' => 'widgets.kpi-summary',          'permission' => null],
        'trend-chart' => ['default_cols' => 2, 'label' => 'Grafik Tren Kinerja', 'view' => 'widgets.trend-chart',         'permission' => null],
        'nko-ranking' => ['default_cols' => 1, 'label' => 'Peringkat NKO Unit',   'view' => 'widgets.nko-ranking',          'permission' => 'dashboard.view'],
        'dataset-status' => ['default_cols' => 3, 'label' => 'Status Dataset',   'view' => 'widgets.dataset-status',       'permission' => null],
        'kinerja-realisasi' => ['default_cols' => 2, 'label' => 'Realisasi vs Target', 'view' => 'widgets.kinerja-realisasi', 'permission' => null],
        'kinerja-detail' => ['default_cols' => 3, 'label' => 'Detail Metrik KPI', 'view' => 'widgets.kinerja-detail',       'permission' => null],
        // Panel per indikator (keputusan Pak Gama 2026-10-19 11:17 & 2026-10-25).
        // Sumber SELALU terstruktur lewat TargetRealisasiPerIndicatorReader;
        // tidak membaca KPI_READ_MODEL dan tidak memakai R8/targetVsRealisasi().
        'target-realisasi-per-indikator' => ['default_cols' => 3, 'label' => 'Target dan Realisasi per Indikator', 'view' => 'widgets.target-realisasi-per-indikator', 'permission' => 'dashboard.view'],
        'pencapaian-nko' => ['default_cols' => 1, 'label' => 'Pencapaian % per Unit', 'view' => 'widgets.pencapaian-nko', 'permission' => null],
        'nko-donut' => ['default_cols' => 1, 'label' => 'Distribusi NKO', 'view' => 'widgets.nko-donut', 'permission' => null],
        // Grafik generik ECharts (Opsi C, Sesi 119): judul/jenis/metrik dari
        // `config` per-instance di layout, divalidasi whitelist di backend.
        'custom-chart' => ['default_cols' => 2, 'label' => 'Grafik Kustom', 'view' => 'widgets.custom-chart', 'permission' => 'dashboard.view'],
        // Katalog saja, TIDAK dipasang ke layout role mana pun. Data Aset
        // dimiliki divisi Managemen Aset (MANSET), sedangkan layout
        // `staff_divisi` di-scope ke divisi staf yang login — memasang widget
        // Aset di sana membuat staf divisi lain hanya melihat empty state.
        // (Keputusan 2026-09-30: widget dicabut dari `staff_divisi` agar
        // dashboard divisi bersih; mounting ulang menunggu pekerjaan MANSET.)
        // Daftar series provisional mengikuti data; subset final menunggu
        // keputusan MANSET.
        'aset-upt-chart' => ['default_cols' => 1, 'label' => 'Stok Aset per UPT', 'view' => 'widgets.aset-upt-chart', 'permission' => 'dashboard.view'],
        'recent-audit' => ['default_cols' => 2, 'label' => 'Audit Terbaru',      'view' => 'widgets.recent-audit',         'permission' => 'audit.view'],
        'failed-datasets' => ['default_cols' => 1, 'label' => 'Dataset Gagal',      'view' => 'widgets.failed-datasets',      'permission' => 'dataset.view'],
        'admin-summary' => ['default_cols' => 3, 'label' => 'Ringkasan Operasional', 'view' => 'widgets.admin-summary',      'permission' => 'dashboard.view'],
        'admin-quick-actions' => ['default_cols' => 3, 'label' => 'Aksi Cepat',      'view' => 'widgets.admin-quick-actions',  'permission' => 'dashboard.view'],
        'layout-editor-placeholder' => ['default_cols' => 3, 'label' => 'Editor Layout',      'view' => 'widgets.layout-editor-placeholder', 'permission' => 'dashboard.update'],
    ],

    /*
     * Layout per role. Key = slug role (string literal, identik dengan
     * konstanta di App\Models\Role), value = daftar widget_key terurut.
     *
     * Admin = operasional saja (audit, dataset); widget KPI/NKO milik
     * dashboard tiap divisi. nko-ranking dipasang di layout lintas divisi
     * (management, editor_dashboard) DAN di staff_divisi.
     *
     * REVISI 2026-10-01 (pembatalan larangan 2026-09-30): sebelumnya nko-ranking
     * sengaja TIDAK dipasang di staff_divisi dengan alasan "peringkat
     * lintas-unit tidak bermakna untuk staf yang scoping-nya satu divisi".
     * Alasan itu keliru konteks: widget ini SUDAH scoped ke divisi user, jadi
     * yang tampil adalah peringkat ANTAR-UNIT DI DALAM divisi staf (mis. 15
     * unit pada divisi Perencanaan Pengusahaan) — justru bermakna. Yang tidak
     * bermakna adalah peringkat lintas DIVISI, dan itu tetap tidak ditampilkan.
     * Keputusan user 2026-10-01.
     *
     * staff_divisi = KPI + tren Kinerja + peringkat unit. Widget Aset TIDAK
     * dipasang di sini (keputusan 2026-09-30): datanya milik divisi Managemen
     * Aset, jadi untuk staf divisi lain widget hanya menampilkan empty state.
     *
     * KETERBATASAN DIKETAHUI (2026-10-01): resolusi layout HANYA per role,
     * belum per divisi — semua divisi berbagi layout `staff_divisi`. Karena
     * itu widget milik satu divisi tidak boleh dipasang di sana: divisi lain
     * akan melihat empty state yang membingungkan. Pemisahan layout per
     * divisi ditunda ke era pekerjaan MANSET (keputusan user 2026-10-01).
     *
     * Revisi 2026-10-05: per-divisi untuk staf. Role lain tetap per-role;
     * staff_divisi punya key fallback dan override per-divisi dengan format
     * `staff_divisi:<division.slug>`.
     */
    'layouts' => [
        'admin' => ['admin-summary', 'recent-audit', 'failed-datasets', 'admin-quick-actions', 'layout-editor-placeholder'],
        'management' => ['kpi-summary', 'trend-chart', 'nko-ranking'],
        'editor_dashboard' => ['kpi-summary', 'trend-chart', 'nko-ranking', 'layout-editor-placeholder'],
        // Default staf divisi yang belum punya override: hanya dataset-status.
        'staff_divisi' => ['dataset-status'],
        // Override per-divisi: divisi Perencanaan Pengusahaan mendapat seluruh
        // widget KPI/Kinerja yang tersedia.
        //
        // REVISI 2026-10-25 (Keputusan Bisnis 5 Pak Gama): isu agregasi lintas
        // satuan C-1 resmi ditutup dengan memilih tampilan kartu per indikator
        // (`target-realisasi-per-indikator`) dan menghapus definisi widget
        // agregat lama (`target-vs-realisasi`) dari katalog.
        'staff_divisi:perencanaan-pengusahaan' => [
            'kpi-summary', 'trend-chart', 'nko-ranking', 'nko-donut',
            'dataset-status', 'kinerja-realisasi', 'pencapaian-nko', 'kinerja-detail',
            'target-realisasi-per-indikator',
        ],
    ],

    // Fallback: role null / slug tidak dikenal -> KPI + chart saja.
    'default' => ['kpi-summary', 'trend-chart'],
];

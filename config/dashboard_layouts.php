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
        'kpi-summary' => [
            'default_cols' => 3,
            'label' => 'Ringkasan KPI',
            'view' => 'widgets.kpi-summary',
            'permission' => null,
            'category' => 'Kinerja / KPI',
            'description' => 'Ringkasan skor NKO, total indikator tercapai, dan periode aktif.',
        ],
        'trend-chart' => [
            'default_cols' => 2,
            'label' => 'Tren NKO',
            'view' => 'widgets.trend-chart',
            'permission' => null,
            'category' => 'Kinerja / KPI',
            'description' => 'Grafik garis tren historis pergerakan kinerja bulanan unit.',
        ],
        'nko-ranking' => [
            'default_cols' => 1,
            'label' => 'Peringkat NKO',
            'view' => 'widgets.nko-ranking',
            'permission' => 'dashboard.view',
            'category' => 'Kinerja / KPI',
            'description' => 'Peringkat unit kerja diurutkan dari skor NKO tertinggi.',
        ],
        'dataset-status' => [
            'default_cols' => 3,
            'label' => 'Status Dataset',
            'view' => 'widgets.dataset-status',
            'permission' => null,
            'category' => 'Operasional & Admin',
            'description' => 'Status dataset siap pakai, jumlah baris data, dan divisi pemilik.',
        ],
        'kinerja-realisasi' => [
            'default_cols' => 2,
            'label' => 'Realisasi vs Target',
            'view' => 'widgets.kinerja-realisasi',
            'permission' => null,
            'category' => 'Kinerja / KPI',
            'description' => 'Grafik komparasi capaian realisasi terhadap target kinerja.',
        ],
        'kinerja-detail' => [
            'default_cols' => 3,
            'label' => 'Detail Metrik KPI',
            'view' => 'widgets.kinerja-detail',
            'permission' => null,
            'category' => 'Kinerja / KPI',
            'description' => 'Matriks tabular seluruh indikator kinerja dan satuan pengukuran.',
        ],
        // Panel per indikator (keputusan Pak Gama 2026-10-19 11:17 & 2026-10-25).
        // Sumber SELALU terstruktur lewat TargetRealisasiPerIndicatorReader;
        // tidak membaca KPI_READ_MODEL dan tidak memakai R8/targetVsRealisasi().
        'target-realisasi-per-indikator' => [
            'default_cols' => 3,
            'label' => 'Target dan Realisasi per Indikator',
            'view' => 'widgets.target-realisasi-per-indikator',
            'permission' => 'dashboard.view',
            'category' => 'Kinerja / KPI',
            'description' => 'Panel terstruktur komparasi target vs realisasi per indikator kinerja.',
        ],
        'pencapaian-nko' => [
            'default_cols' => 1,
            'label' => 'Pencapaian % per Unit',
            'view' => 'widgets.pencapaian-nko',
            'permission' => null,
            'category' => 'Kinerja / KPI',
            'description' => 'Perbandingan persentase pencapaian target seluruh unit kerja.',
        ],
        'nko-donut' => [
            'default_cols' => 1,
            'label' => 'Distribusi NKO',
            'view' => 'widgets.nko-donut',
            'permission' => null,
            'category' => 'Kinerja / KPI',
            'description' => 'Grafik donat proporsi sebaran kategori skor NKO unit.',
        ],
        'kpi-rag-status' => [
            'default_cols' => 1,
            'label' => 'Status Indikator',
            'view' => 'widgets.kpi-rag-status',
            'permission' => 'dashboard.view',
            'category' => 'Kinerja / KPI',
            'description' => 'Tile status RAG (Tercapai, Hampir Tercapai, Perlu Perhatian).',
        ],
        // Grafik generik ECharts (Opsi C, Sesi 119): judul/jenis/metrik dari
        // `config` per-instance di layout, divalidasi whitelist di backend.
        'custom-chart' => [
            'default_cols' => 2,
            'label' => 'Grafik Kustom',
            'view' => 'widgets.custom-chart',
            'permission' => 'dashboard.view',
            'category' => 'Kinerja / KPI',
            'description' => 'Grafik interaktif kustom (bar, line, donut) dengan metrik pilihan.',
        ],
        // Data Aset dimiliki divisi Managemen Aset (MANSET), sedangkan layout
        // `staff_divisi` generik di-scope ke divisi staf yang login — memasang
        // widget Aset di sana membuat staf divisi lain hanya melihat empty
        // state. (Keputusan 2026-09-30: widget dicabut dari `staff_divisi`.)
        // REVISI 2026-11-11 (instruksi user, khusus demo prototipe): widget
        // dipasang HANYA di override `staff_divisi:managemen-aset`, bukan
        // sign-off mentor/PIC. Lebar 3 kolom lewat `default_cols`, karena
        // item layout config hanya berupa string widget_key.
        // Daftar series provisional mengikuti data; subset final menunggu
        // keputusan MANSET.
        'aset-upt-chart' => [
            'default_cols' => 3,
            'label' => 'Stok Aset per UPT',
            'view' => 'widgets.aset-upt-chart',
            'permission' => 'dashboard.view',
            'category' => 'Manajemen Aset',
            'description' => 'Rekapitulasi stok trafo, daya MVA, transmisi, dan tower per UPT.',
        ],
        'recent-audit' => [
            'default_cols' => 2,
            'label' => 'Audit Terbaru',
            'view' => 'widgets.recent-audit',
            'permission' => 'audit.view',
            'category' => 'Operasional & Admin',
            'description' => 'Catatan riwayat audit log aktivitas terbaru pada sistem.',
        ],
        'failed-datasets' => [
            'default_cols' => 1,
            'label' => 'Dataset Gagal',
            'view' => 'widgets.failed-datasets',
            'permission' => 'dataset.view',
            'category' => 'Operasional & Admin',
            'description' => 'Monitoring antrean dataset yang mengalami kendala pemrosesan.',
        ],
        'admin-summary' => [
            'default_cols' => 3,
            'label' => 'Ringkasan Operasional',
            'view' => 'widgets.admin-summary',
            'permission' => 'dashboard.view',
            'category' => 'Operasional & Admin',
            'description' => 'Metrik operasional sistem mencakup total dataset dan pengguna.',
        ],
        'admin-quick-actions' => [
            'default_cols' => 3,
            'label' => 'Aksi Cepat',
            'view' => 'widgets.admin-quick-actions',
            'permission' => 'dashboard.view',
            'category' => 'Operasional & Admin',
            'description' => 'Pintasan navigasi cepat menuju modul utama operasional sistem.',
        ],
        'layout-editor-placeholder' => [
            'default_cols' => 3,
            'label' => 'Editor Layout',
            'view' => 'widgets.layout-editor-placeholder',
            'permission' => 'dashboard.update',
            'category' => 'Operasional & Admin',
            'description' => 'Editor tata letak kanvas dashboard per peran pengguna.',
        ],
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
     * dipasang di layout generik ini (keputusan 2026-09-30): datanya milik
     * divisi Managemen Aset, jadi untuk staf divisi lain widget hanya
     * menampilkan empty state. Pengecualian demo prototipe 2026-11-11 hanya
     * pada override `staff_divisi:managemen-aset` di bawah.
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
        'admin' => ['admin-summary', 'recent-audit', 'failed-datasets', 'admin-quick-actions'],
        'management' => ['kpi-summary', 'trend-chart', 'nko-ranking'],
        'editor_dashboard' => ['kpi-summary', 'trend-chart', 'nko-ranking'],
        // Default staf divisi yang belum punya override: hanya dataset-status.
        'staff_divisi' => ['dataset-status'],
        // Override per-divisi: layar depan (L1) Perencanaan Pengusahaan dirampingkan
        // ke 3 widget inti agar muat satu layar eksekutif tanpa duplikasi daftar
        // 16 indikator antara L1 dan L3 (/dashboard/kpi-detail).
        //
        // REVISI 2026-10-25 (Keputusan Bisnis 5 Pak Gama): isu agregasi lintas
        // satuan C-1 resmi ditutup dengan memilih tampilan kartu per indikator
        // (`target-realisasi-per-indikator`) dan menghapus definisi widget
        // agregat lama (`target-vs-realisasi`) dari katalog.
        // REVISI 2026-11-15: dataset-status, kinerja-realisasi, pencapaian-nko,
        // target-realisasi-per-indikator, dan kinerja-detail tetap di katalog
        // untuk Builder & halaman L3 (/dashboard/kpi-detail), sementara default
        // L1 berfokus pada ringkasan bisnis, tren NKO, dan status RAG.
        'staff_divisi:perencanaan-pengusahaan' => [
            'kpi-summary',
            'trend-chart',
            'kpi-rag-status',
        ],
        // Demo prototipe 2026-11-11 (instruksi user, bukan sign-off mentor/PIC):
        // staf Managemen Aset melihat Status Dataset lalu Stok Aset per UPT.
        'staff_divisi:managemen-aset' => [
            'dataset-status',
            'aset-upt-chart',
        ],
    ],

    // Fallback: role null / slug tidak dikenal -> KPI + chart saja.
    'default' => ['kpi-summary', 'trend-chart'],
];

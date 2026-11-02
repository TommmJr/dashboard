<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DivisionController;
use App\Http\Controllers\PerformanceChartController;
use App\Http\Controllers\PerformanceMetricController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
 Route aplikasi dashboard
*/

// Akses root aplikasi langsung diarahkan ke halaman login.
Route::redirect('/', '/login');

// Grup route untuk pengunjung yang belum login.
Route::middleware('guest')->group(function () {
    // Menampilkan form login.
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    // Memproses submit form login. throttle:login = max 5 percobaan per menit
    // per IP/email (didefinisikan di RateLimiter di AppServiceProvider).
    Route::post('/login', [AuthController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

// Grup route yang wajib login terlebih dahulu.
Route::middleware('auth')->group(function () {
    // Halaman utama dashboard: controller invokable, merakit widget
    // dengan prioritas tabel dashboard_layouts dan fallback config/dashboard_layouts.php.
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::middleware('can:dashboard.update')->group(function () {
        Route::put('/dashboard/layout', [DashboardController::class, 'updateLayout'])
            ->name('dashboard.layout.update');
        Route::delete('/dashboard/layout', [DashboardController::class, 'resetLayout'])
            ->name('dashboard.layout.reset');
    });
    // Proses logout via POST (bukan GET, supaya aman dari CSRF/clickjacking).
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    // Jalur data untuk grafik
    // Proteksi permission 'dashboard.view' agar data agregat hanya dapat
    // diakses oleh user yang memang berhak melihat dashboard kinerja.
    Route::middleware('can:dashboard.view')
        ->get('/api/performance-data', [PerformanceChartController::class, 'index'])
        ->name('performance.data');

    // Dataset CRUD. Index/show untuk semua pemegang dataset.view
    Route::middleware('can:dataset.view')->group(function () {
        Route::get('/datasets', [DatasetController::class, 'index'])->name('datasets.index');
    });
    Route::middleware('can:dataset.import')->group(function () {
        Route::get('/datasets/create', [DatasetController::class, 'create'])->name('datasets.create');
        Route::post('/datasets', [DatasetController::class, 'store'])->name('datasets.store');
        // Import 1-klik laporan KPI mentah dari mentor ke satu dataset owner.
        Route::post('/datasets/import-kpi-report', [DatasetController::class, 'importKpiReport'])
            ->name('datasets.kpi-import');
    });
    Route::middleware('can:dataset.view')
        ->get('/datasets/{dataset}', [DatasetController::class, 'show'])
        ->name('datasets.show');
    Route::middleware(['can:dataset.view', 'can:report.export'])
        ->get('/datasets/{dataset}/export', [DatasetController::class, 'export'])
        ->name('datasets.export');
    Route::middleware('can:dataset.update')
        ->patch('/datasets/{dataset}/archive', [DatasetController::class, 'archive'])
        ->name('datasets.archive');
    Route::middleware('can:dataset.delete')
        ->delete('/datasets/{dataset}', [DatasetController::class, 'destroy'])
        ->name('datasets.destroy');

    Route::middleware('can:dataset.update')->group(function () {
        Route::get('/performance-metrics/{metric}/edit', [PerformanceMetricController::class, 'edit'])
            ->name('performance_metrics.edit');
        Route::patch('/performance-metrics/{metric}', [PerformanceMetricController::class, 'update'])
            ->name('performance_metrics.update');
    });

    // Audit log (append-only): admin, management, editor (gate audit.view).
    Route::middleware('can:audit.view')
        ->get('/audit-logs', [AuditLogController::class, 'index'])
        ->name('audit-logs.index');

    // Fitur Reports dinonaktifkan sementara (2026-09-15) — seluruh route
    // /reports/* di-comment, controller & view dibiarkan ada untuk
    // diaktifkan lagi nanti. Pola sama dengan HIGH-05 (PerformanceUpload).
    // Route::middleware(['can:dataset.view', 'can:report.export'])->group(function () {
    //     Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    //     Route::get('/reports/datasets/{dataset}/validation', [ReportController::class, 'validationExport'])
    //         ->name('reports.validation-export');
    //     Route::get('/reports/datasets/summary', [ReportController::class, 'datasetSummaryExport'])
    //         ->name('reports.dataset-summary');
    // });
    // Route::middleware(['can:audit.view', 'can:report.export'])
    //     ->get('/reports/audit', [ReportController::class, 'auditExport'])
    //     ->name('reports.audit-export');

    // Area khusus ber-permission. Middleware 'can:...' memvalidasi gate
    // permission di backend; tanpa permission = HTTP 403.
    Route::middleware('can:user.view')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
    });

    Route::middleware('can:user.create')->group(function () {
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
    });

    Route::middleware('can:user.update')->group(function () {
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
    });

    Route::middleware('can:user.delete')
        ->delete('/users/{user}', [UserController::class, 'destroy'])
        ->name('users.destroy');

    // Division CRUD — hanya admin (semua permission user.* dimiliki admin).
    Route::middleware('can:user.view')->group(function () {
        Route::get('/divisions', [DivisionController::class, 'index'])->name('divisions.index');
    });
    Route::middleware('can:user.create')->group(function () {
        Route::get('/divisions/create', [DivisionController::class, 'create'])->name('divisions.create');
        Route::post('/divisions', [DivisionController::class, 'store'])->name('divisions.store');
    });
    Route::middleware('can:user.update')->group(function () {
        Route::get('/divisions/{division}/edit', [DivisionController::class, 'edit'])->name('divisions.edit');
        Route::patch('/divisions/{division}', [DivisionController::class, 'update'])->name('divisions.update');
    });
    Route::middleware('can:user.delete')
        ->delete('/divisions/{division}', [DivisionController::class, 'destroy'])
        ->name('divisions.destroy');
});

// Route fallback: user login -> redirect dashboard; user guest -> redirect login.
Route::fallback(function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
});

<?php

use App\Http\Controllers\AuthController;
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
    // Memproses submit form login.
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});
// Grup route yang wajib login terlebih dahulu.
Route::middleware('auth')->group(function () {
    // Halaman utama dashboard.
    Route::get('/dashboard', [AuthController::class, 'dashboard'])->name('dashboard');
    // Proses logout via POST (bukan GET, supaya aman dari CSRF/clickjacking).
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    // Jalur upload data performance (Vertical Slice #1)
    // Proteksi permission 'dataset.import' di backend. Tanpa permission
    // yang berhak mengunggah dataset, request ditolak dengan HTTP 403.
    Route::middleware('can:dataset.import')
        ->post('/performance/upload', [App\Http\Controllers\PerformanceUploadController::class, 'store'])
        ->name('performance.upload');

    // Jalur data untuk grafik (Vertical Slice #1)
    // Proteksi permission 'dashboard.view' agar data agregat hanya dapat
    // diakses oleh user yang memang berhak melihat dashboard kinerja.
    Route::middleware('can:dashboard.view')
        ->get('/api/performance-data', [\App\Http\Controllers\PerformanceChartController::class, 'index'])
        ->name('performance.data');

    // Area khusus ber-permission. Middleware 'can:...' memvalidasi gate
    // permission di backend; tanpa permission = HTTP 403.
    Route::middleware('can:user.view')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
    });
});

// Route fallback: URL yang tidak dikenal diarahkan ke login.
Route::fallback(function () {
    return redirect()->route('login');
});
    
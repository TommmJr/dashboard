<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Route aplikasi dashboard
|--------------------------------------------------------------------------
|
| Alur navigasi:
|   '/'          -> redirect ke halaman login.
|   /login       -> halaman form login (GET) + proses login (POST).
|   /dashboard   -> halaman utama setelah login (wajib autentikasi).
|   /logout      -> proses keluar dari aplikasi (POST).
|
| Middleware:
|   - 'guest' : hanya bisa diakses kalau BELUM login.
|   - 'auth'  : hanya bisa diakses kalau SUDAH login.
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
});

// Route fallback: URL yang tidak dikenal diarahkan ke login.
Route::fallback(function () {
    return redirect()->route('login');
});
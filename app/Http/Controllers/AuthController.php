<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Controller untuk menangani autentikasi (login / logout)
 * Semua halaman selain login dilindungi middleware 'auth' di routes/web.php.
 */
class AuthController extends Controller
{
    /**
     * Menampilkan halaman form login.
     *
     * @return View
     */
    public function create()
    {
        return view('auth.login');
    }

    /**
     * Memproses submit form login.
     *
     * @return RedirectResponse
     *
     * @throws ValidationException
     */
    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Cek kredensial. Tangkap juga opsi "remember me" (checkbox).
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // Kredensial salah -> kembalikan error ke form, jangan bocorkan
            // apakah email atau password yang salah.
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        // Regenerate session ID supaya session lama tidak bisa dibajak
        // (mencegah session fixation).
        $request->session()->regenerate();

        // redirect()->intended() mengarahkan kembali ke halaman yang tadi
        // diminta user sebelum dipaksa login (jika ada).
        return redirect()->intended(route('dashboard'));
    }

    /**
     * Logout: hapus session user, invalidasi session, lalu balik ke login.
     *
     * @return RedirectResponse
     */
    public function destroy(Request $request)
    {
        Auth::logout();

        // Bersihkan seluruh data session + token CSRF agar tidak dipakai ulang.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

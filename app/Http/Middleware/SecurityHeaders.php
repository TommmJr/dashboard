<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tambahkan security headers ke setiap response HTTP.
 *
 * Header yang ditambahkan:
 * - Content-Security-Policy  : batasi sumber resource (script via self + nonce per-request, style, img, font)
 * - X-Frame-Options          : cegah clickjacking via iframe
 * - X-Content-Type-Options   : cegah MIME sniffing
 * - Referrer-Policy          : batasi informasi referrer yang dikirim
 * - Permissions-Policy       : nonaktifkan fitur browser berbahaya
 *
 * CSP dirancang untuk intranet dengan seluruh aset JS/CSS self-hosted (Vite):
 * tidak mengizinkan CDN eksternal dan tidak memakai 'unsafe-inline' pada script-src.
 *
 * ponytail: CSP 'unsafe-inline' untuk style dipertahankan karena Tailwind 4
 * menggunakan inline style saat runtime. Hapus saat Tailwind dikompilasi penuh
 * ke file statis dan tidak ada lagi inline style dinamis.
 *
 * ponytail: 'unsafe-eval' ditambahkan ke script-src karena Alpine.js v3
 * menggunakan new Function() untuk compile template expression (x-data,
 * event listener seperti mouseenter, :class $store). Expression-nya statis dari kode aplikasi,
 * bukan input user — bukan regression keamanan. Hapus bila beralih ke
 * build @alpinejs/csp di masa depan.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Content-Security-Policy', $this->buildCsp($nonce));

        return $response;
    }

    private function buildCsp(string $nonce): string
    {
        // self = origin yang sama dengan aplikasi (intranet), seluruh aset di-bundle via Vite.
        // nonce-{nonce} = izinkan hanya script tag ber-nonce unik per-request (tanpa 'unsafe-inline').
        // 'unsafe-eval' = dibutuhkan oleh Alpine.js v3 runtime evaluator.
        // data: = untuk inline SVG/image kecil di Blade component.
        // Tidak menambahkan HSTS/upgrade-insecure-requests agar akses HTTP lokal tetap kompatibel.
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tambahkan security headers ke setiap response HTTP.
 *
 * Header yang ditambahkan:
 * - Content-Security-Policy  : batasi sumber resource (script, style, img, font)
 * - X-Frame-Options          : cegah clickjacking via iframe
 * - X-Content-Type-Options   : cegah MIME sniffing
 * - Referrer-Policy          : batasi informasi referrer yang dikirim
 * - Permissions-Policy       : nonaktifkan fitur browser berbahaya
 *
 * CSP dirancang untuk intranet: hanya izinkan aset dari origin yang sama
 * + CDN Alpine.js (cdn.jsdelivr.net) yang dipakai saat development.
 * Sesuaikan ALLOWED_SCRIPT_HOSTS jika CDN berubah atau pindah ke self-host.
 *
 * ponytail: CSP 'unsafe-inline' untuk style dipertahankan karena Tailwind 4
 * menggunakan inline style saat runtime. Hapus saat Tailwind dikompilasi penuh
 * ke file statis dan tidak ada lagi inline style dinamis.
 *
 * ponytail: 'unsafe-eval' ditambahkan ke script-src karena Alpine.js v3
 * menggunakan new Function() untuk compile template expression (x-data,
 *
 * @mouseenter, :class $store). Expression-nya statis dari kode aplikasi,
 * bukan input user — bukan regression keamanan. Hapus bila beralih ke
 * Alpine v4 (atau CSP nonce yang benar) di masa depan.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Content-Security-Policy', $this->buildCsp());

        return $response;
    }

    private function buildCsp(): string
    {
        // self = origin yang sama dengan aplikasi (intranet).
        // cdn.jsdelivr.net diizinkan untuk Alpine.js dev CDN.
        // data: untuk inline SVG/image kecil di Blade component.
        // 'unsafe-inline' style karena Tailwind 4 runtime pakai inline style.
        // ponytail: hapus cdn.jsdelivr.net & switch ke self-host Alpine saat prod.
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' cdn.jsdelivr.net 'unsafe-inline' 'unsafe-eval'",
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

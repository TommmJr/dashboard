{{--
    Halaman Login Portal Terpadu IBS UIT JBT.
    Form autentikasi dikirim via POST ke route login.store dengan perlindungan CSRF dan state loading Alpine.
--}}
@extends('layouts.guest')

@section('title', 'Masuk | IBS UIT JBT')

@section('content')
<div id="login-portal-shell" class="relative flex min-h-screen w-full flex-col items-center justify-center overflow-hidden bg-gradient-to-br from-[#0b6175] via-[#0e748b] to-[#16a3b8] px-4 py-10 sm:px-6">
    {{-- Ilustrasi Vektor Datar Latar Belakang Jaringan Transmisi --}}
    <svg
        id="login-vector-illustration"
        class="pointer-events-none absolute inset-0 h-full w-full select-none"
        viewBox="0 0 1440 900"
        preserveAspectRatio="xMidYMax slice"
        fill="none"
        aria-hidden="true"
    >
        <defs>
            <linearGradient id="portal-horizon-back" x1="0" y1="540" x2="1440" y2="900" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stop-color="#0b6175" stop-opacity="0.72" />
                <stop offset="100%" stop-color="#084857" stop-opacity="0.92" />
            </linearGradient>
            <linearGradient id="portal-horizon-front" x1="0" y1="660" x2="1440" y2="900" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stop-color="#084857" stop-opacity="0.95" />
                <stop offset="100%" stop-color="#063743" stop-opacity="1" />
            </linearGradient>
            <radialGradient id="portal-energy-glow" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(1140 190) rotate(90) scale(240 240)">
                <stop offset="0%" stop-color="#f1dc3a" stop-opacity="0.28" />
                <stop offset="55%" stop-color="#bce3e8" stop-opacity="0.1" />
                <stop offset="100%" stop-color="#16a3b8" stop-opacity="0" />
            </radialGradient>
            <pattern id="portal-grid-dots" width="40" height="40" patternUnits="userSpaceOnUse">
                <circle cx="2" cy="2" r="1.2" fill="#e6f4f6" fill-opacity="0.18" />
            </pattern>
        </defs>

        {{-- Subtle geometric grid and ambient energy halo on dark teal --}}
        <rect width="1440" height="900" fill="url(#portal-grid-dots)" />
        <circle cx="1140" cy="190" r="240" fill="url(#portal-energy-glow)" />
        <circle cx="1140" cy="190" r="96" stroke="#bce3e8" stroke-opacity="0.2" stroke-width="1.5" stroke-dasharray="6 8" />
        <circle cx="1140" cy="190" r="52" fill="#f1dc3a" fill-opacity="0.14" stroke="#f1dc3a" stroke-opacity="0.35" stroke-width="1.5" />

        {{-- Ambient top left geometric rings --}}
        <circle cx="210" cy="170" r="140" stroke="#e6f4f6" stroke-opacity="0.12" stroke-width="1.5" />
        <circle cx="210" cy="170" r="88" stroke="#bce3e8" stroke-opacity="0.16" stroke-width="1.5" />

        {{-- Clean energy horizon waves --}}
        <path
            d="M0 640 C240 570 460 680 720 630 C980 580 1210 660 1440 605 L1440 900 L0 900 Z"
            fill="#16a3b8"
            fill-opacity="0.28"
        />
        <path
            d="M0 700 C300 645 520 745 780 690 C1040 635 1250 725 1440 680 L1440 900 L0 900 Z"
            fill="url(#portal-horizon-back)"
        />
        <path
            d="M0 770 C280 735 540 810 830 760 C1080 718 1270 785 1440 755 L1440 900 L0 900 Z"
            fill="url(#portal-horizon-front)"
        />

        {{-- Left transmission tower silhouette --}}
        <g stroke="#bce3e8" stroke-opacity="0.58" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M160 760 L194 420 L218 420 L252 760" />
            <path d="M178 580 L234 580" />
            <path d="M185 505 L227 505" />
            <path d="M168 670 L244 670" />
            <path d="M178 580 L244 670" />
            <path d="M234 580 L168 670" />
            <path d="M185 505 L234 580" />
            <path d="M227 505 L178 580" />
            <path d="M194 420 L206 388 L218 420" />
            {{-- Cross arms --}}
            <path d="M132 465 L280 465" />
            <path d="M132 465 L192 444" />
            <path d="M280 465 L220 444" />
            <path d="M144 532 L268 532" />
            <path d="M144 532 L187 512" />
            <path d="M268 532 L225 512" />
        </g>

        {{-- Right transmission tower silhouette --}}
        <g stroke="#bce3e8" stroke-opacity="0.58" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M1188 760 L1222 435 L1246 435 L1280 760" />
            <path d="M1206 590 L1262 590" />
            <path d="M1213 515 L1255 515" />
            <path d="M1196 675 L1272 675" />
            <path d="M1206 590 L1272 675" />
            <path d="M1262 590 L1196 675" />
            <path d="M1213 515 L1262 590" />
            <path d="M1255 515 L1206 590" />
            <path d="M1222 435 L1234 402 L1246 435" />
            {{-- Cross arms --}}
            <path d="M1160 480 L1308 480" />
            <path d="M1160 480 L1220 458" />
            <path d="M1308 480 L1248 458" />
            <path d="M1172 544 L1296 544" />
            <path d="M1172 544 L1215 524" />
            <path d="M1296 544 L1253 524" />
        </g>

        {{-- Catenary transmission conductors spanning background --}}
        <path d="M0 442 Q72 478 132 465" stroke="#e6f4f6" stroke-opacity="0.34" stroke-width="1.5" />
        <path d="M0 508 Q76 546 144 532" stroke="#e6f4f6" stroke-opacity="0.28" stroke-width="1.5" />
        <path d="M280 465 Q720 555 1160 480" stroke="#e6f4f6" stroke-opacity="0.28" stroke-width="1.5" />
        <path d="M268 532 Q720 618 1172 544" stroke="#bce3e8" stroke-opacity="0.24" stroke-width="1.5" />
        <path d="M1308 480 Q1378 492 1440 468" stroke="#e6f4f6" stroke-opacity="0.34" stroke-width="1.5" />
        <path d="M1296 544 Q1372 558 1440 536" stroke="#e6f4f6" stroke-opacity="0.28" stroke-width="1.5" />

        {{-- Geometric grid nodes --}}
        <circle cx="132" cy="465" r="4" fill="#f1dc3a" fill-opacity="0.75" />
        <circle cx="280" cy="465" r="4" fill="#e6f4f6" fill-opacity="0.85" />
        <circle cx="1160" cy="480" r="4" fill="#e6f4f6" fill-opacity="0.85" />
        <circle cx="1308" cy="480" r="4" fill="#f1dc3a" fill-opacity="0.75" />
        <circle cx="340" cy="240" r="5" fill="#bce3e8" fill-opacity="0.45" />
        <circle cx="1060" cy="310" r="5" fill="#bce3e8" fill-opacity="0.45" />
        <circle cx="960" cy="175" r="4" fill="#f1dc3a" fill-opacity="0.55" />
        <path d="M340 240 L410 205 L485 245" stroke="#bce3e8" stroke-opacity="0.24" stroke-width="1.2" />
        <path d="M960 175 L1060 310 L1140 190" stroke="#bce3e8" stroke-opacity="0.22" stroke-width="1.2" />
    </svg>

    {{-- Centered Login Window Card --}}
    <main id="login-portal-card" class="relative z-10 w-full max-w-[440px] overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-white/25" x-data="{ loading: false }">
        {{-- Minimalist Window Top Accent Bar --}}
        <div class="flex items-center justify-between border-b border-slate-100 bg-[#f4f8fa] px-5 py-3">
            <div class="flex items-center gap-1.5" aria-hidden="true">
                <span class="h-2.5 w-2.5 rounded-full bg-[#0b6175]"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-[#16a3b8]"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-[#bce3e8]"></span>
            </div>
            <span class="text-[11px] font-semibold tracking-wide text-slate-600">Portal Akses Terpadu</span>
        </div>

        {{-- Card Content --}}
        <div class="px-6 pt-7 pb-7 sm:px-8">
            {{-- Top Center Brand Emblem & Heading --}}
            <header class="text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#e6f4f6] text-[#0b6175] ring-4 ring-[#f4f8fa]">
                    <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="9.5" cy="7.5" r="3" />
                        <path d="M4 18.5a5.5 5.5 0 0 1 11 0" stroke-linecap="round" />
                        <path d="M18.5 4.5L15 10h3.5l-1 5.5L21 10h-3.5l1-5.5Z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
                <p class="mt-4 text-xs font-bold tracking-widest text-[#0b6175] uppercase">IBS UIT JBT</p>
                <h1 class="mt-1.5 text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Masuk ke Ruang Kerja</h1>
                <p class="mt-1 text-xs font-medium text-slate-600">Unit Induk Transmisi Jawa Bagian Tengah</p>
            </header>

            {{-- Session Flash Alerts --}}
            @if (session('status'))
                <div role="status" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-medium text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('success'))
                <div role="status" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-medium text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('warning'))
                <div role="alert" class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-medium text-amber-800">
                    {{ session('warning') }}
                </div>
            @endif

            @if (session('error'))
                <div role="alert" class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-medium text-rose-800">
                    {{ session('error') }}
                </div>
            @endif

            {{-- Authentication Form --}}
            <form method="POST" action="{{ route('login.store') }}" aria-label="Sign in" @submit="loading = true" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="email" class="block text-xs font-semibold text-slate-700">Email</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        autocomplete="email"
                        required
                        autofocus
                        placeholder="nama@pln.co.id"
                        class="mt-1.5 block h-11 w-full rounded-xl border border-slate-200 bg-slate-50/70 px-4 text-sm text-slate-900 placeholder:text-slate-400 transition focus:border-[#0b6175] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#16a3b8]/25"
                    >
                    @error('email')
                        <p class="mt-1.5 text-xs font-medium text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-xs font-semibold text-slate-700">Kata Sandi</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        placeholder="Masukkan kata sandi"
                        class="mt-1.5 block h-11 w-full rounded-xl border border-slate-200 bg-slate-50/70 px-4 text-sm text-slate-900 placeholder:text-slate-400 transition focus:border-[#0b6175] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#16a3b8]/25"
                    >
                    @error('password')
                        <p class="mt-1.5 text-xs font-medium text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-between pt-0.5">
                    <label for="remember" class="inline-flex cursor-pointer items-center gap-2.5">
                        <input
                            id="remember"
                            name="remember"
                            type="checkbox"
                            {{ old('remember') ? 'checked' : '' }}
                            class="h-4 w-4 rounded border-slate-300 text-[#0b6175] focus:ring-[#16a3b8]"
                        >
                        <span class="text-xs font-medium text-slate-600">Ingat sesi saya</span>
                    </label>
                </div>

                <button
                    type="submit"
                    :disabled="loading"
                    class="flex h-11 w-full items-center justify-center rounded-xl bg-[#0b6175] px-4 text-sm font-semibold text-white shadow-xs transition hover:bg-[#0e748b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8] focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span x-show="!loading">Masuk</span>
                    <span x-show="loading" x-cloak>Memproses...</span>
                </button>
            </form>
        </div>

        {{-- Clean Footer (Zero Dashes) --}}
        <footer class="border-t border-slate-100 bg-[#f4f8fa] px-6 py-3.5 text-center">
            <p class="text-[11px] font-semibold text-slate-600">Integrated Business System</p>
            <p class="mt-0.5 text-[11px] font-medium text-slate-500">PT PLN (Persero) Unit Induk Transmisi Jawa Bagian Tengah</p>
        </footer>
    </main>
</div>
@endsection

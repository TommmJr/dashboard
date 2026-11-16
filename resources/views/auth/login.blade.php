{{--
    Halaman Login Portal Terpadu IBS UIT JBT.
    Form autentikasi dikirim via POST ke route login.store dengan perlindungan CSRF,
    CSP nonce pada script kanvas partikel, dan interaktivitas Alpine.js tanpa inline handler.
--}}
@extends('layouts.guest')

@section('title', 'Masuk | IBS UIT JBT')

@push('styles')
<style>
    @keyframes dashMove {
        to {
            stroke-dashoffset: -120;
        }
    }

    @keyframes portalShimmer {
        0% {
            transform: translateX(-50%);
        }
        100% {
            transform: translateX(0%);
        }
    }

    @keyframes portalPulseGlow {
        0%, 100% {
            opacity: 0.3;
            transform: scale(1);
        }
        50% {
            opacity: 0.55;
            transform: scale(1.06);
        }
    }

    .animate-energy-conduit {
        stroke-dasharray: 8 16;
        animation: dashMove 5s linear infinite;
    }

    .animate-energy-fast {
        stroke-dasharray: 6 12;
        animation: dashMove 2.5s linear infinite;
    }

    .animate-portal-shimmer {
        animation: portalShimmer 3s linear infinite;
    }

    .animate-portal-glow {
        animation: portalPulseGlow 5s ease-in-out infinite;
    }

    @media (prefers-reduced-motion: reduce) {
        .animate-energy-conduit,
        .animate-energy-fast,
        .animate-portal-shimmer,
        .animate-portal-glow {
            animation: none !important;
        }
    }
</style>
@endpush

@section('content')
<div
    id="login-portal-shell"
    x-data="{ loading: false, showPassword: false }"
    class="relative flex min-h-screen w-full flex-col items-center justify-center overflow-x-clip overflow-y-auto bg-gradient-to-br from-[#084857] via-[#0b6175] to-[#0e748b] px-4 py-10 sm:px-6"
>
    {{-- Live Energy Particle Canvas Layer --}}
    <canvas
        id="energyParticlesCanvas"
        class="pointer-events-none absolute inset-0 z-0 h-full w-full opacity-55"
        aria-hidden="true"
    ></canvas>

    {{-- Ambient Glow Orbs --}}
    <div
        class="animate-portal-glow pointer-events-none absolute -top-40 -left-40 h-[520px] w-[520px] rounded-full bg-[#16a3b8]/20 blur-[120px]"
        aria-hidden="true"
    ></div>
    <div
        class="animate-portal-glow pointer-events-none absolute top-1/4 -right-32 h-[460px] w-[460px] rounded-full bg-[#f1dc3a]/15 blur-[130px]"
        aria-hidden="true"
    ></div>
    <div
        class="pointer-events-none absolute -bottom-36 left-1/3 h-[460px] w-[460px] rounded-full bg-[#063743]/75 blur-[110px]"
        aria-hidden="true"
    ></div>

    {{-- Ilustrasi Vektor Datar Latar Belakang Jaringan Transmisi --}}
    <svg
        id="login-vector-illustration"
        class="pointer-events-none absolute inset-0 z-[1] h-full w-full select-none"
        viewBox="0 0 1440 900"
        preserveAspectRatio="xMidYMax slice"
        fill="none"
        aria-hidden="true"
    >
        <defs>
            <linearGradient id="portal-horizon-back" x1="0" y1="540" x2="1440" y2="900" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stop-color="#0b6175" stop-opacity="0.82" />
                <stop offset="100%" stop-color="#084857" stop-opacity="0.96" />
            </linearGradient>
            <linearGradient id="portal-horizon-front" x1="0" y1="660" x2="1440" y2="900" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stop-color="#084857" stop-opacity="0.95" />
                <stop offset="100%" stop-color="#063743" stop-opacity="1" />
            </linearGradient>
            <radialGradient id="portal-energy-glow" cx="0" cy="0" r="1" gradientUnits="userSpaceOnUse" gradientTransform="translate(1140 190) rotate(90) scale(260 260)">
                <stop offset="0%" stop-color="#f1dc3a" stop-opacity="0.32" />
                <stop offset="48%" stop-color="#16a3b8" stop-opacity="0.16" />
                <stop offset="100%" stop-color="#0e748b" stop-opacity="0" />
            </radialGradient>
            <pattern id="portal-grid-dots" width="44" height="44" patternUnits="userSpaceOnUse">
                <circle cx="2" cy="2" r="1.25" fill="#e6f4f6" fill-opacity="0.18" />
                <path d="M 44 0 L 0 0 0 44" fill="none" stroke="#bce3e8" stroke-width="0.5" stroke-opacity="0.06" />
            </pattern>
            <filter id="glow-gold" x="-20%" y="-20%" width="140%" height="140%">
                <feGaussianBlur stdDeviation="2.5" result="blur" />
                <feComposite in="SourceGraphic" in2="blur" operator="over" />
            </filter>
        </defs>

        {{-- Grid & Substation Rings --}}
        <rect width="1440" height="900" fill="url(#portal-grid-dots)" />
        <circle cx="1140" cy="190" r="260" fill="url(#portal-energy-glow)" />
        <circle cx="1140" cy="190" r="116" stroke="#bce3e8" stroke-opacity="0.22" stroke-width="1.5" stroke-dasharray="6 8" />
        <circle cx="1140" cy="190" r="78" stroke="#f1dc3a" stroke-opacity="0.35" stroke-width="1.5" stroke-dasharray="4 6" />
        <circle cx="1140" cy="190" r="46" fill="#f1dc3a" fill-opacity="0.15" stroke="#f1dc3a" stroke-opacity="0.52" stroke-width="1.6" />
        <circle cx="1140" cy="190" r="7.5" fill="#f1dc3a" filter="url(#glow-gold)" />

        {{-- Top Left Ambient Radial Node --}}
        <circle cx="210" cy="170" r="146" stroke="#e6f4f6" stroke-opacity="0.1" stroke-width="1.5" />
        <circle cx="210" cy="170" r="92" stroke="#bce3e8" stroke-opacity="0.18" stroke-width="1.2" stroke-dasharray="5 7" />
        <circle cx="210" cy="170" r="6" fill="#16a3b8" fill-opacity="0.6" />

        {{-- Rolling Horizon Waves --}}
        <path d="M0 640 C240 570 460 680 720 630 C980 580 1210 660 1440 605 L1440 900 L0 900 Z" fill="#16a3b8" fill-opacity="0.22" />
        <path d="M0 700 C300 645 520 745 780 690 C1040 635 1250 725 1440 680 L1440 900 L0 900 Z" fill="url(#portal-horizon-back)" />
        <path d="M0 770 C280 735 540 810 830 760 C1080 718 1270 785 1440 755 L1440 900 L0 900 Z" fill="url(#portal-horizon-front)" />

        {{-- Left 500kV Tower Lattice --}}
        <g stroke="#bce3e8" stroke-opacity="0.64" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M160 760 L194 420 L218 420 L252 760" />
            <path d="M178 580 L234 580" />
            <path d="M185 505 L227 505" />
            <path d="M168 670 L244 670" />
            <path d="M178 580 L244 670" />
            <path d="M234 580 L168 670" />
            <path d="M185 505 L234 580" />
            <path d="M227 505 L178 580" />
            <path d="M194 420 L206 388 L218 420" />
            <path d="M132 465 L280 465" />
            <path d="M132 465 L192 444" />
            <path d="M280 465 L220 444" />
            <path d="M144 532 L268 532" />
            <path d="M144 532 L187 512" />
            <path d="M268 532 L225 512" />
        </g>

        {{-- Right 500kV Tower Lattice --}}
        <g stroke="#bce3e8" stroke-opacity="0.64" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M1188 760 L1222 435 L1246 435 L1280 760" />
            <path d="M1206 590 L1262 590" />
            <path d="M1213 515 L1255 515" />
            <path d="M1196 675 L1272 675" />
            <path d="M1206 590 L1272 675" />
            <path d="M1262 590 L1196 675" />
            <path d="M1213 515 L1262 590" />
            <path d="M1255 515 L1206 590" />
            <path d="M1222 435 L1234 402 L1246 435" />
            <path d="M1160 480 L1308 480" />
            <path d="M1160 480 L1220 458" />
            <path d="M1308 480 L1248 458" />
            <path d="M1172 544 L1296 544" />
            <path d="M1172 544 L1215 524" />
            <path d="M1296 544 L1253 524" />
        </g>

        {{-- Transmission Catenary Conductors & Animated Power Flow --}}
        <path d="M0 442 Q72 478 132 465" stroke="#e6f4f6" stroke-opacity="0.35" stroke-width="1.6" />
        <path d="M0 508 Q76 546 144 532" stroke="#e6f4f6" stroke-opacity="0.28" stroke-width="1.6" />
        <path d="M280 465 Q720 555 1160 480" stroke="#e6f4f6" stroke-opacity="0.28" stroke-width="1.8" />
        <path d="M268 532 Q720 618 1172 544" stroke="#bce3e8" stroke-opacity="0.24" stroke-width="1.8" />
        <path d="M1308 480 Q1378 492 1440 468" stroke="#e6f4f6" stroke-opacity="0.35" stroke-width="1.6" />
        <path d="M1296 544 Q1372 558 1440 536" stroke="#e6f4f6" stroke-opacity="0.28" stroke-width="1.6" />

        <path d="M280 465 Q720 555 1160 480" stroke="#f1dc3a" stroke-opacity="0.82" stroke-width="2" class="animate-energy-conduit" filter="url(#glow-gold)" />
        <path d="M268 532 Q720 618 1172 544" stroke="#16a3b8" stroke-opacity="0.85" stroke-width="2" class="animate-energy-fast" />

        {{-- Grid Bus Nodes & Topology Vectors --}}
        <circle cx="132" cy="465" r="4.5" fill="#f1dc3a" />
        <circle cx="280" cy="465" r="4.5" fill="#e6f4f6" />
        <circle cx="1160" cy="480" r="4.5" fill="#e6f4f6" />
        <circle cx="1308" cy="480" r="4.5" fill="#f1dc3a" />
        <circle cx="340" cy="240" r="4.5" fill="#bce3e8" fill-opacity="0.6" />
        <circle cx="1060" cy="310" r="4.5" fill="#bce3e8" fill-opacity="0.6" />
        <circle cx="960" cy="175" r="4.5" fill="#f1dc3a" fill-opacity="0.8" />
        <path d="M340 240 L410 205 L485 245" stroke="#bce3e8" stroke-opacity="0.32" stroke-width="1.2" stroke-dasharray="3 3" />
        <path d="M960 175 L1060 310 L1140 190" stroke="#bce3e8" stroke-opacity="0.32" stroke-width="1.2" stroke-dasharray="3 3" />
    </svg>

    {{-- Centered Glass Portal Card --}}
    <main
        id="login-portal-card"
        class="relative z-10 w-full max-w-[440px] overflow-hidden rounded-3xl border border-white/60 bg-white/95 shadow-[0_25px_60px_-15px_rgba(6,55,67,0.5)] backdrop-blur-xl"
    >
        {{-- Decorative Shimmer Accent Bar --}}
        <div class="relative h-1.5 w-full overflow-hidden bg-slate-100" aria-hidden="true">
            <div class="animate-portal-shimmer absolute inset-y-0 left-0 w-[200%] bg-gradient-to-r from-[#0b6175] via-[#16a3b8] to-[#f1dc3a]"></div>
        </div>

        {{-- Minimalist Window Top Accent Bar --}}
        <div class="flex items-center justify-between border-b border-slate-100 bg-[#f4f8fa]/90 px-6 py-3">
            <div class="flex items-center gap-2" aria-hidden="true">
                <span class="h-2.5 w-2.5 rounded-full bg-[#0b6175]"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-[#16a3b8]"></span>
                <span class="h-2.5 w-2.5 rounded-full bg-[#bce3e8]"></span>
            </div>
            <div class="flex items-center gap-1.5 text-[11px] font-semibold tracking-wider text-[#084857] uppercase">
                <svg class="h-3.5 w-3.5 text-[#0b6175]" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" />
                </svg>
                <span>Portal Autentikasi</span>
            </div>
        </div>

        <div class="px-7 pt-7 pb-8 sm:px-8">
            {{-- Top Center Brand Emblem --}}
            <header class="text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-[#e6f4f6] to-[#bce3e8]/45 text-[#0b6175] shadow-inner ring-4 ring-[#f4f8fa]">
                    <svg class="h-8 w-8 text-[#0b6175]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <circle cx="9.5" cy="7.5" r="3" />
                        <path d="M4 18.5a5.5 5.5 0 0 1 11 0" stroke-linecap="round" />
                        <path d="M18.5 4.5L15 10h3.5l-1 5.5L21 10h-3.5l1-5.5Z" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>

                <div class="mt-4 inline-flex items-center gap-1.5 rounded-full border border-[#bce3e8]/70 bg-[#e6f4f6] px-3 py-1">
                    <span class="h-1.5 w-1.5 rounded-full bg-[#0b6175]" aria-hidden="true"></span>
                    <p class="text-[11px] font-bold tracking-widest text-[#0b6175] uppercase">IBS UIT JBT</p>
                </div>

                <h1 class="mt-2.5 text-xl font-bold tracking-tight text-slate-900">Masuk ke Ruang Kerja</h1>
                <p class="mt-1 text-xs font-medium text-slate-500">Unit Induk Transmisi Jawa Bagian Tengah</p>
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
                    <div class="group/field relative mt-1.5">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 transition-colors group-focus-within/field:text-[#0b6175]">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                            </svg>
                        </div>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            enterkeyhint="next"
                            required
                            autofocus
                            placeholder="nama@pln.co.id"
                            @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                            class="block h-11 w-full rounded-xl border border-slate-200 bg-slate-50/80 pl-10 pr-4 text-sm text-slate-900 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-[#0b6175] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#16a3b8]/25"
                        >
                    </div>
                    @error('email')
                        <p id="email-error" class="mt-1.5 text-xs font-medium text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-xs font-semibold text-slate-700">Kata Sandi</label>
                    <div class="group/field relative mt-1.5">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 transition-colors group-focus-within/field:text-[#0b6175]">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <input
                            id="password"
                            name="password"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="current-password"
                            enterkeyhint="done"
                            required
                            placeholder="Masukkan kata sandi"
                            @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                            class="block h-11 w-full rounded-xl border border-slate-200 bg-slate-50/80 pl-10 pr-11 text-sm text-slate-900 placeholder:text-slate-400 transition hover:border-slate-300 focus:border-[#0b6175] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#16a3b8]/25"
                        >
                        <button
                            type="button"
                            @click="showPassword = !showPassword"
                            :aria-pressed="showPassword.toString()"
                            :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Lihat kata sandi'"
                            class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 transition-colors hover:text-[#0b6175] focus-visible:outline-none focus-visible:text-[#0b6175]"
                        >
                            <svg x-show="!showPassword" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            <svg x-show="showPassword" x-cloak class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                            </svg>
                        </button>
                    </div>
                    @error('password')
                        <p id="password-error" class="mt-1.5 text-xs font-medium text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-between pt-0.5">
                    <label for="remember" class="inline-flex cursor-pointer select-none items-center gap-2.5">
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
                    class="group relative flex h-11 w-full items-center justify-center overflow-hidden rounded-xl bg-[#0b6175] px-4 text-sm font-semibold text-white shadow-xs transition hover:bg-[#0e748b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#16a3b8] focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span class="absolute top-0 -left-full h-full w-full bg-gradient-to-r from-transparent via-white/20 to-transparent transition-all duration-700 group-hover:left-full" aria-hidden="true"></span>
                    <span x-show="!loading" class="relative flex items-center gap-2">
                        <span>Masuk</span>
                        <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </span>
                    <span x-show="loading" x-cloak class="relative flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin text-white" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                        </svg>
                        <span>Memproses...</span>
                    </span>
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

@push('scripts')
<script @cspNonce>
    (function () {
        const canvas = document.getElementById('energyParticlesCanvas');
        if (!canvas || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
            return;
        }

        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }

        let width = 0;
        let height = 0;
        const colors = ['#f1dc3a', '#16a3b8', '#bce3e8', '#e6f4f6'];
        const particles = [];

        function resizeCanvas() {
            width = canvas.width = canvas.parentElement ? canvas.parentElement.clientWidth : window.innerWidth;
            height = canvas.height = canvas.parentElement ? canvas.parentElement.clientHeight : window.innerHeight;
        }

        function resetParticle(p) {
            p.x = Math.random() * width;
            p.y = Math.random() * height;
            p.size = Math.random() * 2 + 0.8;
            p.speedX = (Math.random() - 0.4) * 0.45;
            p.speedY = -(Math.random() * 0.65 + 0.2);
            p.alpha = Math.random() * 0.55 + 0.2;
            p.color = colors[Math.floor(Math.random() * colors.length)];
        }

        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);

        for (let i = 0; i < 40; i++) {
            const p = {};
            resetParticle(p);
            particles.push(p);
        }

        function animate() {
            ctx.clearRect(0, 0, width, height);
            for (let i = 0; i < particles.length; i++) {
                const p = particles[i];
                p.x += p.speedX;
                p.y += p.speedY;
                if (p.y < -10 || p.x < -10 || p.x > width + 10) {
                    resetParticle(p);
                    p.y = height + 10;
                }
                ctx.save();
                ctx.globalAlpha = p.alpha;
                ctx.fillStyle = p.color;
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
                ctx.fill();
                ctx.restore();
            }
            window.requestAnimationFrame(animate);
        }

        window.requestAnimationFrame(animate);
    })();
</script>
@endpush

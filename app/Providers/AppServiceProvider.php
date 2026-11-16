<?php

namespace App\Providers;

use App\Http\Controllers\DatasetController;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->configureLazyLoading();
        $this->registerLoginRateLimiter();
        $this->registerEndpointRateLimiters();
        $this->registerPermissionGates();
        $this->registerAuditListeners();
        $this->registerSidebarComposer();
        $this->registerBladeCspDirective();
    }

    private function registerBladeCspDirective(): void
    {
        Blade::directive('cspNonce', function (): string {
            return '<?php if ($__cspNonce = \\Illuminate\\Support\\Facades\\Vite::cspNonce()): ?>nonce="<?php echo e($__cspNonce); ?>"<?php endif; ?>';
        });
    }

    private function registerSidebarComposer(): void
    {
        View::composer('components.sidebar', function ($view): void {
            $view->with('canUploadAny', DatasetController::canUploadAny(Auth::user()));
        });
    }

    /**
     * Lempar exception saat lazy loading di non-production; log warning di
     * production. Tangkap N+1 lebih awal sebelum sampai ke user.
     */
    private function configureLazyLoading(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Model::handleLazyLoadingViolationUsing(function ($model, $relation) {
            $message = "Lazy loading violation: {$model}::{$relation}";

            if (app()->isProduction()) {
                Log::warning($message);

                return;
            }

            throw new LazyLoadingViolationException($model, $relation);
        });
    }

    /**
     * Rate limiter untuk POST /login: maksimal 5 percobaan per menit
     * per kombinasi email+IP. Mencegah brute force password.
     */
    private function registerLoginRateLimiter(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(
                (string) $request->input('email').'|'.$request->ip(),
            );
        });
    }

    /**
     * Rate limiter endpoint sensitif berbasis user ID (fallback IP untuk guest)
     * untuk membatasi penyalahgunaan API grafik, spam mutasi layout, dan DoS
     * upload/import/sinkronisasi dataset berat.
     */
    private function registerEndpointRateLimiters(): void
    {
        $limiters = [
            'chart-api' => 60,
            'layout-mutation' => 20,
            'dataset-heavy' => 10,
        ];

        foreach ($limiters as $name => $maxPerMinute) {
            RateLimiter::for($name, function (Request $request) use ($maxPerMinute): Limit {
                $key = (string) ($request->user()?->id ?: $request->ip());

                return Limit::perMinute($maxPerMinute)
                    ->by($key)
                    ->response(fn (Request $req, array $headers): SymfonyResponse => $this->rateLimitResponse($req, $headers));
            });
        }
    }

    /**
     * Respons seragam HTTP 429 berbahasa Indonesia dengan header Retry-After.
     *
     * @param  array<string, int|string>  $headers
     */
    private function rateLimitResponse(Request $request, array $headers): SymfonyResponse
    {
        $message = 'Terlalu banyak permintaan. Silakan coba beberapa saat lagi.';

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => $message], 429, $headers);
        }

        return response($message, 429, $headers);
    }

    /**
     * Catat LOGIN/LOGOUT/LOGIN_FAILED ke audit log (append-only) via auth events.
     */
    private function registerAuditListeners(): void
    {
        Event::listen(
            Login::class,
            fn (Login $event) => AuditLog::record(
                AuditLog::ACTION_LOGIN,
                null,
                null,
                null,
                $event->user instanceof User ? $event->user->id : (is_numeric($event->user->getAuthIdentifier()) ? (int) $event->user->getAuthIdentifier() : null),
            ),
        );

        Event::listen(
            Logout::class,
            fn (Logout $event) => AuditLog::record(
                AuditLog::ACTION_LOGOUT,
                null,
                null,
                null,
                $event->user instanceof User ? $event->user->id : (is_numeric($event->user?->getAuthIdentifier()) ? (int) $event->user->getAuthIdentifier() : null),
            ),
        );

        Event::listen(
            Failed::class,
            function (Failed $event) {
                $rawCredentials = is_array($event->credentials) ? $event->credentials : [];

                $sanitizedCredentials = [];
                $identity = '';

                foreach ($rawCredentials as $key => $value) {
                    $lowerKey = strtolower((string) $key);

                    if (in_array($lowerKey, ['email', 'username', 'login', 'identity', 'user'], true)) {
                        if ($identity === '' && is_scalar($value)) {
                            $identity = (string) $value;
                        }
                    }

                    // Exclude any key that looks like a password, secret, token, key, or pin
                    if (preg_match('/(pass|secret|token|key|pin|cred)/i', $lowerKey) === 1) {
                        continue;
                    }

                    if (is_scalar($value) || is_null($value)) {
                        $sanitizedCredentials[$key] = $value;
                    }
                }

                if ($identity === '' && isset($sanitizedCredentials['email']) && is_scalar($sanitizedCredentials['email'])) {
                    $identity = (string) $sanitizedCredentials['email'];
                }

                $request = request();

                $newValues = array_merge([
                    'email' => $identity,
                    'ip' => (string) ($request?->ip() ?? '127.0.0.1'),
                    'user_agent' => mb_substr((string) ($request?->userAgent() ?? ''), 0, 255),
                ], $sanitizedCredentials);

                AuditLog::record(
                    AuditLog::ACTION_LOGIN_FAILED,
                    null,
                    null,
                    $newValues,
                    $event->user instanceof User ? $event->user->id : (is_numeric($event->user?->getAuthIdentifier()) ? (int) $event->user->getAuthIdentifier() : null),
                );
            },
        );
    }

    /**
     * Registrasi gate Laravel untuk setiap permission granular.
     */
    private function registerPermissionGates(): void
    {
        foreach (Permission::names() as $name) {
            Gate::define($name, fn (User $user): bool => $user->hasPermission($name));
        }
    }
}

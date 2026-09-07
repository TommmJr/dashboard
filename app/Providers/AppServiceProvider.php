<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPermissionGates();
        $this->registerAuditListeners();
    }

    /**
     * Catat LOGIN/LOGOUT ke audit log (append-only) via auth events.
     */
    private function registerAuditListeners(): void
    {
        Event::listen(
            Login::class,
            fn (Login $event) => AuditLog::record(
                AuditLog::ACTION_LOGIN, null, null, null, $event->user->id,
            ),
        );

        Event::listen(
            Logout::class,
            fn (Logout $event) => AuditLog::record(
                AuditLog::ACTION_LOGOUT, null, null, null, $event->user?->id,
            ),
        );
    }

    /**
     * Registrasi gate Laravel untuk setiap permission granular.
     *
     * Nama gate = nama permission (mis. 'dataset.import'), jadi bisa dipakai
     * seragam di semua lapisan: middleware 'can:dataset.import' di route,
     * Gate::authorize() di controller, dan @can('dataset.import') di Blade.
     *
     * Ability yang BUKAN permission terdaftar (mis. nama policy model di
     * tahap berikutnya) dibiarkan lewat tanpa didefinisikan di sini.
     */
    private function registerPermissionGates(): void
    {
        foreach (Permission::names() as $name) {
            Gate::define($name, fn (User $user): bool => $user->hasPermission($name));
        }
    }
}

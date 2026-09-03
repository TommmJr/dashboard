<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
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

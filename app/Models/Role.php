<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Role - peran pengguna aplikasi.
 *
 * Empat peran sesuai [[User Roles]] (vault):
 * - admin            : super admin sistem, semua permission.
 * - management       : penonton utama dashboard.
 * - editor_dashboard : bidang IT, boleh atur visual dashboard, TIDAK boleh
 *                      menyentuh data (read-only mutlak).
 * - staff_divisi     : penyedia data untuk divisinya sendiri.
 */
class Role extends Model
{
    public const ADMIN = 'admin';
    public const MANAGEMENT = 'management';
    public const EDITOR_DASHBOARD = 'editor_dashboard';
    public const STAFF_DIVISI = 'staff_divisi';

    protected $fillable = ['name', 'slug', 'description'];

    /**
     * Permission-permission yang dimiliki role ini.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /**
     * User-user dengan role ini.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Cek apakah role ini punya sebuah permission.
     * Relasi di-memoize per instance sehingga cek berulang tidak query ulang.
     */
    public function hasPermission(string $name): bool
    {
        return $this->permissions->contains('name', $name);
    }
}

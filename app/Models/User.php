<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// role_id dan division_id sengaja TIDAK masuk fillable untuk mencegah
// privilege escalation via mass assignment. Keduanya hanya di-set secara
// eksplisit dari kode yang sudah tervalidasi otorisasinya.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable;

    /** @var array<int, string> */
    protected array $auditExclude = ['password', 'remember_token'];

    /**
     * Get the attributes that should be cast.
     *
     * 'password' => 'hashed' -> setiap kali password di-set, Laravel otomatis
     *                           meng-hash-nya. Jadi tidak perlu bcrypt() manual.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Role (peran) user ini. Setiap user punya tepat satu role;
     * null berarti belum di-assign (tidak punya permission apa pun).
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * Cek apakah user punya role dengan slug tertentu.
     */
    public function hasRole(string $slug): bool
    {
        return $this->role?->slug === $slug;
    }

    /**
     * Cek apakah user punya sebuah permission, lewat role-nya.
     * Ini satu-satunya sumber kebenaran otorisasi; dipanggil oleh gate
     * yang diregistrasi di AppServiceProvider. Authorization selalu
     * divalidasi di backend - frontend hanya menyembunyikan fitur.
     */
    public function hasPermission(string $name): bool
    {
        return $this->role?->hasPermission($name) ?? false;
    }
}

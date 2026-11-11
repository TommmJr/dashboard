<?php

namespace App\Models;

use App\Models\Builders\AuditLogBuilder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Audit log append-only.
 *
 * - Tidak ada updated_at: entri hanya dibuat, tidak pernah diubah.
 * - Jalur INSTANCE: update()/delete() di-override jadi `never` dan melempar
 *   LogicException.
 * - Jalur MASSAL: ditangani AuditLogBuilder (lihat atribut
 *   UseEloquentBuilder). Builder bawaan Eloquent memanggil toBase() langsung
 *   sehingga event updating/deleting tidak menyala untuk operasi bulk; override
 *   di builder itulah yang menutupnya.
 * - Lapisan terakhir tetap grant DB (docker/mysql/harden-audit-log.sql), karena
 *   DB::table() tidak bisa dijangkau dari model maupun builder.
 */
#[UseEloquentBuilder(AuditLogBuilder::class)]
#[Fillable(['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip', 'user_agent', 'created_at'])]
class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const ACTION_LOGIN = 'LOGIN';

    public const ACTION_LOGOUT = 'LOGOUT';

    public const ACTION_LOGIN_FAILED = 'LOGIN_FAILED';

    public const ACTION_FAILED_LOGIN = self::ACTION_LOGIN_FAILED;

    public const ACTION_CREATE = 'CREATE';

    public const ACTION_UPDATE = 'UPDATE';

    public const ACTION_DELETE = 'DELETE';

    public const ACTION_IMPORT = 'IMPORT';

    /**
     * Tipe auditable internal pipeline yang dikecualikan dari reader audit log utama
     * demi mencegah polusi log massal.
     *
     * @var list<string>
     */
    public const EXCLUDED_AUDITABLE_TYPES = [
        'App\\Models\\PerformanceObservation',
        'App\\Models\\Indicator',
        'App\\Models\\OrgUnit',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope query untuk mengecualikan entri granular terstruktur historis
     * dengan tetap mempertahankan baris ber-auditable_type NULL (seperti LOGIN/LOGOUT).
     */
    public function scopeCleanAuditTrail($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('auditable_type')
                ->orWhereNotIn('auditable_type', self::EXCLUDED_AUDITABLE_TYPES);
        });
    }

    /**
     * Catat satu entri audit. Dipanggil oleh trait Auditable dan
     * listener auth event; ip/user_agent diambil dari request aktif.
     */
    public static function record(
        string $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
    ): static {
        $request = app('request');
        $resolvedUserId = func_num_args() >= 5 ? $userId : auth()->id();

        return static::create([
            'user_id' => $resolvedUserId,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip' => $request?->ip(),
            'user_agent' => mb_substr((string) ($request?->userAgent() ?? ''), 0, 255),
        ]);
    }

    public function update(array $attributes = [], array $options = []): never
    {
        throw new LogicException('Audit log bersifat append-only; entri tidak dapat diubah.');
    }

    public function delete(): never
    {
        throw new LogicException('Audit log bersifat append-only; entri tidak dapat dihapus.');
    }

    /**
     * Boot: blokir update/delete lewat event model (jalur instance dan
     * save()). Operasi MASSAL tidak lewat sini — lihat AuditLogBuilder.
     * DB::table() tetap perlu dilindungi di level DB.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(
            'Audit log bersifat append-only; entri tidak dapat diubah.',
        ));

        static::deleting(fn () => throw new LogicException(
            'Audit log bersifat append-only; entri tidak dapat dihapus.',
        ));
    }
}

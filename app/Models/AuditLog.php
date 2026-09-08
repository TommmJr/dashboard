<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Audit log append-only.
 *
 * - Tidak ada updated_at: entri hanya dibuat, tidak pernah diubah.
 * - update()/delete() dilempar LogicException supaya jalur "perubahan
 *   tanpa jejak" mustahil lewat model (prinsip audit mentor).
 */
#[Fillable(['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip', 'user_agent', 'created_at'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_LOGIN = 'LOGIN';

    public const ACTION_LOGOUT = 'LOGOUT';

    public const ACTION_CREATE = 'CREATE';

    public const ACTION_UPDATE = 'UPDATE';

    public const ACTION_DELETE = 'DELETE';

    public const ACTION_IMPORT = 'IMPORT';

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

        return static::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
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
     * Boot: blokir mass update/delete via Eloquent Builder (selain
     * instance method). DB::table() masih perlu dilindungi di level DB.
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

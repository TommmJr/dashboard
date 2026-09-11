<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Carbon\Carbon;

/**
 * Trait Auditable: mencatat created/updated/deleted model ke audit log.
 *
 * Yang dicatat hanya field yang berubah (getDirty / changes), sehingga
 * jejak ringkas dan tidak membocorkan data yang tidak relevan.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            // Kecualikan field sensitif via property $auditExclude di model.
            // Contoh: protected array $auditExclude = ['password', 'remember_token'];
            $exclude = property_exists($model, 'auditExclude') ? $model->auditExclude : [];
            $attrs = array_diff_key($model->getAttributes(), array_flip($exclude));
            AuditLog::record(AuditLog::ACTION_CREATE, $model, null, static::serializeForAudit($attrs));
        });

        static::updated(function ($model) {
            $exclude = property_exists($model, 'auditExclude') ? $model->auditExclude : [];

            $changes = $model->getChanges();
            // Abaikan update yang hanya menyentuh timestamp.
            unset($changes[$model->getUpdatedAtColumn()]);
            // Buang field sensitif dari changes dan original.
            $changes = array_diff_key($changes, array_flip($exclude));

            if ($changes !== []) {
                $old = array_diff_key(array_intersect_key($model->getOriginal(), $changes), array_flip($exclude));
                // Serialize enum ke value agar JSON tersimpan sebagai string.
                $changes = static::serializeForAudit($changes);
                $old = static::serializeForAudit($old);
                AuditLog::record(AuditLog::ACTION_UPDATE, $model, $old, $changes);
            }
        });

        static::deleted(function ($model) {
            $exclude = property_exists($model, 'auditExclude') ? $model->auditExclude : [];
            $attrs = array_diff_key($model->getOriginal(), array_flip($exclude));
            AuditLog::record(AuditLog::ACTION_DELETE, $model, static::serializeForAudit($attrs), null);
        });
    }

    /**
     * Serialize array atribut ke format JSON-safe: enum di-convert ke .value,
     * Carbon ke string ISO8601, object lain ke string.
     *
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    public static function serializeForAudit(array $attrs): array
    {
        return array_map(function ($value) {
            if ($value instanceof \BackedEnum) {
                return $value->value;
            }
            if ($value instanceof Carbon) {
                return $value->toIso8601String();
            }

            return $value;
        }, $attrs);
    }
}

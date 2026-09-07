<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;

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
            AuditLog::record(AuditLog::ACTION_CREATE, $model, null, $model->getAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();
            // Abaikan update yang hanya menyentuh timestamp.
            unset($changes[$model->getUpdatedAtColumn()]);

            if ($changes !== []) {
                $old = array_intersect_key($model->getOriginal(), $changes);
                AuditLog::record(AuditLog::ACTION_UPDATE, $model, $old, $changes);
            }
        });

        static::deleted(function ($model) {
            AuditLog::record(AuditLog::ACTION_DELETE, $model, $model->getOriginal(), null);
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Konfigurasi susunan widget dashboard berbasis database (Fase 3.2 / FR-001 §3).
 *
 * Menyimpan daftar terurut widget_key dari katalog config/dashboard_layouts.php
 * untuk suatu target role atau kombinasi staff_divisi + divisi.
 * Metadata eksekusi (label, view, permission) tetap bersumber dari katalog kode.
 */
#[Fillable(['layout_key', 'role_slug', 'division_id', 'widgets', 'updated_by'])]
class DashboardLayout extends Model
{
    /**
     * Daftar slug role yang sah memiliki konfigurasi layout dashboard.
     *
     * @var list<string>
     */
    public const ALLOWED_ROLES = [
        Role::ADMIN,
        Role::MANAGEMENT,
        Role::EDITOR_DASHBOARD,
        Role::STAFF_DIVISI,
    ];

    /**
     * Daftar widget di katalog yang dilarang di-mount ke layout mana pun
     * sampai blocker domain terkait dibuka secara resmi.
     *
     * @var list<string>
     */
    public const FORBIDDEN_WIDGETS = [
        'aset-upt-chart',
    ];

    protected $casts = [
        'division_id' => 'integer',
        'widgets' => 'array',
        'updated_by' => 'integer',
    ];

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Periksa apakah slug divisi memenuhi format kebab-case alfanumerik yang valid.
     */
    public static function isValidDivisionSlug(?string $divisionSlug): bool
    {
        return is_string($divisionSlug)
            && $divisionSlug !== ''
            && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $divisionSlug) === 1;
    }

    /**
     * Hitung kunci deterministik layout dari role_slug dan division_slug.
     */
    public static function keyFor(string $roleSlug, ?string $divisionSlug = null): string
    {
        if (! in_array($roleSlug, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException("Role '{$roleSlug}' tidak valid untuk layout dashboard.");
        }

        if ($divisionSlug !== null) {
            if ($roleSlug !== Role::STAFF_DIVISI) {
                throw new InvalidArgumentException('Hanya role staff_divisi yang boleh memiliki division_id pada layout dashboard.');
            }

            if (! self::isValidDivisionSlug($divisionSlug)) {
                throw new InvalidArgumentException('Divisi untuk dashboard layout tidak ditemukan atau tidak memiliki slug valid.');
            }

            return "staff_divisi:{$divisionSlug}";
        }

        return $roleSlug;
    }

    /**
     * Daftar widget_key dari katalog yang diizinkan untuk disimpan/dirender.
     *
     * @param  array<string, mixed>|null  $config
     * @return list<string>
     */
    public static function allowedWidgetKeys(?array $config = null): array
    {
        $catalog = $config['widgets'] ?? config('dashboard_layouts.widgets', []);

        return array_values(array_diff(
            array_keys(is_array($catalog) ? $catalog : []),
            self::FORBIDDEN_WIDGETS,
        ));
    }

    /**
     * Saring daftar widget mentah terhadap katalog aktif dan daftar terlarang.
     *
     * Digunakan sebagai defense-in-depth saat resolusi/pembacaan layout.
     *
     * @param  array<string, mixed>|null  $config
     * @return list<string>
     */
    public static function sanitizeWidgets(mixed $widgets, ?array $config = null): array
    {
        if (! is_array($widgets)) {
            return [];
        }

        $allowed = array_flip(self::allowedWidgetKeys($config));
        $result = [];

        foreach ($widgets as $key) {
            if (is_string($key) && isset($allowed[$key]) && ! in_array($key, $result, true)) {
                $result[] = $key;
            }
        }

        return $result;
    }

    protected static function booted(): void
    {
        static::saving(function (self $layout): void {
            if (! in_array($layout->role_slug, self::ALLOWED_ROLES, true)) {
                throw new InvalidArgumentException("Role '{$layout->role_slug}' tidak valid untuk layout dashboard.");
            }

            $divisionSlug = null;
            if ($layout->division_id !== null) {
                if ($layout->role_slug !== Role::STAFF_DIVISI) {
                    throw new InvalidArgumentException('Hanya role staff_divisi yang boleh memiliki division_id pada layout dashboard.');
                }

                $divisionSlug = Division::query()
                    ->whereKey($layout->division_id)
                    ->value('slug');

                if (! self::isValidDivisionSlug(is_string($divisionSlug) ? $divisionSlug : null)) {
                    throw new InvalidArgumentException('Divisi untuk dashboard layout tidak ditemukan atau tidak memiliki slug valid.');
                }
            }

            $expectedKey = self::keyFor($layout->role_slug, $divisionSlug);

            if ($layout->layout_key !== null && $layout->layout_key !== '' && $layout->layout_key !== $expectedKey) {
                throw new InvalidArgumentException(
                    "layout_key '{$layout->layout_key}' tidak konsisten dengan target '{$expectedKey}'.",
                );
            }

            $layout->layout_key = $expectedKey;

            if (! is_array($layout->widgets) || $layout->widgets === [] || ! array_is_list($layout->widgets)) {
                throw new InvalidArgumentException('Daftar widgets harus berupa list non-kosong.');
            }

            $catalog = config('dashboard_layouts.widgets', []);
            $normalized = [];

            foreach ($layout->widgets as $widgetKey) {
                if (! is_string($widgetKey) || $widgetKey === '') {
                    throw new InvalidArgumentException('Setiap item widgets harus berupa string widget_key.');
                }

                if (in_array($widgetKey, self::FORBIDDEN_WIDGETS, true)) {
                    throw new InvalidArgumentException("Widget '{$widgetKey}' dilarang dipasang pada layout dashboard.");
                }

                if (! is_array($catalog) || ! array_key_exists($widgetKey, $catalog)) {
                    throw new InvalidArgumentException("Widget '{$widgetKey}' tidak terdaftar di katalog dashboard_layouts.");
                }

                if (! in_array($widgetKey, $normalized, true)) {
                    $normalized[] = $widgetKey;
                }
            }

            $layout->widgets = $normalized;
        });
    }
}

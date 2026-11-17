<?php

namespace App\Services\Dashboard;

use App\Models\DashboardLayout;
use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Layanan domain manajemen dan resolusi layout dashboard per role & divisi.
 *
 * Layout dibaca DB-first dari tabel dashboard_layouts dengan fallback
 * konfigurasi config/dashboard_layouts.php. Juga bertanggung jawab atas
 * payload UI Dashboard Builder, validasi target layout, serta persistensi
 * simpan/reset layout di database.
 */
class DashboardLayoutService
{
    /**
     * Daftar slug divisi staf kanonis yang diizinkan memiliki layout kustom.
     *
     * @var list<string>
     */
    public const CANONICAL_STAFF_DIVISION_SLUGS = [
        'perencanaan-pengusahaan',
        'managemen-aset',
    ];

    /**
     * Periksa apakah user memiliki role istimewa (Admin, Management, Editor Dashboard).
     */
    public function isPrivileged(?User $user): bool
    {
        return $user !== null && (
            $user->hasRole(Role::ADMIN)
            || $user->hasRole(Role::MANAGEMENT)
            || $user->hasRole(Role::EDITOR_DASHBOARD)
        );
    }

    /**
     * Resolusi daftar widget_key terurut dengan prioritas database lalu fallback config.
     *
     * Urutan kandidat untuk staff_divisi berdivisi <slug>:
     * 1. DB staff_divisi:<slug>
     * 2. Config staff_divisi:<slug>
     * 3. DB staff_divisi
     * 4. Config staff_divisi
     * 5. Config default
     *
     * Urutan kandidat untuk role lain:
     * 1. DB <role_slug>
     * 2. Config <role_slug>
     * 3. Config default
     *
     * @param  array<string, mixed>|null  $config
     * @return array<int, mixed>
     */
    public function resolveLayout(?User $user, ?array $config = null): array
    {
        $config ??= config('dashboard_layouts');
        $config = is_array($config) ? $config : [];
        $roleSlug = $user?->role?->slug;

        if ($roleSlug === Role::STAFF_DIVISI) {
            $divisionSlug = $user?->division?->slug;

            if (DashboardLayout::isValidDivisionSlug($divisionSlug)) {
                $divisionKey = DashboardLayout::keyFor(Role::STAFF_DIVISI, $divisionSlug);

                if (($dbDivision = $this->resolveDbLayout($divisionKey, $config)) !== []) {
                    return $dbDivision;
                }

                if (($cfgDivision = $this->resolveConfigLayout($divisionKey, $config)) !== []) {
                    return $cfgDivision;
                }
            }

            if (($dbStaff = $this->resolveDbLayout(Role::STAFF_DIVISI, $config)) !== []) {
                return $dbStaff;
            }

            if (($cfgStaff = $this->resolveConfigLayout(Role::STAFF_DIVISI, $config)) !== []) {
                return $cfgStaff;
            }

            return is_array($config['default'] ?? null) ? $config['default'] : [];
        }

        if (is_string($roleSlug) && in_array($roleSlug, DashboardLayout::ALLOWED_ROLES, true)) {
            if (($dbRole = $this->resolveDbLayout($roleSlug, $config)) !== []) {
                return $dbRole;
            }

            if (($cfgRole = $this->resolveConfigLayout($roleSlug, $config)) !== []) {
                return $cfgRole;
            }
        } elseif (is_string($roleSlug) && isset($config['layouts'][$roleSlug])) {
            if (($cfgCustom = $this->resolveConfigLayout($roleSlug, $config)) !== []) {
                return $cfgCustom;
            }
        }

        return is_array($config['default'] ?? null) ? $config['default'] : [];
    }

    /**
     * Ambil dan saring daftar widget dari database untuk layout_key tertentu.
     *
     * @param  array<string, mixed>|null  $config
     * @return list<string>
     */
    public function resolveDbLayout(string $layoutKey, ?array $config = null): array
    {
        $config ??= config('dashboard_layouts');
        $config = is_array($config) ? $config : [];

        try {
            $record = DashboardLayout::query()
                ->where('layout_key', $layoutKey)
                ->first(['layout_key', 'role_slug', 'division_id', 'widgets']);
        } catch (QueryException) {
            return [];
        }

        if ($record === null) {
            return [];
        }

        if (str_starts_with($layoutKey, Role::STAFF_DIVISI.':')) {
            if ($record->role_slug !== Role::STAFF_DIVISI || $record->division_id === null) {
                return [];
            }
        } elseif ($record->role_slug !== $layoutKey || $record->division_id !== null) {
            return [];
        }

        return DashboardLayout::sanitizeWidgets($record->widgets, $config);
    }

    /**
     * Ambil daftar widget dari config untuk layout_key tertentu.
     *
     * @param  array<string, mixed>|null  $config
     * @return list<string>
     */
    public function resolveConfigLayout(string $layoutKey, ?array $config = null): array
    {
        $config ??= config('dashboard_layouts');
        $config = is_array($config) ? $config : [];

        if (! isset($config['layouts'][$layoutKey]) || ! is_array($config['layouts'][$layoutKey])) {
            return [];
        }

        return array_values(array_filter(
            $config['layouts'][$layoutKey],
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }

    /**
     * Resolusi daftar widget fallback (tanpa override DB target itu sendiri).
     *
     * @param  array<string, mixed>|null  $config
     * @return list<string>
     */
    public function resolveFallbackWidgetsForTarget(string $roleSlug, ?string $divisionSlug, ?array $config = null): array
    {
        $config ??= config('dashboard_layouts');
        $config = is_array($config) ? $config : [];

        if ($roleSlug === Role::STAFF_DIVISI) {
            if (DashboardLayout::isValidDivisionSlug($divisionSlug)) {
                $divisionKey = DashboardLayout::keyFor(Role::STAFF_DIVISI, $divisionSlug);
                if (($cfgDivision = DashboardLayout::sanitizeWidgets($this->resolveConfigLayout($divisionKey, $config), $config)) !== []) {
                    return $cfgDivision;
                }

                if (($dbStaff = $this->resolveDbLayout(Role::STAFF_DIVISI, $config)) !== []) {
                    return $dbStaff;
                }
            }

            if (($cfgStaff = DashboardLayout::sanitizeWidgets($this->resolveConfigLayout(Role::STAFF_DIVISI, $config), $config)) !== []) {
                return $cfgStaff;
            }

            return DashboardLayout::sanitizeWidgets($config['default'] ?? [], $config);
        }

        if (($cfgRole = DashboardLayout::sanitizeWidgets($this->resolveConfigLayout($roleSlug, $config), $config)) !== []) {
            return $cfgRole;
        }

        return DashboardLayout::sanitizeWidgets($config['default'] ?? [], $config);
    }

    /**
     * Payload konfigurasi UI Dashboard Builder untuk pemegang dashboard.update.
     *
     * @return array{
     *     catalog: list<array{
     *         key: string,
     *         label: string,
     *         permission: string|null,
     *         default_cols: int,
     *         category: string,
     *         description: string
     *     }>,
     *     targets: list<array{
     *         layout_key: string,
     *         label: string,
     *         role_slug: string,
     *         division_id: int|null,
     *         active_widgets: list<string>,
     *         fallback_widgets: list<string>,
     *         is_customized: bool
     *     }>,
     *     initial_target_key: string,
     *     current_user_layout_key: string
     * }
     */
    public function layoutEditorData(?User $user): array
    {
        if ($user === null || ! Gate::forUser($user)->allows('dashboard.update')) {
            return [
                'catalog' => [],
                'targets' => [],
                'initial_target_key' => '',
                'current_user_layout_key' => '',
            ];
        }

        $config = config('dashboard_layouts');
        $config = is_array($config) ? $config : [];
        $allowedKeys = DashboardLayout::allowedWidgetKeys($config);

        $catalog = [];
        foreach ($allowedKeys as $widgetKey) {
            $meta = is_array($config['widgets'][$widgetKey] ?? null) ? $config['widgets'][$widgetKey] : [];
            $catalog[] = [
                'key' => $widgetKey,
                'label' => (string) ($meta['label'] ?? $widgetKey),
                'permission' => isset($meta['permission']) && is_string($meta['permission'])
                    ? $meta['permission']
                    : null,
                'default_cols' => (int) ($meta['default_cols'] ?? 3),
                'category' => (string) ($meta['category'] ?? 'Operasional & Admin'),
                'description' => (string) ($meta['description'] ?? ''),
            ];
        }

        try {
            $dbRecords = DashboardLayout::query()
                ->get(['layout_key', 'widgets'])
                ->keyBy('layout_key');
        } catch (QueryException) {
            $dbRecords = collect();
        }

        $targetDefinitions = [
            ['role_slug' => Role::ADMIN, 'division_id' => null, 'division_slug' => null, 'label' => 'Admin'],
        ];

        $targetDivisions = [
            'perencanaan-pengusahaan' => 'Staf Perencanaan Pengusahaan',
            'managemen-aset' => 'Staf Managemen Aset',
        ];

        $divisionsBySlug = Division::query()
            ->whereIn('slug', array_keys($targetDivisions))
            ->get(['id', 'name', 'slug'])
            ->keyBy('slug');

        foreach ($targetDivisions as $slug => $label) {
            $division = $divisionsBySlug->get($slug);
            if ($division && DashboardLayout::isValidDivisionSlug($division->slug)) {
                $targetDefinitions[] = [
                    'role_slug' => Role::STAFF_DIVISI,
                    'division_id' => (int) $division->id,
                    'division_slug' => $division->slug,
                    'label' => $label,
                ];
            }
        }

        $targets = [];
        foreach ($targetDefinitions as $def) {
            $layoutKey = DashboardLayout::keyFor($def['role_slug'], $def['division_slug']);
            $fallbackWidgets = $this->resolveFallbackWidgetsForTarget(
                $def['role_slug'],
                $def['division_slug'],
                $config,
            );

            $dbRecord = $dbRecords->get($layoutKey);
            $dbWidgets = $dbRecord !== null
                ? DashboardLayout::sanitizeWidgets($dbRecord->widgets, $config)
                : [];

            $isCustomized = $dbWidgets !== [];
            $activeWidgets = $isCustomized ? $dbWidgets : $fallbackWidgets;

            $targets[] = [
                'layout_key' => $layoutKey,
                'label' => $def['label'],
                'role_slug' => $def['role_slug'],
                'division_id' => $def['division_id'],
                'active_widgets' => $activeWidgets,
                'fallback_widgets' => $fallbackWidgets,
                'is_customized' => $isCustomized,
            ];
        }

        $userRoleSlug = $user->role?->slug;
        $userDivisionSlug = $userRoleSlug === Role::STAFF_DIVISI && DashboardLayout::isValidDivisionSlug($user->division?->slug)
            ? $user->division?->slug
            : null;
        $currentUserLayoutKey = is_string($userRoleSlug) && in_array($userRoleSlug, DashboardLayout::ALLOWED_ROLES, true)
            ? DashboardLayout::keyFor($userRoleSlug, $userDivisionSlug)
            : Role::EDITOR_DASHBOARD;

        $targetKeys = array_column($targets, 'layout_key');
        $initialTargetKey = in_array($currentUserLayoutKey, $targetKeys, true)
            ? $currentUserLayoutKey
            : ($targetKeys[0] ?? Role::ADMIN);

        return [
            'catalog' => $catalog,
            'targets' => $targets,
            'initial_target_key' => $initialTargetKey,
            'current_user_layout_key' => $currentUserLayoutKey,
        ];
    }

    /**
     * Validasi target layout (role_slug, division_id, layout_key) dan opsional daftar widgets.
     *
     * @return array{0: string, 1: int|null, 2: string, 3: list<string|array{key: string, cols: int, config: array<string, mixed>}>}
     */
    public function validateLayoutTarget(Request $request, bool $requireWidgets): array
    {
        if ($request->filled('target') && ! $request->has('role_slug')) {
            $targetInput = (string) $request->input('target');
            if (str_contains($targetInput, ':')) {
                [$targetRole, $targetDivSlug] = explode(':', $targetInput, 2);
                $div = Division::query()->where('slug', $targetDivSlug)->first();
                $request->merge([
                    'role_slug' => $targetRole,
                    'division_id' => $div?->id,
                    'layout_key' => $targetInput,
                ]);
            } else {
                $request->merge([
                    'role_slug' => $targetInput,
                    'layout_key' => $targetInput,
                ]);
            }
        }

        $roleSlugInput = $request->input('role_slug');
        $divisionRule = $roleSlugInput === Role::STAFF_DIVISI
            ? ['required', 'integer', 'exists:divisions,id']
            : ['prohibited'];
        $divisionSlugRule = $roleSlugInput === Role::STAFF_DIVISI
            ? ['nullable', 'string']
            : ['prohibited'];

        $rules = [
            'role_slug' => ['required', 'string', Rule::in([Role::ADMIN, Role::STAFF_DIVISI])],
            'division_id' => $divisionRule,
            'division_slug' => $divisionSlugRule,
            'layout_key' => ['nullable', 'string'],
        ];

        if ($requireWidgets) {
            $rules['widgets'] = ['required', 'array', 'list', 'min:1'];
        }

        $validated = $request->validate($rules);

        $roleSlug = (string) $validated['role_slug'];
        $divisionId = isset($validated['division_id']) ? (int) $validated['division_id'] : null;
        $divisionSlug = null;

        if ($divisionId !== null) {
            $divisionSlug = Division::query()->whereKey($divisionId)->value('slug');

            if (! DashboardLayout::isValidDivisionSlug(is_string($divisionSlug) ? $divisionSlug : null)) {
                throw ValidationException::withMessages([
                    'division_id' => 'Divisi yang dipilih tidak memiliki slug yang valid untuk layout dashboard.',
                ]);
            }
        }

        if ($roleSlug === Role::STAFF_DIVISI) {
            if (! is_string($divisionSlug) || ! in_array($divisionSlug, self::CANONICAL_STAFF_DIVISION_SLUGS, true)) {
                throw ValidationException::withMessages([
                    'division_id' => 'Target divisi yang dipilih bukan merupakan target layout kanonis.',
                ]);
            }
        }

        if (array_key_exists('division_slug', $validated) && $validated['division_slug'] !== null && $validated['division_slug'] !== '') {
            $inputDivisionSlug = (string) $validated['division_slug'];
            if (! DashboardLayout::isValidDivisionSlug($inputDivisionSlug) || $divisionSlug === null || $inputDivisionSlug !== $divisionSlug) {
                throw ValidationException::withMessages([
                    'division_slug' => 'Slug divisi tidak valid atau tidak sesuai dengan division_id.',
                ]);
            }
        }

        $expectedKey = DashboardLayout::keyFor($roleSlug, is_string($divisionSlug) ? $divisionSlug : null);

        if (isset($validated['layout_key']) && $validated['layout_key'] !== '' && $validated['layout_key'] !== $expectedKey) {
            throw ValidationException::withMessages([
                'layout_key' => 'Kunci layout tidak sesuai dengan kombinasi role dan divisi.',
            ]);
        }

        $user = $request->user();
        if ($user !== null && ! $user->hasRole(Role::ADMIN)) {
            if ($roleSlug === Role::ADMIN || $expectedKey === Role::ADMIN) {
                abort(403, 'Editor Dashboard tidak memiliki izin untuk mengelola layout Admin.');
            }
        }

        $widgets = $requireWidgets
            ? $this->validateAndCompactWidgets($validated['widgets'])
            : [];

        return [$roleSlug, $divisionId, $expectedKey, $widgets];
    }

    /**
     * Simpan atau perbarui override layout dashboard di database secara transaksional.
     *
     * @param  list<string|array{key: string, cols: int, config: array<string, mixed>}>  $widgets
     */
    public function saveLayout(User $user, string $layoutKey, string $roleSlug, ?int $divisionId, array $widgets): DashboardLayout
    {
        return DB::transaction(function () use ($layoutKey, $roleSlug, $divisionId, $widgets, $user): DashboardLayout {
            $attributes = [
                'role_slug' => $roleSlug,
                'division_id' => $divisionId,
                'widgets' => $widgets,
                'updated_by' => $user->id,
            ];

            try {
                return DashboardLayout::query()->updateOrCreate(
                    ['layout_key' => $layoutKey],
                    $attributes,
                );
            } catch (UniqueConstraintViolationException) {
                $existing = DashboardLayout::query()
                    ->where('layout_key', $layoutKey)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing->fill($attributes);
                $existing->save();

                return $existing;
            }
        });
    }

    /**
     * Hapus override layout database agar kembali ke konfigurasi bawaan.
     */
    public function deleteLayout(string $layoutKey, string $roleSlug, ?int $divisionId): void
    {
        DB::transaction(function () use ($layoutKey, $roleSlug, $divisionId): void {
            $layout = DashboardLayout::query()
                ->where('layout_key', $layoutKey)
                ->where('role_slug', $roleSlug)
                ->when(
                    $divisionId === null,
                    fn ($query) => $query->whereNull('division_id'),
                    fn ($query) => $query->where('division_id', $divisionId),
                )
                ->first();

            $layout?->delete();
        });
    }

    /**
     * Validasi daftar widget mentah terhadap allowlist, cols, dan konfigurasi custom-chart,
     * lalu normalisasi dan kompress ke bentuk simpan ringkas.
     *
     * @param  array<int, mixed>  $rawWidgets
     * @return list<string|array{key: string, cols: int, config: array<string, mixed>}>
     */
    private function validateAndCompactWidgets(array $rawWidgets): array
    {
        $allowedKeys = DashboardLayout::allowedWidgetKeys();
        $widgetErrors = [];

        foreach ($rawWidgets as $i => $item) {
            if (is_string($item)) {
                if (! in_array($item, $allowedKeys, true)) {
                    $widgetErrors["widgets.{$i}"] = ["The selected widgets.{$i} is invalid."];
                }

                continue;
            }

            if (! is_array($item)) {
                $widgetErrors["widgets.{$i}"] = ["The widgets.{$i} field must be a string or array."];

                continue;
            }

            $itemKey = $item['key'] ?? null;
            if (! is_string($itemKey) || ! in_array($itemKey, $allowedKeys, true)) {
                $widgetErrors["widgets.{$i}.key"] = ["The selected widgets.{$i}.key is invalid."];
            }

            if (array_key_exists('cols', $item)) {
                if (! is_int($item['cols']) || ! in_array($item['cols'], [1, 2, 3], true)) {
                    $widgetErrors["widgets.{$i}.cols"] = ["The widgets.{$i}.cols field must be between 1 and 3."];
                }
            }

            if ($itemKey === 'custom-chart') {
                $config = $item['config'] ?? null;
                if (! is_array($config)) {
                    $widgetErrors["widgets.{$i}.config"] = ["The widgets.{$i}.config field must be an array."];
                } else {
                    if (array_key_exists('title', $config)) {
                        if (! is_string($config['title']) || mb_strlen($config['title']) > 60) {
                            $widgetErrors["widgets.{$i}.config.title"] = ["The widgets.{$i}.config.title field must not be greater than 60 characters."];
                        }
                    }

                    $chartType = $config['chart_type'] ?? null;
                    if (! is_string($chartType) || ! in_array($chartType, ['bar', 'line', 'donut'], true)) {
                        $widgetErrors["widgets.{$i}.config.chart_type"] = ["The selected widgets.{$i}.config.chart_type is invalid."];
                    }

                    $metric = $config['metric'] ?? null;
                    if (! is_string($metric) || ! in_array($metric, ['nko_score', 'pencapaian_ratio'], true)) {
                        $widgetErrors["widgets.{$i}.config.metric"] = ["The selected widgets.{$i}.config.metric is invalid."];
                    }
                }
            }
        }

        if ($widgetErrors !== []) {
            throw ValidationException::withMessages($widgetErrors);
        }

        $catalog = config('dashboard_layouts.widgets', []);
        $catalog = is_array($catalog) ? $catalog : [];
        $normalized = DashboardLayout::normalizeWidgets($rawWidgets, $catalog);

        return DashboardLayout::compactWidgets($normalized, $catalog);
    }
}

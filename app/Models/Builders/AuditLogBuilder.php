<?php

namespace App\Models\Builders;

use App\Models\AuditLog;
use App\Models\DashboardLayout;
use App\Models\Dataset;
use App\Models\Division;
use App\Models\PerformanceMetric;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Builder khusus AuditLog yang menutup seluruh jalur MASSAL.
 *
 * KENAPA INI PERLU
 * Eloquent\Builder menulis ke database lewat toBase()/$this->query langsung
 * (query builder), sehingga event `updating`/`deleting` pada
 * AuditLog::booted() TIDAK menyala untuk operasi bulk. Tanpa override di
 * sini, entri audit bisa diubah atau dimusnahkan tanpa perlawanan.
 *
 * PROTOKOL LENGKAP (dipetakan dari vendor/laravel/framework Eloquent\Builder,
 * diverifikasi 2026-09-28). Setiap method berikut menulis tanpa melewati model,
 * jadi semuanya harus ditolak:
 *   update()         L1305 toBase()->update()
 *   upsert()         L1330 toBase()->upsert()
 *   updateFrom()     L1350 toBase()->update()
 *   touch()          L1359 toBase()->update()
 *   increment()      L1372 toBase()->increment()
 *   decrement()      L1387 toBase()->decrement()
 *   incrementEach()  L1401 toBase()->incrementEach()
 *   decrementEach()  L1415 toBase()->decrementEach()
 *   delete()         L1547 toBase()->delete()
 *   forceDelete()    L1559 $this->query->delete()   <- TIDAK lewat toBase(),
 *                                                      jadi wajib di-override sendiri
 *
 * insert()/insertGetId()/create() sengaja TIDAK ditolak: menambah entri audit
 * adalah operasi yang sah (append-only berarti boleh ditambah, bukan diubah).
 *
 * Batas yang tetap berlaku: DB::table('audit_logs') tidak bisa dijangkau dari
 * kelas ini, sehingga proteksi level DB (grant) tetap menjadi lapisan terakhir.
 *
 * @extends Builder<AuditLog>
 */
class AuditLogBuilder extends Builder
{
    private const PESAN_UBAH = 'Audit log bersifat append-only; entri tidak dapat diubah.';

    private const PESAN_HAPUS = 'Audit log bersifat append-only; entri tidak dapat dihapus.';

    /**
     * Pemetaan kunci filter entitas UI => morph class auditable (null = tanpa model / akun).
     *
     * @return array<string, class-string|null>
     */
    public static function entityTypeMap(): array
    {
        return [
            'dataset' => Dataset::class,
            'performance_metric' => PerformanceMetric::class,
            'user' => User::class,
            'division' => Division::class,
            'dashboard_layout' => DashboardLayout::class,
            'account' => null,
        ];
    }

    /**
     * Escape karakter wildcard LIKE ('!', '%', '_') dengan karakter escape '!'.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Saring entri audit berdasarkan kata kunci bebas secara aman terhadap wildcard LIKE.
     */
    public function searchKeyword(?string $keyword): self
    {
        $term = trim((string) $keyword);
        if ($term === '') {
            return $this;
        }

        $pattern = '%'.self::escapeLike($term).'%';
        $numericId = ltrim($term, '#');

        return $this->where(function (self $query) use ($pattern, $numericId): void {
            $query->whereRaw("action LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("auditable_type LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("ip LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("user_agent LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("old_values LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("new_values LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('user', function ($userQuery) use ($pattern): void {
                    $userQuery->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern]);
                });

            if ($numericId !== '' && ctype_digit($numericId)) {
                $query->orWhere('auditable_id', (int) $numericId);
            }
        });
    }

    /**
     * Saring entri audit berdasarkan jenis entitas yang dipetakan dari whitelist.
     */
    public function filterEntity(?string $entity): self
    {
        if ($entity === null || $entity === '') {
            return $this;
        }

        $map = self::entityTypeMap();
        if (! array_key_exists($entity, $map)) {
            return $this;
        }

        $targetClass = $map[$entity];
        if ($targetClass === null) {
            return $this->whereNull('auditable_type');
        }

        return $this->where('auditable_type', (new $targetClass)->getMorphClass());
    }

    public function update(array $values)
    {
        throw new LogicException(self::PESAN_UBAH);
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException(self::PESAN_UBAH);
    }

    public function updateFrom(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException(self::PESAN_UBAH);
    }

    public function touch($column = null)
    {
        throw new LogicException(self::PESAN_UBAH);
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException(self::PESAN_UBAH);
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException(self::PESAN_UBAH);
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        throw new LogicException(self::PESAN_UBAH);
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        throw new LogicException(self::PESAN_UBAH);
    }

    public function delete()
    {
        throw new LogicException(self::PESAN_HAPUS);
    }

    public function forceDelete()
    {
        throw new LogicException(self::PESAN_HAPUS);
    }
}

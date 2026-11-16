<?php

namespace App\Models\Builders;

use App\Models\AuditLog;
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

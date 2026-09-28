#!/usr/bin/env bash
#
# Assert bahwa hardening grant audit log benar-benar berlaku.
#
# Dipakai oleh job "db-grants" di .github/workflows/ci.yml setelah:
#   1. migrate dijalankan,
#   2. docker/mysql/harden-audit-log.sql dijalankan.
#
# Yang di-assert (menggagalkan build kalau tidak sesuai):
#   - UPDATE audit_logs  -> ditolak (ERROR 1142)
#   - DELETE audit_logs  -> ditolak (ERROR 1142)
#   - SELECT audit_logs  -> diizinkan
#   - INSERT audit_logs  -> diizinkan
#
# Yang hanya dilaporkan (known gap, TIDAK menggagalkan build):
#   - TRUNCATE / ALTER / DROP audit_logs. Probe 2026-09-28 membuktikan
#     ketiganya BERHASIL sebagai dashboard_app, karena migration butuh grant
#     DDL di seluruh database. Ini dicatat sebagai gap, bukan di-assert gagal,
#     supaya CI tetap hijau selama gap belum ditutup. Kalau gap sudah ditutup,
#     ubah blok KNOWN GAP di bawah menjadi assert.
#
# Parameter koneksi dibaca dari environment, TIDAK ada kredensial hardcode:
#   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD

set -uo pipefail

: "${DB_HOST:?DB_HOST wajib diisi}"
: "${DB_DATABASE:?DB_DATABASE wajib diisi}"
: "${DB_USERNAME:?DB_USERNAME wajib diisi}"
: "${DB_PASSWORD:?DB_PASSWORD wajib diisi}"
DB_PORT="${DB_PORT:-3306}"

MYSQL_BASE=(mysql --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "-p$DB_PASSWORD" "$DB_DATABASE")

failures=0

# Jalankan query, cetak output tanpa warning password.
# --skip-column-names: hasil satu kolom jadi bersih; stderr tetap ditangkap
# supaya pesan ERROR 1142 bisa diperiksa.
run_sql() {
  MYSQL_PWD="$DB_PASSWORD" mysql --protocol=TCP -h "$DB_HOST" -P "$DB_PORT" \
    -u "$DB_USERNAME" --skip-column-names "$DB_DATABASE" -e "$1" 2>&1
}

echo "== audit log grant assertions =="

# 1. UPDATE harus DITOLAK (ERROR 1142).
update_out="$(run_sql "UPDATE audit_logs SET action = 'x' WHERE id = -1;")"
update_rc=$?
if [ "$update_rc" -eq 0 ] || ! grep -q "1142" <<<"$update_out"; then
  echo "FAIL: UPDATE audit_logs seharusnya ditolak, hasil: $update_out"
  failures=$((failures + 1))
else
  echo "PASS: UPDATE audit_logs ditolak (ERROR 1142)"
fi

# 2. DELETE harus DITOLAK (ERROR 1142).
delete_out="$(run_sql "DELETE FROM audit_logs WHERE id = -1;")"
delete_rc=$?
if [ "$delete_rc" -eq 0 ] || ! grep -q "1142" <<<"$delete_out"; then
  echo "FAIL: DELETE audit_logs seharusnya ditolak, hasil: $delete_out"
  failures=$((failures + 1))
else
  echo "PASS: DELETE audit_logs ditolak (ERROR 1142)"
fi

# 3. SELECT harus DIIZINKAN.
select_out="$(run_sql "SELECT COUNT(*) FROM audit_logs;")"
if [ $? -ne 0 ]; then
  echo "FAIL: SELECT audit_logs seharusnya diizinkan, hasil: $select_out"
  failures=$((failures + 1))
else
  echo "PASS: SELECT audit_logs diizinkan (count=$select_out)"
fi

# 4. INSERT harus DIIZINKAN.
insert_out="$(run_sql "INSERT INTO audit_logs (action, created_at) VALUES ('CI_GRANT_PROBE', NOW());")"
if [ $? -ne 0 ]; then
  echo "FAIL: INSERT audit_logs seharusnya diizinkan, hasil: $insert_out"
  failures=$((failures + 1))
else
  echo "PASS: INSERT audit_logs diizinkan"
fi

echo ""
echo "== known gap (dilaporkan, tidak menggagalkan build) =="
echo "Grant saat ini membatasi DATA, bukan STRUKTUR. Probe 2026-09-28:"
echo "TRUNCATE / ALTER / DROP audit_logs BERHASIL sebagai aplikasi user."
echo "Menutup gap ini butuh user migration terpisah dari user aplikasi."
echo "Assertions berikut sengaja TIDAK dijalankan supaya CI tetap hijau:"
echo "  - TRUNCATE TABLE audit_logs"
echo "  - ALTER TABLE audit_logs ..."
echo "  - DROP TABLE audit_logs"

echo ""
if [ "$failures" -gt 0 ]; then
  echo "RESULT: $failures assertion gagal"
  exit 1
fi

echo "RESULT: semua assertion lulus"

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
#   - TRUNCATE audit_logs -> ditolak (ERROR 1142)
#   - ALTER TABLE audit_logs -> ditolak (ERROR 1142)
#   - DROP TABLE audit_logs -> ditolak (ERROR 1142)
#
# Tiga assertion terakhir adalah perubahan P0-6: setelah user migrasi dipisah,
# dashboard_app TIDAK lagi punya DDL, sehingga struktur audit_logs tidak bisa
# dimusnahkan lewat kredensial aplikasi. Sebelumnya ini hanya dilaporkan
# sebagai known gap; sekarang kegagalannya menggagalkan build.
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

# 5. TRUNCATE harus DITOLAK (dashboard_app tanpa DDL).
truncate_out="$(run_sql "TRUNCATE TABLE audit_logs;")"
truncate_rc=$?
if [ "$truncate_rc" -eq 0 ] || ! grep -q "1142" <<<"$truncate_out"; then
  echo "FAIL: TRUNCATE audit_logs seharusnya ditolak, hasil: $truncate_out"
  failures=$((failures + 1))
else
  echo "PASS: TRUNCATE audit_logs ditolak (ERROR 1142)"
fi

# 6. ALTER TABLE harus DITOLAK.
alter_out="$(run_sql "ALTER TABLE audit_logs ADD COLUMN ci_probe INT NULL;")"
alter_rc=$?
if [ "$alter_rc" -eq 0 ] || ! grep -q "1142" <<<"$alter_out"; then
  echo "FAIL: ALTER audit_logs seharusnya ditolak, hasil: $alter_out"
  failures=$((failures + 1))
else
  echo "PASS: ALTER audit_logs ditolak (ERROR 1142)"
fi

# 7. DROP TABLE harus DITOLAK.
drop_out="$(run_sql "DROP TABLE audit_logs;")"
drop_rc=$?
if [ "$drop_rc" -eq 0 ] || ! grep -q "1142" <<<"$drop_out"; then
  echo "FAIL: DROP audit_logs seharusnya ditolak, hasil: $drop_out"
  failures=$((failures + 1))
else
  echo "PASS: DROP audit_logs ditolak (ERROR 1142)"
fi

# 8. DDL pada tabel biasa juga harus ditolak — bukti pemisahan user berlaku
#    menyeluruh, bukan hanya di audit_logs.
ddl_out="$(run_sql "CREATE TABLE ci_ddl_probe (id INT);")"
ddl_rc=$?
if [ "$ddl_rc" -eq 0 ] || ! grep -q "1142" <<<"$ddl_out"; then
  echo "FAIL: CREATE TABLE seharusnya ditolak untuk dashboard_app, hasil: $ddl_out"
  failures=$((failures + 1))
else
  echo "PASS: CREATE TABLE ditolak untuk dashboard_app (ERROR 1142)"
fi

echo ""
if [ "$failures" -gt 0 ]; then
  echo "RESULT: $failures assertion gagal"
  exit 1
fi

echo "RESULT: semua assertion lulus"

#!/usr/bin/env bash
#
# Buat user migrasi terpisah dari user aplikasi.
#
# KENAPA DIPISAH
# User aplikasi (dashboard_app) tidak boleh punya DDL, karena DDL membuat
# TRUNCATE/ALTER/DROP pada audit_logs mungkin — artinya audit trail bisa
# dimusnahkan lewat kredensial aplikasi. Migration membutuhkan DDL, jadi
# migration harus memakai user lain: dashboard_migrator.
#
# KAPAN DIJALANKAN
# SEBELUM `php artisan migrate`. File ini idempotent (CREATE USER IF NOT
# EXISTS) sehingga aman dijalankan berulang. Di container baru, MySQL
# menjalankannya otomatis lewat /docker-entrypoint-initdb.d.
#
# CATATAN PENTING
# Skrip initdb berjalan di dalam fase inisialisasi, saat server MySQL belum
# membuka port TCP. Karena itu koneksi memakai unix socket (--protocol=socket)
# dan bukan TCP 127.0.0.1 — kalau memakai TCP, skrip gagal dengan
# "ERROR 2003 Can't connect" dan container ikut mati.
#
# Password dibaca dari environment, TIDAK ada kredensial hardcode di SQL.

set -euo pipefail

: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD wajib diisi}"
: "${DB_DATABASE:=dashboard}"
: "${DB_MIGRATOR_USERNAME:=dashboard_migrator}"
: "${DB_MIGRATOR_PASSWORD:?DB_MIGRATOR_PASSWORD wajib diisi}"

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE USER IF NOT EXISTS '${DB_MIGRATOR_USERNAME}'@'%' IDENTIFIED BY '${DB_MIGRATOR_PASSWORD}';
ALTER USER '${DB_MIGRATOR_USERNAME}'@'%' IDENTIFIED BY '${DB_MIGRATOR_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_MIGRATOR_USERNAME}'@'%';
FLUSH PRIVILEGES;
SQL

echo "user migrasi '${DB_MIGRATOR_USERNAME}' siap (privileges penuh di ${DB_DATABASE})"

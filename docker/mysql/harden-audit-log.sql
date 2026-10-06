-- Hardening grant untuk audit log append-only.
--
-- KENAPA FILE INI ADA
-- Docker MySQL otomatis memberi `GRANT ALL PRIVILEGES ON dashboard.*` ke user
-- aplikasi saat container pertama kali dibuat. Grant itu membuat `audit_logs`
-- bisa di-UPDATE dan di-DELETE oleh kredensial aplikasi, dan — lebih buruk —
-- user aplikasi juga mendapat DDL, sehingga TRUNCATE/ALTER/DROP TABLE
-- audit_logs mungkin. Artinya audit trail bisa dimusnahkan seluruhnya.
--
-- DUA USER, DUA PERAN
--   dashboard_migrator : DDL + DML penuh. HANYA dipakai `php artisan migrate`.
--                        Dibuat oleh docker/mysql/init/01-create-migrator.sh.
--   dashboard_app      : DML saja, TANPA DDL. Dipakai aplikasi saat runtime.
--                        Diatur oleh file ini.
--
-- File ini SENGAJA tidak memberi CREATE/ALTER/DROP/INDEX/REFERENCES kepada
-- dashboard_app. Itulah yang menutup jalur TRUNCATE/ALTER/DROP pada audit_logs.
--
-- MySQL tidak bisa menimpa grant database-level dengan REVOKE table-level
-- (ERROR 1147), jadi grant database-level harus dihapus lebih dulu lalu
-- dibuat ulang per tabel.
--
-- KAPAN DIJALANKAN
-- Setelah `php artisan migrate`, karena GRANT pada tabel yang belum ada
-- ditolak (ERROR 1146). Jalankan ulang setiap kali ada tabel baru dari
-- migration, kalau tidak tabel itu tidak akan punya hak akses.
--
-- CARA MENJALANKAN (dari root project)
--   docker cp docker/mysql/harden-audit-log.sql plnjbt_db:/tmp/harden.sql
--   docker compose exec -T db bash -c "mysql -uroot -p$DB_ROOT_PASSWORD < /tmp/harden.sql"

-- 1. Hapus grant database-level supaya grant per-tabel bisa berlaku.
--    IF EXISTS supaya file ini aman dijalankan berulang.
REVOKE IF EXISTS ALL PRIVILEGES ON `dashboard`.* FROM 'dashboard_app'@'%';

-- 2. SENGAJA TIDAK ADA BLOK DDL DI SINI.
--    dashboard_app tidak boleh punya CREATE/ALTER/DROP. Kalau blok DDL
--    ditambahkan kembali, jalur TRUNCATE/ALTER/DROP pada audit_logs terbuka
--    lagi dan gap P0-6 muncul kembali. DDL adalah milik dashboard_migrator.

-- 3. DML penuh untuk tabel operasional.
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`cache` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`cache_locks` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`datasets` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`divisions` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`failed_jobs` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`indicators` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`job_batches` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`jobs` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`migrations` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`org_units` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`password_reset_tokens` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`performance_metrics` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`performance_observations` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`permission_role` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`permissions` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`roles` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`sessions` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`users` TO 'dashboard_app'@'%';

-- 4. Audit log: append-only. Tanpa UPDATE, tanpa DELETE.
--    Tanpa DDL, jadi TRUNCATE/ALTER/DROP juga ditolak.
GRANT SELECT, INSERT ON `dashboard`.`audit_logs` TO 'dashboard_app'@'%';

FLUSH PRIVILEGES;

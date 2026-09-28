-- Hardening grant untuk audit log append-only.
--
-- KENAPA FILE INI ADA
-- Docker MySQL otomatis memberi `GRANT ALL PRIVILEGES ON dashboard.*` ke user
-- aplikasi saat container pertama kali dibuat. Grant itu membuat `audit_logs`
-- bisa di-UPDATE dan di-DELETE oleh kredensial aplikasi, sehingga klaim
-- "append-only 2 lapis" tidak terpenuhi di lapisan DB.
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
--
-- CATATAN RISIKO
-- Blok DDL di bawah memberi hak ubah STRUKTUR (CREATE/ALTER/DROP) di seluruh
-- database, karena migration membutuhkannya. Artinya struktur audit_logs
-- masih bisa diubah/di-drop; yang dikunci adalah DATA-nya (UPDATE/DELETE).
-- Untuk production, pisahkan user migration dari user aplikasi.

-- 1. Hapus grant database-level supaya grant per-tabel bisa berlaku.
--    IF EXISTS supaya file ini aman dijalankan berulang.
REVOKE IF EXISTS ALL PRIVILEGES ON `dashboard`.* FROM 'dashboard_app'@'%';

-- 2. DDL untuk kebutuhan migration (tabel baru, alter, rollback).
GRANT CREATE, ALTER, DROP, INDEX, REFERENCES ON `dashboard`.* TO 'dashboard_app'@'%';

-- 3. DML penuh untuk tabel operasional.
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`cache` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`cache_locks` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`datasets` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`divisions` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`failed_jobs` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`job_batches` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`jobs` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`migrations` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`password_reset_tokens` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`performance_metrics` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`permission_role` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`permissions` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`roles` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`sessions` TO 'dashboard_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `dashboard`.`users` TO 'dashboard_app'@'%';

-- 4. Audit log: append-only. Tanpa UPDATE, tanpa DELETE.
GRANT SELECT, INSERT ON `dashboard`.`audit_logs` TO 'dashboard_app'@'%';

FLUSH PRIVILEGES;

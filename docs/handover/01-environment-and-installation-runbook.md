# 01 - Panduan Lingkungan Sistem & Runbook Instalasi Bersih (Fresh Deployment)

Dokumen ini memuat panduan komprehensif bagi Tim Infrastruktur Teknologi Informasi (IT), DevOps Engineer, dan Administrator Sistem PLN Unit Induk Transmisi Jawa Bagian Tengah (UIT JBT) dalam menyiapkan lingkungan server, mengonfigurasi kontainer, serta melaksanakan prosedur instalasi bersih (*fresh deployment*) aplikasi IBS / plnjbt-dashboard.

---

## 1. Spesifikasi Perangkat Lunak & Persyaratan Sistem

Aplikasi dirancang untuk berjalan di lingkungan kontainer Docker berbasis Linux pada host server Windows Server atau Linux Ubuntu/RHEL:

| Komponen | Spesifikasi Resmi | Keterangan & Rujukan |
|---|---|---|
| **Sistem Operasi Host** | Windows Server 2022 / Windows 10/11 Pro / Ubuntu 22.04 LTS | Mendukung Docker Engine / Docker Desktop |
| **Runtime PHP** | **PHP 8.4** (`php:8.4-apache`) | Modul: `pdo_mysql`, `mbstring`, `exif`, `pcntl`, `bcmath`, `gd`, `zip` |
| **Web Server** | Apache 2.4 (terintegrasi dalam kontainer `plnjbt_app`) | Modul `mod_rewrite` aktif, DocumentRoot: `/var/www/html/public` |
| **Basis Data Relasional** | **MySQL 8.4 Community Server LTS** | InnoDB, Collation: `utf8mb4_unicode_ci` dan `utf8mb4_bin` |
| **Node.js & NPM** | Node.js v20+ LTS / NPM v10+ | Digunakan untuk kompilasi bundel aset Tailwind CSS 4 dan Alpine.js 3 |
| **Composer** | Composer v2.7+ | Manajer dependensi PHP |
| **Memori Server Minimal** | Minimal 4 GB RAM (Rekomendasi: 8 GB RAM) | Memastikan kelancaran konversi berkas Excel berukuran besar |
| **Penyimpanan Disk** | Minimal 20 GB ruang kosong SSD | Media simpan volume basis data `db_data` dan arsip berkas dataset |

---

## 2. Arsitektur Basis Data Dua-Pengguna (Two-User Model)

Sistem menerapkan prinsip pembatasan hak akses minimum (*Principle of Least Privilege*) dengan membagi koneksi database ke dalam dua akun terpisah:

```mermaid
flowchart TD
    subgraph HostCLI["Eksekusi Terminal / CI / Deployment"]
        ArtisanMigrate["php artisan migrate<br/>(Menggunakan dashboard_migrator)"]
    end

    subgraph WebRuntime["Permintaan Web Pengguna (HTTP)"]
        LaravelApp["Laravel Web Application Runtime<br/>(Menggunakan dashboard_app)"]
    end

    subgraph MySQL84["MySQL 8.4 Community Server (plnjbt_db)"]
        UserMigrator[("Akun: dashboard_migrator<br/>Hak Akses: DDL + DML Penuh<br/>(CREATE, ALTER, DROP, INSERT, UPDATE, DELETE)")]
        UserApp[("Akun: dashboard_app<br/>Hak Akses: DML Per-Tabel Saja<br/>(TANPA DDL, audit_logs Append-Only)")]

        TablesOperasional[("20 Tabel Operasional<br/>(SELECT, INSERT, UPDATE, DELETE)")]
        TableAuditLogs[("Tabel audit_logs<br/>(HANYA SELECT dan INSERT)")]
    end

    ArtisanMigrate --> UserMigrator
    LaravelApp --> UserApp

    UserMigrator -->|Kendalikan Skema| TablesOperasional
    UserMigrator -->|Kendalikan Skema| TableAuditLogs

    UserApp -->|Baca & Tulis Transaksional| TablesOperasional
    UserApp -->|Tulis Jejak Kekal (Append-Only)| TableAuditLogs
```

### 2.1 Pengguna Migrasi: `dashboard_migrator`
- **Peran:** Menjalankan pembaruan skema database melalui perintah framework (`php artisan migrate`).
- **Inisialisasi:** Dibuat secara otomatis saat pertama kali volume database diinisialisasi melalui skrip `docker/mysql/init/01-create-migrator.sh`.
- **Wewenang:** Memiliki hak penuh Data Definition Language (DDL) seperti `CREATE`, `ALTER`, `DROP`, `REFERENCES`, `INDEX` serta Data Manipulation Language (DML) atas skema `dashboard.*`.
- **Kredensial:** Dikonfigurasi pada berkas `.env` melalui variabel `DB_MIGRATOR_USERNAME` dan `DB_MIGRATOR_PASSWORD`.

### 2.2 Pengguna Runtime Aplikasi: `dashboard_app`
- **Peran:** Menangani seluruh transaksi harian saat aplikasi melayani permintaan HTTP dari pengguna dasbor.
- **Wewenang:** Hanya memiliki hak DML per-tabel pada tabel operasional aplikasi. **Sama sekali tidak memiliki hak DDL** (tidak dapat melakukan `CREATE`, `ALTER`, `DROP`, atau `TRUNCATE`).
- **Sifat Kekal Audit Log (Append-Only):** Pada tabel `audit_logs`, akun ini secara eksplisit hanya diberikan hak `SELECT` dan `INSERT`. Perintah `UPDATE` dan `DELETE` ditolak langsung pada level engine MySQL guna menjamin integritas forensik jejak audit sistem.
- **Kredensial:** Dikonfigurasi pada berkas `.env` melalui variabel `DB_USERNAME` dan `DB_PASSWORD`.

---

## 3. Langkah-demi-Langkah Instalasi Bersih (Fresh Deployment Runbook)

Ikuti sembilan (9) langkah terstruktur berikut saat melakukan instalasi pertama kali di server baru:

### Langkah 1: Kloning Repositori Proyek
Buka terminal server dan kloning repositori ke direktori kerja yang ditentukan:
```bash
git clone https://github.com/TommmJr/dashboard.git /opt/plnjbt-dashboard
cd /opt/plnjbt-dashboard
```

### Langkah 2: Konfigurasi Berkas Lingkungan (`.env`)
Salin template konfigurasi dan sesuaikan variabel lingkungan:
```bash
cp .env.example .env
```

Pastikan variabel-variabel kunci berikut disetel secara presisi:
```dotenv
APP_NAME="IBS Dashboard"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://localhost:8000

# Konfigurasi Koneksi Runtime Aplikasi (dashboard_app)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dashboard
DB_USERNAME=dashboard_app
DB_PASSWORD=GantiPasswordKuatApp123!

# Kredensial Root & Migrator Database
DB_ROOT_PASSWORD=GantiPasswordKuatRoot123!
DB_MIGRATOR_USERNAME=dashboard_migrator
DB_MIGRATOR_PASSWORD=GantiPasswordKuatMigrator123!

# Konfigurasi Model Baca KPI (Wajib structured untuk produksi)
KPI_READ_MODEL=structured

# Batasan Cakupan Unit Kinerja (Default TJBT)
KPI_VISIBLE_UNITS=TJBT
```

Generate application encryption key:
```bash
php artisan key:generate
```

### Langkah 3: Kompilasi Aset Frontend (Vite)
Sebelum kontainer aplikasi dibangun atau dijalankan, kompilasi seluruh aset CSS/JS agar artefak produksi tersedia:
```bash
npm install
npm run build
```
Pastikan berkas `public/build/manifest.json` telah berhasil digenerate.

### Langkah 4: Membangun & Menyalakan Kontainer Docker
Nyalakan kontainer menggunakan Docker Compose:
```bash
docker compose up -d --build
```
Verifikasi bahwa kedua kontainer utama (`plnjbt_app` dan `plnjbt_db`) berada dalam status `Up`:
```bash
docker compose ps
```

### Langkah 5: Instalasi Dependensi PHP Vendor
Jalankan pengunduhan pustaka Composer di dalam kontainer:
```bash
docker compose exec -T app composer install --no-dev --optimize-autoloader
```

### Langkah 6: Eksekusi Migrasi Skema & Seeding Data Awal
Jalankan migrasi skema 20 tabel (21 batch migrasi) dan pengisian data peran resmi (`RolePermissionSeeder`):
```bash
# Migrasi dijalankan menggunakan kredensial dashboard_migrator
docker compose exec -T app php artisan migrate --force --seed
```

### Langkah 7: Eksekusi Wajib Skrip Hardening Grant Database (`harden-audit-log.sql`)
Tahap ini merupakan syarat mutlak keamanan sistem. Eksekusi skrip SQL hardening untuk mencabut hak istimewa global dan menegakkan hak DML per-tabel serta aturan append-only:
```bash
# 1. Salin berkas skrip hardening ke dalam kontainer database
docker cp docker/mysql/harden-audit-log.sql plnjbt_db:/tmp/harden.sql

# 2. Eksekusi skrip menggunakan akun root database
docker compose exec -T db bash -c "mysql -uroot -p\$DB_ROOT_PASSWORD < /tmp/harden.sql"

# 3. Hapus berkas temporary dari kontainer
docker compose exec -T db rm /tmp/harden.sql
```

### Langkah 8: Verifikasi Hak Akses Basis Data Pasca-Hardening
Pastikan konfigurasi hak akses pengguna runtime `dashboard_app` telah terkunci dengan benar:
```bash
docker compose exec -T db mysql -uroot -p"$DB_ROOT_PASSWORD" -e "SHOW GRANTS FOR 'dashboard_app'@'%';"
```
Keluaran yang sah **WAJIB** menunjukkan:
- `GRANT USAGE ON *.* TO 'dashboard_app'@'%'`
- `GRANT SELECT, INSERT ON dashboard.audit_logs TO 'dashboard_app'@'%'` (tanpa hak `UPDATE` atau `DELETE`)
- `GRANT SELECT, INSERT, UPDATE, DELETE ON dashboard.<tabel_operasional>` untuk 19 tabel lainnya.

### Langkah 9: Konfigurasi Storage Link & Optimasi Cache Laravel
Buat tautan simbolik direktori penyimpanan publik dan aktifkan cache konfigurasi:
```bash
docker compose exec -T app php artisan storage:link
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache
```

---

## 4. Checklist Kesiapan Operasional Pasca-Instalasi (Verification Gate)

Sebelum menyerahkan sistem kepada pengguna, lakukan pengecekan mandiri terhadap checklist berikut:

- [ ] Kontainer `plnjbt_app` dan `plnjbt_db` berstatus `Up` tanpa restart berulang.
- [ ] Port web `8000` dapat diakses dari peramban dan menampilkan halaman masuk (*login*).
- [ ] Port database `3306` hanya terikat ke loopback `127.0.0.1` (`netstat -ano | findstr 3306`).
- [ ] Akun administrator bawaan dapat masuk ke aplikasi dengan sukses.
- [ ] Menu `Dashboard` memuat data tanpa galat HTTP 500.
- [ ] Percobaan login gagal tercatat ke dalam tabel `audit_logs` dengan status `LOGIN_FAILED`.
- [ ] Verifikasi uji coba otomatis di dalam kontainer lulus hijau:
  ```bash
  docker compose exec -T app php artisan test --filter="HandoverReadinessSmokeTest|SecurityHeadersTest|AuditLogTest"
  ```

---

## 5. Prosedur Pemeliharaan & Pembaruan Sistem (Maintenance Runbook)

### 5.1 Penambahan Tabel / Migrasi Baru di Masa Depan
Jika tim IT PLN menambahkan berkas migrasi baru:
1. Jalankan migrasi: `docker compose exec -T app php artisan migrate --force`.
2. **Wajib:** Jalankan ulang skrip hardening hak akses:
   ```bash
   docker cp docker/mysql/harden-audit-log.sql plnjbt_db:/tmp/harden.sql
   docker compose exec -T db bash -c "mysql -uroot -p\$DB_ROOT_PASSWORD < /tmp/harden.sql"
   ```
   *(Hal ini diperlukan karena MySQL menolak pemberian hak akses pada tabel yang belum tercipta saat skrip pertama kali dijalankan).*

### 5.2 Pembersihan Cache Setelah Pembaruan Konfigurasi
```bash
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan cache:clear
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache
```

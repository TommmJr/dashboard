# plnjbt-dashboard

Aplikasi web untuk pengelolaan dan visualisasi data kinerja di Bidang
Perencanaan PLN UIT JBT - pengganti Looker Studio yang datanya dan
fiturnya bisa diolah sendiri oleh karyawan, tanpa bergantung pada
developer.

## Fitur

- Unggah data laporan Excel/CSV dengan validasi otomatis
- Dashboard yang bisa disusun, diubah, dan dihapus langsung dari aplikasi
- Visualisasi: KPI card, grafik bar, grafik line, dan tabel
- Filter tanggal dan unit yang berlaku lintas widget
- Jejak audit untuk setiap perubahan data

## Persyaratan

- PHP >= 8.2
- Composer
- Node.js dan npm
- MySQL 8

## Instalasi

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Sesuaikan kredensial MySQL di file `.env`, lalu:

```bash
php artisan migrate
npm run dev
php artisan serve
```

Aplikasi bisa diakses di `http://localhost:8000`.

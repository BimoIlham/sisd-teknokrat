# Panduan Operasional & Pemeliharaan Sistem (Operations Runbook)

Versi: **2.0** · Lembaga Penjaminan Mutu (LPM) Universitas Teknokrat Indonesia

---

## 1. Arsitektur Layanan & Port Lokal

| Komponen | Runtime | Port / Interface | Perintah Menjalankan |
|---|---|---|---|
| **Web Server (Laravel)** | PHP 8.3 CLI / Nginx | `127.0.0.1:8000` | `php artisan serve --port=8000` |
| **Vite Dev Server** | Node.js v20.x | `127.0.0.1:5173` | `npm run dev` (atau `npm run build` untuk produksi) |
| **Python Compute Service** | Python 3.10 FastAPI | `127.0.0.1:8100` | `python python-service/run_server.py` |
| **Database Server** | MySQL 8.0.41 | `127.0.0.1:3306` | Layanan Windows `MySQL80` (Laragon) |
| **Queue Worker** | Laravel Queue | CLI background | `php artisan queue:work --tries=3 --timeout=180` |

---

## 2. Prosedur Instalasi Bersih (Clean Setup)

1. **Konfigurasi Environment**:
   Salin `.env.example` ke `.env` dan pastikan kredensial basis data:
   ```ini
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=sisd_teknokrat
   DB_USERNAME=root
   DB_PASSWORD=210505
   
   PYTHON_SERVICE_URL=http://127.0.0.1:8100
   PYTHON_SERVICE_SECRET=sisd_internal_secret_token_2026
   ```

2. **Generate Application Key**:
   ```powershell
   php artisan key:generate
   ```

3. **Migrasi Database & Seeding Awal**:
   ```powershell
   php artisan migrate --force
   php artisan db:seed
   ```

4. **Kompilasi Aset Frontend**:
   ```powershell
   npm install
   npm run build
   ```

5. **Inisialisasi Python Compute Service**:
   ```powershell
   python -m pip install -r python-service/requirements.txt
   ```

---

## 3. Akun Bawaan Sistem (Demo & Development)

Sistem menginisialisasi akun bawaan melalui seeder:
- **Superadmin (LPM)**:
  - Email: `lpm@teknokrat.ac.id`
  - Password: `Password123!`
  - Hak Akses: Manajemen seluruh term, impor PDF, penugasan otomatis/manual, finalisasi, pemantauan dashboard, dan konfigurasi template.
- **Supervisor Dosen Contoh**:
  - Email: `supervisor1@teknokrat.ac.id`
  - Password: `Password123!`
  - Hak Akses: Dashboard tugas personal, Jadwal Saya, pengisian formulir supervisi, dan unggah bukti.

---

## 4. Prosedur Backup & Restore Data

### 4.1 Backup Database & Storage Privat
```powershell
# 1. Backup SQL Dump
mysqldump -u root -p210505 sisd_teknokrat > backup_sisd_teknokrat.sql

# 2. Backup File Privat (Jadwal & Bukti Supervisi)
Compress-Archive -Path storage\app\private -DestinationPath backup_storage_private.zip
```

### 4.2 Restore Data
```powershell
mysql -u root -p210505 -e "DROP DATABASE IF EXISTS sisd_teknokrat; CREATE DATABASE sisd_teknokrat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p210505 sisd_teknokrat < backup_sisd_teknokrat.sql
Expand-Archive -Path backup_storage_private.zip -DestinationPath storage\app\private -Force
```

---

## 5. Pemulihan & Monitoring Queue Gagal (Failed Jobs)

Jika terjadi kegagalan parser PDF atau solver timeout:
```powershell
# Cek daftar job gagal
php artisan queue:failed

# Retry job tertentu
php artisan queue:retry <id>

# Retry seluruh job gagal
php artisan queue:retry all
```
Setiap transaksi solver dan commit impor telah dirancang idempotent dan atomic sehingga retry tidak akan menimbulkan data ganda.

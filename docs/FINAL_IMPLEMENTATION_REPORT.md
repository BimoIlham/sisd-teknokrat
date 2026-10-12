# Laporan Akhir Implementasi — Sistem Supervisi Mutu Perkuliahan (v2.0)
**Universitas Teknokrat Indonesia — Lembaga Penjaminan Mutu (LPM)**  
Tanggal: **22 September 2026** · Status: **P0 SELESAI & TERVERIFIKASI**

---

## 1. Status Nyata Sistem

| Komponen | Status Kesiapan | Catatan Operasional |
|---|---|---|
| **Pondasi & Auth** | **SIAP OPERASI** | Dual-role (`superadmin`, `supervisor`), Spatie RBAC, Force password change, IDOR mitigation. |
| **Database & Integritas Data** | **SIAP OPERASI** | MySQL 8.0.41 InnoDB, FK cascade/restrict, unique active generated column, optimistic locks (`row_version`). |
| **Pipeline Import Jadwal** | **SIAP OPERASI** | Ekstraksi tabular PyMuPDF & pdfplumber, preview UI, identity match review, commit idempoten. |
| **Constraint Solver CP-SAT** | **SIAP OPERASI** | Engine Google OR-Tools CP-SAT bertingkat (Coverage > Balance > Stability) + PHP fallback engine. |
| **Workspace Penugasan & Finalisasi**| **SIAP OPERASI** | Visualisasi penugasan, manual override locked, revision lock 409 conflict, finalisasi tahap T1/T2. |
| **Cetak Jadwal PDF Personal** | **SIAP OPERASI** | DomPDF adapter, jadwal gabungan mengajar & tugas supervisi bertanggal dengan highlight warna kuning. |
| **Form Dinamis & Bukti Privat** | **SIAP OPERASI** | Engine form data-driven (SCORE, YES_NO, LINK, FILE, TEXTAREA), upload bukti di storage privat, draft autosave. |
| **Scoring & Snapshot Immutability**| **SIAP OPERASI** | Perhitungan nilai sisi server dari opsi DB, freeze snapshot JSON saat submit, pembulatan di view. |
| **Dashboard & Analitik Kohor** | **SIAP OPERASI** | Denominator target `INCLUDED * stages`, komparasi delta berpasangan T1 vs T2 dengan filter sebanding. |
| **Bahan Resmi Institusi** | **TERBUKA (D01–D07)**| Memakai instrumen demo berlabel eksplisit (`DEMO_OFFLINE` 29 item, `DEMO_ONLINE` 20 item) menunggu rilis resmi BAAK/LPM. |

---

## 2. Modul dan Alur yang Telah Dibangun

1. **Alur Autentikasi & Akun**:
   - Superadmin (LPM) dan Supervisor login dengan kredensial aman (`password_verify`, rate limiting 5 percobaan/menit).
   - Akun baru dengan flag `must_change_password` diarahkan ke form pembaruan password wajib sebelum dapat mengakses fitur.
   - Hak akses supervisor dibatasi per periode dan terlindung kebijakan autorisasi ketat (Invariant 3 & Invariant 5).

2. **Alur Master & Penjadwalan Waktu**:
   - Master Fakultas, Program Studi, Dosen, Mata Kuliah, dan Tahun Akademik.
   - Konsep jadwal mingguan dibedakan tegas dari **occurrence bertanggal nyata** (`teaching_occurrences`) dengan timezone `Asia/Jakarta`.
   - Periode supervisi dan tahap (T1, T2) dengan rentang tanggal eksplisit.

3. **Alur Import & Parsing PDF Jadwal**:
   - Unggah berkas jadwal BAAK (PDF) ke staging privat.
   - Ekstraksi data via Python service (FastAPI) menggunakan PyMuPDF / pdfplumber.
   - Layar verifikasi interaktif: pencocokan dosen/mata kuliah, deteksi anomali, approval per baris, dan komit transaksional idempoten (Invariant 11).

4. **Alur Penjadwalan Otomatis & Manual**:
   - Pembentukan kandidat jadwal memenuhi hard constraints H01–H08:
     - H01: Larangan self-supervision.
     - H02: Larangan bentrok dengan jadwal mengajar supervisor.
     - H03: Larangan bentrok dengan penugasan supervisi lain.
     - H04: Validitas jadwal kelas target.
     - H05: Ambang mengajar >= 3 kelas per hari memblokir tugas supervisi tambahan.
     - H06: Larangan penugasan pada tanggal libur akademik.
     - H07: Urutan tahap (T2 wajib bertanggal setelah T1).
     - H08: Kesesuaian moda pembelajaran (OFFLINE / ONLINE).
   - Pengiriman payload ke solver CP-SAT Google OR-Tools untuk menghasilkan proposal optimal.
   - Penerapan proposal terlindung `scheduling_state` revision (mencegah penimpaan usang).
   - Penugasan manual berstatus `MANUAL` terkunci secara permanen dari penimpaan scheduler (Invariant 16).

5. **Alur Eksekusi Supervisi & Penilaian**:
   - Supervisor mengakses "Jadwal Saya", melihat agenda terpadu, dan mengunduh PDF personal dengan slot supervisi berwarna kuning institusional (Invariant 39).
   - Form supervisi dinamis berbasis section dan question model:
     - Autosave berkala via AJAX dengan validasi `row_version` (mencegah race condition antar-tab).
     - Unggah foto bukti ke storage privat dengan verifikasi MIME type dan batas ukuran.
     - Skor `0` dan nilai boolean `NO/false` diperlakukan sebagai masukan sah (Invariant 26).
     - Submit atomik memvalidasi kelengkapan, menghitung nilai di server, mengunci form, dan membekukan snapshot JSON lengkap (Invariant 29).

6. **Alur Dashboard & Analitik**:
   - Dashboard LPM memonitor progres penugasan dan pengisian form dengan penyebut target `INCLUDED * mandatory_stages` (Invariant 38).
   - Analitik perbandingan T1 vs T2 menyandingkan dosen yang sama (`period_id + lecturer_id`) dan secara cerdas menyaring data yang tidak sebanding akibat perbedaan moda perkuliahan atau instrumen (Invariant 36, 37).

---

## 3. Cara Menjalankan Aplikasi

### Kebutuhan Sistem
- PHP >= 8.3 (dengan ekstensi `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `fileinfo`)
- MySQL >= 8.0 (InnoDB)
- Composer >= 2.0
- Node.js >= 20 & npm
- Python >= 3.10 (untuk modul FastAPI parser & solver)

### Langkah Cepat Menjalankan
```bash
# 1. Masuk ke direktori proyek
cd c:/laragon/www/sisd-teknokrat

# 2. Jalankan migrasi dan seeder dasar
php artisan migrate:fresh --seed

# 3. Build aset frontend
npm run build

# 4. Jalankan Python Service (FastAPI) pada port 8100
python-service\venv\Scripts\python python-service/run_server.py

# 5. Jalankan Web Server Laravel pada terminal terpisah
php artisan serve
```

Seeder default menyiapkan role, permission, serta bank pertanyaan OFFLINE dan ONLINE. Seeder ini tidak membuat akun admin bawaan atau data supervisi simulasi.

---

## 4. Bukti Hasil Pengujian

1. **PHPUnit Feature Tests**:
   - Perintah: `php artisan test --testdox`
   - Hasil: **20 Tests Passed, 48 Assertions, 0 Failures (100% Green)**.
   - Waktu Eksekusi: 1.63 detik pada database MySQL `sisd_teknokrat_test`.
   - Menguji: Login, password change, IDOR supervisor, Invariant 5 (self-evaluation hidden), Hard constraints H01, H02, H05, Manual lock (Inv 16), Stale revision 409 (Inv 20), Autosave draft, Server scoring, Nilai 0 & False valid (Inv 26), Submit snapshot (Inv 29), Progress denominator (Inv 38), Paired cohort & comparability (Inv 36-37).

2. **Frontend Production Build**:
   - Perintah: `npm run build`
   - Hasil: Bundle CSS (`42.50 kB`) dan JS (`87.10 kB`) terkompilasi tanpa error.

3. **PDF Generation Service**:
   - Hasil: DomPDF sukses merender jadwal personal supervisor (ukuran file 14,207 bytes) dengan penataan tabel padat, header institusi, dan penandaan baris supervisi warna kuning.

---

## 5. Register Keputusan Bisnis Terbuka (D01–D07)

Aplikasi dibangun dengan engine yang siap menerima pengesahan LPM:
- **D01 (Teks Instrumen Resmi)**: Menggunakan instrumen demo berlabel eksplisit `DEMO_OFFLINE` (29 butir) dan `DEMO_ONLINE` (20 butir). Engine form siap menerima instrumen resmi kapan saja tanpa mengubah kode.
- **D02 (Formula Nilai Resmi)**: 3 strategi kalkulasi (`MEAN_0_4`, `LINEAR_1_4`, `FLOOR_1_4`) telah diimplementasikan di server. Demo default menggunakan `MEAN_0_4`.
- **D03 (Batas Span 480 Menit)**: Tersedia sebagai parameter konfigurasi aturan jadwal; dinonaktifkan secara default menunggu instruksi pimpinan. Ambang batas mengajar `>=3` kelas per hari aktif sebagai baseline H05.
- **D04 (Aturan Kohor Supervisor T1/T2)**: Konfigurasi mendukung opsi penugasan supervisor sama atau berbeda untuk T1 dan T2.
- **D05 (Cakupan Dosen Target)**: Fitur pemilihan cakupan `INCLUDED`/`EXCLUDED` per dosen per periode telah berfungsi penuh.
- **D06 (Variasi Layout PDF Jadwal)**: Adapter ekstraksi tabular multi-halaman siap membaca berkas jadwal PDF.
- **D07 (Kredensial & Kalender Produksi)**: Dikelola via `.env` dan database terisolasi.

---

## 6. Lokasi Berkas & Dokumentasi

- **Source Code Backend**: `app/Http/Controllers/`, `app/Models/`, `app/Services/`, `app/Policies/`
- **Frontend Blade & Styles**: `resources/views/`, `resources/css/app.css`, `resources/js/app.js`
- **Python Service**: `python-service/` (`main.py`, `cp_sat_solver.py`, `schedule_parser.py`, `run_server.py`)
- **Migrations & Seeders**: `database/migrations/`, `database/seeders/`
- **Dokumentasi Lengkap**:
  - `README.md`: Panduan instalasi dan operasional bersih.
  - `docs/DECISIONS.md`: Register keputusan D01–D07 dan catatan arsitektur (ADR).
  - `docs/IMPLEMENTATION_PLAN.md`: Tahapan eksekusi F0–F9.
  - `docs/IMPLEMENTATION_STATUS.md`: Log progres dan checkpoint.
  - `docs/REQUIREMENT_TRACEABILITY.md`: Pemetaan requirement ke kode dan test.
  - `docs/TEST_REPORT.md`: Laporan eksekusi test suite terverifikasi.
  - `docs/OPERATIONS.md`: Prosedur backup, restore, antrian, dan pemeliharaan sistem.

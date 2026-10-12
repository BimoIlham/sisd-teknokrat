# Sistem Supervisi Mutu Perkuliahan (v2.0)
**Lembaga Penjaminan Mutu (LPM) — Universitas Teknokrat Indonesia**

Aplikasi manajemen, penjadwalan ber-constraint (Google OR-Tools CP-SAT), pelaksanaan observasi perkuliahan, form dinamis, evaluasi berbasis bukti, dan analitik mutu pengajaran dosen berbasis Laravel 13 dan Python FastAPI.

---

## 1. Arsitektur dan Teknologi

- **Backend Web**: Laravel 13.x (PHP 8.3+)
- **Database**: MySQL 8.0+ (Engine InnoDB dengan Foreign Keys, Generative Column Unique, Optimistic Concurrency `row_version`)
- **Frontend**: Blade Templating, Vite 8, Tailwind CSS 4, Alpine.js 3, Lucide Icons, Chart.js, SweetAlert2
- **Keamanan & Akses**: Spatie Laravel Permission (Role `superadmin` & `supervisor`), Policy IDOR Defense, Forced Password Change
- **Layanan Ekstraksi & Solver**: Python 3.10+ FastAPI pada internal loopback (`127.0.0.1:8100`)
  - Ekstraksi PDF: PyMuPDF (`fitz`) & `pdfplumber` (Multi-page tabular bounding box extraction)
  - Constraint Engine: Google OR-Tools CP-SAT (`Coverage > Balance > Stability`)
- **Laporan & Dokumen**: DomPDF Adapter (Jadwal personal mengajar & supervisi gabungan berpenanda kuning institusional)

---

## 2. Persyaratan Sistem

- PHP >= 8.3 dengan ekstensi: `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `fileinfo`, `xml`, `gd`
- Composer >= 2.2
- Node.js >= 20.x & npm
- Python >= 3.10 dengan pip
- MySQL >= 8.0 (InnoDB)

---

## 3. Langkah Instalasi Bersih

### A. Clone & Instal Dependensi PHP
```bash
cd c:/laragon/www/sisd-teknokrat
composer install
```

### B. Konfigurasi Lingkungan (.env)
Salin `.env.example` menjadi `.env` dan sesuaikan kredensial MySQL:
```bash
cp .env.example .env
php artisan key:generate
```

Pastikan variabel database pada `.env` telah sesuai:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sisd_teknokrat
DB_USERNAME=root
DB_PASSWORD=your_password

# Python Compute Service URL
PYTHON_SERVICE_URL=http://127.0.0.1:8100
```

### C. Migrasi & Seeder Basis Data
Jalankan migrasi dan seeder awal:
```bash
# Untuk database utama operasional/demo
php artisan migrate:fresh --seed

# Untuk database testing terisolasi (pastikan database sisd_teknokrat_test sudah ada di MySQL)
php artisan --env=testing migrate:fresh --seed
```

### D. Setup Python Service
Siapkan virtual environment dan pasang pustaka yang dibutuhkan:
```bash
cd python-service
python -m venv venv
venv\Scripts\pip install -r requirements.txt
cd ..
```

### E. Kompilasi Aset Frontend (Tailwind CSS 4 & Alpine.js)
```bash
npm install
npm run build
```

---

## 4. Menjalankan Aplikasi

Aplikasi memerlukan dua proses utama (Web Server Laravel dan Layanan Solver Python):

### Terminal 1: Python Service (FastAPI)
```bash
python-service\venv\Scripts\python python-service/run_server.py
# Layanan aktif di http://127.0.0.1:8100 (Health check: GET http://127.0.0.1:8100/health)
```

### Terminal 2: Laravel Web Server
```bash
php artisan serve
# Aplikasi web aktif di http://127.0.0.1:8000
```

### Terminal 3 (Opsional): Laravel Background Queue Worker
```bash
php artisan queue:work --tries=3 --timeout=120
```

---

## 5. Akun Pengguna Bawaan (Hasil Seeder Demo)

| Role | Nama | Email | Password |
|---|---|---|---|
| **Superadmin (LPM)** | Administrator LPM | `lpm@teknokrat.ac.id`<br>*(atau `admin.lpm@teknokrat.ac.id`)* | `Password123!` |
| **Supervisor 1** | Dr. Budi Santoso, M.Kom. | `supervisor1@teknokrat.ac.id` | `Password123!` |
| **Supervisor 2** | Fitri Yulianti, S.T., M.Cs. | `supervisor2@teknokrat.ac.id` | `Password123!` |
| **Supervisor 3** | Andi Pratama, M.Kom. | `supervisor3@teknokrat.ac.id` | `Password123!` |

---

## 6. Verifikasi & Pengujian

### Menjalankan Test Suite Backend (PHPUnit)
Pengujian mencakup otentikasi, mitigasi IDOR, Invariant 5 (pencegahan akses penilaian diri sendiri), hard constraints H01-H08, manual override locking, revision conflict (HTTP 409), server scoring, dan paired analytics:
```bash
php artisan test --testdox
```

### Menjalankan Test Suite Python (Pytest)
Pengujian ekstraksi PDF tabular dan solver Google OR-Tools CP-SAT:
```bash
cd python-service
venv\Scripts\pytest tests
cd ..
```

---

## 7. Struktur Direktori Proyek

```
sisd-teknokrat/
├── app/
│   ├── Http/Controllers/
│   │   ├── Auth/                  # Login, change password, logout, audit
│   │   ├── Lpm/                   # Dashboard LPM, Periods, Import, Assignments, Instruments, Analytics
│   │   └── Supervisor/            # Dashboard Supervisor, Personal Schedule, Execution
│   ├── Models/                    # Academic identity, Schedules, Periods, Assignments, Supervisions
│   ├── Policies/                  # SupervisionAssignmentPolicy, SupervisionPolicy, EvidencePolicy
│   └── Services/
│       ├── Analytics/             # SupervisionAnalyticsService (Paired T1 vs T2 & Progress denominator)
│       ├── Reporting/             # SchedulePdfService (DomPDF yellow highlighted schedule)
│       ├── ScheduleImport/        # ScheduleImportService & ScheduleCommitService
│       ├── Scheduling/            # ConflictChecker (H01-H08), SolverClient, ApplyRun, ManualOverride
│       └── Supervision/           # DraftService (row_version), SubmitService (freeze snapshot), ScoringService
├── database/
│   ├── migrations/                # 7 Migration tabel utama + Spatie permissions
│   └── seeders/                   # Role, User, DemoAcademic, DemoInstrument seeders
├── docs/
│   ├── DECISIONS.md               # Register D01-D07 & Architectural Decision Records (ADR)
│   ├── IMPLEMENTATION_PLAN.md     # Fase F0 - F9
│   ├── IMPLEMENTATION_STATUS.md   # Checkpoint dan status terkini
│   ├── REQUIREMENT_TRACEABILITY.md# Traceability matrix requirement -> code -> test
│   ├── TEST_REPORT.md             # Laporan pengujian terverifikasi
│   ├── OPERATIONS.md              # Runbook operasional, backup, restore, dan queue
│   └── FINAL_IMPLEMENTATION_REPORT.md # Laporan serah terima implementasi
├── python-service/
│   ├── app/
│   │   ├── contracts/             # Pydantic schemas (parser & solver)
│   │   ├── parsers/               # PyMuPDF & pdfplumber schedule extractor
│   │   └── solvers/               # Google OR-Tools CP-SAT scheduler
│   ├── tests/                     # Pytest unit & integration tests
│   ├── main.py                    # FastAPI application
│   └── run_server.py              # Uvicorn launcher
├── resources/
│   ├── css/app.css                # Tailwind CSS 4 institutional maroon design tokens
│   ├── js/app.js                  # Alpine.js, Chart.js, SweetAlert2 setup
│   └── views/                     # Blade layouts & modules
└── routes/web.php                 # 37 Web routes terproteksi middleware RBAC
```

---

## 8. Panduan Masalah & Troubleshooting

1. **MySQL 1364: Field doesn't have a default value**:
   - Pastikan model Eloquent menggunakan mutator/accessor yang selaras dengan schema basis data InnoDB (tersedia di `TeachingSchedule`, `SupervisionAnswer`, `Supervision`).
2. **Koneksi Solver Gagal (Connection Refused pada port 8100)**:
   - Pastikan layanan Python berjalan: `python-service\venv\Scripts\python python-service/run_server.py`.
   - Sistem memiliki fallback otomatis internal jika solver Python tidak merespon dalam batas waktu.
3. **HTTP 409 Conflict saat Apply Jadwal**:
   - Hal ini merupakan perilaku normal **Invariant 20** jika operator lain telah mengubah atau memfinalisasi jadwal sebelum tombol simpan ditekan. Lakukan refresh halaman untuk memuat revisi mutasi terkini.

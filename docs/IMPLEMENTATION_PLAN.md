# Plan Implementasi — Sistem Supervisi Mutu Perkuliahan (v2.0)

Dokumen ini memetakan urutan implementasi dari Fase F0 hingga F9 sesuai dengan dokumen panduan `MVP.md` dan `Arsitektur.md`.

---

## Ringkasan Fase dan Status Implementasi

| Fase | Deskripsi | Status | Output Kunci |
|---|---|---|---|
| **F0** | Audit Repositori, Spesifikasi Arsitektur, & Isolasi Database | **SELESAI** | Database `sisd_teknokrat` dan `sisd_teknokrat_test` (MySQL 8 InnoDB), `docs/DECISIONS.md`, `docs/OPERATIONS.md`. |
| **F1** | Pondasi Laravel 13, Autentikasi, Role Spatie, & UI Shell Tailwind 4 | **SELESAI** | Dual-role (`superadmin`, `supervisor`), Force change password, Layout Maroon Institutional, Dark Mode, Navbar/Sidebar dinamis. |
| **F2** | Master Akademik, Kalender, Penjadwalan Waktu, & Integritas Schema | **SELESAI** | Migrations 1-7, Model Eloquent dengan accessors/mutators, Seeders `DemoAcademicSeeder` (8 dosen, 3 supervisor, 172 kelas terjadwal). |
| **F3** | Python Service (FastAPI) & Pipeline Import PDF Nyata | **SELESAI** | Modul `python-service/` (PyMuPDF + pdfplumber tabular extraction), `ScheduleImportService`, `ScheduleCommitService`, approval workflow. |
| **F4** | Solver Penjadwalan OR-Tools CP-SAT & Engine Constraint Server | **SELESAI** | `python-service/cp_sat_solver.py` (Coverage > Balance > Stability), `ConflictChecker.php` (H01-H08), `SolverClientService.php`, snapshot revision. |
| **F5** | Workspace Penugasan, Manual Override, Finalisasi, & PDF Personal | **SELESAI** | `AssignmentWorkspaceController.php`, `ManualOverrideService.php`, `StageFinalizationService.php`, `SchedulePdfService.php` (DomPDF dengan baris supervisi kuning). |
| **F6** | Dynamic Instrument Engine & Validasi Kondisional | **SELESAI** | Schema `form_templates`, `form_sections`, `questions`, `question_options`, 29 butir offline, 20 butir online, demo fixtures terisolasi. |
| **F7** | Pelaksanaan Supervisi, Bukti Privat, Submit Atomik, & Scoring Engine | **SELESAI** | `DraftService.php` (optimistic locking `row_version`), `EvidenceService.php` (private uploads), `SubmitService.php` (snapshot beku), `ScoringService.php` (3 strategi). |
| **F8** | Dashboard Analitik & Perbandingan Berpasangan T1 vs T2 | **SELESAI** | Denominator target `INCLUDED * mandatory_stages`, paired cohort matching (`period_id + lecturer_id`), filter komparabilitas delivery mode & template. |
| **F9** | Test Suite Otomatis, Verifikasi Mutu, & Kesiapan Operasi | **SELESAI** | 20 test fitur menyeluruh (100% PASS), pengujian IDOR, race condition revision, MySQL InnoDB constraint verification, asset build production. |

---

## Detail Pelaksanaan Komponen

### 1. Backend & Database
- **Framework**: Laravel 13.32 (PHP 8.3.31).
- **Database**: MySQL 8.0.41 InnoDB dengan foreign key constraints nyata, soft locks (`lockForUpdate`), optimistic locking (`row_version`), dan generated column `active_target_key`.
- **RBAC**: Spatie Laravel Permission dengan 2 role resmi produk: `superadmin` (LPM) dan `supervisor` (dosen ditugaskan per periode).
- **Storage**: Storage disk privat (`storage/app/private`) terlindung policy authorization.

### 2. Python Compute Service
- **Framework**: Python 3.10 + FastAPI + Uvicorn.
- **Ekstraksi PDF**: PyMuPDF (`fitz`) dan `pdfplumber` dengan adapter tabular multi-halaman dan ekstraksi bounding box.
- **Constraint Programming**: Google OR-Tools CP-SAT solver dengan model optimasi bertingkat:
  1. Penugasan wajib semua dosen target yang masuk cakupan (`INCLUDED`).
  2. Keseimbangan beban supervisi antar-supervisor aktif.
  3. Stabilitas jadwal terhadap perubahan sebelumnya.
- **Arsitektur**: Loopback internal `127.0.0.1:8100`, tanpa akses database langsung.

### 3. Frontend & Desain UI
- **Pustaka**: Blade, Vite 8, Tailwind CSS 4, Alpine.js 3, Lucide Icons, Chart.js, SweetAlert2.
- **Identitas Visual**: Maroon institusional (`#7f1d1d`), slot supervisi kuning (`#facc15`), status badge semantik, typography Plus Jakarta Sans, dark mode support.
- **Responsivitas**: Drawer menu pada mobile, tabel dengan sticky header dan horizontal scroll, filter dinamis.
